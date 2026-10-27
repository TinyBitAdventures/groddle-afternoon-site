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
 * Server outcomes as credits (G335 slice 3). The realtime server decides some
 * of what players get (groddle-afternoon-server credits.go): a currants
 * quoin and its shards, a race win's 100 currants, what a player takes from
 * a shared rock, tree, Wood Tree or resource node. It tells the ledger here,
 * in signed batches (ServiceCall), idempotent per event id:
 *
 *   POST groddle/v1/ledger/credits
 *        {"credits":[{"id":"cr-q-…","user":7,"why":"quoin|shard|race|take","got":Goods,"at":…},…]}
 *        200 {"done":["cr-q-…",…]}   every id recorded, now or before, or refused for good
 *
 * A credit is a receipt the player's save may show (table
 * groddle_ledger_outcomes, one row per event id): the next save write
 * (Ledger::write, LedgerMath::check $receipts) takes a gain the unused
 * receipts cover as exact, outside the earning budget, and uses those
 * receipts up, oldest first. A receipt the save never shows is not a loss
 * and refunds nothing: it simply stays unused until it is KEEP old. Only
 * what the save shows moves the ledger, so the client that applies the
 * outcome (shared-quoins-feature, races-feature, street-objects-feature)
 * needs to remember nothing, and showing more than the server decided is
 * an ordinary gain against the budget.
 *
 * A credit that comes after the write that showed it (the site was down,
 * or the realtime server's outbox was waiting it out) finds that the write
 * paid for the gain from the budget: what each write charged the budget is
 * kept for LATE seconds with the write's time (one row per character, why
 * "charged"), and a late credit gives back what it explains of the charges
 * written since its event happened (its "at", less SKEW), into the budget
 * (Ledger::refund), before the rest becomes a receipt. A charge from before
 * the event (the first write's pack, a sale) is never the event's.
 *
 * Users 1 and 54 are players like the others here: the realtime server
 * credits whoever played.
 */
final readonly class LedgerCredits
{
    public const ROUTE = '/groddle/v1/ledger/credits';
    public const TABLE = 'groddle_ledger_outcomes';

    /** Events in one call (credits.go sends at most creditBatch, 50). */
    public const BATCH = 100;

    /** A receipt unused this long (seconds) is dropped; a settled trade's pending view lasts as long (Ledger::PENDING_FOR). */
    public const KEEP = 86400;

    /** How long what a write charged the budget waits for a late credit to give it back (seconds). */
    public const LATE = 900;

    /** Clock slack between the realtime server and the site when a late credit looks for its write (seconds). */
    public const SKEW = 2;

    /** Charges kept per character (the oldest go first). */
    public const CHARGES_KEPT = 40;

    public const WHY = ['quoin', 'shard', 'race', 'take'];

    /**
     * Bounds on one event, against a bug rather than a forger (only the
     * realtime server can sign): a quoin pays at most 100 x 100
     * (long random large currants times quoin_capacity_limit, config_base.js:252),
     * a take at most a Wood Tree's 80 planks or a rock's swing with every help.
     */
    public const MOST_CURRANTS = 10000;
    public const MOST_ITEMS = 1000;

    private const NONE = ['items' => [], 'currants' => 0];

    /** One log row per character per this many seconds sums the credits that came (Ledger::CLAMP_LOG_EVERY). */
    public const LOG_EVERY = 60;

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Post, self::ROUTE, policy: new Policy(Access::Public))]
    public function credits(Request $request): Response
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', self::ROUTE, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
        $body = json_decode($request->body, true);
        $list = is_array($body) && is_array($body['credits'] ?? null) && array_is_list($body['credits']) ? $body['credits'] : null;
        if ($list === null || count($list) > self::BATCH) {
            throw new RestError('groddle_ledger_invalid', 'Not a batch of credits.', 400);
        }
        $economy = Economy::shared();
        $done = [];
        $byUser = [];
        foreach ($list as $raw) {
            $ev = self::event($raw, $economy);
            if ($ev === null) {
                // Never taken, but answered, so the realtime server stops sending it.
                if (is_array($raw) && self::id($raw['id'] ?? null)) {
                    $done[] = $raw['id'];
                }
                continue;
            }
            $byUser[$ev['user']][] = $ev;
        }
        $ledger = Ledger::fromSeams($this->minn);
        $now = time();
        foreach ($byUser as $user => $events) {
            $ids = Ledger::atomically($this->minn->db, fn () => self::record($this->minn->db, $ledger, (int) $user, $events, $economy, $now));
            array_push($done, ...$ids);
        }
        return Response::json(['done' => $done]);
    }

    /** credits.go: cr-<why letter>-<process>-<n>, lowercase letters, digits and dashes, at most 40. */
    public static function id(mixed $id): bool
    {
        return is_string($id) && preg_match('/^cr-[a-z0-9-]{1,37}$/', $id) === 1;
    }

    /**
     * One event as the realtime server sends it, checked: its goods as
     * counts of tradeable classes only. Null when it is not one.
     *
     * @return array{id: string, user: int, why: string, got: array{items: array<string,int>, currants: int}, at: int}|null
     */
    public static function event(mixed $raw, Economy $economy): ?array
    {
        if (!is_array($raw) || !self::id($raw['id'] ?? null) || !is_int($raw['user'] ?? null) || $raw['user'] <= 0
            || !in_array($raw['why'] ?? null, self::WHY, true) || !is_array($raw['got'] ?? null)) {
            return null;
        }
        $got = LedgerMath::goods($raw['got']);
        $got['items'] = array_filter($got['items'], static fn ($n, $c) => $economy->tradeable((string) $c), ARRAY_FILTER_USE_BOTH);
        if ($got['currants'] > self::MOST_CURRANTS || array_filter($got['items'], static fn ($n) => $n > self::MOST_ITEMS) !== []) {
            return null;
        }
        $at = is_int($raw['at'] ?? null) ? $raw['at'] : 0;
        return ['id' => $raw['id'], 'user' => $raw['user'], 'why' => $raw['why'], 'got' => $got, 'at' => $at];
    }

    /**
     * Records one character's new events once each (an id seen before is
     * answered as done and changes nothing), gives back into the budget what
     * a late one explains of the last LATE seconds' charges, and keeps the
     * rest as receipts. Returns the ids now recorded.
     *
     * @param list<array{id: string, user: int, why: string, got: array}> $events
     * @return list<string>
     */
    public static function record(Db $db, Ledger $ledger, int $user, array $events, Economy $economy, int $now): array
    {
        // The ledger row first, as a save write takes it: the two never interleave.
        $ledger->lock($user, $now);
        $t = $db->table(self::TABLE);
        $ids = array_values(array_unique(array_column($events, 'id')));
        $seen = [];
        foreach ($db->rows("SELECT event_id FROM {$t} WHERE event_id IN (" . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids) as $r) {
            $seen[(string) $r['event_id']] = true;
        }
        $charges = self::chargesNow($db, $user, $now);
        $refund = 0.0;
        $sum = self::NONE;
        $why = [];
        $stamp = gmdate('Y-m-d H:i:s', $now);
        foreach ($events as $ev) {
            if (isset($seen[$ev['id']])) {
                continue;
            }
            $seen[$ev['id']] = true;
            // Only a write at or after the event can have shown it (no time: it happened now).
            $at = $ev['at'] > 0 ? min($ev['at'], $now) : $now;
            $unused = $ev['got'];
            foreach ($charges as $i => $ch) {
                if ($ch['at'] < $at - self::SKEW || LedgerMath::nothing($unused)) {
                    continue;
                }
                $late = self::overlap($unused, $ch['goods']);
                $charges[$i]['goods'] = self::minus($ch['goods'], $late);
                $unused = self::minus($unused, $late);
                $refund += self::worth($late, $economy);
            }
            $db->execute("INSERT IGNORE INTO {$t} (event_id, user_id, why, got, unused, created_at) VALUES (?, ?, ?, ?, ?, ?)",
                [$ev['id'], $user, $ev['why'], self::json($ev['got']), LedgerMath::nothing($unused) ? '' : self::json($unused), $stamp]);
            $sum = LedgerMath::add($sum, $ev['got']);
            $why[$ev['why']] = ($why[$ev['why']] ?? 0) + 1;
        }
        if ($why === []) {
            return $ids;
        }
        if ($refund > 0) {
            $ledger->refund($user, $refund, $now);
            self::putCharges($db, $user, $charges, $now);
        }
        self::logCredits($db, $ledger, $user, $why, $sum, $refund, $now);
        return $ids;
    }

    // —— A save write (Ledger::write) ——

    /**
     * The receipts a save write may show, locked, oldest first, after
     * dropping what is older than KEEP. 'goods' is their sum.
     *
     * @return array{goods: array{items: array<string,int>, currants: int}, rows: list<array{0: string, 1: array}>}
     */
    public static function open(Db $db, int $user, int $now): array
    {
        $t = $db->table(self::TABLE);
        $db->execute("DELETE FROM {$t} WHERE user_id = ? AND created_at < ?", [$user, gmdate('Y-m-d H:i:s', $now - self::KEEP)]);
        $rows = [];
        $goods = self::NONE;
        foreach ($db->rows("SELECT event_id, unused FROM {$t} WHERE user_id = ? AND why <> 'charged' AND unused <> '' ORDER BY created_at, event_id FOR UPDATE", [$user]) as $r) {
            $g = self::counts(json_decode((string) $r['unused'], true));
            if (!LedgerMath::nothing($g)) {
                $rows[] = [(string) $r['event_id'], $g];
                $goods = LedgerMath::add($goods, $g);
            }
        }
        return ['goods' => $goods, 'rows' => $rows];
    }

    /**
     * After a write: uses up the receipts the write showed ($allowed), oldest
     * first, and remembers what it charged the budget for a late credit.
     *
     * @param array{goods: array, rows: list<array{0: string, 1: array}>} $open
     */
    public static function used(Db $db, int $user, array $open, array $allowed, array $charged, int $now): void
    {
        $t = $db->table(self::TABLE);
        $want = $allowed;
        foreach ($open['rows'] as [$id, $g]) {
            if (LedgerMath::nothing($want)) {
                break;
            }
            $take = self::overlap($g, $want);
            if (LedgerMath::nothing($take)) {
                continue;
            }
            $want = self::minus($want, $take);
            $rest = self::minus($g, $take);
            $db->execute("UPDATE {$t} SET unused = ? WHERE event_id = ?", [LedgerMath::nothing($rest) ? '' : self::json($rest), $id]);
        }
        if (!LedgerMath::nothing($charged)) {
            $charges = self::chargesNow($db, $user, $now);
            $charges[] = ['at' => $now, 'goods' => $charged];
            self::putCharges($db, $user, $charges, $now);
        }
    }

    /**
     * What a write's reconcile charged the budget for: the gains it kept
     * that no transform made (LedgerMath::reconcile delta and made).
     *
     * @return array{items: array<string,int>, currants: int}
     */
    public static function charged(array $reconciled): array
    {
        $items = [];
        foreach ($reconciled['delta']['items'] as $c => $n) {
            $k = (int) $n - (int) ($reconciled['made'][$c] ?? 0);
            if ($k > 0) {
                $items[(string) $c] = $k;
            }
        }
        ksort($items);
        return ['items' => $items, 'currants' => max(0, (int) $reconciled['delta']['currants'])];
    }

    // —— Goods arithmetic ——

    /** What a and b have in common, class by class. */
    public static function overlap(array $a, array $b): array
    {
        $items = [];
        foreach ($a['items'] as $c => $n) {
            $k = min((int) $n, (int) ($b['items'][$c] ?? 0));
            if ($k > 0) {
                $items[(string) $c] = $k;
            }
        }
        ksort($items);
        return ['items' => $items, 'currants' => max(0, min($a['currants'], $b['currants']))];
    }

    /** a less b (never below 0). */
    public static function minus(array $a, array $b): array
    {
        $items = [];
        foreach ($a['items'] as $c => $n) {
            $k = (int) $n - (int) ($b['items'][$c] ?? 0);
            if ($k > 0) {
                $items[(string) $c] = $k;
            }
        }
        ksort($items);
        return ['items' => $items, 'currants' => max(0, $a['currants'] - $b['currants'])];
    }

    /** Goods' value as the budget counts it (currants 1 each, items Economy::value). */
    public static function worth(array $goods, Economy $economy): float
    {
        $v = (float) $goods['currants'];
        foreach ($goods['items'] as $c => $n) {
            $v += $n * $economy->value((string) $c);
        }
        return $v;
    }

    /** Stored counts ({"items":{class:n},"currants":n}) read back. */
    public static function counts(mixed $json): array
    {
        $items = [];
        foreach (is_array($json['items'] ?? null) ? $json['items'] : [] as $c => $n) {
            $k = LedgerMath::count($n);
            if (is_string($c) && $k > 0) {
                $items[$c] = $k;
            }
        }
        ksort($items);
        return ['items' => $items, 'currants' => LedgerMath::count($json['currants'] ?? 0)];
    }

    private static function json(array $goods): string
    {
        return (string) json_encode(['items' => (object) $goods['items'], 'currants' => $goods['currants']], JSON_UNESCAPED_SLASHES);
    }

    // —— What writes charged ——

    /**
     * The charges of the last LATE seconds, oldest first, locked: what each
     * write charged the budget and when.
     *
     * @return list<array{at: int, goods: array{items: array<string,int>, currants: int}}>
     */
    private static function chargesNow(Db $db, int $user, int $now): array
    {
        $raw = $db->value('SELECT unused FROM ' . $db->table(self::TABLE) . ' WHERE event_id = ? FOR UPDATE', ['charged-' . $user]);
        $list = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        $out = [];
        foreach (is_array($list) ? $list : [] as $ch) {
            $at = is_array($ch) && is_int($ch['at'] ?? null) ? $ch['at'] : 0;
            if ($at >= $now - self::LATE) {
                $out[] = ['at' => $at, 'goods' => self::counts($ch)];
            }
        }
        return $out;
    }

    private static function putCharges(Db $db, int $user, array $charges, int $now): void
    {
        $keep = [];
        foreach ($charges as $ch) {
            if ($ch['at'] >= $now - self::LATE && !LedgerMath::nothing($ch['goods'])) {
                $keep[] = ['at' => $ch['at'], 'items' => (object) $ch['goods']['items'], 'currants' => $ch['goods']['currants']];
            }
        }
        $keep = array_slice($keep, -self::CHARGES_KEPT);
        $t = $db->table(self::TABLE);
        $db->execute("INSERT INTO {$t} (event_id, user_id, why, got, unused, created_at) VALUES (?, ?, 'charged', '{}', ?, ?) ON DUPLICATE KEY UPDATE unused = VALUES(unused), created_at = VALUES(created_at)",
            ['charged-' . $user, $user, $keep === [] ? '' : (string) json_encode($keep, JSON_UNESCAPED_SLASHES), gmdate('Y-m-d H:i:s', $now)]);
    }

    /** One 'credit' log row a minute per character, summing what came (repeats counted on it). */
    private static function logCredits(Db $db, Ledger $ledger, int $user, array $why, array $got, float $refund, int $now): void
    {
        $t = $db->table(Ledger::LOG);
        $last = $db->row("SELECT id, detail FROM {$t} WHERE user_id = ? AND kind = 'credit' AND at >= ? ORDER BY id DESC LIMIT 1", [$user, gmdate('Y-m-d H:i:s', $now - self::LOG_EVERY)]);
        $was = $last !== null ? json_decode((string) $last['detail'], true) : null;
        if (is_array($was) && is_array($was['server'] ?? null)) {
            foreach ($why as $k => $n) {
                $was['server'][$k] = (int) ($was['server'][$k] ?? 0) + $n;
            }
            $sum = LedgerMath::add(self::counts($was['got'] ?? null), $got);
            $was['got'] = ['items' => (object) $sum['items'], 'currants' => $sum['currants']];
            $was['late'] = round((float) ($was['late'] ?? 0) + $refund, 1);
            $db->execute("UPDATE {$t} SET detail = ? WHERE id = ?", [json_encode($was, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int) $last['id']]);
            return;
        }
        $ledger->log($user, 'credit', ['server' => $why, 'got' => ['items' => (object) $got['items'], 'currants' => $got['currants']], 'late' => round($refund, 1)], $now);
    }
}
