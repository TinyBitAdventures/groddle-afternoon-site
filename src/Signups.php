<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;

/**
 * Sign-ups waiting on their email (Austin 2026-10-27: no account exists
 * until the email is proven). A sign-up keeps the email, the password's
 * HASH (Accounts::passwordHash, never the password), the character name it
 * asked for and the hash of a one-time token whose link was mailed; the
 * link makes the account. One row per email: signing up again with it
 * replaces the row (a new token, so the older link stops working).
 *
 * A link works once, for a day. A used row keeps who it made (user_id) and
 * forgets the hash, so the link can still say it was used; rows go a week
 * after they expire. A held name is only a wish: two sign-ups may ask for
 * one name and the first to open its link gets it (Characters decides).
 * Each row has at most MAX_SENDS emails, RESEND_GAP seconds apart, and an
 * address makes at most PER_ADDRESS_HOUR new rows an hour (an IPv6 address
 * counted by its /64, MailLimits::addressKey, which is what ip keeps).
 */
final readonly class Signups
{
    public const TABLE = 'groddle_signups';

    /** How long a link works. */
    public const LIFETIME = 86400;

    /** How long a used or expired row stays after it expires, so its link can say why it no longer works. */
    public const KEEP = 7 * 86400;

    /** Emails one sign-up may send, and the wait between two of them. */
    public const MAX_SENDS = 5;
    public const RESEND_GAP = 60;

    /** New sign-ups one address may make in an hour. */
    public const PER_ADDRESS_HOUR = 10;

    public function __construct(private Db $db)
    {
    }

    /** A new one-time token: 48 hex characters for the link; only its hash is stored. */
    public static function token(): string
    {
        return bin2hex(random_bytes(24));
    }

    /** The stored form of a token. */
    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Whether a string reads as one of our tokens. */
    public static function looksLikeToken(string $token): bool
    {
        return preg_match('/^[0-9a-f]{48}$/', $token) === 1;
    }

    /** @return array<string, mixed>|null the row a token's link names */
    public function byToken(string $token): ?array
    {
        $table = $this->db->table(self::TABLE);
        return $this->db->row("SELECT * FROM {$table} WHERE token_hash = ? LIMIT 1", [self::hashOf($token)]);
    }

    /** @return array<string, mixed>|null the row for an email, used or not */
    public function byEmail(string $email): ?array
    {
        $table = $this->db->table(self::TABLE);
        return $this->db->row("SELECT * FROM {$table} WHERE email_key = ? LIMIT 1", [self::key($email)]);
    }

    /** Whether a row's link is still waiting to be opened (not used, not expired). */
    public static function pending(array $row, int $now): bool
    {
        return ($row['used_at'] ?? null) === null && strtotime((string) $row['expires_at'] . ' UTC') > $now;
    }

    /** Whether another email's sign-up still waiting on its link asked for this name. */
    public function nameHeldByOther(string $name, string $email, int $now): bool
    {
        $table = $this->db->table(self::TABLE);
        return $this->db->value("SELECT token_hash FROM {$table} WHERE name_key = ? AND email_key <> ? AND used_at IS NULL AND expires_at > ? LIMIT 1", [self::key($name), self::key($email), self::time($now)]) !== null;
    }

    /** How many sign-ups an address (an IPv6 one by its /64) started in the last hour. */
    public function recentFrom(string $address, int $now): int
    {
        $key = MailLimits::addressKey($address);
        if ($key === '') {
            return 0;
        }
        $table = $this->db->table(self::TABLE);
        return (int) $this->db->value("SELECT COUNT(*) FROM {$table} WHERE ip = ? AND created_at > ?", [$key, self::time($now - 3600)]);
    }

    /**
     * Why a row may not send another email now ('wait' or 'spent'), or null
     * when it may. A used row never sends.
     */
    public static function sendRefusal(?array $row, int $now): ?string
    {
        if ($row === null || ($row['used_at'] ?? null) !== null) {
            return null;
        }
        if ((int) $row['sends'] >= self::MAX_SENDS) {
            return 'spent';
        }
        return strtotime((string) $row['sent_at'] . ' UTC') > $now - self::RESEND_GAP ? 'wait' : null;
    }

    /**
     * Holds a sign-up for an email (replacing any row it had) and returns
     * the new token for the link. A replaced row that was still waiting
     * carries its count of emails on, so signing up again is no way round
     * the limit.
     */
    public function hold(string $email, string $passHash, string $name, string $address, int $now): string
    {
        $table = $this->db->table(self::TABLE);
        $old = $this->byEmail($email);
        $sends = $old !== null && ($old['used_at'] ?? null) === null ? (int) $old['sends'] + 1 : 1;
        $token = self::token();
        $row = [
            self::hashOf($token), $email, self::key($email), $passHash, $name, self::key($name), $sends,
            self::time($now), self::time($now), self::time($now + self::LIFETIME), MailLimits::addressKey($address),
        ];
        $this->db->execute("DELETE FROM {$table} WHERE email_key = ?", [self::key($email)]);
        $this->db->execute("INSERT INTO {$table} (token_hash, email, email_key, pass_hash, name, name_key, sends, sent_at, created_at, expires_at, used_at, user_id, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 0, ?)", $row);
        return $token;
    }

    /** A new token for a row still waiting (or expired): the old link stops working, the day starts again. */
    public function reissue(array $row, int $now): string
    {
        $table = $this->db->table(self::TABLE);
        $token = self::token();
        $this->db->execute("UPDATE {$table} SET token_hash = ?, sends = sends + 1, sent_at = ?, expires_at = ? WHERE token_hash = ? AND used_at IS NULL", [self::hashOf($token), self::time($now), self::time($now + self::LIFETIME), (string) $row['token_hash']]);
        return $token;
    }

    /** Marks a row's link used, once: false when another request used it first. */
    public function spend(array $row, int $now): bool
    {
        $table = $this->db->table(self::TABLE);
        return $this->db->execute("UPDATE {$table} SET used_at = ? WHERE token_hash = ? AND used_at IS NULL", [self::time($now), (string) $row['token_hash']]) === 1;
    }

    /** Gives a spent link back when the account could not be made after all. */
    public function unspend(array $row): void
    {
        $table = $this->db->table(self::TABLE);
        $this->db->execute("UPDATE {$table} SET used_at = NULL WHERE token_hash = ?", [(string) $row['token_hash']]);
    }

    /** Records who a used link made; the password's hash is no longer kept. */
    public function made(array $row, int $userId, string $name): void
    {
        $table = $this->db->table(self::TABLE);
        $this->db->execute("UPDATE {$table} SET pass_hash = '', user_id = ?, name = ?, name_key = ? WHERE token_hash = ?", [$userId, $name, self::key($name), (string) $row['token_hash']]);
    }

    /** Lets go of rows a week past their expiry. */
    public function purge(int $now): void
    {
        $table = $this->db->table(self::TABLE);
        $this->db->execute("DELETE FROM {$table} WHERE expires_at < ?", [self::time($now - self::KEEP)]);
    }

    private static function key(string $text): string
    {
        return strtolower(trim($text));
    }

    private static function time(int $at): string
    {
        return gmdate('Y-m-d H:i:s', $at);
    }
}
