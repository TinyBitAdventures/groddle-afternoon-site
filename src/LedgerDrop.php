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
 * Stacks dropped on a public street for everyone (register G332): in the
 * original a dropped stack lay in the location for whoever picked it up
 * first (GameServerJS items/include/takeable.js takeable_drop 157-213,
 * takeable_pickup_conditions 5-51, takeable_pickup 53-102). The realtime
 * server (groddle-afternoon-server drops.go) decides who is on the street,
 * the dropper's 100 px guard and who asked first; this keeps the stacks and
 * moves the goods through the ledger (Ledger, G335):
 *
 *   POST groddle/v1/ledger/drop       (the realtime server, signed: ServiceCall)
 *        {"op":"drop-<16 hex>","id":7,"street":"LA5…","x":1200,"y":-120,"item":{"c":"apple","n":3,"r"?:40}}
 *        200 {"status":"settled"} | 409 {"status":"refused","why":"type|short","text":"…"}
 *
 *   The stack leaves the dropper's ledger at once, recorded as a settled
 *   trade with nobody (groddle_trades, b_id 0, as LedgerSpend does), and
 *   waits in groddle_drops, in nobody's ledger.
 *
 *   POST groddle/v1/ledger/drop/take  (signed) {"op":"drop-…","id":9,"why":"pickup"|"return"}
 *        200 {"status":"settled","op":"drop-…-t","item":{…}} | 409 {"status":"refused","why":"gone","by":9}
 *
 *   The first take has it: the stack goes into the taker's ledger, recorded
 *   as a settled credit with nobody (a: the taker, who got it; the id
 *   <op>-t, as ButlerParcels' <op>-in). Asking again for the same taker
 *   answers as recorded; anyone else hears it is gone. A return is the
 *   dropper's own take of a stack nobody picked up (drops.go dropKeep).
 *
 *   POST groddle/v1/ledger/drop/take  (signed) {"op":"drop-…","id":9,"why":"pickup","n":3,"from":10}
 *        200 {"status":"settled","op":"drop-…-t10","item":{…,"n":3},"n":7}
 *        | 409 {"status":"refused","why":"gone","by":9} | 409 {"status":"refused","why":"stale","n":7}
 *
 *   A part of the stack (G560, Pick up how many: main.js 3227-3240 splits
 *   the stack, the rest stays on the ground): n of the from the realtime
 *   server holds, with n < from. Its credit is <op>-t<from>: a stack's count
 *   only ever falls (takes, bites), so each part taken has its own id, and
 *   the server's retry of the same ask finds it recorded and answers as
 *   recorded. A take on a count the stack no longer has (two at once, or an
 *   answer the server missed) is refused as stale with the count there is,
 *   so nobody takes more than lies there. The row keeps the rest; a whole
 *   take or a return later moves what is left under <op>-t.
 *
 *   POST groddle/v1/ledger/drop/eat   (signed) {"op":"drop-…","from":3}
 *        200 {"status":"settled","n":2} | 409 {"status":"refused","why":"gone|stale"}
 *
 *   A street Piggy ate one of the stack (GameServerJS items/npc_piggy.js
 *   onFoodPathing 1489-1500, apiConsume(1); the realtime server's
 *   objects_pigs.go decides which Piggy and when). The stack is in nobody's
 *   ledger, so only its count moves; from is the count the server holds, so
 *   asking again answers as recorded. The last one eaten leaves the row
 *   'eaten'. A dropper's missed drop still applies what was dropped (the
 *   trade's goods), a picker's take what was there when they took it.
 *
 *   POST groddle/v1/ledger/drop/place (signed) {"op":"drop-…","id":7,"street":"LA5…","x":1200,"y":-120,"item":{"c":"plop","n":1,"t"?:{…}}}
 *        200 {"status":"settled"} | 409 {"status":"refused","why":"type","text":"…"}
 *
 *   A stack the street makes (G605): a Plop a fed street Piggy leaves (npc_animal doPlop
 *   1315-1350) or the Piglet a Potion of Animal Youth leaves where its Piggy stood
 *   (potion_animal_youth.js onPour 547-569). It comes out of nobody's ledger, so no trade is
 *   recorded for it: the row waits under the player whose act made it (dropper_id, for the
 *   realtime server's 100 px guard and a return after its keep), an outcome of that act
 *   (logged as "place"). Recorded once per op. From then on it is any stack on the ground.
 *
 *   POST groddle/v1/ledger/drops      (signed) {}  → {"stacks":[{id, by, name, street, x, y, c, n, r?, at}]}
 *        every stack on the ground, for a realtime server that has just started; {"street":"HOUSE-7"}
 *        one place's (G1224: drops.go asks when someone joins a house or Tower floor, for the old
 *        floor drops HouseFloors moved there on the owner's load).
 *
 *   On a house or Tower floor (HOUSE-<owner>, TOWER-<owner>-<floor>) a drop may name a like stack:
 *   POST groddle/v1/ledger/drop {…, "into":"drop-…", "max"?:9999}
 *        200 {"status":"settled","into":"drop-…","joined":2,"in":3,"n":1}   (no "into": it lies on its own)
 *   takeable_drop put the stack in with apiPutItemIntoPosition, which merged it into a like stack
 *   there below its stackmax (GameServerJS items/include/takeable.js 202-207; Eleven Location.addItem
 *   475-503): as many as the stack takes join it in the drop's own transaction, the rest lies on its
 *   own ("n"). A stack takes only from its own dropper (joins; Austin 2026-11-24: each row keeps its
 *   dropper, so drops.go's return after dropKeep never sends anyone's goods to someone else). The
 *   drop keeps its row, 'merged' when all of it joined, so its op is found as any drop's. A pile there
 *   has no stack limit for any class, only the most a stack holds anywhere (pileCap, 9999; Austin
 *   2026-11-24: "Let's remove the pile limits", a difference from Glitch, whose stackmax capped every
 *   pile); a tool or a class whose copies carry words never joins. The site reads the pile from its own
 *   table: the "max" drops.go sends is not needed (a realtime server from before sent the stackmax).
 *
 *   POST groddle/v1/ledger/drop/move  (signed) {"op":"move-<16 hex>","id":<owner>,"drop":"drop-…","street":"HOUSE-7","x":900,"y":620,"into"?:"drop-…","max"?:9999}
 *        200 {"status":"settled","n":3,"x":900,"y":620,"into"?,"joined"?,"in"?} | 409 {"status":"refused","why":"place|gone"}
 *   The owner's Decoration Mode move of a stack on their floor (main.js doModifyItem 4852-4890 moved any
 *   stack in the house, merging onto a like one): it stays on the ground for everyone, recorded once per
 *   move op on the row.
 *
 *   A merge raises a stack's count, which a part's id (<op>-t<from>) relied on never happening: each
 *   rise moves the stack's generation on, and a part of a grown stack is <op>-t<from>-g<gen>, so an id
 *   recorded before the rise is never answered "as recorded" for a new take (partId).
 *
 *   GET  groddle/v1/drops             the signed-in player's drops and takes their save has not applied
 *        {"drops":[{"op","side":"dropped"|"took","id","item":{c,n,r?},"why"?}]}
 *
 * Each save applies a drop or a take through the trade slice's applied list
 * (features/drops-mp-feature.js, ledgerReceipt), and until it does a write
 * is compared as if it had not happened; a load applies it to the stored
 * save (Ledger::catchUp, a plain stack in or out of the pack).
 *
 * Streets: a public street's tsid; a player's home street, house (with its back
 * yard) and Tower floors as the realtime server names them with "-" for "_"
 * (HOME-<owner>, HOUSE-<owner>, TOWER-<owner>-<floor>; drops.go dropSiteStreet,
 * G627 and G1190), all within the street rule below.
 *
 * Ours: only a class the ledger carries (Economy) is shared; the game drops
 * anything else as its own, as in solo. Words: inc_trading.js:456 for a
 * dropper whose ledger lacks the stack.
 */
final readonly class LedgerDrop
{
    public const TABLE = 'groddle_drops';

    public const DROP = '/groddle/v1/ledger/drop';
    public const TAKE = '/groddle/v1/ledger/drop/take';
    public const GROUND = '/groddle/v1/ledger/drops';
    public const EAT = '/groddle/v1/ledger/drop/eat';
    public const PLACE = '/groddle/v1/ledger/drop/place';
    public const MOVE = '/groddle/v1/ledger/drop/move';

    /** Stacks one list carries (drops.go holds 60 a street; a house or Tower floor any number, G1224). */
    public const LIST_MAX = 50000;

    public const TEXT_SHORT = "You don't have enough of that item.";
    public const TEXT_TYPE = "That can't be dropped for others to find.";

    private const NONE = '{"items":[],"currants":0}';

    public function __construct(private Seams $minn)
    {
    }

    // —— The realtime server ——

    #[Route(Method::Post, self::DROP, policy: new Policy(Access::Public))]
    public function drop(Request $request): Response
    {
        $this->signed($request, self::DROP);
        $body = json_decode($request->body, true);
        $drop = is_array($body) ? self::call($body) : null;
        if ($drop === null) {
            throw new RestError('groddle_ledger_invalid', 'Not a drop.', 400);
        }
        $db = $this->minn->db;
        $out = Ledger::atomically($db, fn () => self::settle($db, Ledger::fromSeams($this->minn), $drop, time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    #[Route(Method::Post, self::PLACE, policy: new Policy(Access::Public))]
    public function place(Request $request): Response
    {
        $this->signed($request, self::PLACE);
        $body = json_decode($request->body, true);
        $drop = is_array($body) ? self::call($body) : null;
        if ($drop === null) {
            throw new RestError('groddle_ledger_invalid', 'Not a placed stack.', 400);
        }
        $db = $this->minn->db;
        $out = Ledger::atomically($db, fn () => self::put($db, Ledger::fromSeams($this->minn), $drop, time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    #[Route(Method::Post, self::MOVE, policy: new Policy(Access::Public))]
    public function moveOnFloor(Request $request): Response
    {
        $this->signed($request, self::MOVE);
        $body = json_decode($request->body, true);
        $m = is_array($body) ? self::moveCall($body) : null;
        if ($m === null) {
            throw new RestError('groddle_ledger_invalid', 'Not a move.', 400);
        }
        $db = $this->minn->db;
        $out = Ledger::atomically($db, fn () => self::moveStack($db, Ledger::fromSeams($this->minn), $m, time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    #[Route(Method::Post, self::TAKE, policy: new Policy(Access::Public))]
    public function take(Request $request): Response
    {
        $this->signed($request, self::TAKE);
        $body = json_decode($request->body, true);
        $op = is_array($body) ? ($body['op'] ?? null) : null;
        $id = is_array($body) ? ($body['id'] ?? null) : null;
        $why = is_array($body) ? ($body['why'] ?? null) : null;
        $n = is_array($body) ? ($body['n'] ?? null) : null;
        $from = is_array($body) ? ($body['from'] ?? null) : null;
        if (!self::opId($op) || !is_int($id) || $id <= 0 || !in_array($why, ['pickup', 'return'], true) || !self::part($why, $n, $from)) {
            throw new RestError('groddle_ledger_invalid', 'Not a pickup.', 400);
        }
        $db = $this->minn->db;
        $out = Ledger::atomically($db, fn () => self::give($db, Ledger::fromSeams($this->minn), $op, $id, $why, time(), $n, $from));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    #[Route(Method::Post, self::EAT, policy: new Policy(Access::Public))]
    public function eat(Request $request): Response
    {
        $this->signed($request, self::EAT);
        $body = json_decode($request->body, true);
        $op = is_array($body) ? ($body['op'] ?? null) : null;
        $from = is_array($body) ? ($body['from'] ?? null) : null;
        if (!self::opId($op) || !is_int($from) || $from < 1 || $from > 9999) {
            throw new RestError('groddle_ledger_invalid', 'Not a bite.', 400);
        }
        $out = Ledger::atomically($this->minn->db, fn () => $this->bite($op, $from, time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    /**
     * One of a stack on the ground eaten, once for each count the server held.
     *
     * @param array{status: string, count: int} $row the row as it stands (status, count)
     * @return array{status: string, n?: int, why?: string, set?: array{status: string, count: int}}
     */
    public static function biteOf(array $row, int $from): array
    {
        $count = (int) $row['count'];
        if ($row['status'] === 'eaten' && $count === 0 && $from === 1) {
            return ['status' => 'settled', 'n' => 0];
        }
        if ($row['status'] !== 'ground') {
            return ['status' => 'refused', 'why' => 'gone'];
        }
        if ($count === $from - 1) {
            return ['status' => 'settled', 'n' => $count];
        }
        if ($count !== $from) {
            return ['status' => 'refused', 'why' => 'stale'];
        }
        $left = $count - 1;
        return ['status' => 'settled', 'n' => $left, 'set' => ['status' => $left > 0 ? 'ground' : 'eaten', 'count' => $left]];
    }

    /** @return array{status: string, n?: int, why?: string} */
    private function bite(string $op, int $from, int $now): array
    {
        $db = $this->minn->db;
        $drops = $db->table(self::TABLE);
        $row = $db->row("SELECT dropper_id, class_tsid, count, status FROM {$drops} WHERE id = ? FOR UPDATE", [$op]);
        if ($row === null) {
            return ['status' => 'refused', 'why' => 'gone'];
        }
        $out = self::biteOf(['status' => (string) $row['status'], 'count' => (int) $row['count']], $from);
        if (isset($out['set'])) {
            $set = $out['set'];
            unset($out['set']);
            if ($set['status'] === 'eaten') {
                $db->execute("UPDATE {$drops} SET count = 0, status = 'eaten', why = 'eaten', taken_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', $now), $op]);
            } else {
                $db->execute("UPDATE {$drops} SET count = ? WHERE id = ?", [$set['count'], $op]);
            }
            Ledger::fromSeams($this->minn)->log((int) $row['dropper_id'], 'eaten', ['drop' => $op, 'ate' => [(string) $row['class_tsid'] => 1], 'left' => $set['count']], $now);
        }
        return $out;
    }

    #[Route(Method::Post, self::GROUND, policy: new Policy(Access::Public))]
    public function ground(Request $request): Response
    {
        $this->signed($request, self::GROUND);
        $db = $this->minn->db;
        // G1224: {"street": "HOUSE-7"} lists one place (drops.go refresh: stacks moved there on the owner's load).
        $street = $request->json()['street'] ?? null;
        $one = is_string($street) && preg_match('/^[A-Za-z0-9-]{1,32}$/', $street) === 1;
        $rows = $db->rows('SELECT id, dropper_id, street, x, y, class_tsid, count, points, tag, created_at FROM ' . $db->table(self::TABLE) . " WHERE status = 'ground'" . ($one ? ' AND street = ?' : '') . ' ORDER BY created_at, id LIMIT ' . self::LIST_MAX, $one ? [$street] : []);
        $names = (new Characters($db))->namesOf(array_values(array_unique(array_map(static fn (array $r): int => (int) $r['dropper_id'], $rows))));
        $out = [];
        foreach ($rows as $r) {
            $by = (int) $r['dropper_id'];
            $row = ['id' => (string) $r['id'], 'by' => $by, 'name' => $names[$by] ?? 'Someone', 'street' => (string) $r['street'], 'x' => (int) $r['x'], 'y' => (int) $r['y'],
                'c' => (string) $r['class_tsid'], 'n' => (int) $r['count'], 'at' => (int) strtotime((string) $r['created_at'] . ' UTC')];
            if ($r['points'] !== null) {
                $row['r'] = (int) $r['points'];
            }
            // One copy's own words (CopyTags): a named Cubimal lies on the street with its name.
            if (isset($r['tag']) && ($t = CopyTags::decode($r['tag'])) !== null && (int) $r['count'] === 1) {
                $row['t'] = $t;
            }
            $out[] = $row;
        }
        return Response::json(['stacks' => $out]);
    }

    /**
     * A drop as the realtime server sends it, checked: null when it is not one.
     *
     * @return array{op: string, id: int, street: string, x: int, y: int, c: string, n: int, r: ?int, t?: string}|null
     */
    public static function call(array $body): ?array
    {
        $op = $body['op'] ?? null;
        $id = $body['id'] ?? null;
        $street = $body['street'] ?? null;
        $item = $body['item'] ?? null;
        $x = $body['x'] ?? 0;
        $y = $body['y'] ?? 0;
        if (!self::opId($op) || !is_int($id) || $id <= 0 || !is_string($street) || preg_match('/^[A-Za-z0-9-]{1,32}$/', $street) !== 1
            || !is_int($x) || !is_int($y) || abs($x) > 1000000 || abs($y) > 1000000 || !is_array($item)) {
            return null;
        }
        $c = $item['c'] ?? null;
        $n = $item['n'] ?? null;
        if (!is_string($c) || preg_match('/^[a-z0-9_]{1,64}$/', $c) !== 1 || !is_int($n) || $n < 1 || $n > 9999) {
            return null;
        }
        $r = is_int($item['r'] ?? null) && $item['r'] >= 0 && $item['r'] <= 1000000 ? $item['r'] : null;
        // The dropper's own copy's words, checked again (the realtime server checked them first).
        $t = isset($item['t']) ? CopyTags::json(Economy::shared(), $c, $n, $item['t']) : null;
        $out = ['op' => $op, 'id' => $id, 'street' => $street, 'x' => $x, 'y' => $y, 'c' => $c, 'n' => $n, 'r' => $r] + ($t !== null ? ['t' => $t] : []);
        // The like stack it lands on (a house or Tower floor, drops.go); its pile is the site's own (pileCap).
        $into = $body['into'] ?? null;
        if (self::opId($into) && $into !== $op) {
            $out += ['into' => $into];
        }
        return $out;
    }

    /** A house or a Tower floor as the site names it (drops.go dropSiteStreet), and whose it is; 0 for any other street. */
    public static function floorOwner(string $street): int
    {
        if (preg_match('/^HOUSE-([1-9][0-9]{0,17})$/', $street, $m) === 1 || preg_match('/^TOWER-([1-9][0-9]{0,17})-[0-9]$/', $street, $m) === 1) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * How many of a drop (or a moved stack) of `c`, `n`, wear `r`, words `t`, dropped by `by` on
     * `street`, the like stack `$into` takes (its row locked): 0 when it takes none. Only on a house
     * or Tower floor; a stack still on the ground there, of the same class, with no wear or words on
     * either; dropped by the same player (Austin 2026-11-24: only one player's own stacks join, so each
     * row keeps its dropper's return after dropKeep and nobody's goods change hands); and no more than
     * the class's pile (pileCap: 9999, the rest lying beside it).
     *
     * @param array{status: string, street: string, class_tsid: string, count: int|string, points: mixed, tag: mixed, dropper_id: int|string}|null $into
     */
    public static function joins(?array $into, string $street, string $c, int $n, ?int $r, ?string $t, int $by): int
    {
        $owner = self::floorOwner($street);
        if ($into === null || $owner === 0 || $into['status'] !== 'ground' || (string) $into['street'] !== $street || (string) $into['class_tsid'] !== $c) {
            return 0;
        }
        if ($r !== null || $t !== null || $into['points'] !== null || ($into['tag'] ?? null) !== null) {
            return 0;
        }
        if ((int) $into['dropper_id'] !== $by) {
            return 0;
        }
        return max(0, min($n, self::pileCap($c) - (int) $into['count']));
    }

    /** The most one pile holds on a house or Tower floor (the most a drop or a take moves, call and part). */
    public const PILE_MAX = 9999;

    /**
     * How many of class `c` one pile on a house or Tower floor holds (floors, shelves and tables): PILE_MAX
     * for every class, except one whose copies keep their own identity, which never joins (1): a tool (its
     * points) or a class whose copies carry words (its tag kind; the game's keepsOwnStack classes, a Still
     * and a Teleportation Script, are tagged). A drop that would pass it joins up to it, the rest beside it.
     * ours: Austin 2026-11-24, "Let's remove the pile limits": piles at home have no limit for any class;
     * Glitch's stackmax capped every pile (Eleven Location.addItem 475-503: emblems 1, moonstones 3). The
     * pack keeps Glitch's stackmax. The game (js/world-items.js pileMaxOf) and the realtime server
     * (drops.go pileMax) apply the same rule.
     */
    public static function pileCap(string $c): int
    {
        $economy = Economy::shared();
        if ($economy->points($c) > 0 || $economy->tagKind($c) !== '') {
            return 1;
        }
        return self::PILE_MAX;
    }

    /**
     * Whether a take's n and from are none (the whole stack) or a part of it: a pickup of
     * 1 <= n < from <= 9999.
     */
    public static function part(string $why, mixed $n, mixed $from): bool
    {
        if ($n === null && $from === null) {
            return true;
        }
        return $why === 'pickup' && is_int($n) && is_int($from) && $n >= 1 && $n < $from && $from <= 9999;
    }

    /**
     * A drop settled once per op (the transaction is the caller's: Ledger::atomically).
     *
     * @return array{status: string, why?: string, text?: string}
     */
    public static function settle(Db $db, Ledger $ledger, array $drop, int $now): array
    {
        $t = $db->table(Ledger::TRADES);
        $drops = $db->table(self::TABLE);
        $held = $db->row("SELECT status FROM {$t} WHERE trade_id = ? FOR UPDATE", [$drop['op']]);
        if ($held !== null) {
            if ($held['status'] !== 'settled') {
                return ['status' => 'refused', 'why' => 'short', 'text' => self::TEXT_SHORT];
            }
            // As recorded: the part that joined a like stack, and what lies on its own.
            $mine = $db->row("SELECT count, status, merged_into FROM {$drops} WHERE id = ?", [$drop['op']]);
            if ($mine !== null && $mine['merged_into'] !== null) {
                $in = $db->value("SELECT count FROM {$drops} WHERE id = ?", [(string) $mine['merged_into']]);
                $rest = $mine['status'] === 'ground' ? (int) $mine['count'] : 0;
                return ['status' => 'settled', 'into' => (string) $mine['merged_into'], 'joined' => $drop['n'] - $rest, 'in' => (int) $in, 'n' => $rest];
            }
            return ['status' => 'settled'];
        }
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $gave = self::goodsJson($drop['c'], $drop['n']);
        if (!Economy::shared()->tradeable($drop['c'])) {
            $ledger->log($drop['id'], 'refuse', ['drop' => $drop['op'], 'why' => 'type', 'c' => $drop['c']], $now);
            return ['status' => 'refused', 'why' => 'type', 'text' => self::TEXT_TYPE];
        }
        $state = $ledger->lock($drop['id'], $now);
        if (($state['items'][$drop['c']] ?? 0) < $drop['n']) {
            // Recorded void, so asking again answers the same.
            $db->execute("INSERT INTO {$t} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'void', ?, 0, ?, ?, NULL, ?)", [$drop['op'], $drop['id'], $gave, self::NONE, $stamp]);
            $ledger->log($drop['id'], 'refuse', ['drop' => $drop['op'], 'why' => 'short', 'c' => $drop['c'], 'n' => $drop['n']], $now);
            return ['status' => 'refused', 'why' => 'short', 'text' => self::TEXT_SHORT];
        }
        self::move($db, $drop['id'], $state, $drop['c'], -$drop['n'], $now);
        $db->execute("INSERT INTO {$t} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$drop['op'], $drop['id'], $gave, self::NONE, $stamp, $stamp]);
        // Onto a like stack on a house or Tower floor (takeable_drop's apiPutItemIntoPosition merges): as many
        // as it takes join it, in this same transaction; the rest lies on its own. The drop keeps a row of its
        // own either way (the trade's stack: GET groddle/v1/drops and catchUp find it), 'merged' when all joined.
        $joined = 0;
        $into = null;
        if (isset($drop['into'])) {
            $into = $db->row("SELECT status, street, class_tsid, count, points, tag, dropper_id FROM {$drops} WHERE id = ? FOR UPDATE", [$drop['into']]);
            $joined = self::joins($into, $drop['street'], $drop['c'], $drop['n'], $drop['r'], $drop['t'] ?? null, $drop['id']);
        }
        $rest = $drop['n'] - $joined;
        $db->execute("INSERT INTO {$drops} (id, dropper_id, street, x, y, class_tsid, count, points, tag, status, created_at, taken_at, why, merged_into) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $drop['op'], $drop['id'], $drop['street'], $drop['x'], $drop['y'], $drop['c'], $rest, $drop['r'], $drop['t'] ?? null,
            $rest > 0 ? 'ground' : 'merged', $stamp, $rest > 0 ? null : $stamp, $rest > 0 ? '' : 'merged', $joined > 0 ? $drop['into'] : null,
        ]);
        $ledger->log($drop['id'], 'drop', ['drop' => $drop['op'], 'street' => $drop['street'], 'gave' => [$drop['c'] => $drop['n']]] + ($joined > 0 ? ['into' => $drop['into'], 'joined' => $joined] : []), $now);
        if ($joined < 1) {
            return ['status' => 'settled'];
        }
        $in = self::grow($db, (string) $drop['into'], (int) $into['count'], $joined);
        return ['status' => 'settled', 'into' => (string) $drop['into'], 'joined' => $joined, 'in' => $in, 'n' => $rest];
    }

    /**
     * A like stack takes `$joined` more (its row locked): its count rises, so its generation moves on and
     * a part taken from it from now on has a new id (takePart: <op>-t<from>-g<gen>); an id recorded before
     * the rise is never answered as recorded for a new take. Returns its count now.
     */
    private static function grow(Db $db, string $id, int $had, int $joined): int
    {
        $db->execute('UPDATE ' . $db->table(self::TABLE) . ' SET count = ?, gen = gen + 1 WHERE id = ?', [$had + $joined, $id]);
        return $had + $joined;
    }

    /**
     * A floor-to-floor move in a house or on a Tower floor (doModifyItem, main.js 4852-4890: the owner moves
     * any stack in their house, merging into a like stack it is set on): the realtime server's op once per
     * move ("move-<16 hex>", recorded on the moved row). The stack stays where everyone can take it, with its
     * own dropper; onto `into` as many as it takes join it (joins, grow) and the rest is set down at (x, y).
     * The transaction is the caller's (Ledger::atomically).
     *
     * @param array{op: string, id: int, drop: string, street: string, x: int, y: int, into?: string} $m
     * @return array{status: string, why?: string, n?: int, x?: int, y?: int, into?: string, joined?: int, in?: int}
     */
    public static function moveStack(Db $db, Ledger $ledger, array $m, int $now): array
    {
        $drops = $db->table(self::TABLE);
        $owner = self::floorOwner($m['street']);
        if ($owner === 0 || $owner !== $m['id']) {
            return ['status' => 'refused', 'why' => 'place'];
        }
        // Both rows locked in one order, so two moves at once never wait on each other.
        $ids = isset($m['into']) ? [$m['drop'], $m['into']] : [$m['drop']];
        sort($ids);
        $rows = [];
        foreach ($ids as $id) {
            $rows[$id] = $db->row("SELECT id, status, street, x, y, class_tsid, count, points, tag, dropper_id, merged_into, moved_op FROM {$drops} WHERE id = ? FOR UPDATE", [$id]);
        }
        $row = $rows[$m['drop']];
        if ($row === null) {
            return ['status' => 'refused', 'why' => 'gone'];
        }
        $into = isset($m['into']) ? $rows[$m['into']] : null;
        if ($row['moved_op'] === $m['op']) {
            // As recorded.
            $left = $row['status'] === 'ground' ? (int) $row['count'] : 0;
            $out = ['status' => 'settled', 'n' => $left, 'x' => (int) $row['x'], 'y' => (int) $row['y']];
            if ($row['merged_into'] !== null && $into !== null && (string) $row['merged_into'] === $m['into']) {
                $out += ['into' => $m['into'], 'in' => (int) $into['count']];
            }
            return $out;
        }
        if ($row['status'] !== 'ground' || (string) $row['street'] !== $m['street']) {
            return ['status' => 'refused', 'why' => 'gone'];
        }
        $n = (int) $row['count'];
        $tag = $row['tag'] ?? null;
        $joined = $into === null ? 0 : self::joins($into, $m['street'], (string) $row['class_tsid'], $n, $row['points'] === null ? null : (int) $row['points'], $tag === null ? null : (string) $tag, (int) $row['dropper_id']);
        $left = $n - $joined;
        $stamp = gmdate('Y-m-d H:i:s', $now);
        if ($left > 0) {
            // A count that falls needs no new generation: an earlier part's id was taken from more.
            $db->execute("UPDATE {$drops} SET x = ?, y = ?, count = ?, moved_op = ?" . ($joined > 0 ? ', merged_into = ?' : '') . ' WHERE id = ?', array_merge([$m['x'], $m['y'], $left, $m['op']], $joined > 0 ? [$m['into']] : [], [$m['drop']]));
        } else {
            $db->execute("UPDATE {$drops} SET count = 0, status = 'merged', why = 'merged', taken_at = ?, merged_into = ?, moved_op = ? WHERE id = ?", [$stamp, $m['into'], $m['op'], $m['drop']]);
        }
        $out = ['status' => 'settled', 'n' => $left, 'x' => $left > 0 ? $m['x'] : (int) $row['x'], 'y' => $left > 0 ? $m['y'] : (int) $row['y']];
        if ($joined > 0) {
            $out += ['into' => $m['into'], 'joined' => $joined, 'in' => self::grow($db, $m['into'], (int) $into['count'], $joined)];
        }
        $ledger->log($m['id'], 'move', ['drop' => $m['drop'], 'move' => $m['op'], 'street' => $m['street'], 'x' => $m['x'], 'y' => $m['y']] + ($joined > 0 ? ['into' => $m['into'], 'joined' => $joined] : []), $now);
        return $out;
    }

    /** A move as the realtime server sends it, checked: null when it is not one. */
    public static function moveCall(array $body): ?array
    {
        $op = $body['op'] ?? null;
        $id = $body['id'] ?? null;
        $drop = $body['drop'] ?? null;
        $street = $body['street'] ?? null;
        $x = $body['x'] ?? null;
        $y = $body['y'] ?? null;
        if (!is_string($op) || preg_match('/^move-[0-9a-f]{16}$/', $op) !== 1 || !is_int($id) || $id <= 0 || !self::opId($drop) || !is_string($street) || self::floorOwner($street) === 0
            || !is_int($x) || !is_int($y) || abs($x) > 1000000 || abs($y) > 1000000) {
            return null;
        }
        $out = ['op' => $op, 'id' => $id, 'drop' => $drop, 'street' => $street, 'x' => $x, 'y' => $y];
        $into = $body['into'] ?? null;
        if (self::opId($into) && $into !== $drop) {
            $out += ['into' => $into];
        }
        return $out;
    }

    /**
     * G1224 (HouseFloors): an old floor stack of the owner's save moved to the shared floor of their
     * house or Tower, as a drop of theirs already applied (the save loses it in the same transaction:
     * the caller takes the row out of the stored save). Recorded once per op: true when it is on the
     * ground now (or was moved before), false when the owner's ledger lacks it (it stays in the save).
     * The transaction is the caller's (Ledger::atomically).
     *
     * @param array{op: string, street: string, x: int, y: int, c: string, n: int, r: ?int, t: ?string, at: int} $m
     */
    public static function fromFloor(Db $db, Ledger $ledger, array $m, int $user, int $now): bool
    {
        $t = $db->table(Ledger::TRADES);
        $held = $db->row("SELECT status FROM {$t} WHERE trade_id = ? FOR UPDATE", [$m['op']]);
        if ($held !== null) {
            return $held['status'] === 'settled';
        }
        if (!Economy::shared()->tradeable($m['c'])) {
            return false;
        }
        $state = $ledger->lock($user, $now);
        if (($state['items'][$m['c']] ?? 0) < $m['n']) {
            return false;
        }
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $at = $m['at'] > 0 && $m['at'] <= $now ? gmdate('Y-m-d H:i:s', $m['at']) : $stamp;
        self::move($db, $user, $state, $m['c'], -$m['n'], $now);
        // Applied as it is made: the stored save loses the row in this same transaction.
        $db->execute("INSERT INTO {$t} (trade_id, status, a_id, b_id, a_gave, b_gave, a_applied_at, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?, ?)", [$m['op'], $user, self::goodsJson($m['c'], $m['n']), self::NONE, $stamp, $stamp, $stamp]);
        $db->execute('INSERT INTO ' . $db->table(self::TABLE) . " (id, dropper_id, street, x, y, class_tsid, count, points, tag, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ground', ?)", [
            $m['op'], $user, $m['street'], $m['x'], $m['y'], $m['c'], $m['n'], $m['r'], $m['t'], $at,
        ]);
        $ledger->log($user, 'drop', ['drop' => $m['op'], 'street' => $m['street'], 'gave' => [$m['c'] => $m['n']], 'from' => 'floor'], $now);
        return true;
    }

    /**
     * G1224 (HouseFloors::revert, and #670 HouseFloors::returnKept, why 'kept'): a moved floor stack
     * still on the ground goes back to its owner's ledger (the caller puts the row back in the stored
     * save, in this same transaction), recorded once as <op>-r, applied as it is made. False when it is
     * no longer on the ground (picked up, eaten).
     */
    public static function backToFloor(Db $db, Ledger $ledger, string $op, int $user, int $now, string $why = 'revert'): bool
    {
        $drops = $db->table(self::TABLE);
        $row = $db->row("SELECT class_tsid, count, status, dropper_id FROM {$drops} WHERE id = ? FOR UPDATE", [$op]);
        if ($row === null || $row['status'] !== 'ground' || (int) $row['dropper_id'] !== $user) {
            return false;
        }
        $c = (string) $row['class_tsid'];
        $n = (int) $row['count'];
        $state = $ledger->lock($user, $now);
        self::move($db, $user, $state, $c, $n, $now);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $db->execute("UPDATE {$drops} SET status = 'reverted', taker_id = ?, why = ?, taken_at = ? WHERE id = ?", [$user, $why, $stamp, $op]);
        $db->execute('INSERT IGNORE INTO ' . $db->table(Ledger::TRADES) . " (trade_id, status, a_id, b_id, a_gave, b_gave, a_applied_at, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?, ?)", [$op . '-r', $user, self::NONE, self::goodsJson($c, $n), $stamp, $stamp, $stamp]);
        $ledger->log($user, 'credit', ['trade' => $op . '-r', 'got' => [$c => $n], 'from' => 'floor ' . $why], $now);
        return true;
    }

    /**
     * A stack the street made, placed once per op under the player whose act made it (no ledger
     * gives it up). The transaction is the caller's (Ledger::atomically).
     *
     * @return array{status: string, why?: string, text?: string}
     */
    public static function put(Db $db, Ledger $ledger, array $drop, int $now): array
    {
        $drops = $db->table(self::TABLE);
        if ($db->row("SELECT id FROM {$drops} WHERE id = ? FOR UPDATE", [$drop['op']]) !== null) {
            return ['status' => 'settled'];
        }
        if (!Economy::shared()->tradeable($drop['c'])) {
            return ['status' => 'refused', 'why' => 'type', 'text' => self::TEXT_TYPE];
        }
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $db->execute("INSERT INTO {$drops} (id, dropper_id, street, x, y, class_tsid, count, points, tag, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ground', ?)", [
            $drop['op'], $drop['id'], $drop['street'], $drop['x'], $drop['y'], $drop['c'], $drop['n'], $drop['r'], $drop['t'] ?? null, $stamp,
        ]);
        $ledger->log($drop['id'], 'place', ['drop' => $drop['op'], 'street' => $drop['street'], 'placed' => [$drop['c'] => $drop['n']]], $now);
        return ['status' => 'settled'];
    }

    /**
     * A take of a stack on the ground: the whole of it ($n null), or $n of the $from the realtime
     * server holds (takePart). The transaction is the caller's (Ledger::atomically).
     *
     * @return array{status: string, op?: string, item?: array, why?: string, by?: int, n?: int}
     */
    public static function give(Db $db, Ledger $ledger, string $op, int $id, string $why, int $now, ?int $n = null, ?int $from = null): array
    {
        $drops = $db->table(self::TABLE);
        $row = $db->row("SELECT dropper_id, class_tsid, count, points, tag, status, taker_id, gen FROM {$drops} WHERE id = ? FOR UPDATE", [$op]);
        if ($row === null) {
            return ['status' => 'refused', 'why' => 'gone'];
        }
        $item = ['c' => (string) $row['class_tsid'], 'n' => (int) $row['count']];
        if ($row['points'] !== null) {
            $item['r'] = (int) $row['points'];
        }
        // Whoever takes it gets the stack's own words, never a picker's.
        if (isset($row['tag']) && ($t = CopyTags::decode($row['tag'])) !== null && $item['n'] === 1) {
            $item['t'] = $t;
        }
        if ($n !== null && $from !== null) {
            return self::takePart($db, $ledger, $op, $row, $item, $id, $n, $from, $now);
        }
        $credit = $op . '-t';
        if ($row['status'] !== 'ground') {
            return (int) $row['taker_id'] === $id ? ['status' => 'settled', 'op' => $credit, 'item' => $item] : ['status' => 'refused', 'why' => 'gone', 'by' => (int) $row['taker_id']];
        }
        if ($why === 'return' && (int) $row['dropper_id'] !== $id) {
            return ['status' => 'refused', 'why' => 'gone', 'by' => 0];
        }
        $state = $ledger->lock($id, $now);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        self::move($db, $id, $state, $item['c'], $item['n'], $now);
        $db->execute('INSERT IGNORE INTO ' . $db->table(Ledger::TRADES) . " (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$credit, $id, self::NONE, self::goodsJson($item['c'], $item['n']), $stamp, $stamp]);
        $db->execute("UPDATE {$drops} SET status = 'taken', taker_id = ?, why = ?, taken_at = ? WHERE id = ?", [$id, $why, $stamp, $op]);
        $ledger->log($id, $why, ['drop' => $op, 'from' => (int) $row['dropper_id'], 'took' => [$item['c'] => $item['n']]], $now);
        return ['status' => 'settled', 'op' => $credit, 'item' => $item];
    }

    /**
     * $n of a stack the realtime server says holds $from (the row is locked). The credit
     * <op>-t<from> is recorded once: asked again for the same taker it answers as recorded,
     * for anyone else the count they saw is gone. $item is the stack's (class, wear).
     *
     * @return array{status: string, op?: string, item?: array, why?: string, by?: int, n?: int}
     */
    private static function takePart(Db $db, Ledger $ledger, string $op, array $row, array $item, int $id, int $n, int $from, int $now): array
    {
        $t = $db->table(Ledger::TRADES);
        $credit = self::partId($op, $from, (int) ($row['gen'] ?? 0));
        $left = $row['status'] === 'ground' ? (int) $row['count'] : 0;
        unset($item['t']); // a copy's own words ride only with a stack of one, never a part
        $held = $db->row("SELECT a_id, b_gave FROM {$t} WHERE trade_id = ? FOR UPDATE", [$credit]);
        if ($held !== null) {
            return (int) $held['a_id'] === $id
                ? ['status' => 'settled', 'op' => $credit, 'item' => ['n' => self::movedCount((string) $held['b_gave'], $n)] + $item, 'n' => $left]
                : ['status' => 'refused', 'why' => 'stale', 'n' => $left];
        }
        if ($row['status'] !== 'ground') {
            return ['status' => 'refused', 'why' => 'gone', 'by' => (int) $row['taker_id']];
        }
        if ($left !== $from) {
            return ['status' => 'refused', 'why' => 'stale', 'n' => $left];
        }
        $state = $ledger->lock($id, $now);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        self::move($db, $id, $state, $item['c'], $n, $now);
        $db->execute("INSERT INTO {$t} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$credit, $id, self::NONE, self::goodsJson($item['c'], $n), $stamp, $stamp]);
        $db->execute('UPDATE ' . $db->table(self::TABLE) . ' SET count = ? WHERE id = ?', [$from - $n, $op]);
        $ledger->log($id, 'pickup', ['drop' => $op, 'from' => (int) $row['dropper_id'], 'took' => [$item['c'] => $n], 'left' => $from - $n], $now);
        return ['status' => 'settled', 'op' => $credit, 'item' => ['n' => $n] + $item, 'n' => $from - $n];
    }

    // —— The player ——

    /** The signed-in player's drops and takes their save has not applied, oldest first (a missed answer). */
    #[Route(Method::Get, '/groddle/v1/drops', policy: new Policy(Access::SignedIn))]
    public function mine(Request $request): Response
    {
        return Response::json(['drops' => self::unapplied($this->minn->db, $this->minn->reader->userId, time())]);
    }

    /**
     * A character's settled drops and takes their save has not applied, oldest first: a drop's op
     * is the stack's id, a take's that id and -t, a part's -t and the count it was taken from.
     *
     * @return list<array{op: string, side: string, id: string, item: array, why?: string}>
     */
    public static function unapplied(Db $db, int $me, int $now): array
    {
        $since = gmdate('Y-m-d H:i:s', $now - Ledger::PENDING_FOR);
        $rows = $db->rows('SELECT t.trade_id, t.a_gave, t.b_gave, d.id, d.class_tsid, d.count, d.points, d.tag, d.why FROM ' . $db->table(Ledger::TRADES) . ' t JOIN ' . $db->table(self::TABLE) . " d ON d.id = LEFT(t.trade_id, 21)
            WHERE t.status = 'settled' AND t.a_id = ? AND t.a_applied_at IS NULL AND t.created_at >= ? AND t.trade_id LIKE 'drop-%'
            ORDER BY t.created_at, t.trade_id LIMIT 40", [$me, $since]);
        $out = [];
        foreach ($rows as $r) {
            $trade = (string) $r['trade_id'];
            $took = self::isTake($trade);
            if (!$took && $trade !== (string) $r['id']) {
                continue;
            }
            $part = $took && !str_ends_with($trade, '-t');
            // What moved is the trade's: a Piggy may have eaten some of a stack since it was dropped.
            $item = ['c' => (string) $r['class_tsid'], 'n' => self::movedCount((string) ($took ? $r['b_gave'] : $r['a_gave']), (int) $r['count'])];
            if ($r['points'] !== null) {
                $item['r'] = (int) $r['points'];
            }
            if (!$part && isset($r['tag']) && ($t = CopyTags::decode($r['tag'])) !== null && $item['n'] === 1) {
                $item['t'] = $t;
            }
            $entry = ['op' => $trade, 'side' => $took ? 'took' : 'dropped', 'id' => (string) $r['id'], 'item' => $item];
            if ($took) {
                // A part is always a pickup, whatever later became of the rest of the stack.
                $entry['why'] = $part ? 'pickup' : (string) $r['why'];
            }
            $out[] = $entry;
        }
        return $out;
    }

    // —— Helpers ——

    /** The count a drop's or a take's goods JSON (goodsJson) moved, or $else. */
    public static function movedCount(string $goods, int $else): int
    {
        $g = json_decode($goods, true);
        $n = is_array($g) ? ($g['items'][0]['n'] ?? null) : null;
        return is_int($n) && $n > 0 ? $n : $else;
    }

    /**
     * A take of a stack on the ground, as Ledger::catchUp sees its trade id: drop-<16 hex>-t for
     * the whole of it, -t<from> for a part (takePart), -t<from>-g<gen> for a part of a stack that a
     * merge has grown (its count rose, so the same count can come round again: grow).
     */
    public static function isTake(string $trade): bool
    {
        return preg_match('/^drop-[0-9a-f]{16}-t(?:[1-9][0-9]{0,3}(?:-g[1-9][0-9]{0,8})?)?$/', $trade) === 1;
    }

    /** A part's credit id: <op>-t<from>, and -g<gen> once merges have grown the stack (gen > 0). */
    public static function partId(string $op, int $from, int $gen): string
    {
        return $op . '-t' . $from . ($gen > 0 ? '-g' . $gen : '');
    }

    /**
     * Ledger::catchUp for a take: the stack into the pack (LedgerMath::applyTrade) and, for a
     * tool, its wear (the stack's points), as the game applies one (features/drops-mp-feature.js
     * apply: the tools picked up take the stack's points, the ones held keep their own;
     * systems.tools.condition, js/tools.js receiveToolCopy).
     *
     * @param array{trade: string, gave: array, got: array} $p
     */
    public static function catchUp(Db $db, array $entries, int $user, array $p): array
    {
        $row = $db->row('SELECT class_tsid, count, points, tag FROM ' . $db->table(self::TABLE) . ' WHERE id = ?', [substr($p['trade'], 0, 21)]);
        $class = $row === null ? '' : (string) $row['class_tsid'];
        $had = self::packCount($entries, $class);
        $entries = LedgerMath::applyTrade($entries, $p['trade'], $p['gave'], $p['got']);
        if ($row === null || $row['points'] === null) {
            return $entries;
        }
        $tag = (int) $row['count'] === 1 && isset($row['tag']) && str_ends_with($p['trade'], '-t') ? CopyTags::decode($row['tag']) : null;
        return self::withToolPoints($entries, $class, (int) $row['points'], $had, max(1, self::packCount($entries, $class) - $had), $tag);
    }

    private static function packCount(array $entries, string $class): int
    {
        $raw = $entries['playerState'] ?? null;
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return LedgerMath::count(is_array($state) ? ($state['inventory'][$class] ?? 0) : 0);
    }

    /**
     * A stored save's tools after a take (systems.tools.condition[class], js/tools.js): each tool
     * held keeps its own wear in `copies` (pack order), the $had held before keep theirs and the
     * $n picked up are added last with the stack's $points. An older save's one value per class
     * is the first tool's; a held tool with no value of its own is new (null: full). `remaining`
     * and `broken` repeat the tool in use, the first with points left (tool_base isWorking).
     * $tag: the one copy's own words (CopyTags), kept in `tags` beside `copies` (js/tools.js row.tags).
     */
    public static function withToolPoints(array $entries, string $class, int $points, int $had, int $n = 1, mixed $tag = null): array
    {
        $raw = $entries['playerState'] ?? null;
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, false) : null;
        if (!$state instanceof \stdClass) {
            return $entries;
        }
        $at = $state;
        foreach (['systems', 'tools', 'condition'] as $k) {
            if (!($at->{$k} ?? null) instanceof \stdClass) {
                $at->{$k} = new \stdClass();
            }
            $at = $at->{$k};
        }
        $cond = $at;
        $row = ($cond->{$class} ?? null) instanceof \stdClass ? $cond->{$class} : null;
        if ($row !== null && is_array($row->copies ?? null)) {
            $copies = array_map(static fn ($r) => is_numeric($r) ? max(0, (int) $r) : null, array_values($row->copies));
        } elseif ($row !== null && is_numeric($row->remaining ?? null)) {
            $copies = [($row->broken ?? false) === true ? 0 : max(0, (int) $row->remaining)];
        } else {
            $copies = [];
        }
        $copies = array_slice($copies, 0, max(0, $had));
        while (count($copies) < $had) {
            $copies[] = null;
        }
        for ($i = 0; $i < max(1, $n); $i++) {
            $copies[] = max(0, $points);
        }
        $inUse = null;
        foreach ($copies as $r) {
            if ($r === null || $r >= 1) {
                $inUse = $r;
                break;
            }
        }
        $first = $inUse ?? $copies[0];
        if ($row === null) {
            $row = (object) ['uses' => 0, 'repairs' => 0];
        }
        $row->copies = $copies;
        if ($tag !== null || is_array($row->tags ?? null)) {
            $tags = array_slice(is_array($row->tags ?? null) ? array_values($row->tags) : [], 0, max(0, $had));
            while (count($tags) < $had) {
                $tags[] = null;
            }
            for ($i = 0; $i < max(1, $n); $i++) {
                $tags[] = $n === 1 ? $tag : null;
            }
            $row->tags = $tags;
        }
        if ($first !== null) {
            $row->remaining = $first;
            $row->broken = $first <= 0;
        } else {
            unset($row->remaining);
            $row->broken = false;
        }
        $cond->{$class} = $row;
        $entries['playerState'] = (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $entries;
    }

    /** drops.go: drop- and 16 hex. */
    public static function opId(mixed $op): bool
    {
        return is_string($op) && preg_match('/^drop-[0-9a-f]{16}$/', $op) === 1;
    }

    /** Moves one class in a character's ledger by $by (the row is locked: Ledger::lock). */
    private static function move(Db $db, int $user, array $state, string $class, int $by, int $now): void
    {
        $count = ($state['items'][$class] ?? 0) + $by;
        $items = $db->table(Ledger::ITEMS);
        $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET updated_at = ? WHERE user_id = ?', [gmdate('Y-m-d H:i:s', $now), $user]);
        if ($count <= 0) {
            $db->execute("DELETE FROM {$items} WHERE user_id = ? AND class_tsid = ?", [$user, $class]);
        } else {
            $db->execute("INSERT INTO {$items} (user_id, class_tsid, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)", [$user, $class, $count]);
        }
    }

    private static function goodsJson(string $c, int $n): string
    {
        return (string) json_encode(['items' => [['c' => $c, 'n' => $n]], 'currants' => 0], JSON_UNESCAPED_SLASHES);
    }

    private function signed(Request $request, string $route): void
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', $route, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
    }
}
