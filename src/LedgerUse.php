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
        if (!self::isUse(is_string($op) ? $op : '') || !is_int($id) || $id <= 0 || !is_string($class) || !self::usable($class, $n)) {
            throw new RestError('groddle_ledger_invalid', 'Not a use.', 400);
        }
        $out = Ledger::atomically($this->minn->db, fn () => $this->once($op, $id, $class, time(), $n));
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

    /** What a save's slice says is left in the open one of $class (null: a full one, or none named). */
    public static function savedLeft(array $entries, string $class): ?int
    {
        [$charges, $slice, $key] = self::CLASSES[$class];
        $state = json_decode((string) ($entries['playerState'] ?? ''), true);
        $kept = is_array($state) ? ($state['systems']['features'][$slice][$key] ?? null) : null;
        $n = self::scalar($class) ? $kept : (is_array($kept) ? ($kept[$class] ?? null) : null);
        return is_int($n) && $n > 0 && $n < $charges ? $n : null;
    }

    /** Whether the slice keeps the open one's charges as one number for the class (the Rook Balm). */
    private static function scalar(string $class): bool
    {
        return (self::CLASSES[$class][3] ?? false) === true;
    }

    /** @return array{status: string, left?: int, spent?: bool, n?: int, short?: array} */
    private function once(string $op, int $id, string $class, int $now, int $n = 0): array
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
        $kept = $db->value("SELECT n FROM {$c} WHERE user_id = ? AND kind = ? AND ref = ? FOR UPDATE", [$id, self::KIND, $class]);
        $stored = $db->value('SELECT data FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ?', [$id]);
        $save = is_string($stored) ? json_decode($stored, true) : null;
        $entries = is_array($save['entries'] ?? null) ? array_filter($save['entries'], 'is_string') : [];
        $held = (int) ($state['items'][$class] ?? 0);
        $step = $n > 0
            ? ['ok' => $held >= $n, 'left' => 0, 'spent' => $held >= $n]
            : self::step($held, $kept === null ? null : (int) $kept, self::savedLeft($entries, $class), self::CLASSES[$class][0]);
        $take = max(1, $n);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        if (!$step['ok']) {
            $answer = ['status' => 'refused', 'short' => ['id' => $id, 'c' => $class]];
            if ($n === 0) {
                $db->execute("DELETE FROM {$c} WHERE user_id = ? AND kind = ? AND ref = ?", [$id, self::KIND, $class]);
            }
            $ledger->log($id, 'refuse', ['use' => $op, 'c' => $class], $now);
        } else {
            $answer = ['status' => 'settled', 'left' => $step['left'], 'spent' => $step['spent']];
            if ($n > 0) {
                $answer['n'] = $n;
            }
            if ($step['spent']) {
                $this->setHeld($id, $class, $held - $take, $stamp);
                $gave = (string) json_encode(['items' => [['c' => $class, 'n' => $take]], 'currants' => 0], JSON_UNESCAPED_SLASHES);
                $db->execute('INSERT INTO ' . $db->table(Ledger::TRADES) . " (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$op, $id, $gave, self::NONE_JSON, $stamp, $stamp]);
                if ($n === 0) {
                    $db->execute("DELETE FROM {$c} WHERE user_id = ? AND kind = ? AND ref = ?", [$id, self::KIND, $class]);
                }
            } else {
                $db->execute("INSERT INTO {$c} (user_id, kind, ref, n, at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE n = VALUES(n), at = VALUES(at)", [$id, self::KIND, $class, $step['left'], $stamp]);
            }
            $ledger->log($id, 'use', ['use' => $op, 'c' => $class, 'n' => $take, 'left' => $step['left'], 'spent' => $step['spent']], $now);
        }
        // Kept with its class, so a give-back knows which open one the charge came from.
        $db->execute("INSERT INTO {$o} (event_id, user_id, why, got, unused, created_at) VALUES (?, ?, 'use', ?, '', ?)", [$op, $id, (string) json_encode($answer + ['c' => $class], JSON_UNESCAPED_SLASHES), $stamp]);
        return $answer;
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
                $this->setHeld($id, $class, (int) ($state['items'][$class] ?? 0) + (int) $count, $stamp);
                if (isset(self::CLASSES[$class]) && empty($was['n'])) {
                    // The jar it emptied had one charge left before it.
                    $db->execute("INSERT INTO {$c} (user_id, kind, ref, n, at) VALUES (?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE n = VALUES(n), at = VALUES(at)", [$id, self::KIND, $class, $stamp]);
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
                $db->execute("UPDATE {$c} SET n = LEAST(n + 1, ?), at = ? WHERE user_id = ? AND kind = ? AND ref = ?", [self::CLASSES[$class][0], $stamp, $id, self::KIND, $class]);
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
        foreach ($kept as $class => $n) {
            $class = (string) $class;
            if (!isset(self::CLASSES[$class])) {
                continue;
            }
            if ((int) ($inv[$class] ?? 0) < 1) {
                $db->execute("DELETE FROM {$c} WHERE user_id = ? AND kind = ? AND ref = ?", [$user, self::KIND, $class]);
                continue;
            }
            $saved = self::savedLeft($entries, $class);
            if ($saved !== null && $saved < (int) $n) {
                $db->execute("UPDATE {$c} SET n = ?, at = ? WHERE user_id = ? AND kind = ? AND ref = ?", [$saved, gmdate('Y-m-d H:i:s', $now), $user, self::KIND, $class]);
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
