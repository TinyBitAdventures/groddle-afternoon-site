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

    /** Whether a character, or any account's display name, already has this name (letter case aside). */
    public function taken(string $name): bool
    {
        $table = $this->db->table(self::TABLE);
        $users = $this->db->table('users');
        return $this->db->value("SELECT user_id FROM {$table} WHERE name_key = ? LIMIT 1", [self::key($name)]) !== null
            || $this->db->value("SELECT ID FROM {$users} WHERE LOWER(display_name) = ? LIMIT 1", [self::key($name)]) !== null;
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
        if (preg_match(PlayerAccount::NAME, $name) !== 1) {
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
        $this->db->execute("INSERT IGNORE INTO {$table} (user_id, name, name_key, created_at) VALUES (?, ?, ?, ?)", [$userId, $name, self::key($name), gmdate('Y-m-d H:i:s')]);
        return (int) $this->db->value("SELECT user_id FROM {$table} WHERE name_key = ?", [self::key($name)]) === $userId;
    }

    private static function key(string $name): string
    {
        return strtolower($name);
    }
}
