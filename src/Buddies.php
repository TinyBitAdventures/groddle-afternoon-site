<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Content\Users;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * groddle/v1/buddies: a player's buddy list and ignore list in multiplayer
 * (register G325), the rules of GameServerJS players/inc_buddies.js.
 *
 * Buddies are one way, as in the original: adding someone puts them on your
 * list (table groddle_buddies, one row per buddy); they see you on theirs as
 * a reverse entry (who added you, marked new until they have seen it), and
 * hear "X just added you as a contact!" if they are online (the realtime
 * server, from a proof this route signs). One group, "Buddies"
 * (inc_buddies.js:6-12; createBuddyGroup is disabled, :121), and at most
 * BUDDY_LIMIT buddies (config_prod.js:1276 buddy_limit) unless they added
 * you first (addToBuddyGroup 161-165, main.js:3626-3640). Ignoring someone
 * (groddle_ignores) takes them off your buddies (buddies_add_ignore 512-560)
 * and hides you from theirs: their entry for you stays in their rows and is
 * left out of every answer while you ignore them (only the caller's own rows
 * are ever written; the original deleted it, buddies_add_ignored_by 582-597).
 *
 * Every answer is the whole list plus a watch token: the caller's buddy,
 * reverse and ignore ids signed with the realtime secret (RealtimeTicket),
 * which the game hands the realtime server (buddies.go, buddies_watch) so
 * it can tell the player who is online without ever trusting a list a
 * client sends. A fresh token comes with each change.
 *
 * REST rules: signed in, the cookie with its nonce; the user is the caller,
 * never read from the request.
 */
final readonly class Buddies
{
    public const TABLE = 'groddle_buddies';

    public const IGNORES = 'groddle_ignores';

    /** config_prod.js:1276 buddy_limit. */
    public const BUDDY_LIMIT = 1000;

    /** inc_buddies.js:10 the default group's label. */
    public const GROUP = 'Buddies';

    /** Seconds a watch token is good for (the game asks for a fresh one well before). */
    public const WATCH_TTL = 3600;

    /** Seconds an "added you" proof is good for. */
    public const TELL_TTL = 120;

    /** The longest watch token sent (the realtime server reads frames of 8 KB). */
    public const TOKEN_BUDGET = 6000;

    public function __construct(private Seams $minn)
    {
    }

    /** The lists and a watch token. */
    #[Route(Method::Get, '/groddle/v1/buddies', policy: new Policy(Access::SignedIn))]
    public function list(Request $request): Response
    {
        return Response::json($this->answer());
    }

    /** Adds a buddy by player id (addToBuddyGroup). */
    #[Route(Method::Post, '/groddle/v1/buddies', policy: new Policy(Access::SignedIn))]
    public function add(Request $request): Response
    {
        $me = $this->me();
        $id = $this->target($request->json()['id'] ?? null, 'You can\'t add yourself.');
        $db = $this->minn->db;
        $buddies = $db->table(self::TABLE);
        if ($this->ignoring($id, $me)) {
            // addToBuddyGroup 158 'ignored'; main.js:3194 says it so.
            throw new RestError('groddle_buddy_ignored', 'That player has blocked you.', 403);
        }
        if ($db->value("SELECT 1 FROM {$buddies} WHERE user_id = ? AND buddy_id = ?", [$me, $id]) !== null) {
            throw new RestError('groddle_buddy_exists', 'They are already on your buddy list.', 409);
        }
        $count = (int) $db->value("SELECT COUNT(*) FROM {$buddies} WHERE user_id = ?", [$me]);
        $reverse = $db->value("SELECT 1 FROM {$buddies} WHERE user_id = ? AND buddy_id = ?", [$id, $me]) !== null;
        if ($count >= self::BUDDY_LIMIT && !$reverse) {
            // main.js:3640 disabled_reason.
            throw new RestError('groddle_buddy_limit', 'You currently have the maximum number of friends (' . self::BUDDY_LIMIT . ' or more!)', 400, ['limit' => self::BUDDY_LIMIT]);
        }
        $db->execute("INSERT IGNORE INTO {$buddies} (user_id, buddy_id, group_name, added_at, noticed) VALUES (?, ?, ?, ?, 0)", [$me, $id, self::GROUP, gmdate('Y-m-d H:i:s')]);
        $now = time();
        $told = ['k' => 'buddy_added', 'sub' => $me, 'to' => $id, 'iat' => $now, 'exp' => $now + self::TELL_TTL, 'n' => bin2hex(random_bytes(8))];
        $secret = (new RealtimeTicket($this->minn))->secret();
        return Response::json($this->answer() + ['added' => $id, 'told' => $secret === null ? null : RealtimeTicket::sign($told, $secret)], 201);
    }

    /** Takes a buddy off the list (removeBuddy). */
    #[Route(Method::Delete, '/groddle/v1/buddies/{id:\d+}', policy: new Policy(Access::SignedIn))]
    public function remove(Request $request, string $id): Response
    {
        $buddies = $this->minn->db->table(self::TABLE);
        if ($this->minn->db->execute("DELETE FROM {$buddies} WHERE user_id = ? AND buddy_id = ?", [$this->me(), (int) $id]) === 0) {
            throw new RestError('groddle_buddy_missing', 'They are not on your buddy list.', 404);
        }
        return Response::json($this->answer() + ['removed' => (int) $id]);
    }

    /** Ignores (blocks) a player: off your buddies, out of your lists (buddies_add_ignore). */
    #[Route(Method::Post, '/groddle/v1/buddies/ignores', policy: new Policy(Access::SignedIn))]
    public function ignore(Request $request): Response
    {
        $me = $this->me();
        $id = $this->target($request->json()['id'] ?? null, 'You can\'t block yourself.');
        $db = $this->minn->db;
        $db->execute('DELETE FROM ' . $db->table(self::TABLE) . ' WHERE user_id = ? AND buddy_id = ?', [$me, $id]);
        $db->execute('INSERT IGNORE INTO ' . $db->table(self::IGNORES) . ' (user_id, ignored_id, added_at) VALUES (?, ?, ?)', [$me, $id, gmdate('Y-m-d H:i:s')]);
        return Response::json($this->answer() + ['ignored' => $id]);
    }

    /** Stops ignoring a player (buddies_remove_ignore). */
    #[Route(Method::Delete, '/groddle/v1/buddies/ignores/{id:\d+}', policy: new Policy(Access::SignedIn))]
    public function unignore(Request $request, string $id): Response
    {
        $ignores = $this->minn->db->table(self::IGNORES);
        if ($this->minn->db->execute("DELETE FROM {$ignores} WHERE user_id = ? AND ignored_id = ?", [$this->me(), (int) $id]) === 0) {
            throw new RestError('groddle_ignore_missing', 'You are not blocking that player.', 404);
        }
        return Response::json($this->answer() + ['unignored' => (int) $id]);
    }

    private function me(): int
    {
        return $this->minn->reader->userId;
    }

    /** A player id from the body: a number, not the caller, an account that exists. */
    private function target(mixed $id, string $self): int
    {
        if (!is_int($id) && !(is_string($id) && preg_match('/^[1-9]\d{0,18}$/', $id) === 1)) {
            throw new RestError('groddle_buddy_id', 'Name a player by their id.', 400);
        }
        $id = (int) $id;
        if ($id <= 0) {
            throw new RestError('groddle_buddy_id', 'Name a player by their id.', 400);
        }
        if ($id === $this->me()) {
            throw new RestError('groddle_buddy_self', $self, 400);
        }
        if ((new Users($this->minn->db))->find($id) === null) {
            throw new RestError('groddle_buddy_unknown', 'There is no player with that id.', 404);
        }
        return $id;
    }

    private function ignoring(int $ignorer, int $other): bool
    {
        $ignores = $this->minn->db->table(self::IGNORES);
        return $this->minn->db->value("SELECT 1 FROM {$ignores} WHERE user_id = ? AND ignored_id = ?", [$ignorer, $other]) !== null;
    }

    /** The caller's lists, newest first, and the watch token over them. */
    private function answer(): array
    {
        $db = $this->minn->db;
        $me = $this->me();
        $buddies = $db->table(self::TABLE);
        $ignores = $db->table(self::IGNORES);
        // Mine, but not those who ignore me (buddies_add_ignored_by took me off their list).
        $mine = $db->rows("SELECT b.buddy_id, b.added_at, (SELECT 1 FROM {$buddies} r WHERE r.user_id = b.buddy_id AND r.buddy_id = b.user_id) AS mutual
            FROM {$buddies} b WHERE b.user_id = ? AND NOT EXISTS (SELECT 1 FROM {$ignores} i WHERE i.user_id = b.buddy_id AND i.ignored_id = b.user_id)
            ORDER BY b.added_at DESC, b.buddy_id DESC", [$me]);
        // Who added me, but not those I ignore.
        $reverse = $db->rows("SELECT r.user_id, r.added_at, r.noticed FROM {$buddies} r WHERE r.buddy_id = ?
            AND NOT EXISTS (SELECT 1 FROM {$ignores} i WHERE i.user_id = r.buddy_id AND i.ignored_id = r.user_id)
            ORDER BY r.added_at DESC, r.user_id DESC", [$me]);
        $ignored = $db->rows("SELECT ignored_id, added_at FROM {$ignores} WHERE user_id = ? ORDER BY added_at DESC, ignored_id DESC", [$me]);
        $names = (new Characters($db))->namesOf(array_merge(
            array_map(static fn (array $r): int => (int) $r['buddy_id'], $mine),
            array_map(static fn (array $r): int => (int) $r['user_id'], $reverse),
            array_map(static fn (array $r): int => (int) $r['ignored_id'], $ignored),
        ));
        $out = ['limit' => self::BUDDY_LIMIT, 'group' => self::GROUP, 'buddies' => [], 'reverse' => [], 'ignoring' => []];
        foreach ($mine as $r) {
            $id = (int) $r['buddy_id'];
            if (isset($names[$id])) {
                $out['buddies'][] = ['id' => $id, 'name' => $names[$id], 'addedAt' => SaveShape::time((string) $r['added_at']), 'mutual' => $r['mutual'] !== null];
            }
        }
        foreach ($reverse as $r) {
            $id = (int) $r['user_id'];
            if (isset($names[$id])) {
                $out['reverse'][] = ['id' => $id, 'name' => $names[$id], 'addedAt' => SaveShape::time((string) $r['added_at']), 'new' => (int) $r['noticed'] === 0];
            }
        }
        foreach ($ignored as $r) {
            $id = (int) $r['ignored_id'];
            if (isset($names[$id])) {
                $out['ignoring'][] = ['id' => $id, 'name' => $names[$id]];
            }
        }
        // Seen now: the next answer no longer calls them new.
        if (array_filter($out['reverse'], static fn (array $r): bool => $r['new']) !== []) {
            $db->execute("UPDATE {$buddies} SET noticed = 1 WHERE buddy_id = ? AND noticed = 0", [$me]);
        }
        $out['watch'] = $this->watch($out);
        return $out;
    }

    /**
     * The signed list the realtime server watches: {k, sub, b, r, i, iat,
     * exp}, each list encoded by ids(). Kept under TOKEN_BUDGET by leaving
     * out the oldest reverse entries, then the oldest buddies; ignores go
     * last of all, since they guard.
     *
     * @return array{token: string, expiresIn: int}|null null when the site has no realtime secret
     */
    private function watch(array $lists): ?array
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null) {
            return null;
        }
        $ids = static fn (array $rows): array => array_map(static fn (array $r): int => $r['id'], $rows);
        $b = $ids($lists['buddies']);
        $r = $ids($lists['reverse']);
        $i = $ids($lists['ignoring']);
        $now = time();
        for (;;) {
            $payload = ['k' => 'buddies', 'sub' => $this->me(), 'b' => self::ids($b), 'r' => self::ids($r), 'i' => self::ids($i), 'iat' => $now, 'exp' => $now + self::WATCH_TTL];
            $token = RealtimeTicket::sign($payload, $secret);
            if (strlen($token) <= self::TOKEN_BUDGET || ($b === [] && $r === [] && $i === [])) {
                return ['token' => $token, 'expiresIn' => self::WATCH_TTL];
            }
            // Newest first, so the oldest are at the end.
            if ($r !== []) {
                $r = array_slice($r, 0, intdiv(count($r) * 3, 4));
            } elseif ($b !== []) {
                $b = array_slice($b, 0, intdiv(count($b) * 3, 4));
            } else {
                $i = array_slice($i, 0, intdiv(count($i) * 3, 4));
            }
        }
    }

    /**
     * A list of ids as the realtime server reads it (buddies.go buddyIDs):
     * sorted, each the difference from the one before, in base 36, comma
     * separated.
     *
     * @param list<int> $ids
     */
    public static function ids(array $ids): string
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        sort($ids);
        $out = [];
        $at = 0;
        foreach ($ids as $id) {
            $out[] = base_convert((string) ($id - $at), 10, 36);
            $at = $id;
        }
        return implode(',', $out);
    }
}
