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
 *   POST groddle/v1/ledger/drops      (signed) {}  → {"stacks":[{id, by, name, street, x, y, c, n, r?, at}]}
 *        every stack on the ground, for a realtime server that has just started.
 *
 *   GET  groddle/v1/drops             the signed-in player's drops and takes their save has not applied
 *        {"drops":[{"op","side":"dropped"|"took","id","item":{c,n,r?},"why"?}]}
 *
 * Each save applies a drop or a take through the trade slice's applied list
 * (features/drops-mp-feature.js, ledgerReceipt), and until it does a write
 * is compared as if it had not happened; a load applies it to the stored
 * save (Ledger::catchUp, a plain stack in or out of the pack).
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

    /** Stacks one list carries (drops.go holds at most 60 a street). */
    public const LIST_MAX = 5000;

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
        $out = Ledger::atomically($this->minn->db, fn () => $this->settle($drop, time()));
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
        if (!self::opId($op) || !is_int($id) || $id <= 0 || !in_array($why, ['pickup', 'return'], true)) {
            throw new RestError('groddle_ledger_invalid', 'Not a pickup.', 400);
        }
        $out = Ledger::atomically($this->minn->db, fn () => $this->give($op, $id, $why, time()));
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
        $rows = $db->rows('SELECT id, dropper_id, street, x, y, class_tsid, count, points, created_at FROM ' . $db->table(self::TABLE) . " WHERE status = 'ground' ORDER BY created_at, id LIMIT " . self::LIST_MAX);
        $names = (new Characters($db))->namesOf(array_values(array_unique(array_map(static fn (array $r): int => (int) $r['dropper_id'], $rows))));
        $out = [];
        foreach ($rows as $r) {
            $by = (int) $r['dropper_id'];
            $row = ['id' => (string) $r['id'], 'by' => $by, 'name' => $names[$by] ?? 'Someone', 'street' => (string) $r['street'], 'x' => (int) $r['x'], 'y' => (int) $r['y'],
                'c' => (string) $r['class_tsid'], 'n' => (int) $r['count'], 'at' => (int) strtotime((string) $r['created_at'] . ' UTC')];
            if ($r['points'] !== null) {
                $row['r'] = (int) $r['points'];
            }
            $out[] = $row;
        }
        return Response::json(['stacks' => $out]);
    }

    /**
     * A drop as the realtime server sends it, checked: null when it is not one.
     *
     * @return array{op: string, id: int, street: string, x: int, y: int, c: string, n: int, r: ?int}|null
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
        return ['op' => $op, 'id' => $id, 'street' => $street, 'x' => $x, 'y' => $y, 'c' => $c, 'n' => $n, 'r' => $r];
    }

    /** @return array{status: string, why?: string, text?: string} */
    private function settle(array $drop, int $now): array
    {
        $db = $this->minn->db;
        $t = $db->table(Ledger::TRADES);
        $held = $db->row("SELECT status FROM {$t} WHERE trade_id = ? FOR UPDATE", [$drop['op']]);
        if ($held !== null) {
            return $held['status'] === 'settled' ? ['status' => 'settled'] : ['status' => 'refused', 'why' => 'short', 'text' => self::TEXT_SHORT];
        }
        $ledger = Ledger::fromSeams($this->minn);
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
        $db->execute('INSERT INTO ' . $db->table(self::TABLE) . " (id, dropper_id, street, x, y, class_tsid, count, points, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ground', ?)", [
            $drop['op'], $drop['id'], $drop['street'], $drop['x'], $drop['y'], $drop['c'], $drop['n'], $drop['r'], $stamp,
        ]);
        $ledger->log($drop['id'], 'drop', ['drop' => $drop['op'], 'street' => $drop['street'], 'gave' => [$drop['c'] => $drop['n']]], $now);
        return ['status' => 'settled'];
    }

    /** @return array{status: string, op?: string, item?: array, why?: string, by?: int} */
    private function give(string $op, int $id, string $why, int $now): array
    {
        $db = $this->minn->db;
        $drops = $db->table(self::TABLE);
        $row = $db->row("SELECT dropper_id, class_tsid, count, points, status, taker_id FROM {$drops} WHERE id = ? FOR UPDATE", [$op]);
        if ($row === null) {
            return ['status' => 'refused', 'why' => 'gone'];
        }
        $item = ['c' => (string) $row['class_tsid'], 'n' => (int) $row['count']];
        if ($row['points'] !== null) {
            $item['r'] = (int) $row['points'];
        }
        $credit = $op . '-t';
        if ($row['status'] !== 'ground') {
            return (int) $row['taker_id'] === $id ? ['status' => 'settled', 'op' => $credit, 'item' => $item] : ['status' => 'refused', 'why' => 'gone', 'by' => (int) $row['taker_id']];
        }
        if ($why === 'return' && (int) $row['dropper_id'] !== $id) {
            return ['status' => 'refused', 'why' => 'gone', 'by' => 0];
        }
        $ledger = Ledger::fromSeams($this->minn);
        $state = $ledger->lock($id, $now);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        self::move($db, $id, $state, $item['c'], $item['n'], $now);
        $db->execute('INSERT IGNORE INTO ' . $db->table(Ledger::TRADES) . " (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$credit, $id, self::NONE, self::goodsJson($item['c'], $item['n']), $stamp, $stamp]);
        $db->execute("UPDATE {$drops} SET status = 'taken', taker_id = ?, why = ?, taken_at = ? WHERE id = ?", [$id, $why, $stamp, $op]);
        $ledger->log($id, $why, ['drop' => $op, 'from' => (int) $row['dropper_id'], 'took' => [$item['c'] => $item['n']]], $now);
        return ['status' => 'settled', 'op' => $credit, 'item' => $item];
    }

    // —— The player ——

    /** The signed-in player's drops and takes their save has not applied, oldest first (a missed answer). */
    #[Route(Method::Get, '/groddle/v1/drops', policy: new Policy(Access::SignedIn))]
    public function mine(Request $request): Response
    {
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        $since = gmdate('Y-m-d H:i:s', time() - Ledger::PENDING_FOR);
        $rows = $db->rows('SELECT t.trade_id, t.a_gave, t.b_gave, d.id, d.class_tsid, d.count, d.points, d.why FROM ' . $db->table(Ledger::TRADES) . ' t JOIN ' . $db->table(self::TABLE) . " d ON (t.trade_id = d.id OR t.trade_id = CONCAT(d.id, '-t'))
            WHERE t.status = 'settled' AND t.a_id = ? AND t.a_applied_at IS NULL AND t.created_at >= ? AND t.trade_id LIKE 'drop-%'
            ORDER BY t.created_at, t.trade_id LIMIT 40", [$me, $since]);
        $out = [];
        foreach ($rows as $r) {
            $took = str_ends_with((string) $r['trade_id'], '-t');
            // What moved is the trade's: a Piggy may have eaten some of a stack since it was dropped.
            $item = ['c' => (string) $r['class_tsid'], 'n' => self::movedCount((string) ($took ? $r['b_gave'] : $r['a_gave']), (int) $r['count'])];
            if ($r['points'] !== null) {
                $item['r'] = (int) $r['points'];
            }
            $entry = ['op' => (string) $r['trade_id'], 'side' => $took ? 'took' : 'dropped', 'id' => (string) $r['id'], 'item' => $item];
            if ($took) {
                $entry['why'] = (string) $r['why'];
            }
            $out[] = $entry;
        }
        return Response::json(['drops' => $out]);
    }

    // —— Helpers ——

    /** The count a drop's or a take's goods JSON (goodsJson) moved, or $else. */
    public static function movedCount(string $goods, int $else): int
    {
        $g = json_decode($goods, true);
        $n = is_array($g) ? ($g['items'][0]['n'] ?? null) : null;
        return is_int($n) && $n > 0 ? $n : $else;
    }

    /** A take of a stack on the ground (drop-<16 hex>-t), as Ledger::catchUp sees its trade id. */
    public static function isTake(string $trade): bool
    {
        return preg_match('/^drop-[0-9a-f]{16}-t$/', $trade) === 1;
    }

    /**
     * Ledger::catchUp for a take: the stack into the pack (LedgerMath::applyTrade) and, for a
     * tool, its wear (the stack's points), as the game applies one (features/drops-mp-feature.js
     * apply: a first one takes the stack's points, one more keeps the more worn;
     * systems.tools.condition, js/tools.js setToolRemaining).
     *
     * @param array{trade: string, gave: array, got: array} $p
     */
    public static function catchUp(Db $db, array $entries, int $user, array $p): array
    {
        $row = $db->row('SELECT class_tsid, points FROM ' . $db->table(self::TABLE) . ' WHERE id = ?', [substr($p['trade'], 0, -2)]);
        $had = self::packCount($entries, $row === null ? '' : (string) $row['class_tsid']);
        $entries = LedgerMath::applyTrade($entries, $p['trade'], $p['gave'], $p['got']);
        if ($row === null || $row['points'] === null) {
            return $entries;
        }
        return self::withToolPoints($entries, (string) $row['class_tsid'], (int) $row['points'], $had > 0);
    }

    private static function packCount(array $entries, string $class): int
    {
        $raw = $entries['playerState'] ?? null;
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return LedgerMath::count(is_array($state) ? ($state['inventory'][$class] ?? 0) : 0);
    }

    /** A tool's points left in a stored save (systems.tools.condition[class]): $points, or the more worn when one was there. */
    public static function withToolPoints(array $entries, string $class, int $points, bool $hadOne): array
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
        $mine = $row !== null && is_numeric($row->remaining ?? null) ? (int) $row->remaining : null;
        $left = max(0, $hadOne && $mine !== null ? min($mine, $points) : $points);
        if ($row === null) {
            $row = (object) ['remaining' => $left, 'broken' => $left <= 0, 'uses' => 0, 'repairs' => 0];
        } else {
            $row->remaining = $left;
            $row->broken = $left <= 0;
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
