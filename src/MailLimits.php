<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Content\Site;
use Minn\Db;

/**
 * How often the site's account emails may go out (Austin 2026-10-28), so the
 * forms can't be used to fill someone's inbox or the site's mail quota.
 * Every sign-up email and every password reset asked for is a row here (the
 * email's sha256, never the email; the asking address, IPv6 by its /64;
 * whether a mail really went), kept two days.
 *
 * Password resets: one a minute and RESET_PER_DAY a day per email,
 * RESET_PER_ADDRESS_HOUR an hour per address. Rows are written for every
 * accepted ask, player or not, so a limit stops a player's email and a
 * stranger's alike and the answer never tells them apart. The same address
 * asking for the same email again within the minute is told to wait (it
 * learns only what it did itself); any other stop answers as if sent.
 *
 * Site-wide: at most groddle_mail_hour_cap (default HOUR_CAP) sign-up,
 * reset and report-reply emails an hour (a reply is a REPLY row, AdminTickets); above it the forms say the site is busy, and the
 * stop is logged (once every LOG_EVERY seconds).
 */
final readonly class MailLimits
{
    public const TABLE = 'groddle_mail_sends';

    /** The kind of row an administrator's reply to a bug report mails (AdminTickets): counted in the hourly ceiling. */
    public const REPLY = 'reply';

    public const RESET_GAP = 60;
    public const RESET_PER_DAY = 5;
    public const RESET_PER_ADDRESS_HOUR = 10;

    /** Sign-up and reset emails the whole site sends in an hour, unless the option says otherwise. */
    public const HOUR_CAP = 300;
    public const CAP_OPTION = 'groddle_mail_hour_cap';

    /** Rows outlive the longest window (a day) by a day. */
    public const KEEP = 2 * 86400;

    public const LOG_EVERY = 600;
    private const LOGGED_OPTION = 'groddle_mail_cap_logged';

    public const BUSY = 'We\'re sending a lot of emails right now. Try again in a few minutes.';
    public const RESET_WAIT = 'We just sent a link. Wait a minute, then ask again.';

    public function __construct(private Db $db, private Site $site)
    {
    }

    /**
     * The address a limit counts: an IPv4 address as it is, an IPv6 one by
     * its /64 (one home or phone network is handed a whole /64, so counting
     * single IPv6 addresses counts nothing), an IPv4-mapped IPv6 address as
     * its IPv4. Anything else, cut to the column's 45 characters.
     */
    public static function addressKey(string $address): string
    {
        $address = trim($address);
        $binary = $address === '' ? false : @inet_pton($address);
        if ($binary === false) {
            return substr($address, 0, 45);
        }
        if (strlen($binary) === 16) {
            if (str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff")) {
                return (string) inet_ntop(substr($binary, 12));
            }
            return inet_ntop(substr($binary, 0, 8) . str_repeat("\0", 8)) . '/64';
        }
        return (string) inet_ntop($binary);
    }

    /** The stored form of an email: its sha256, lowercased first. */
    public static function emailHash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /** The site-wide hourly ceiling: the option when it is a positive whole number, else HOUR_CAP. */
    public static function capFrom(?string $option): int
    {
        $value = trim((string) $option);
        return ctype_digit($value) && (int) $value > 0 ? (int) $value : self::HOUR_CAP;
    }

    /**
     * What a password reset ask gets, from the counts of earlier accepted
     * asks: 'wait' (this address asked for this email within the minute),
     * 'quiet' (a limit stops it; answered as if sent), or 'send'.
     *
     * @param array{sameAddressMinute: int, emailMinute: int, emailDay: int, addressHour: int} $counts
     */
    public static function resetDecision(array $counts): string
    {
        return match (true) {
            $counts['sameAddressMinute'] > 0 => 'wait',
            $counts['emailMinute'] > 0, $counts['emailDay'] >= self::RESET_PER_DAY, $counts['addressHour'] >= self::RESET_PER_ADDRESS_HOUR => 'quiet',
            default => 'send',
        };
    }

    /** The counts resetDecision reads, for one email from one address. */
    public function resetCounts(string $email, string $address, int $now): array
    {
        $table = $this->db->table(self::TABLE);
        $hash = self::emailHash($email);
        $key = self::addressKey($address);
        $count = fn (string $where, array $params): int => (int) $this->db->value("SELECT COUNT(*) FROM {$table} WHERE kind = 'reset' AND {$where}", $params);
        return [
            'sameAddressMinute' => $count('email_hash = ? AND address = ? AND sent_at > ?', [$hash, $key, self::time($now - self::RESET_GAP)]),
            'emailMinute' => $count('email_hash = ? AND sent_at > ?', [$hash, self::time($now - self::RESET_GAP)]),
            'emailDay' => $count('email_hash = ? AND sent_at > ?', [$hash, self::time($now - 86400)]),
            'addressHour' => $key === '' ? 0 : $count('address = ? AND sent_at > ?', [$key, self::time($now - 3600)]),
        ];
    }

    /** Whether the site has sent its hourly ceiling of account emails; a stop is logged now and then. */
    public function overHourCap(int $now, string $what): bool
    {
        $cap = self::capFrom($this->site->option(self::CAP_OPTION));
        $table = $this->db->table(self::TABLE);
        $sent = (int) $this->db->value("SELECT COUNT(*) FROM {$table} WHERE mailed = 1 AND sent_at > ?", [self::time($now - 3600)]);
        if ($sent < $cap) {
            return false;
        }
        $logged = (int) ($this->site->option(self::LOGGED_OPTION) ?? 0);
        if ($logged < $now - self::LOG_EVERY) {
            $this->site->setOption(self::LOGGED_OPTION, (string) $now, 'off');
            error_log("Groddle Afternoon: the site has sent {$sent} account emails in the last hour (ceiling {$cap}, option " . self::CAP_OPTION . "); refusing {$what} emails for now.");
        }
        return true;
    }

    /** Records one accepted ask: its kind ('signup' or 'reset'), the email, the address, and whether a mail went. */
    public function record(string $kind, string $email, string $address, bool $mailed, int $now): void
    {
        $table = $this->db->table(self::TABLE);
        $this->db->execute("INSERT INTO {$table} (kind, email_hash, address, mailed, sent_at) VALUES (?, ?, ?, ?, ?)", [$kind, self::emailHash($email), self::addressKey($address), $mailed ? 1 : 0, self::time($now)]);
    }

    /** Lets go of rows past the longest window. */
    public function purge(int $now): void
    {
        $table = $this->db->table(self::TABLE);
        $this->db->execute("DELETE FROM {$table} WHERE sent_at < ?", [self::time($now - self::KEEP)]);
    }

    private static function time(int $at): string
    {
        return gmdate('Y-m-d H:i:s', $at);
    }
}
