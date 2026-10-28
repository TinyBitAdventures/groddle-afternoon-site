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
 */
final readonly class LedgerUse
{
    public const ROUTE = '/groddle/v1/ledger/use';
    public const KIND = 'uses';

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
     */
    public const CLASSES = [
        'fertilidust' => [5, 'powders', 'charges'],
        'fertilidust_lite' => [5, 'powders', 'charges'],
        'potion_tree_poison' => [5, 'tree-death', 'uses'],
        'potion_tree_poison_antidote' => [5, 'tree-death', 'uses'],
    ];

    private const NONE_JSON = '{"items":[],"currants":0}';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Post, self::ROUTE, policy: new Policy(Access::Public))]
    public function use(Request $request): Response
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', self::ROUTE, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
        $body = json_decode($request->body, true);
        $op = is_array($body) ? ($body['op'] ?? null) : null;
        $id = is_array($body) ? ($body['id'] ?? null) : null;
        $class = is_array($body) ? ($body['c'] ?? null) : null;
        if (!self::isUse(is_string($op) ? $op : '') || !is_int($id) || $id <= 0 || !is_string($class) || !isset(self::CLASSES[$class])) {
            throw new RestError('groddle_ledger_invalid', 'Not a use.', 400);
        }
        $out = Ledger::atomically($this->minn->db, fn () => $this->once($op, $id, $class, time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
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
        $n = is_array($state) ? ($state['systems']['features'][$slice][$key][$class] ?? null) : null;
        return is_int($n) && $n > 0 && $n < $charges ? $n : null;
    }

    /** @return array{status: string, left?: int, spent?: bool, short?: array} */
    private function once(string $op, int $id, string $class, int $now): array
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
        $step = self::step($held, $kept === null ? null : (int) $kept, self::savedLeft($entries, $class), self::CLASSES[$class][0]);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        if (!$step['ok']) {
            $answer = ['status' => 'refused', 'short' => ['id' => $id, 'c' => $class]];
            $db->execute("DELETE FROM {$c} WHERE user_id = ? AND kind = ? AND ref = ?", [$id, self::KIND, $class]);
            $ledger->log($id, 'refuse', ['use' => $op, 'c' => $class], $now);
        } else {
            $answer = ['status' => 'settled', 'left' => $step['left'], 'spent' => $step['spent']];
            if ($step['spent']) {
                $items = $db->table(Ledger::ITEMS);
                if ($held - 1 <= 0) {
                    $db->execute("DELETE FROM {$items} WHERE user_id = ? AND class_tsid = ?", [$id, $class]);
                } else {
                    $db->execute("UPDATE {$items} SET count = ? WHERE user_id = ? AND class_tsid = ?", [$held - 1, $id, $class]);
                }
                $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET updated_at = ? WHERE user_id = ?', [$stamp, $id]);
                $gave = (string) json_encode(['items' => [['c' => $class, 'n' => 1]], 'currants' => 0], JSON_UNESCAPED_SLASHES);
                $db->execute('INSERT INTO ' . $db->table(Ledger::TRADES) . " (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$op, $id, $gave, self::NONE_JSON, $stamp, $stamp]);
                $db->execute("DELETE FROM {$c} WHERE user_id = ? AND kind = ? AND ref = ?", [$id, self::KIND, $class]);
            } else {
                $db->execute("INSERT INTO {$c} (user_id, kind, ref, n, at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE n = VALUES(n), at = VALUES(at)", [$id, self::KIND, $class, $step['left'], $stamp]);
            }
            $ledger->log($id, 'use', ['use' => $op, 'c' => $class, 'left' => $step['left'], 'spent' => $step['spent']], $now);
        }
        $db->execute("INSERT INTO {$o} (event_id, user_id, why, got, unused, created_at) VALUES (?, ?, 'use', ?, '', ?)", [$op, $id, (string) json_encode($answer, JSON_UNESCAPED_SLASHES), $stamp]);
        return $answer;
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
            if (isset($state->systems->features->{$slice}->{$key}->{$class})) {
                unset($state->systems->features->{$slice}->{$key}->{$class});
            }
        }
        $entries['playerState'] = (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $entries;
    }
}
