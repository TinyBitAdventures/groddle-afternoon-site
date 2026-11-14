<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;

/**
 * New sign-in emails waiting on their link (2026-11-14): a player asks for
 * a new address with the current password, and the account keeps its old
 * address until the link mailed to the new one is opened, so an address
 * an account signs in with (and Google sign-in links by) is always one its
 * owner opened mail at. The same shape as Signups: the hash of a one-time
 * token, never the token; a link works once, for LIFETIME.
 *
 * One row per login (user_id is the login's account, Logins): asking again
 * replaces the row, so the older link stops working. A row may send
 * MAX_SENDS emails, RESEND_GAP seconds apart; asking again carries the
 * count on while the older row is still waiting, so a day holds at most
 * MAX_SENDS of them (our limit; Glitch had no email change). A used row
 * keeps no token worth anything and goes KEEP after it expires, so its link
 * can still say it was used.
 */
final readonly class EmailChanges
{
    public const TABLE = 'groddle_email_changes';

    /** How long a link works. */
    public const LIFETIME = 86400;

    /** How long a used or expired row stays after it expires, so its link can say why it no longer works. */
    public const KEEP = 7 * 86400;

    /** Emails one waiting change may send, and the wait between two of them. */
    public const MAX_SENDS = 5;
    public const RESEND_GAP = 60;

    public function __construct(private Db $db)
    {
    }

    /** Whether a string reads as one of our tokens (48 hex characters, as Signups makes them). */
    public static function looksLikeToken(string $token): bool
    {
        return Signups::looksLikeToken($token);
    }

    /** @return array<string, mixed>|null the row a token's link names */
    public function byToken(string $token): ?array
    {
        $table = $this->db->table(self::TABLE);
        return $this->db->row("SELECT * FROM {$table} WHERE token_hash = ? LIMIT 1", [Signups::hashOf($token)]);
    }

    /** @return array<string, mixed>|null the login's row, used or not */
    public function forLogin(int $login): ?array
    {
        $table = $this->db->table(self::TABLE);
        return $this->db->row("SELECT * FROM {$table} WHERE user_id = ? LIMIT 1", [$login]);
    }

    /** Whether a row's link is still waiting to be opened (not used, not expired). */
    public static function pending(array $row, int $now): bool
    {
        return ($row['used_at'] ?? null) === null && strtotime((string) $row['expires_at'] . ' UTC') > $now;
    }

    /**
     * Why a login may not be sent another link now ('wait' or 'spent'),
     * from its row, or null when it may. Only a row still waiting counts:
     * a used or expired one starts afresh.
     */
    public static function sendRefusal(?array $row, int $now): ?string
    {
        if ($row === null || !self::pending($row, $now)) {
            return null;
        }
        if ((int) $row['sends'] >= self::MAX_SENDS) {
            return 'spent';
        }
        return strtotime((string) $row['sent_at'] . ' UTC') > $now - self::RESEND_GAP ? 'wait' : null;
    }

    /**
     * Holds a new email for a login (replacing any row it had) and returns
     * the token for the link. A replaced row still waiting carries its
     * count of emails on, so asking again is no way round the limit (a
     * spent login waits until its newest link expires).
     */
    public function hold(int $login, string $email, string $address, int $now): string
    {
        $table = $this->db->table(self::TABLE);
        $old = $this->forLogin($login);
        $carry = $old !== null && self::pending($old, $now);
        $sends = $carry ? (int) $old['sends'] + 1 : 1;
        $created = $carry ? (string) $old['created_at'] : self::time($now);
        $token = Signups::token();
        $this->db->execute("DELETE FROM {$table} WHERE user_id = ?", [$login]);
        $this->db->execute(
            "INSERT INTO {$table} (token_hash, user_id, email, sends, sent_at, created_at, expires_at, used_at, ip) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)",
            [Signups::hashOf($token), $login, $email, $sends, self::time($now), $created, self::time($now + self::LIFETIME), MailLimits::addressKey($address)],
        );
        return $token;
    }

    /** Marks a row's link used, once: false when another request used it first. */
    public function spend(array $row, int $now): bool
    {
        $table = $this->db->table(self::TABLE);
        return $this->db->execute("UPDATE {$table} SET used_at = ? WHERE token_hash = ? AND used_at IS NULL", [self::time($now), (string) $row['token_hash']]) === 1;
    }

    /** Gives a spent link back when the email could not be saved after all. */
    public function unspend(array $row): void
    {
        $table = $this->db->table(self::TABLE);
        $this->db->execute("UPDATE {$table} SET used_at = NULL WHERE token_hash = ?", [(string) $row['token_hash']]);
    }

    /** Lets go of rows a week past their expiry. */
    public function purge(int $now): void
    {
        $table = $this->db->table(self::TABLE);
        $this->db->execute("DELETE FROM {$table} WHERE expires_at < ?", [self::time($now - self::KEEP)]);
    }

    private static function time(int $at): string
    {
        return gmdate('Y-m-d H:i:s', $at);
    }
}
