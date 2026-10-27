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
 * The Magic Rock's daily iMG for what visitors do on a home street (register
 * G201, G119). In the original a home street (the home's exterior, the public
 * half: locations/inc_cultivation.js) kept a pool of iMG per game day:
 *
 * - cultivation_add_img_rewards (inc_cultivation.js 168-178) adds to today's
 *   amount when someone who is not the owner does something on the home
 *   street: only on a home (is_home), only on its public half (is_public, the
 *   street, never the house or its back yard), never for the owner (outside
 *   config.is_dev). Its callers pass a fixed amount: a garden's harvest
 *   (include/garden.js:579, 5), petting or nibbling a Piggy
 *   (npc_piggy.js 1664, 1764, 4), a Patch dug (patch.js:615, 8) or tended
 *   (patch.js:714, 3), a tree petted, watered or harvested (include/trants.js
 *   90, 196, 420: 3, 3, 8), mining (include/mining.js 391-400, 15 to 30),
 *   Making at a machine (players/inc_making.js:836, 7) and the rest
 *   (items/npc_butterfly.js 1187/1356 and npc_chicken.js:1560, 4 each;
 *   wood_tree.js:1018, 3). And each new visitor a game day adds 3% of the
 *   owner's cap (locations/home.js home_log_visitor 435-458, from
 *   onPlayerEnter 89-97 for anyone but the owner).
 * - cultivation_get_img_rewards (244-277) is what the owner's Magic Rock hands
 *   over (items/magic_rock.js 427-446): nothing once cashed in this game day
 *   (the daily counter cultivation_img_rewards); else the best earlier day's
 *   amount (today's never counts, so today's visitors pay tomorrow; the other
 *   earlier days are dropped), capped at the owner's level's cap
 *   (cultivation_rewards_cap 180-241, by stats_get_level), plus the stipend
 *   from level 2: 20% of the cap (Math.round(cap * 0.20)).
 * - cultivation_reset_img_rewards (284-292) after the hand-over: cashed in
 *   for today, and every day but today is gone.
 *
 * Here the pool is the site's, one row per owner and game day
 * (groddle_home_pool), because the acts that fill it are other players':
 * the realtime server tells of a visitor on the street (groddle-afternoon-server
 * home_pool.go, signed: ServiceCall), HomeTending adds a key-holder's harvest
 * of a garden on the street, and no client names an amount. What was counted
 * once (a visitor's day, a cash-in) is a row in groddle_home_pool_marks.
 *
 *   POST groddle/v1/homes/pool/visit   (the realtime server, signed) {"owner":7,"visitor":9}
 *        200 {"status":"settled","added":5,"day":12345} | 409 {"status":"refused","why":"own|no_home"}
 *   GET  groddle/v1/homes/pool          the signed-in owner: {"day","collect","cashed","today","cap","stipend"}
 *   POST groddle/v1/homes/pool/collect  the signed-in owner: {"amount","day","op","again"?}
 *        the hand-over, once a game day; asking again the same day answers the
 *        same amount with again:true, so the game applies it once (by op).
 *
 * The iMG itself lands in the owner's save through their game, as every iMG
 * does (levels and xp are still the client's, G335). Words: magic_rock.js 445.
 */
final readonly class HomePool
{
    public const TABLE = 'groddle_home_pool';
    public const MARKS = 'groddle_home_pool_marks';

    public const VISIT = '/groddle/v1/homes/pool/visit';

    /** common.js current_day_key: the remake's game day (js/game-day.js GAME_EPOCH_MS, GAME_DAY_MS; objects.go). */
    public const GAME_EPOCH_MS = 1238562000000;
    public const GAME_DAY_MS = 14400000;

    /** inc_cultivation.js cultivation_rewards_cap 180-241 (index level - 1). */
    public const CAP = [
        50, 75, 100, 125, 150, 175, 200, 225, 250, 275,
        325, 375, 425, 475, 525, 585, 645, 705, 765, 825,
        900, 975, 1050, 1125, 1200, 1275, 1350, 1425, 1500, 1575,
        1675, 1775, 1875, 1975, 2075, 2175, 2275, 2375, 2475, 2575,
        2675, 2775, 2875, 2975, 3075, 3175, 3275, 3375, 3475, 3575,
        3675, 3775, 3875, 3975, 4075, 4175, 4275, 4375, 4475, 4575,
    ];

    /** cultivation_get_img_rewards: `if (pc.stats_get_level() >= 2)` the stipend, Math.round(cap * 0.20). */
    public const STIPEND_LEVEL = 2;
    public const STIPEND_SHARE = 0.20;

    /** home.js:450 Math.round(cap * 0.03) for each new visitor a game day. */
    public const VISIT_SHARE = 0.03;

    /**
     * A key-holder's harvest of a garden on the home street (include/garden.js:579, pick; HomeTending).
     * The other visitor acts the source paid for (the class doc) have no visitor verb on a home street here yet.
     */
    public const HARVEST = 5;

    public const TEXT_OWN = 'That is your own home.';
    public const TEXT_NO_HOME = 'They have no home street yet.';

    public function __construct(private Seams $minn)
    {
    }

    // —— Pure rules (tests: php -r) ——

    /** The game day a time (Unix ms) falls on. */
    public static function dayOf(int $ms): int
    {
        return intdiv($ms - self::GAME_EPOCH_MS, self::GAME_DAY_MS) - (($ms - self::GAME_EPOCH_MS) % self::GAME_DAY_MS < 0 ? 1 : 0);
    }

    /** cultivation_get_rewards_cap 279-282 (levels past the table use its last row). */
    public static function cap(int $level): int
    {
        $n = max(1, $level);
        return self::CAP[min($n, count(self::CAP)) - 1];
    }

    public static function stipend(int $level): int
    {
        return $level >= self::STIPEND_LEVEL ? (int) round(self::cap($level) * self::STIPEND_SHARE) : 0;
    }

    /** home_log_visitor: a new visitor's share of the owner's cap. */
    public static function visitAmount(int $ownerLevel): int
    {
        return (int) round(self::cap($ownerLevel) * self::VISIT_SHARE);
    }

    /**
     * cultivation_get_img_rewards 244-277: what the rock hands over now.
     *
     * @param array<int, int> $pool day => amount
     */
    public static function collectable(array $pool, int $level, bool $cashed, int $today): int
    {
        if ($cashed) {
            return 0;
        }
        $best = 0;
        foreach ($pool as $day => $amount) {
            if ((int) $day !== $today && (int) $amount > $best) {
                $best = (int) $amount;
            }
        }
        return min($best, self::cap($level)) + self::stipend($level);
    }

    /** The character's level as their stored save has it (1 without one). */
    public static function levelOf(?object $state): int
    {
        $level = $state?->player?->level ?? 1;
        return is_int($level) || (is_float($level) && is_finite($level)) ? max(1, (int) $level) : 1;
    }

    public static function today(): int
    {
        return self::dayOf((int) floor(microtime(true) * 1000));
    }

    /**
     * Adds to an owner's pool for today (cultivation_add_img_rewards). The
     * caller decides that the act was a visitor's on the owner's street.
     */
    public static function add(Db $db, int $owner, int $amount, int $day): void
    {
        if ($owner <= 0 || $amount <= 0) {
            return;
        }
        $t = $db->table(self::TABLE);
        $db->execute("INSERT INTO {$t} (owner_id, day, amount, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount), updated_at = VALUES(updated_at)", [$owner, $day, $amount, gmdate('Y-m-d H:i:s')]);
    }

    /** Marks something counted once (a visitor's day, a cash-in): false when it already was. */
    public static function mark(Db $db, int $owner, int $visitor, int $day, string $what, int $amount = 0): bool
    {
        return $db->execute('INSERT IGNORE INTO ' . $db->table(self::MARKS) . ' (owner_id, visitor_id, day, mark, amount, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$owner, $visitor, $day, $what, $amount, gmdate('Y-m-d H:i:s')]) === 1;
    }

    /** @return array<int, int> day => amount */
    private static function poolOf(Db $db, int $owner): array
    {
        $out = [];
        foreach ($db->rows('SELECT day, amount FROM ' . $db->table(self::TABLE) . ' WHERE owner_id = ?', [$owner]) as $r) {
            $out[(int) $r['day']] = (int) $r['amount'];
        }
        return $out;
    }

    private static function cashedAmount(Db $db, int $owner, int $day): ?int
    {
        $v = $db->value('SELECT amount FROM ' . $db->table(self::MARKS) . " WHERE owner_id = ? AND visitor_id = ? AND day = ? AND mark = 'cashed'", [$owner, $owner, $day]);
        return $v === null ? null : (int) $v;
    }

    // —— The realtime server ——

    /** home_log_visitor: a visitor stood on the owner's home street (the server saw the join). */
    #[Route(Method::Post, self::VISIT, policy: new Policy(Access::Public))]
    public function visit(Request $request): Response
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', self::VISIT, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
        $body = json_decode($request->body, true);
        $owner = is_array($body) ? ($body['owner'] ?? null) : null;
        $visitor = is_array($body) ? ($body['visitor'] ?? null) : null;
        if (!is_int($owner) || !is_int($visitor) || $owner <= 0 || $visitor <= 0) {
            throw new RestError('groddle_pool_invalid', 'Name the owner and the visitor.', 400);
        }
        if ($owner === $visitor) {
            return Response::json(['status' => 'refused', 'why' => 'own', 'text' => self::TEXT_OWN], 409);
        }
        $db = $this->minn->db;
        $day = self::today();
        $out = Ledger::atomically($db, function () use ($db, $owner, $visitor, $day): array {
            $state = Neighbours::state($db, $owner);
            if (!Neighbours::hasHome($state)) {
                return ['status' => 'refused', 'why' => 'no_home', 'text' => self::TEXT_NO_HOME];
            }
            $amount = self::visitAmount(self::levelOf($state));
            if (!self::mark($db, $owner, $visitor, $day, 'visit', $amount)) {
                return ['status' => 'settled', 'added' => 0, 'day' => $day];
            }
            self::add($db, $owner, $amount, $day);
            // Marks older than yesterday count nothing any more.
            $db->execute('DELETE FROM ' . $db->table(self::MARKS) . ' WHERE owner_id = ? AND day < ?', [$owner, $day - 1]);
            return ['status' => 'settled', 'added' => $amount, 'day' => $day];
        });
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    // —— The owner ——

    /** What the owner's Magic Rock would hand over now (houses_get_img_rewards). */
    #[Route(Method::Get, '/groddle/v1/homes/pool', policy: new Policy(Access::SignedIn))]
    public function mine(Request $request): Response
    {
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        $day = self::today();
        $level = self::levelOf(Neighbours::state($db, $me));
        $pool = self::poolOf($db, $me);
        $cashed = self::cashedAmount($db, $me, $day) !== null;
        return Response::json([
            'day' => $day,
            'collect' => self::collectable($pool, $level, $cashed, $day),
            'cashed' => $cashed,
            'today' => $pool[$day] ?? 0,
            'cap' => self::cap($level),
            'stipend' => self::stipend($level),
        ]);
    }

    /** magic_rock.js 427-446: the hand-over, then cultivation_reset_img_rewards. */
    #[Route(Method::Post, '/groddle/v1/homes/pool/collect', policy: new Policy(Access::SignedIn))]
    public function collect(Request $request): Response
    {
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        $day = self::today();
        $out = Ledger::atomically($db, function () use ($db, $me, $day): array {
            $db->value('SELECT version FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ? FOR UPDATE', [$me]);
            $op = 'pool-' . $me . '-' . $day;
            $had = self::cashedAmount($db, $me, $day);
            if ($had !== null) {
                return ['amount' => $had, 'day' => $day, 'op' => $op, 'again' => true];
            }
            $amount = self::collectable(self::poolOf($db, $me), self::levelOf(Neighbours::state($db, $me)), false, $day);
            if ($amount <= 0) {
                return ['amount' => 0, 'day' => $day, 'op' => $op];
            }
            self::mark($db, $me, $me, $day, 'cashed', $amount);
            $db->execute('DELETE FROM ' . $db->table(self::TABLE) . ' WHERE owner_id = ? AND day <> ?', [$me, $day]);
            $db->execute('DELETE FROM ' . $db->table(self::MARKS) . ' WHERE owner_id = ? AND day < ?', [$me, $day - 1]);
            return ['amount' => $amount, 'day' => $day, 'op' => $op];
        });
        return Response::json($out);
    }
}
