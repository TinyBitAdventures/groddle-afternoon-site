<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Auth\Capabilities;
use Minn\Db;

/**
 * Who is on the team: an administrator's characters (Austin 2026-11-24: his own character's bug
 * reports stay off the testers boards). Decided by the account's capability, manage_options (the
 * administrator role), never by a list of ids, and by the login as a ban is (AdminConsole ban: "a
 * login with an administrator among its characters"): an extra character is its own account with a
 * subscriber's role (Logins), so a character counts when its own account or any character of its
 * login has the capability.
 */
final readonly class Team
{
    public const CAP = 'manage_options';

    /**
     * The team's ids among these, sorted.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public static function of(Db $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $logins = (new Logins($db))->loginsOf($ids);
        $loginIds = array_values(array_unique($logins));
        // Every account of those logins: the logins' own rows, and their extra characters.
        $members = [];
        foreach ($loginIds as $login) {
            $members[$login][] = $login;
        }
        foreach ($db->rows('SELECT user_id, login_id FROM ' . $db->table(Logins::TABLE) . ' WHERE login_id IN (?)', [$loginIds]) as $r) {
            $members[(int) $r['login_id']][] = (int) $r['user_id'];
        }
        $caps = Capabilities::fromDb($db);
        $teamLogins = [];
        foreach ($members as $login => $accounts) {
            foreach (array_unique($accounts) as $account) {
                if ($caps->can($account, self::CAP)) {
                    $teamLogins[$login] = true;
                    break;
                }
            }
        }
        $out = array_values(array_filter($ids, static fn (int $id): bool => isset($teamLogins[$logins[$id] ?? $id])));
        sort($out);
        return $out;
    }
}
