<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;

/**
 * Moderator tokens (Austin 2026-11-09: Grok Bot gets its own scoped moderator credential, never the
 * administrators' login). A token is a bearer secret for the moderation routes (Moderation) and
 * nothing else: it signs nobody in, so every other route answers it as a visitor (admin/*, the
 * console, Minn Admin, players' routes).
 *
 *   Authorization: Bearer gmod_<16 hex id>_<43 base64url characters>
 *   (or X-Groddle-Moderator: <the same>, for a host that drops Authorization)
 *
 * The database keeps the id, a label ("Grok Bot"), its capabilities and the sha256 of the secret,
 * never the secret: it is shown once, when an administrator makes the token in the console's
 * Moderation tab (session only; a token can never make, list or revoke tokens). Revoking is at
 * once. Capabilities: "read" (the feed: chat log, reports with their reported IM lines, flags,
 * community and board reports, the moderators' log) and "moderate" (the actions).
 */
final readonly class ModTokens
{
    public const TABLE = 'groddle_mod_tokens';
    public const PREFIX = 'gmod_';
    public const HEADER = 'x-groddle-moderator';
    public const CAPS = ['read', 'moderate'];
    public const LABEL_MAX = 60;
    /** How often last_used_at is written (seconds): a polling token does not write on every call. */
    public const TOUCH_EVERY = 60;

    public function __construct(private Db $db)
    {
    }

    /** The token a request carries (Bearer, else the X-Groddle-Moderator header), or null. */
    public static function fromHeaders(?string $authorization, ?string $header): ?string
    {
        $a = trim((string) $authorization);
        if (preg_match('/^Bearer\s+(\S+)$/i', $a, $m) === 1 && str_starts_with($m[1], self::PREFIX)) {
            return $m[1];
        }
        $h = trim((string) $header);
        return str_starts_with($h, self::PREFIX) ? $h : null;
    }

    /** [id, secret] from a token's text, or null when it is not one. @return array{0: string, 1: string}|null */
    public static function parse(string $token): ?array
    {
        return preg_match('/^gmod_([0-9a-f]{16})_([A-Za-z0-9_-]{43})$/', $token, $m) === 1 ? [$m[1], $m[2]] : null;
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', 'groddle-moderator/1' . "\n" . $secret);
    }

    /**
     * A new token: its row and the text shown once.
     *
     * @param list<string> $caps
     * @return array{id: string, token: string, label: string, caps: list<string>}
     */
    public function create(string $label, array $caps, int $by, ?int $now = null): array
    {
        $label = trim(mb_substr((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', ' ', $label)), 0, self::LABEL_MAX));
        $caps = array_values(array_intersect(self::CAPS, $caps));
        if ($label === '' || $caps === []) {
            throw new \InvalidArgumentException('A token needs a label and at least one capability.');
        }
        $id = bin2hex(random_bytes(8));
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->execute('INSERT INTO ' . $this->db->table(self::TABLE) . ' (token_id, label, caps, secret_hash, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)', [
            $id, $label, implode(',', $caps), self::hash($secret), $by, gmdate('Y-m-d H:i:s', $now ?? time()),
        ]);
        return ['id' => $id, 'token' => self::PREFIX . $id . '_' . $secret, 'label' => $label, 'caps' => $caps];
    }

    /**
     * The live token a text names, or null (unknown, revoked, a wrong secret).
     *
     * @return array{id: string, label: string, caps: list<string>, created_by: int}|null
     */
    public function verify(string $token, ?int $now = null): ?array
    {
        $parts = self::parse($token);
        if ($parts === null) {
            return null;
        }
        [$id, $secret] = $parts;
        $row = $this->db->row('SELECT * FROM ' . $this->db->table(self::TABLE) . ' WHERE token_id = ?', [$id]);
        if ($row === null || $row['revoked_at'] !== null || !hash_equals((string) $row['secret_hash'], self::hash($secret))) {
            return null;
        }
        $now ??= time();
        $used = $row['last_used_at'] === null ? 0 : (int) strtotime((string) $row['last_used_at'] . ' UTC');
        if ($now - $used >= self::TOUCH_EVERY) {
            $this->db->execute('UPDATE ' . $this->db->table(self::TABLE) . ' SET last_used_at = ? WHERE token_id = ?', [gmdate('Y-m-d H:i:s', $now), $id]);
        }
        return ['id' => $id, 'label' => (string) $row['label'], 'caps' => array_values(array_filter(explode(',', (string) $row['caps']))), 'created_by' => (int) $row['created_by']];
    }

    /** Every token, newest first, without its hash. @return list<array<string, mixed>> */
    public function list(): array
    {
        $out = [];
        foreach ($this->db->rows('SELECT token_id, label, caps, created_by, created_at, last_used_at, revoked_at FROM ' . $this->db->table(self::TABLE) . ' ORDER BY created_at DESC, token_id') as $r) {
            $out[] = [
                'id' => (string) $r['token_id'],
                'label' => (string) $r['label'],
                'caps' => array_values(array_filter(explode(',', (string) $r['caps']))),
                'createdBy' => (int) $r['created_by'],
                'createdAt' => SaveShape::time((string) $r['created_at']),
                'lastUsedAt' => $r['last_used_at'] === null ? null : SaveShape::time((string) $r['last_used_at']),
                'revokedAt' => $r['revoked_at'] === null ? null : SaveShape::time((string) $r['revoked_at']),
            ];
        }
        return $out;
    }

    /** Revokes a token at once; true when it was live. */
    public function revoke(string $id, ?int $now = null): bool
    {
        return $this->db->execute('UPDATE ' . $this->db->table(self::TABLE) . ' SET revoked_at = ? WHERE token_id = ? AND revoked_at IS NULL', [gmdate('Y-m-d H:i:s', $now ?? time()), $id]) > 0;
    }
}
