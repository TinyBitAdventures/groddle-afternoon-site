<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;
use Minn\Http\Request;
use Minn\RestError;

/**
 * Alternate characters (Austin 2026-11-12): one login (an email and a
 * password) with up to LIMIT characters. Each character is its own account
 * row (its own save, buddies, mail, name), and an extra one is tied to the
 * login it was made from in groddle_character_logins (user_id, login_id,
 * created_at, retired_at). Only extra characters get a row: an account with
 * none is its own login, so every character made before this is the first
 * character of its login, with nothing to migrate.
 *
 * The login's email and password live on the login's own row. An extra
 * character's row has a password nobody knows and an address under
 * EMAIL_DOMAIN (the reserved .invalid name, never delivered), so it is only
 * ever reached by switching (CharacterRoutes::play) from a character of the
 * same login.
 *
 * Rules: LIMIT characters a login (its own counts as one, and so does a
 * retired one until its name hold ends: Austin 2026-11-12); at most one new
 * character every CREATE_GAP seconds a login; only an extra character
 * retires (hidden, its data kept, restorable), and its name stays its own
 * for NAME_HOLD seconds, then it stops counting and another player may take
 * the name (Characters::taken and claim). A restore after that needs a free
 * place, and a new name when the old one was taken.
 *
 * Who shares a login is for administrators and moderators only (the console,
 * the mod API): nothing a player reads names another player's characters.
 */
final readonly class Logins
{
    public const TABLE = 'groddle_character_logins';

    /** Characters a login may have at once, its own included. */
    public const LIMIT = 3;

    /** Seconds between two new characters of one login. */
    public const CREATE_GAP = 86400;

    /** Seconds a retired character's name stays theirs. */
    public const NAME_HOLD = 30 * 86400;

    /** An extra character's own address: a reserved name, never delivered, never typed by anyone. */
    public const EMAIL_DOMAIN = 'characters.invalid';

    /** The header a game page names its character with (every save write, the realtime ticket, value-moving routes). */
    public const HEADER = 'x-groddle-character';

    public function __construct(private Db $db)
    {
    }

    /** The login an account belongs to: its own id when it is a first character (or not a player at all). */
    public function loginOf(int $user): int
    {
        if ($user <= 0) {
            return $user;
        }
        $login = $this->db->value('SELECT login_id FROM ' . $this->db->table(self::TABLE) . ' WHERE user_id = ?', [$user]);
        return $login === null ? $user : (int) $login;
    }

    /**
     * Logins for several accounts at once: id => login.
     *
     * @param list<int> $ids
     * @return array<int, int>
     */
    public function loginsOf(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $out = array_combine($ids, $ids) ?: [];
        if ($ids === []) {
            return [];
        }
        foreach ($this->db->rows('SELECT user_id, login_id FROM ' . $this->db->table(self::TABLE) . ' WHERE user_id IN (?)', [$ids]) as $r) {
            $out[(int) $r['user_id']] = (int) $r['login_id'];
        }
        return $out;
    }

    /** How many different logins these accounts are (several characters of one login count once). @param list<int> $ids */
    public function distinct(array $ids): int
    {
        return count(array_unique($this->loginsOf($ids)));
    }

    /**
     * Every account of the login this account belongs to, itself and retired characters included,
     * the first character first.
     *
     * @return list<int>
     */
    public function siblings(int $user): array
    {
        if ($user <= 0) {
            return [];
        }
        return array_map(static fn (array $c): int => $c['id'], $this->charactersOf($this->loginOf($user)));
    }

    /** Whether two accounts are characters of one login. */
    public function sameLogin(int $a, int $b): bool
    {
        return $a > 0 && $b > 0 && $this->loginOf($a) === $this->loginOf($b);
    }

    /**
     * A login's characters: its own row first, then the extra ones oldest first, retired ones included.
     *
     * @return list<array{id: int, first: bool, createdAt: ?int, retiredAt: ?int}>
     */
    public function charactersOf(int $login): array
    {
        if ($login <= 0) {
            return [];
        }
        $out = [['id' => $login, 'first' => true, 'createdAt' => null, 'retiredAt' => null]];
        foreach ($this->db->rows('SELECT user_id, created_at, retired_at FROM ' . $this->db->table(self::TABLE) . ' WHERE login_id = ? ORDER BY created_at, user_id', [$login]) as $r) {
            $out[] = ['id' => (int) $r['user_id'], 'first' => false, 'createdAt' => self::unix($r['created_at']), 'retiredAt' => self::unix($r['retired_at'])];
        }
        return $out;
    }

    /** When an extra character was retired (unix), or null. */
    public function retiredAt(int $user): ?int
    {
        return self::unix($this->db->value('SELECT retired_at FROM ' . $this->db->table(self::TABLE) . ' WHERE user_id = ?', [$user]));
    }

    /** Whether a retired character's name has been held long enough for someone else to take it. */
    public function nameReleased(int $user, int $now): bool
    {
        $at = $this->retiredAt($user);
        return $at !== null && $now - $at >= self::NAME_HOLD;
    }

    /**
     * The accounts among these whose names are free again (retired over NAME_HOLD ago).
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public function released(array $ids, int $now): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->rows('SELECT user_id FROM ' . $this->db->table(self::TABLE) . ' WHERE user_id IN (?) AND retired_at IS NOT NULL AND retired_at <= ?', [$ids, gmdate('Y-m-d H:i:s', $now - self::NAME_HOLD)]);
        return array_map(static fn (array $r): int => (int) $r['user_id'], $rows);
    }

    /**
     * How many of a login's characters count toward LIMIT now: every one not retired, and a retired
     * one until its NAME_HOLD ends. $except leaves one character out (a restore counts the others).
     */
    public function counted(int $login, int $now, int $except = 0): int
    {
        $n = 0;
        foreach ($this->charactersOf($login) as $c) {
            if ($c['id'] !== $except && ($c['retiredAt'] === null || $now - $c['retiredAt'] < self::NAME_HOLD)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Why this login may not make a character now, or null: limit (LIMIT counted
     * characters, 409; see counted) or daily (one every CREATE_GAP seconds, 429, with when).
     *
     * @return array{code: string, status: int, nextCreateAt: ?int}|null
     */
    public function createRefusal(int $login, int $now): ?array
    {
        if ($this->counted($login, $now) >= self::LIMIT) {
            return ['code' => 'character_limit', 'status' => 409, 'nextCreateAt' => null];
        }
        $next = $this->nextCreateAt($login, $now);
        return $next === null ? null : ['code' => 'character_daily', 'status' => 429, 'nextCreateAt' => $next];
    }

    /** When this login may make its next character, or null when it may now (the one-a-day rule only). */
    public function nextCreateAt(int $login, int $now): ?int
    {
        $last = self::unix($this->db->value('SELECT MAX(created_at) FROM ' . $this->db->table(self::TABLE) . ' WHERE login_id = ?', [$login]));
        return $last !== null && $now - $last < self::CREATE_GAP ? $last + self::CREATE_GAP : null;
    }

    /** Ties a new account to a login as its extra character. */
    public function link(int $user, int $login, int $now): void
    {
        $this->db->execute('INSERT INTO ' . $this->db->table(self::TABLE) . ' (user_id, login_id, created_at, retired_at) VALUES (?, ?, ?, NULL)', [$user, $login, gmdate('Y-m-d H:i:s', $now)]);
    }

    public function retire(int $user, int $now): bool
    {
        return $this->db->execute('UPDATE ' . $this->db->table(self::TABLE) . ' SET retired_at = ? WHERE user_id = ? AND retired_at IS NULL', [gmdate('Y-m-d H:i:s', $now), $user]) > 0;
    }

    public function restore(int $user): bool
    {
        return $this->db->execute('UPDATE ' . $this->db->table(self::TABLE) . ' SET retired_at = NULL WHERE user_id = ? AND retired_at IS NOT NULL', [$user]) > 0;
    }

    /**
     * Why the signed-in account may not play as $target, or null when it may: the
     * target must be a character of the caller's own login (missing, 404: an
     * account outside the login reads exactly like one that does not exist),
     * not retired (409), and the login not suspended (403).
     *
     * @return array{code: string, status: int}|null
     */
    public function playRefusal(int $caller, int $target): ?array
    {
        if ($caller <= 0 || $target <= 0 || !in_array($target, $this->siblings($caller), true)) {
            return ['code' => 'groddle_character_missing', 'status' => 404];
        }
        if ($this->retiredAt($target) !== null) {
            return ['code' => 'groddle_character_retired', 'status' => 409];
        }
        if (AdminConsole::banned($this->db, $target) !== null) {
            return ['code' => 'groddle_suspended', 'status' => 403];
        }
        return null;
    }

    /**
     * A game page names the character it plays (X-Groddle-Character): when
     * the header is there and is not the signed-in account (another tab
     * switched character since this page loaded), the write is refused with
     * 409 character_changed and nothing changes. No header, or an empty one
     * (an older game): accepted.
     */
    public static function guard(Request $request, int $user): void
    {
        $named = self::named($request);
        if ($named !== null && $named !== (string) $user) {
            throw new RestError('character_changed', 'You switched to another character in another window. This window has stopped saving.', 409);
        }
    }

    /** The header's value, trimmed, or null when the request has none (or an empty one). */
    public static function named(Request $request): ?string
    {
        $value = trim((string) ($request->headers[self::HEADER] ?? ''));
        return $value === '' ? null : $value;
    }

    /** A new extra character's own address (EMAIL_DOMAIN). */
    public static function email(): string
    {
        return 'character-' . bin2hex(random_bytes(6)) . '@' . self::EMAIL_DOMAIN;
    }

    private static function unix(mixed $at): ?int
    {
        if (!is_string($at) || $at === '') {
            return null;
        }
        $t = strtotime($at . ' UTC');
        return $t === false ? null : $t;
    }
}
