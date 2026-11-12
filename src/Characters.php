<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Content\UserRecord;
use Minn\Db;

/**
 * The character name each player chose at sign-up: the name other players
 * see in chat, over their heads and in the realtime ticket. Kept in the
 * extension's own table (groddle_players), unique whatever its letter case
 * (name_key), so it never follows the account's display name, which a
 * player could change on their profile to look like someone else. An
 * account without a row (the site's own people) shows its display name.
 */
final readonly class Characters
{
    public const TABLE = 'groddle_players';

    public function __construct(private Db $db)
    {
    }

    /** The name the game shows for an account. */
    public function nameOf(UserRecord $user): string
    {
        $table = $this->db->table(self::TABLE);
        $name = $this->db->value("SELECT name FROM {$table} WHERE user_id = ?", [$user->id]);
        if (is_string($name) && $name !== '') {
            return $name;
        }
        return $user->displayName !== '' ? $user->displayName : $user->login;
    }

    /**
     * The names the game shows for several accounts at once (nameOf's rule,
     * one query): id => name, leaving out ids with no account.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function namesOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $table = $this->db->table(self::TABLE);
        $users = $this->db->table('users');
        $rows = $this->db->rows("SELECT u.ID, u.user_login, u.display_name, c.name FROM {$users} u LEFT JOIN {$table} c ON c.user_id = u.ID WHERE u.ID IN (?)", [array_values(array_unique($ids))]);
        $out = [];
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            $display = (string) ($row['display_name'] ?? '');
            $out[(int) $row['ID']] = $name !== '' ? $name : ($display !== '' ? $display : (string) $row['user_login']);
        }
        return $out;
    }

    /**
     * Whether a character, or any account's display name, already has this name (letter case aside).
     * A retired character's name is free again once Logins::NAME_HOLD has passed.
     */
    public function taken(string $name, ?int $now = null): bool
    {
        $table = $this->db->table(self::TABLE);
        $users = $this->db->table('users');
        $holders = array_map('intval', array_column($this->db->rows("SELECT user_id FROM {$table} WHERE name_key = ?", [self::key($name)]), 'user_id'));
        $holders = [...$holders, ...array_map('intval', array_column($this->db->rows("SELECT ID AS user_id FROM {$users} WHERE LOWER(display_name) = ? LIMIT 20", [self::key($name)]), 'user_id'))];
        $holders = array_values(array_unique($holders));
        if ($holders === []) {
            return false;
        }
        return array_diff($holders, (new Logins($this->db))->released($holders, $now ?? time())) !== [];
    }

    /**
     * An account playing online with no character yet (the site's own people, made outside the
     * game) takes its display name, the name the game already shows for it (nameOf), when the name
     * fits the sign-up rules (PlayerAccount::NAME) and no other account or character has it.
     */
    public function claimOwnName(UserRecord $user): void
    {
        $table = $this->db->table(self::TABLE);
        if ($this->db->value("SELECT user_id FROM {$table} WHERE user_id = ?", [$user->id]) !== null) {
            return;
        }
        $name = trim(preg_replace('/\s+/', ' ', $user->displayName) ?? '');
        if (preg_match(PlayerAccount::NAME, $name) !== 1 || WordFilter::has($name)) {
            return;
        }
        $users = $this->db->table('users');
        if ($this->db->value("SELECT ID FROM {$users} WHERE LOWER(display_name) = ? AND ID <> ? LIMIT 1", [self::key($name), $user->id]) !== null) {
            return;
        }
        $this->claim($user->id, $name);
    }

    /** Records a new player's character name; false when someone took it first. */
    public function claim(int $userId, string $name): bool
    {
        $table = $this->db->table(self::TABLE);
        $this->release($name, time());
        $this->db->execute("INSERT IGNORE INTO {$table} (user_id, name, name_key, created_at) VALUES (?, ?, ?, ?)", [$userId, $name, self::key($name), gmdate('Y-m-d H:i:s')]);
        return (int) $this->db->value("SELECT user_id FROM {$table} WHERE name_key = ?", [self::key($name)]) === $userId;
    }

    /**
     * A retired character whose name was held long enough (Logins::NAME_HOLD) lets go of it, so
     * another player can take it: its name_key becomes "~<id>", which no name can be. The name
     * itself stays on the row; a restore asks for a new one (CharacterRoutes::restore).
     */
    public function release(string $name, int $now): void
    {
        $table = $this->db->table(self::TABLE);
        $holder = $this->db->value("SELECT user_id FROM {$table} WHERE name_key = ?", [self::key($name)]);
        if ($holder !== null && (new Logins($this->db))->nameReleased((int) $holder, $now)) {
            $this->db->execute("UPDATE {$table} SET name_key = ? WHERE user_id = ?", ['~' . (int) $holder, (int) $holder]);
        }
    }

    /** Whether this character still holds its own name (false once a retired character let go of it). */
    public function holdsOwnName(int $userId): bool
    {
        $row = $this->db->row('SELECT name, name_key FROM ' . $this->db->table(self::TABLE) . ' WHERE user_id = ?', [$userId]);
        return $row !== null && (string) $row['name_key'] === self::key((string) $row['name']);
    }

    /**
     * A character's new name (an administrator's rename, or a restore after the name was let go):
     * the row's name and key, and the account's display name and nickname, so every place that
     * reads the name now reads the new one. Copies made before (chat lines, community posts, Hi
     * signs) keep the old one. False when someone else holds the name.
     */
    public function rename(int $userId, string $name): bool
    {
        $table = $this->db->table(self::TABLE);
        $this->release($name, time());
        $holder = $this->db->value("SELECT user_id FROM {$table} WHERE name_key = ?", [self::key($name)]);
        if ($holder !== null && (int) $holder !== $userId) {
            return false;
        }
        if ($this->db->value("SELECT user_id FROM {$table} WHERE user_id = ?", [$userId]) === null) {
            $this->db->execute("INSERT INTO {$table} (user_id, name, name_key, created_at) VALUES (?, ?, ?, ?)", [$userId, $name, self::key($name), gmdate('Y-m-d H:i:s')]);
        } else {
            $this->db->execute("UPDATE {$table} SET name = ?, name_key = ? WHERE user_id = ?", [$name, self::key($name), $userId]);
        }
        $this->db->execute('UPDATE ' . $this->db->table('users') . ' SET display_name = ? WHERE ID = ?', [$name, $userId]);
        $this->db->execute('UPDATE ' . $this->db->table('usermeta') . " SET meta_value = ? WHERE user_id = ? AND meta_key = 'nickname'", [$name, $userId]);
        return true;
    }

    private static function key(string $name): string
    {
        return strtolower($name);
    }
}
