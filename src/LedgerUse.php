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
 * groddle/v1/ledger/use: the realtime server uses one charge of a jar or
 * bottle from a character's ledger before a shared act that needs it
 * (G335 slice 4; groddle-afternoon-server objects_uses.go): Fertilidust
 * grows a street's trees for everyone, its Lite one tree, Tree Poison kills
 * a street tree for everyone and its Antidote saves one. For the realtime
 * server only, signed with the realtime secret (ServiceCall):
 *
 *   POST groddle/v1/ledger/use  {"op":"use-<16 hex>","id":7,"c":"fertilidust"}
 *        200 {"status":"settled","left":4,"spent":false}   a charge used; left in the open one
 *        409 {"status":"refused","short":{"id":7,"c":"fertilidust"}}   the ledger holds none
 *
 * A jar or bottle has CHARGES uses (the source's maxCharges and
 * points_remaining). The ledger counts jars; the open one's charges left are
 * kept here per character and class (groddle_ledger_credited, kind KIND, ref
 * the class, n what is left; no row: a full one, or, before the first use
 * here, what the stored save's own slice says). The use that empties one
 * takes it out of the ledger and is recorded as a spend with nobody
 * (groddle_trades, the op as its id, b_id 0, as LedgerSpend records a Party
 * Pack), so until the save lists the op as applied (the trade slice's
 * applied list, which the game writes when the server's word comes) a save
 * write is compared as if the jar were still there, and a load applies it
 * (Ledger::catchUp, catchUp here). Idempotent per op: the answer is kept
 * with the op (groddle_ledger_outcomes, why "use") and asking again answers
 * as recorded.
 *
 * A save write lowers the kept charges to what the save's slice shows, and
 * forgets them once the save holds none of the class (observe), so a jar
 * used where nothing is shared (your own Patch, a home) is not used twice.
 *
 * Per copy (2026-11-04): each jar or bottle keeps its own charges, as Glitch
 * kept charges and points_remaining on each item, so a new one is full and two
 * of a kind keep their own. A use may name the copy, its place among the
 * class's copies in pack order ("k", 0 the first; left out, the one in use,
 * the first). The save keeps each copy's charges in the game's tool state
 * (systems.tools.condition[class].copies, js/tools.js); an older save's one
 * slice value is the first copy's. The kept rows are per copy too: ref the
 * class for the first, "<class>#<k>" for the others; a copy the use empties
 * leaves, and the kept rows after it move up one, as the pack's copies do. A
 * kept row a save write shows differently for more than KEPT_TRUST seconds
 * after the use takes the save's number (the game had the answer long
 * before; this follows a copy the player moved in the pack).
 *
 * Slice 5 (objects_pigs.go, rook.go, hi.go, objects_tools.go):
 *
 *   - More classes with charges: Sneezing and Sparkle Powder, and the Rook
 *     Balm (CLASSES).
 *   - Whole items: with "n" the use takes n of any tradeable class at once
 *     (food fed to a street Piggy: the server decides which foods sate one,
 *     from its data), recorded as an emptied jar is:
 *       {"op":"use-…","id":7,"c":"apple","n":2}  200 {"status":"settled","left":0,"spent":true,"n":2}
 *   - A lost answer is given back (the server calls this when its use got no
 *     answer, again until this answers; idempotent per op):
 *       POST groddle/v1/ledger/use/void  {"op":"use-…","id":7}
 *            200 {"status":"void"}     given back now or before, or it never
 *                                      came (a late copy then answers void and uses nothing)
 *            200 {"status":"settled"}  a load already applied it: it stays used
 *     A charge goes back into the open one; an emptied jar or whole items go
 *     back into the ledger and their spend row turns void.
 *   - Does the player hold one? For the tools behind shared takes (a pick, a
 *     Hatchet, a scraper, a shovel, a watering can), nothing used:
 *       POST groddle/v1/ledger/has  {"id":7,"c":["pick","fancy_pick",…]}
 *            200 {"status":"settled","has":["pick"]}
 */
final readonly class LedgerUse
{
    public const ROUTE = '/groddle/v1/ledger/use';
    public const VOID = '/groddle/v1/ledger/use/void';
    public const HAS = '/groddle/v1/ledger/has';
    public const KIND = 'uses';
    /** One has call names at most this many classes. */
    private const HAS_MAX = 32;
    /** The most copies of a class a use may name (k), and how long a kept row outranks the save. */
    public const K_MAX = 255;
    public const KEPT_TRUST = 120;

    /**
     * Each class a shared act uses: the charges a full one has, and the
     * game's save slice (systems.features.<slice>.<key>[class]) that keeps
     * the open one's.
     *   fertilidust, fertilidust_lite: maxCharges 5 (items/fertilidust.js:23,
     *     fertilidust_lite.js:23; powder_base use() 529-534, deleted at 0,
     *     491-492); js/features/powders-feature.js slice {charges}.
     *   potion_tree_poison(_antidote): points_remaining 5
     *     (potion_tree_poison.js:36; tool_base use(1), the fifth pour
     *     consumes it); js/features/tree-death-feature.js slice {uses}.
     *   sneezing_powder, sparkle_powder (slice 5): maxCharges 5
     *     (items/sneezing_powder.js:23, sparkle_powder.js:23; powder_base
     *     use(), deleted at 0); powders-feature slice {charges}.
     *   potion_rook_balm (slice 5): points_capacity 5
     *     (items/potion_rook_balm.js:25; tool_base use(1)); the open one's
     *     points are js/features/rook-attack-feature.js's slice balmPoints, one
     *     number for the class (the fourth field), 0 meaning none open.
     */
    public const CLASSES = [
        'fertilidust' => [5, 'powders', 'charges'],
        'fertilidust_lite' => [5, 'powders', 'charges'],
        'potion_tree_poison' => [5, 'tree-death', 'uses'],
        'potion_tree_poison_antidote' => [5, 'tree-death', 'uses'],
        'sneezing_powder' => [5, 'powders', 'charges'],
        'sparkle_powder' => [5, 'powders', 'charges'],
        'potion_rook_balm' => [5, 'rook-attack', 'balmPoints', true],
    ];

    private const NONE_JSON = '{"items":[],"currants":0}';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Post, self::ROUTE, policy: new Policy(Access::Public))]
    public function use(Request $request): Response
    {
        $this->signed($request, self::ROUTE);
        $body = json_decode($request->body, true);
        $op = is_array($body) ? ($body['op'] ?? null) : null;
        $id = is_array($body) ? ($body['id'] ?? null) : null;
        $class = is_array($body) ? ($body['c'] ?? null) : null;
        $n = is_array($body) ? ($body['n'] ?? 0) : 0;
        $k = is_array($body) ? ($body['k'] ?? null) : null;
        if (!self::isUse(is_string($op) ? $op : '') || !is_int($id) || $id <= 0 || !is_string($class) || !self::usable($class, $n)) {
            throw new RestError('groddle_ledger_invalid', 'Not a use.', 400);
        }
        if ($k !== null && (!is_int($k) || $k < 0 || $k > self::K_MAX)) {
            throw new RestError('groddle_ledger_invalid', 'Not a use.', 400);
        }
        $out = Ledger::atomically($this->minn->db, fn () => $this->once($op, $id, $class, time(), $n, $k));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    /** A charge of a class with charges (n 0), or n whole items of any tradeable class. */
    public static function usable(string $class, mixed $n): bool
    {
        if (!is_int($n) || $n < 0 || $n > 9999) {
            return false;
        }
        if ($n === 0) {
            return isset(self::CLASSES[$class]);
        }
        return preg_match('/^[a-z0-9_]{1,64}$/', $class) === 1 && Economy::shared()->tradeable($class);
    }

    #[Route(Method::Post, self::VOID, policy: new Policy(Access::Public))]
    public function void(Request $request): Response
    {
        $this->signed($request, self::VOID);
        $body = json_decode($request->body, true);
        $op = is_array($body) ? ($body['op'] ?? null) : null;
        $id = is_array($body) ? ($body['id'] ?? null) : null;
        if (!self::isUse(is_string($op) ? $op : '') || !is_int($id) || $id <= 0) {
            throw new RestError('groddle_ledger_invalid', 'Not a use.', 400);
        }
        return Response::json(Ledger::atomically($this->minn->db, fn () => $this->giveBack($op, $id, time())));
    }

    #[Route(Method::Post, self::HAS, policy: new Policy(Access::Public))]
    public function has(Request $request): Response
    {
        $this->signed($request, self::HAS);
        $body = json_decode($request->body, true);
        $id = is_array($body) ? ($body['id'] ?? null) : null;
        $classes = is_array($body) ? ($body['c'] ?? null) : null;
        if (!is_int($id) || $id <= 0 || !is_array($classes) || $classes === [] || count($classes) > self::HAS_MAX) {
            throw new RestError('groddle_ledger_invalid', 'Not a question.', 400);
        }
        foreach ($classes as $c) {
            if (!is_string($c) || preg_match('/^[a-z0-9_]{1,64}$/', $c) !== 1) {
                throw new RestError('groddle_ledger_invalid', 'Not a question.', 400);
            }
        }
        $now = time();
        $state = Ledger::atomically($this->minn->db, fn () => Ledger::fromSeams($this->minn)->lock($id, $now));
        return Response::json(['status' => 'settled', 'has' => self::held($state['items'] ?? [], $classes)]);
    }

    /**
     * Which of $classes the ledger's items hold one of at least, in the order asked.
     *
     * @param array<string, int> $items
     * @param list<string> $classes
     * @return list<string>
     */
    public static function held(array $items, array $classes): array
    {
        $out = [];
        foreach ($classes as $c) {
            if ((int) ($items[$c] ?? 0) > 0 && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }
        return $out;
    }

    private function signed(Request $request, string $route): void
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', $route, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
    }

    /** objects_uses.go newOp: use- and 16 hex. */
    public static function isUse(string $op): bool
    {
        return preg_match('/^use-[0-9a-f]{16}$/', $op) === 1;
    }

    /**
     * One use, the pure part: refused when the ledger holds none, else the
     * charges left after it, and whether it emptied the open one. $kept is
     * what this ledger kept of the open one (null: nothing), $saved what the
     * save's slice says (null: nothing); with neither the open one is full.
     *
     * @return array{ok: bool, left: int, spent: bool}
     */
    public static function step(int $held, ?int $kept, ?int $saved, int $charges): array
    {
        if ($held < 1) {
            return ['ok' => false, 'left' => 0, 'spent' => false];
        }
        $open = $kept ?? $saved ?? $charges;
        if ($open < 1 || $open > $charges) {
            $open = $charges;
        }
        $left = $open - 1;
        return ['ok' => true, 'left' => $left, 'spent' => $left === 0];
    }

    /**
     * What a save says is left in copy $k of $class (null: a full one, or none named). The game's
     * tool state keeps each copy's (systems.tools.condition[class].copies); an older save's one
     * slice value is the first copy's. $k null: the one in use (the first).
     */
    public static function savedLeft(array $entries, string $class, ?int $k = null): ?int
    {
        [$charges, $slice, $key] = self::CLASSES[$class];
        $state = json_decode((string) ($entries['playerState'] ?? ''), true);
        $copies = self::savedCopies($state, $class);
        $at = $k ?? 0;
        if ($copies !== null) {
            $n = $copies[$at] ?? null;
            return is_int($n) && $n > 0 && $n < $charges ? $n : null;
        }
        if ($at !== 0) {
            return null;
        }
        $kept = is_array($state) ? ($state['systems']['features'][$slice][$key] ?? null) : null;
        $n = self::scalar($class) ? $kept : (is_array($kept) ? ($kept[$class] ?? null) : null);
        return is_int($n) && $n > 0 && $n < $charges ? $n : null;
    }

    /**
     * Each copy's charges in a save's tool state, in pack order (null: none named for that copy, a
     * full one), or null when the save keeps none for the class (an older save).
     *
     * @return list<?int>|null
     */
    public static function savedCopies(mixed $state, string $class): ?array
    {
        $row = is_array($state) ? ($state['systems']['tools']['condition'][$class] ?? null) : null;
        if (!is_array($row) || !is_array($row['copies'] ?? null)) {
            return null;
        }
        return array_map(static fn ($r) => is_int($r) || (is_float($r) && is_finite($r)) ? (int) $r : null, array_values($row['copies']));
    }

    /** The kept row's ref for copy $k of $class: the class itself for the first (older rows), "<class>#<k>" for the others. */
    public static function keptRef(string $class, int $k): string
    {
        return $k === 0 ? $class : $class . '#' . $k;
    }

    /** [class, k] of a kept row's ref, or null. */
    public static function refCopy(string $ref): ?array
    {
        if (preg_match('/^([a-z0-9_]{1,64})(?:#(\d{1,3}))?$/', $ref, $m) !== 1) {
            return null;
        }
        return [$m[1], isset($m[2]) ? (int) $m[2] : 0];
    }

    /** Whether the slice keeps the open one's charges as one number for the class (the Rook Balm). */
    private static function scalar(string $class): bool
    {
        return (self::CLASSES[$class][3] ?? false) === true;
    }

    /** @return array{status: string, left?: int, spent?: bool, n?: int, short?: array} */
    private function once(string $op, int $id, string $class, int $now, int $n = 0, ?int $k = null): array
    {
        $db = $this->minn->db;
        $o = $db->table(LedgerCredits::TABLE);
        $was = $db->value("SELECT got FROM {$o} WHERE event_id = ? FOR UPDATE", [$op]);
        if (is_string($was)) {
            $answer = json_decode($was, true);
            if (is_array($answer) && isset($answer['status'])) {
                return $answer;
            }
        }
        $ledger = Ledger::fromSeams($this->minn);
        $state = $ledger->lock($id, $now);
        $c = $db->table(Ledger::CREDITED);
        // The copy used: the one named, else the one in use (the first in pack order).
        $at = $k ?? 0;
        $ref = self::keptRef($class, $at);
        $kept = $db->value("SELECT n FROM {$c} WHERE user_id = ? AND kind = ? AND ref = ? FOR UPDATE", [$id, self::KIND, $ref]);
        $stored = $db->value('SELECT data FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ?', [$id]);
        $save = is_string($stored) ? json_decode($stored, true) : null;
        $entries = is_array($save['entries'] ?? null) ? array_filter($save['entries'], 'is_string') : [];
        $held = (int) ($state['items'][$class] ?? 0);
        if ($n === 0 && $at >= $held) {
            // A copy past the ones held is the one in use (the pack moved on since the server asked).
            $at = 0;
            $ref = $class;
            $kept = $db->value("SELECT n FROM {$c} WHERE user_id = ? AND kind = ? AND ref = ? FOR UPDATE", [$id, self::KIND, $ref]);
        }
        $step = $n > 0
            ? ['ok' => $held >= $n, 'left' => 0, 'spent' => $held >= $n]
            : self::step($held, $kept === null ? null : (int) $kept, self::savedLeft($entries, $class, $at), self::CLASSES[$class][0]);
        $take = max(1, $n);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        if (!$step['ok']) {
            $answer = ['status' => 'refused', 'short' => ['id' => $id, 'c' => $class]];
            if ($n === 0) {
                $db->execute("DELETE FROM {$c} WHERE user_id = ? AND kind = ? AND (ref = ? OR ref LIKE ?)", [$id, self::KIND, $class, $class . '#%']);
            }
            $ledger->log($id, 'refuse', ['use' => $op, 'c' => $class], $now);
        } else {
            $answer = ['status' => 'settled', 'left' => $step['left'], 'spent' => $step['spent']];
            if ($n > 0) {
                $answer['n'] = $n;
            }
            if ($step['spent']) {
                $this->setHeld($id, $class, $held - $take, $stamp);
                // The emptied copy's place rides with the spend, so a load takes that very copy out (catchUp).
                $item = ['c' => $class, 'n' => $take] + ($n === 0 ? ['k' => $at] : []);
                $gave = (string) json_encode(['items' => [$item], 'currants' => 0], JSON_UNESCAPED_SLASHES);
                $db->execute('INSERT INTO ' . $db->table(Ledger::TRADES) . " (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$op, $id, $gave, self::NONE_JSON, $stamp, $stamp]);
                if ($n === 0) {
                    $this->dropCopy($id, $class, $at, $held);
                }
            } else {
                $db->execute("INSERT INTO {$c} (user_id, kind, ref, n, at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE n = VALUES(n), at = VALUES(at)", [$id, self::KIND, $ref, $step['left'], $stamp]);
            }
            $ledger->log($id, 'use', ['use' => $op, 'c' => $class, 'k' => $at, 'n' => $take, 'left' => $step['left'], 'spent' => $step['spent']], $now);
        }
        // Kept with its class and copy, so a give-back knows which one the charge came from.
        $db->execute("INSERT INTO {$o} (event_id, user_id, why, got, unused, created_at) VALUES (?, ?, 'use', ?, '', ?)", [$op, $id, (string) json_encode($answer + ['c' => $class, 'k' => $at], JSON_UNESCAPED_SLASHES), $stamp]);
        return $answer;
    }

    /**
     * Which copy of each class an emptying use took (the spend's items carry k), for catchUp.
     *
     * @return array<string, int>
     */
    public static function spentCopies(Db $db, string $op): array
    {
        $gave = json_decode((string) $db->value('SELECT a_gave FROM ' . $db->table(Ledger::TRADES) . ' WHERE trade_id = ?', [$op]), true);
        $out = [];
        foreach ((is_array($gave) ? ($gave['items'] ?? []) : []) as $it) {
            if (is_array($it) && is_string($it['c'] ?? null) && is_int($it['k'] ?? null)) {
                $out[$it['c']] = $it['k'];
            }
        }
        return $out;
    }

    /** Copy $at of $class left: its kept row goes and the ones after it move up one (pack order). */
    private function dropCopy(int $id, string $class, int $at, int $held): void
    {
        $db = $this->minn->db;
        $c = $db->table(Ledger::CREDITED);
        $db->execute("DELETE FROM {$c} WHERE user_id = ? AND kind = ? AND ref = ?", [$id, self::KIND, self::keptRef($class, $at)]);
        for ($j = $at + 1; $j < min($held, self::K_MAX + 1); $j++) {
            $db->execute("UPDATE {$c} SET ref = ? WHERE user_id = ? AND kind = ? AND ref = ?", [self::keptRef($class, $j - 1), $id, self::KIND, self::keptRef($class, $j)]);
        }
    }

    /** Copy $at of $class came back: the kept rows from it on move down one, and it holds $n. */
    private function insertCopy(int $id, string $class, int $at, int $held, int $n, string $stamp): void
    {
        $db = $this->minn->db;
        $c = $db->table(Ledger::CREDITED);
        for ($j = min($held, self::K_MAX) - 1; $j >= $at; $j--) {
            $db->execute("UPDATE {$c} SET ref = ? WHERE user_id = ? AND kind = ? AND ref = ?", [self::keptRef($class, $j + 1), $id, self::KIND, self::keptRef($class, $j)]);
        }
        $db->execute("INSERT INTO {$c} (user_id, kind, ref, n, at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE n = VALUES(n), at = VALUES(at)", [$id, self::KIND, self::keptRef($class, $at), $n, $stamp]);
    }

    /** The ledger's count of one class for a character (rows at 0 are deleted). */
    private function setHeld(int $id, string $class, int $count, string $stamp): void
    {
        $db = $this->minn->db;
        $items = $db->table(Ledger::ITEMS);
        if ($count <= 0) {
            $db->execute("DELETE FROM {$items} WHERE user_id = ? AND class_tsid = ?", [$id, $class]);
        } else {
            $db->execute("INSERT INTO {$items} (user_id, class_tsid, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)", [$id, $class, $count]);
        }
        $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET updated_at = ? WHERE user_id = ?', [$stamp, $id]);
    }

    /**
     * A use whose answer the server never got (slice 5): give back what it used unless a load
     * already applied it, and make sure a late copy of it uses nothing.
     *
     * @return array{status: string}
     */
    private function giveBack(string $op, int $id, int $now): array
    {
        $db = $this->minn->db;
        $o = $db->table(LedgerCredits::TABLE);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $void = '{"status":"void"}';
        $row = $db->row("SELECT user_id, got FROM {$o} WHERE event_id = ? FOR UPDATE", [$op]);
        if ($row === null) {
            $db->execute("INSERT INTO {$o} (event_id, user_id, why, got, unused, created_at) VALUES (?, ?, 'use', ?, '', ?)", [$op, $id, $void, $stamp]);
            return ['status' => 'void'];
        }
        $was = json_decode((string) $row['got'], true);
        if ((int) $row['user_id'] !== $id || !is_array($was) || ($was['status'] ?? '') !== 'settled') {
            return ['status' => 'void'];
        }
        $ledger = Ledger::fromSeams($this->minn);
        $state = $ledger->lock($id, $now);
        $t = $db->table(Ledger::TRADES);
        $c = $db->table(Ledger::CREDITED);
        $back = ['op' => $op];
        if (!empty($was['spent'])) {
            $spend = $db->row("SELECT status, a_gave, a_applied_at FROM {$t} WHERE trade_id = ? FOR UPDATE", [$op]);
            if ($spend !== null && $spend['a_applied_at'] !== null) {
                return ['status' => 'settled'];
            }
            $gave = LedgerMath::goods(json_decode((string) ($spend['a_gave'] ?? ''), true));
            foreach ($gave['items'] ?? [] as $class => $count) {
                $class = (string) $class;
                $had = (int) ($state['items'][$class] ?? 0);
                $this->setHeld($id, $class, $had + (int) $count, $stamp);
                if (isset(self::CLASSES[$class]) && empty($was['n'])) {
                    // The jar it emptied had one charge left before it, back in its own place.
                    $k = is_int($was['k'] ?? null) ? min((int) $was['k'], $had) : 0;
                    $this->insertCopy($id, $class, $k, $had, 1, $stamp);
                }
                $back['c'] = $class;
                $back['n'] = (int) $count;
            }
            $db->execute("UPDATE {$t} SET status = 'void' WHERE trade_id = ?", [$op]);
        } else {
            // The charge goes back into the open one (kept with the answer: "c"); a kept row the save
            // has since let go of (it holds none of the class) has nothing to go back into.
            $class = (string) ($was['c'] ?? '');
            if (isset(self::CLASSES[$class])) {
                $ref = self::keptRef($class, is_int($was['k'] ?? null) ? (int) $was['k'] : 0);
                $db->execute("UPDATE {$c} SET n = LEAST(n + 1, ?), at = ? WHERE user_id = ? AND kind = ? AND ref = ?", [self::CLASSES[$class][0], $stamp, $id, self::KIND, $ref]);
                $back['c'] = $class;
            }
        }
        $db->execute("UPDATE {$o} SET got = ? WHERE event_id = ?", [$void, $op]);
        $ledger->log($id, 'unuse', $back, $now);
        return ['status' => 'void'];
    }

    /**
     * After a save write: the kept charges of each class go down to what the
     * save's slice shows (never up), and are forgotten once the save holds
     * none of the class. $kept: this character's kept rows (class => n).
     *
     * @param array<string, int> $kept
     */
    public static function observe(Db $db, int $user, array $entries, array $kept, int $now): void
    {
        if ($kept === []) {
            return;
        }
        $state = json_decode((string) ($entries['playerState'] ?? ''), true);
        $inv = is_array($state) && is_array($state['inventory'] ?? null) ? $state['inventory'] : [];
        $c = $db->table(Ledger::CREDITED);
        $ages = [];
        foreach ($db->rows("SELECT ref, at FROM {$c} WHERE user_id = ? AND kind = ?", [$user, self::KIND]) as $row) {
            $ages[(string) $row['ref']] = strtotime((string) $row['at'] . ' UTC') ?: $now;
        }
        foreach ($kept as $ref => $n) {
            $ref = (string) $ref;
            $copy = self::refCopy($ref);
            if ($copy === null || !isset(self::CLASSES[$copy[0]])) {
                continue;
            }
            [$class, $k] = $copy;
            // A copy the save no longer holds has nothing to keep.
            if ((int) ($inv[$class] ?? 0) <= $k) {
                $db->execute("DELETE FROM {$c} WHERE user_id = ? AND kind = ? AND ref = ?", [$user, self::KIND, $ref]);
                continue;
            }
            $saved = self::savedLeft($entries, $class, $k);
            $full = self::CLASSES[$class][0];
            $shows = $saved ?? (self::savedCopies($state, $class) !== null ? $full : null);
            $old = $now - ($ages[$ref] ?? $now) > self::KEPT_TRUST;
            if ($shows !== null && ($shows < (int) $n || ($old && $shows !== (int) $n))) {
                // Lower at once; a different number long after the use is the save's (a copy moved in the pack).
                $db->execute("UPDATE {$c} SET n = ?, at = ? WHERE user_id = ? AND kind = ? AND ref = ?", [$shows, gmdate('Y-m-d H:i:s', $now), $user, self::KIND, $ref]);
            }
        }
    }

    /**
     * A load that finds an emptied jar or bottle the save has not applied:
     * out of the pack as a trade's gave is (LedgerMath::applyTrade), and the
     * slice's charges for its class with it, so the next one starts full.
     *
     * @param array{trade: string, gave: array, got: array} $p
     */
    public static function catchUp(array $entries, array $p): array
    {
        $entries = LedgerMath::applyTrade($entries, $p['trade'], $p['gave'], $p['got']);
        $state = json_decode((string) ($entries['playerState'] ?? ''));
        if (!is_object($state) || !isset($state->systems->features)) {
            return $entries;
        }
        foreach (array_keys($p['gave']['items']) as $class) {
            $class = (string) $class;
            if (!isset(self::CLASSES[$class])) {
                continue;
            }
            // The emptied copy leaves the save's tool state (its place rode with the spend: k).
            $row = $state->systems->tools->condition->{$class} ?? null;
            if (is_object($row) && is_array($row->copies ?? null)) {
                $k = is_int($p['k'][$class] ?? null) ? (int) $p['k'][$class] : 0;
                if ($k < count($row->copies)) {
                    array_splice($row->copies, $k, 1);
                    if (is_array($row->tags ?? null) && $k < count($row->tags)) {
                        array_splice($row->tags, $k, 1);
                    }
                }
                continue;
            }
            [, $slice, $key] = self::CLASSES[$class];
            if (self::scalar($class)) {
                if (isset($state->systems->features->{$slice}->{$key})) {
                    $state->systems->features->{$slice}->{$key} = 0;
                }
            } elseif (isset($state->systems->features->{$slice}->{$key}->{$class})) {
                unset($state->systems->features->{$slice}->{$key}->{$class});
            }
        }
        $entries['playerState'] = (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $entries;
    }
}
