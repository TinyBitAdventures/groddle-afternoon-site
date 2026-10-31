<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;
use Minn\RestError;

/**
 * Builder keys (the game's docs/level-builder/spec.md 8.4): how a local agent reaches the level
 * builder's routes without a session. A key has a label, scopes, an expiry (30 days unless set)
 * and can be revoked; keys are made, listed and revoked from the console's Builder tab with the
 * session cookie only (AdminBuilderKeys), never with a key.
 *
 * A keyed request carries
 *
 *   X-Groddle-Builder: <key id>.<unix seconds>.<16 hex nonce>.<base64url HMAC-SHA256>
 *
 * signed with the key's secret over
 *
 *   "groddle-builder/1\n" METHOD "\n" route "\n" query "\n" seconds "\n" nonce "\n" hex(sha256(body))
 *
 * (ServiceCall's scheme with its own prefix, plus the query): route is the REST route the request
 * names (/groddle/v1/admin/builder/..., no /wp-json); query is its pairs sorted by name then value,
 * each part rawurlencoded, joined by "&" (rest_route left out). It is good for WINDOW seconds either
 * side of the site's clock. Builder writes are not idempotent, so every nonce a key uses is kept
 * for NONCE_KEEP seconds and a repeat is refused. The game's tools/builder/sign.mjs signs the same
 * way; tests/builder-keys.test.js holds the two to the same bytes.
 *
 * A key's secret is HMAC-SHA256(master, "groddle-builder-key/1\n" key_id "\n" created_at) as hex,
 * so the database never holds one; the master is the file the option groddle_builder_secret_file
 * names (outside the webroot, like the realtime secret), else builder.secret beside the webroot.
 * Rotating the master revokes every key. The secret is shown once, when the key is made.
 *
 * Rate limits (spec 12), per key, counted over the nonces of the last minute: 120 writes, 20
 * renders, 60 validates, 300 fetches of new art's files; over them is 429 builder_rate_limited with
 * Retry-After.
 */
final class BuilderKeys
{
    public const KEYS = 'groddle_builder_keys';
    public const NONCES = 'groddle_builder_nonces';
    public const HEADER = 'x-groddle-builder';
    public const PREFIX = 'groddle-builder/1';
    public const KEY_LABEL = 'groddle-builder-key/1';
    public const WINDOW = 60;
    public const NONCE_KEEP = 120;
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 365;
    public const SECRET_OPTION = 'groddle_builder_secret_file';
    /** Every scope a key may hold. None reaches publishing, keys or any other admin route. */
    public const SCOPES = ['drafts:read', 'drafts:write', 'assets:write', 'validate', 'render'];
    /** Calls a key may make in a minute, by kind (spec 12; art is new art's bytes, for publish); other reads are not limited. */
    public const RATES = ['write' => 120, 'render' => 20, 'validate' => 60, 'art' => 300];

    public function __construct(private Db $db)
    {
    }

    // —— The signature (pure) ——

    /** RFC 3986 pairs sorted by name then value, joined by "&"; rest_route left out. @param array<string, mixed> $query */
    public static function canonicalQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $k => $v) {
            if ((string) $k === 'rest_route' || $v === null) {
                continue;
            }
            if (!is_scalar($v)) {
                throw new RestError('builder_key_refused', 'A signed request carries plain query values only.', 401, ['reason' => 'query']);
            }
            $pairs[] = [(string) $k, is_bool($v) ? ($v ? '1' : '') : (string) $v];
        }
        usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));
        return implode('&', array_map(static fn (array $p): string => rawurlencode($p[0]) . '=' . rawurlencode($p[1]), $pairs));
    }

    /** @param array<string, mixed> $query */
    public static function message(string $method, string $route, array $query, int $at, string $nonce, string $body): string
    {
        return self::PREFIX . "\n" . strtoupper($method) . "\n" . $route . "\n" . self::canonicalQuery($query) . "\n" . $at . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    /** The header value for a keyed call (tests; the CLI signs the same way). @param array<string, mixed> $query */
    public static function sign(string $keyId, string $secret, string $method, string $route, array $query, int $at, string $nonce, string $body): string
    {
        $sig = hash_hmac('sha256', self::message($method, $route, $query, $at, $nonce, $body), $secret, true);
        return $keyId . '.' . $at . '.' . $nonce . '.' . rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
    }

    /** A header value's parts, or null when it is not one. @return array{key: string, at: int, nonce: string, sig: string}|null */
    public static function parse(?string $header): ?array
    {
        if ($header === null || preg_match('/^([0-9a-f]{16})\.(\d{1,12})\.([0-9a-f]{16})\.([A-Za-z0-9_-]{43})$/', trim($header), $m) !== 1) {
            return null;
        }
        return ['key' => $m[1], 'at' => (int) $m[2], 'nonce' => $m[3], 'sig' => $m[4]];
    }

    /** A key's secret from the master (hex). */
    public static function secretFor(string $master, string $keyId, string $createdAt): string
    {
        return hash_hmac('sha256', self::KEY_LABEL . "\n" . $keyId . "\n" . $createdAt, $master);
    }

    /** The REST route a request names: its path without /wp-json, or its ?rest_route=. */
    public static function routeOf(string $path, ?string $restRoute): string
    {
        if (str_starts_with($path, '/wp-json/')) {
            return substr($path, strlen('/wp-json'));
        }
        if (is_string($restRoute) && $restRoute !== '') {
            return '/' . ltrim($restRoute, '/');
        }
        return $path;
    }

    /** Scopes from a list, in SCOPES order; null when one is not a scope. @return list<string>|null */
    public static function scopes(mixed $list): ?array
    {
        if (!is_array($list) || $list === []) {
            return null;
        }
        foreach ($list as $s) {
            if (!is_string($s) || !in_array($s, self::SCOPES, true)) {
                return null;
            }
        }
        return array_values(array_filter(self::SCOPES, static fn (string $s): bool => in_array($s, $list, true)));
    }

    // —— The master ——

    /** The master secret's file: the option's path, else builder.secret beside the webroot. */
    public static function masterFile(?string $named): string
    {
        $named = trim((string) $named);
        if ($named !== '') {
            return $named;
        }
        return defined('ABSPATH') ? dirname(rtrim((string) constant('ABSPATH'), '/')) . '/builder.secret' : '';
    }

    /** The master secret (at least 32 characters), or null when there is none. */
    public static function master(?string $named): ?string
    {
        $file = self::masterFile($named);
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            return null;
        }
        $value = trim((string) file_get_contents($file));
        return strlen($value) >= 32 ? $value : null;
    }

    // —— The store ——

    /**
     * A new key. The secret is in the answer and nowhere else.
     *
     * @param list<string> $scopes
     * @return array{key: array<string, mixed>, secret: string, line: string}
     */
    public function create(string $master, string $label, array $scopes, int $days, int $creator, ?int $now = null): array
    {
        $now ??= time();
        $id = bin2hex(random_bytes(8));
        $created = gmdate('Y-m-d H:i:s', $now);
        $expires = gmdate('Y-m-d H:i:s', $now + max(1, min(self::MAX_DAYS, $days)) * 86400);
        $this->db->execute('INSERT INTO ' . $this->db->table(self::KEYS) . ' (key_id, label, scopes, created_by, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)', [$id, $label, implode(' ', $scopes), $creator, $created, $expires]);
        $secret = self::secretFor($master, $id, $created);
        AdminConsole::recordAction($this->db, $creator, 0, 'builder', ['key' => $id, 'label' => $label, 'text' => 'key made', 'scopes' => $scopes], 'stored', $now);
        return ['key' => $this->find($id) ?? [], 'secret' => $secret, 'line' => $id . '.' . $secret];
    }

    /** @return list<array<string, mixed>> every key, newest first */
    public function list(?int $now = null): array
    {
        $rows = $this->db->rows('SELECT key_id, label, scopes, created_by, created_at, expires_at, last_used_at, revoked_at FROM ' . $this->db->table(self::KEYS) . ' ORDER BY created_at DESC, key_id');
        return array_map(fn (array $r): array => self::shape($r, $now ?? time()), $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id, ?int $now = null): ?array
    {
        $r = $this->db->row('SELECT key_id, label, scopes, created_by, created_at, expires_at, last_used_at, revoked_at FROM ' . $this->db->table(self::KEYS) . ' WHERE key_id = ?', [$id]);
        return $r === null ? null : self::shape($r, $now ?? time());
    }

    public function revoke(string $id, int $admin, ?int $now = null): bool
    {
        $now ??= time();
        $key = $this->find($id, $now);
        if ($key === null) {
            return false;
        }
        if ($key['revokedAt'] === null) {
            $this->db->execute('UPDATE ' . $this->db->table(self::KEYS) . ' SET revoked_at = ? WHERE key_id = ? AND revoked_at IS NULL', [gmdate('Y-m-d H:i:s', $now), $id]);
            $this->db->execute('DELETE FROM ' . $this->db->table(self::NONCES) . ' WHERE key_id = ?', [$id]);
            AdminConsole::recordAction($this->db, $admin, 0, 'builder', ['key' => $id, 'label' => (string) $key['label'], 'text' => 'key revoked'], 'stored', $now);
        }
        return true;
    }

    /** A revoked key's row deleted for good (the example session's cleanup); false when it is not revoked. */
    public function purge(string $id, int $admin, ?int $now = null): bool
    {
        $key = $this->find($id, $now);
        if ($key === null || $key['revokedAt'] === null) {
            return false;
        }
        $this->db->execute('DELETE FROM ' . $this->db->table(self::NONCES) . ' WHERE key_id = ?', [$id]);
        $this->db->execute('DELETE FROM ' . $this->db->table(self::KEYS) . ' WHERE key_id = ?', [$id]);
        AdminConsole::recordAction($this->db, $admin, 0, 'builder', ['key' => $id, 'label' => (string) $key['label'], 'text' => 'key deleted'], 'stored', $now);
        return true;
    }

    /**
     * Checks a keyed request and counts it. Refusals are 401 builder_key_refused with data.reason
     * (shape, unknown, revoked, expired, stale, signature, replay, nomaster), 403 builder_key_scope,
     * or 429 builder_rate_limited.
     *
     * @param array<string, mixed> $query
     * @return array{id: string, label: string, scopes: list<string>, createdBy: int}
     */
    public function verify(?string $master, ?string $header, string $method, string $route, array $query, string $body, string $scope, string $kind, ?int $now = null): array
    {
        $now ??= time();
        $refuse = static fn (string $reason, string $message): RestError => new RestError('builder_key_refused', $message, 401, ['reason' => $reason]);
        $h = self::parse($header);
        if ($h === null) {
            throw $refuse('shape', 'X-Groddle-Builder is <key id>.<seconds>.<nonce>.<signature>.');
        }
        if ($master === null) {
            throw $refuse('nomaster', 'This site has no builder master secret, so no key works.');
        }
        $row = $this->db->row('SELECT key_id, label, scopes, created_by, created_at, expires_at, revoked_at FROM ' . $this->db->table(self::KEYS) . ' WHERE key_id = ?', [$h['key']]);
        if ($row === null) {
            throw $refuse('unknown', 'No builder key has that id.');
        }
        if ($row['revoked_at'] !== null) {
            throw $refuse('revoked', 'That builder key was revoked.');
        }
        if ((int) strtotime($row['expires_at'] . ' UTC') <= $now) {
            throw $refuse('expired', 'That builder key has expired.');
        }
        if (abs($now - $h['at']) > self::WINDOW) {
            throw $refuse('stale', 'The signature is more than ' . self::WINDOW . ' seconds from the site\'s clock.');
        }
        $secret = self::secretFor($master, (string) $row['key_id'], (string) $row['created_at']);
        if (!hash_equals(self::sign((string) $row['key_id'], $secret, $method, $route, $query, $h['at'], $h['nonce'], $body), trim((string) $header))) {
            throw $refuse('signature', 'The signature does not match this request.');
        }
        $scopes = array_values(array_filter(explode(' ', (string) $row['scopes'])));
        if (!in_array($scope, $scopes, true)) {
            throw new RestError('builder_key_scope', "This key lacks the {$scope} scope.", 403, ['scope' => $scope]);
        }
        $n = $this->db->table(self::NONCES);
        $this->db->execute("DELETE FROM {$n} WHERE at < ?", [gmdate('Y-m-d H:i:s', $now - self::NONCE_KEEP)]);
        $limit = self::RATES[$kind] ?? null;
        if ($limit !== null) {
            $used = (int) $this->db->value("SELECT COUNT(*) FROM {$n} WHERE key_id = ? AND kind = ? AND at >= ?", [$h['key'], $kind, gmdate('Y-m-d H:i:s', $now - 60)]);
            if ($used >= $limit) {
                $oldest = $this->db->value("SELECT MIN(at) FROM {$n} WHERE key_id = ? AND kind = ? AND at >= ?", [$h['key'], $kind, gmdate('Y-m-d H:i:s', $now - 60)]);
                $wait = max(1, 61 - ($now - (int) strtotime((string) $oldest . ' UTC')));
                throw new RestError('builder_rate_limited', "At most {$limit} {$kind} calls a minute for a key. Try again in {$wait} s.", 429, ['retryAfter' => $wait, 'limit' => $limit, 'kind' => $kind]);
            }
        }
        if ($this->db->execute("INSERT IGNORE INTO {$n} (key_id, nonce, kind, at) VALUES (?, ?, ?, ?)", [$h['key'], $h['nonce'], $kind, gmdate('Y-m-d H:i:s', $now)]) !== 1) {
            throw $refuse('replay', 'That nonce was already used. Sign each request afresh.');
        }
        $this->db->execute('UPDATE ' . $this->db->table(self::KEYS) . ' SET last_used_at = ? WHERE key_id = ?', [gmdate('Y-m-d H:i:s', $now), $h['key']]);
        return ['id' => (string) $row['key_id'], 'label' => (string) $row['label'], 'scopes' => $scopes, 'createdBy' => (int) $row['created_by']];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function shape(array $r, int $now): array
    {
        $expires = (string) $r['expires_at'];
        $revoked = $r['revoked_at'] === null ? null : SaveShape::time((string) $r['revoked_at']);
        return [
            'id' => (string) $r['key_id'],
            'label' => (string) $r['label'],
            'scopes' => array_values(array_filter(explode(' ', (string) $r['scopes']))),
            'createdBy' => (int) $r['created_by'],
            'createdAt' => SaveShape::time((string) $r['created_at']),
            'expiresAt' => SaveShape::time($expires),
            'lastUsedAt' => $r['last_used_at'] === null ? null : SaveShape::time((string) $r['last_used_at']),
            'revokedAt' => $revoked,
            'state' => $revoked !== null ? 'revoked' : ((int) strtotime($expires . ' UTC') <= $now ? 'expired' : 'active'),
        ];
    }
}
