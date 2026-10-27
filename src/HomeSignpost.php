<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * The signpost on a player's home street, with friends' homes on its arms
 * (register G328, the Good Neighbour badge), the rules of GameServerJS
 * locations/home.js:
 *
 * - addNeighbor 222-256 (main.js doAddNeighbor 6973-6982): five arms,
 *   positions 0 to 4; the neighbour must be on the owner's buddy list
 *   (owner.buddies_is_buddy), not on the post already, have a house and
 *   have finished the tutorial (has_done_intro, leave_gentle_island);
 *   anything else is "Could not add neighbor". A new neighbour takes the
 *   arm whoever was on it (the source's TODO: the arm is simply set).
 *   Adding the fifth sets the counter signpost:num_neighbors to 5
 *   (250-252, achievements/good_neighbour.js "Added 5 friends to your
 *   signpost"), and so does coming home to a post with five (35-37).
 * - removeNeighbor 258-264 (main.js doRemoveNeighbor 6984-6990).
 * - A neighbour leaves the post when the owner unfriends them
 *   (inc_buddies.js:279-280), when they block the owner (inc_buddies.js
 *   555-558: the blocker leaves the blocked player's post) and when they
 *   lose their home (inc_houses.js 728-736). Here those rows are left out
 *   of every answer (and dropped when the owner next changes the post), so
 *   nothing has to watch for them.
 * - updateNeighborSignpost 287-327: each arm leads to that neighbour's home
 *   street; the game visits it through Neighbours (groddle/v1/homes/{id}).
 *
 *   GET    groddle/v1/homes/signpost             the signed-in player's post
 *   PUT    groddle/v1/homes/signpost             {"position":0-4,"id":<buddy>}  houses_add_neighbor
 *   DELETE groddle/v1/homes/signpost/{position}  houses_remove_neighbor
 *        200 {"neighbors":[{"position":0,"id":9,"name":"Bo"}],"count":1,"five":false}
 *
 * The source refused both off the owner's home ("You are not at home",
 * main.js:6974): the game offers Edit your signpost only there. Table
 * groddle_home_signposts, one row an arm. A visitor sees the post through
 * HomeStreet (groddle/v1/homes/{id}/street).
 */
final readonly class HomeSignpost
{
    public const TABLE = 'groddle_home_signposts';

    /** locations/home.js:226 position > 4 is refused: five arms. */
    public const ARMS = 5;

    /** home.js:250 count >= 4 before the add, :36 num_keys >= 5. */
    public const GOOD_NEIGHBOUR = 5;

    public const TEXT_COULD_NOT = 'Could not add neighbor';      // main.js:6979
    public const TEXT_COULD_NOT_REMOVE = 'Could not remove neighbor'; // main.js:6987
    public const TEXT_NO_HOME = "You can't edit a signpost without a home street.";

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/homes/signpost', policy: new Policy(Access::SignedIn))]
    public function mine(Request $request): Response
    {
        return Response::json(self::answer($this->minn->db, $this->minn->reader->userId));
    }

    #[Route(Method::Put, '/groddle/v1/homes/signpost', policy: new Policy(Access::SignedIn))]
    public function add(Request $request): Response
    {
        $me = $this->minn->reader->userId;
        $body = $request->json();
        $position = $body['position'] ?? null;
        $id = $body['id'] ?? null;
        if (!is_int($position) || $position < 0 || $position >= self::ARMS) {
            throw new RestError('groddle_signpost_position', self::TEXT_COULD_NOT, 400);
        }
        if (!is_int($id) && !(is_string($id) && preg_match('/^[1-9]\d{0,18}$/', $id) === 1)) {
            throw new RestError('groddle_signpost_id', self::TEXT_COULD_NOT, 400);
        }
        $id = (int) $id;
        $db = $this->minn->db;
        if (!Neighbours::hasHome(Neighbours::state($db, $me))) {
            throw new RestError('groddle_signpost_home', self::TEXT_NO_HOME, 400);
        }
        $why = self::refuse($db, $me, $id);
        if ($why !== null) {
            throw new RestError('groddle_signpost_' . $why, self::TEXT_COULD_NOT, 400);
        }
        $out = Ledger::atomically($db, function () use ($db, $me, $id, $position): array {
            $t = $db->table(self::TABLE);
            // Rows of neighbours who have since gone (unfriended, blocking, no home) go first: they are not arms any more.
            $db->value("SELECT owner_id FROM {$t} WHERE owner_id = ? LIMIT 1 FOR UPDATE", [$me]);
            self::prune($db, $me);
            $live = self::neighbors($db, $me);
            foreach ($live as $n) {
                if ($n['id'] === $id) {
                    // home.js:232-235: already there (the source redrew the post and said no).
                    return ['error' => 'already'];
                }
            }
            $before = count($live) - (array_filter($live, static fn (array $n): bool => $n['position'] === $position) === [] ? 0 : 1);
            $db->execute("REPLACE INTO {$t} (owner_id, position, neighbor_id, added_at) VALUES (?, ?, ?, ?)", [$me, $position, $id, gmdate('Y-m-d H:i:s')]);
            return ['before' => $before];
        });
        if (isset($out['error'])) {
            throw new RestError('groddle_signpost_' . $out['error'], self::TEXT_COULD_NOT, 409);
        }
        $answer = self::answer($db, $me);
        // home.js:250-252: the fifth friend (four there before this one).
        $answer['added'] = $id;
        $answer['five'] = $answer['five'] || $out['before'] >= self::GOOD_NEIGHBOUR - 1;
        return Response::json($answer);
    }

    #[Route(Method::Delete, '/groddle/v1/homes/signpost/{position:\d}', policy: new Policy(Access::SignedIn))]
    public function remove(Request $request, string $position): Response
    {
        $me = $this->minn->reader->userId;
        $p = (int) $position;
        $db = $this->minn->db;
        if ($p < 0 || $p >= self::ARMS || $db->execute('DELETE FROM ' . $db->table(self::TABLE) . ' WHERE owner_id = ? AND position = ?', [$me, $p]) === 0) {
            throw new RestError('groddle_signpost_remove', self::TEXT_COULD_NOT_REMOVE, 404);
        }
        return Response::json(self::answer($db, $me) + ['removed' => $p]);
    }

    /** Why id may not go on owner's post (home.js addNeighbor), or null. */
    public static function refuse(Db $db, int $owner, int $id): ?string
    {
        if ($id <= 0 || $id === $owner) {
            return 'self';
        }
        $buddies = $db->table(Buddies::TABLE);
        if ($db->value("SELECT 1 FROM {$buddies} WHERE user_id = ? AND buddy_id = ?", [$owner, $id]) === null) {
            return 'buddy';
        }
        if (self::blockedEither($db, $owner, $id)) {
            return 'blocked';
        }
        $theirs = Neighbours::state($db, $id);
        if (!Neighbours::hasHome($theirs)) {
            return 'no_house';
        }
        if (Neighbours::inTutorial($theirs)) {
            return 'newxp';
        }
        return null;
    }

    private static function blockedEither(Db $db, int $a, int $b): bool
    {
        $ignores = $db->table(Buddies::IGNORES);
        return $db->value("SELECT 1 FROM {$ignores} WHERE (user_id = ? AND ignored_id = ?) OR (user_id = ? AND ignored_id = ?)", [$a, $b, $b, $a]) !== null;
    }

    /**
     * The arms of owner's post that still stand: the neighbour is still the
     * owner's buddy, neither blocks the other and the neighbour still has a
     * home. With names, by position.
     *
     * @return list<array{position: int, id: int, name: string}>
     */
    public static function neighbors(Db $db, int $owner): array
    {
        $t = $db->table(self::TABLE);
        $buddies = $db->table(Buddies::TABLE);
        $ignores = $db->table(Buddies::IGNORES);
        $rows = $db->rows("SELECT s.position, s.neighbor_id FROM {$t} s
            JOIN {$buddies} b ON b.user_id = s.owner_id AND b.buddy_id = s.neighbor_id
            WHERE s.owner_id = ?
              AND NOT EXISTS (SELECT 1 FROM {$ignores} i WHERE (i.user_id = s.owner_id AND i.ignored_id = s.neighbor_id) OR (i.user_id = s.neighbor_id AND i.ignored_id = s.owner_id))
            ORDER BY s.position", [$owner]);
        $ids = array_map(static fn (array $r): int => (int) $r['neighbor_id'], $rows);
        $names = (new Characters($db))->namesOf($ids);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['neighbor_id'];
            if (!isset($names[$id]) || !Neighbours::hasHome(Neighbours::state($db, $id))) {
                continue;
            }
            $out[] = ['position' => (int) $r['position'], 'id' => $id, 'name' => $names[$id]];
        }
        return $out;
    }

    /** Drops the rows neighbors() leaves out (inc_buddies.js 279-280, 555-558; inc_houses.js 728-736). */
    private static function prune(Db $db, int $owner): void
    {
        $keep = array_map(static fn (array $n): int => $n['position'], self::neighbors($db, $owner));
        $t = $db->table(self::TABLE);
        if ($keep === []) {
            $db->execute("DELETE FROM {$t} WHERE owner_id = ?", [$owner]);
            return;
        }
        $db->execute("DELETE FROM {$t} WHERE owner_id = ? AND position NOT IN (?)", [$owner, $keep]);
    }

    /** @return array{neighbors: list<array>, count: int, five: bool} */
    public static function answer(Db $db, int $owner): array
    {
        $n = self::neighbors($db, $owner);
        return ['neighbors' => $n, 'count' => count($n), 'five' => count($n) >= self::GOOD_NEIGHBOUR];
    }
}
