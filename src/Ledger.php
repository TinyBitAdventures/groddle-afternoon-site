<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;
use Minn\Extension\Seams;

/**
 * The authoritative ledger of each multiplayer character: currants and the
 * tradeable classes it holds (tables groddle_ledger, groddle_ledger_items),
 * the trades settled between two ledgers (groddle_trades) and a log of what
 * the site decided (groddle_ledger_log). G335; the design and its reasons
 * are glitch-audit research/coverage/g335-economy.md, the arithmetic is
 * LedgerMath.
 *
 * - A save write (MultiplayerSave) is compared with the ledger: losses
 *   stand, gains stand while the character's earning budget lasts (a token
 *   bucket in currants of value: groddle_ledger_rate a minute up to
 *   groddle_ledger_capacity, site options), and the rest is taken back out
 *   of the stored save and logged.
 * - A trade the realtime server finished settles here (LedgerService):
 *   both ledgers move at once, or neither does when a giver's ledger does
 *   not hold what it gives.
 * - A character with a save from before the ledger is seeded from it once
 *   (with a full budget); a new character starts at zero with NEW_BUDGET,
 *   enough for the tutorial's first finds.
 * - Slice 2 explains gains so the rate can be small: transforms the
 *   write's own losses cover, exact credits for what the save says it did
 *   (quests, badges, level-ups, imagination cards; groddle_ledger_credited
 *   keeps each to once per character, and what a character had done before
 *   its first checked write is marked without paying), and a daily count
 *   for classes only drop tables grant (LedgerMath).
 *
 * Every method runs inside the caller's Db::transaction and locks the
 * ledger rows it reads (FOR UPDATE), so a save write and a settlement for
 * the same character never interleave.
 */
final readonly class Ledger
{
    public const TABLE = 'groddle_ledger';
    public const ITEMS = 'groddle_ledger_items';
    public const TRADES = 'groddle_trades';
    public const LOG = 'groddle_ledger_log';
    public const CREDITED = 'groddle_ledger_credited';

    /**
     * Defaults for the site options: value a minute, and the most the budget
     * holds. Slice 2 explains crafting, Cubimal boxes, artifacts, quests,
     * badges, level-ups and card items; slice 3 credits what the realtime
     * server decides (quoins, race wins, shared rocks, trees and nodes,
     * LedgerCredits), so the rate covers only what is still unexplained
     * (drops, skill bonuses, animals, gardens, private trees): two honest
     * players with quoins, mining, harvesting and a race paid 0.6 to 38.7 a
     * minute (g335-economy.md section 7), so 100 leaves a margin of 2.5 times
     * over the worst.
     */
    public const RATE = 100.0;
    public const CAPACITY = 50000.0;

    /** A settled trade a save has not applied counts as applied after this (seconds). */
    public const PENDING_FOR = 86400;

    /** One clamp log row per character per this many seconds (repeats are counted on it). */
    public const CLAMP_LOG_EVERY = 60;

    /** A new character's budget to start with (the first harvests of the tutorial; slice 1's 5 minutes of 1,000). */
    public const NEW_BUDGET = 5000.0;

    /**
     * MariaDB errors that mean "run the transaction again": a deadlock (1213),
     * a lock wait timeout (1205) and, under innodb_snapshot_isolation, a row
     * another transaction changed after this one's snapshot (1020: both
     * players' saves stamping the same settled trade at once).
     */
    private const RETRY_CODES = [1020, 1205, 1213];

    public function __construct(private Db $db, private Economy $economy, private float $rate, private float $capacity)
    {
    }

    public static function fromSeams(Seams $minn): self
    {
        $num = static function (string $name, float $default) use ($minn): float {
            $v = $minn->site->option($name);
            return is_numeric($v) && (float) $v >= 0 ? (float) $v : $default;
        };
        return new self($minn->db, Economy::shared(), $num('groddle_ledger_rate', self::RATE), $num('groddle_ledger_capacity', self::CAPACITY));
    }

    /**
     * Runs work in one transaction, again (up to three times in all) when the
     * database says to restart it. Every ledger write goes through here.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function atomically(Db $db, callable $work): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return $db->transaction($work);
            } catch (\mysqli_sql_exception $e) {
                if ($try >= 3 || !in_array($e->getCode(), self::RETRY_CODES, true)) {
                    throw $e;
                }
                usleep(20000 * $try);
            }
        }
    }

    // —— Rows ——

    /**
     * The character's ledger row, locked, made when missing: seeded from the
     * stored save when there is one (a character from before the ledger),
     * else at zero.
     *
     * @return array{currants: int, items: array<string,int>, budget: float, budget_at: int, seeded: string}
     */
    public function lock(int $user, int $now): array
    {
        $t = $this->db->table(self::TABLE);
        $row = $this->db->row("SELECT currants, budget, budget_at, seeded FROM {$t} WHERE user_id = ? FOR UPDATE", [$user]);
        if ($row === null) {
            $stored = $this->db->value('SELECT data FROM ' . $this->db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ?', [$user]);
            $entries = [];
            if (is_string($stored)) {
                $save = json_decode($stored, true);
                $entries = is_array($save['entries'] ?? null) ? $save['entries'] : [];
            }
            $seed = $entries === [] ? ['items' => [], 'currants' => 0] : LedgerMath::holdings($entries, $this->economy);
            $seeded = $entries === [] ? 'new' : 'migrated';
            $budget = $seeded === 'migrated' ? $this->capacity : min($this->capacity, self::NEW_BUDGET);
            $this->db->execute("INSERT IGNORE INTO {$t} (user_id, currants, budget, budget_at, seeded, updated_at) VALUES (?, ?, ?, ?, ?, ?)", [$user, $seed['currants'], $budget, $now, $seeded, gmdate('Y-m-d H:i:s', $now)]);
            $this->writeItems($user, [], $seed['items']);
            if ($seeded === 'migrated') {
                $this->log($user, 'seed', ['currants' => $seed['currants'], 'items' => (object) $seed['items']], $now);
            }
            $row = $this->db->row("SELECT currants, budget, budget_at, seeded FROM {$t} WHERE user_id = ? FOR UPDATE", [$user]);
        }
        $items = [];
        foreach ($this->db->rows('SELECT class_tsid, count FROM ' . $this->db->table(self::ITEMS) . ' WHERE user_id = ?', [$user]) as $r) {
            $items[(string) $r['class_tsid']] = (int) $r['count'];
        }
        ksort($items);
        return ['currants' => (int) $row['currants'], 'items' => $items, 'budget' => (float) $row['budget'], 'budget_at' => (int) $row['budget_at'], 'seeded' => (string) $row['seeded']];
    }

    /** The ledger as the player may see it (no lock). */
    public function peek(int $user): ?array
    {
        $row = $this->db->row('SELECT currants, budget, budget_at, seeded FROM ' . $this->db->table(self::TABLE) . ' WHERE user_id = ?', [$user]);
        if ($row === null) {
            return null;
        }
        $items = [];
        foreach ($this->db->rows('SELECT class_tsid, count FROM ' . $this->db->table(self::ITEMS) . ' WHERE user_id = ? ORDER BY class_tsid', [$user]) as $r) {
            $items[(string) $r['class_tsid']] = (int) $r['count'];
        }
        return ['currants' => (int) $row['currants'], 'items' => (object) $items, 'budget' => $this->refilled((float) $row['budget'], (int) $row['budget_at'], time()), 'seeded' => (string) $row['seeded']];
    }

    private function store(int $user, array $before, array $after, float $budget, int $now): void
    {
        $this->db->execute('UPDATE ' . $this->db->table(self::TABLE) . ' SET currants = ?, budget = ?, budget_at = ?, updated_at = ? WHERE user_id = ?', [$after['currants'], $budget, $now, gmdate('Y-m-d H:i:s', $now), $user]);
        $this->writeItems($user, $before['items'], $after['items']);
    }

    private function writeItems(int $user, array $before, array $after): void
    {
        $t = $this->db->table(self::ITEMS);
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $c) {
            $n = (int) ($after[$c] ?? 0);
            if ($n === (int) ($before[$c] ?? 0)) {
                continue;
            }
            if ($n <= 0) {
                $this->db->execute("DELETE FROM {$t} WHERE user_id = ? AND class_tsid = ?", [$user, (string) $c]);
            } else {
                $this->db->execute("INSERT INTO {$t} (user_id, class_tsid, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)", [$user, (string) $c, $n]);
            }
        }
    }

    /**
     * The budget refills on the wall clock (open question 1 in
     * g335-economy.md: connected time instead would be the realtime
     * server's to report, through the credits it already sends; only this
     * method would change).
     */
    private function refilled(float $budget, int $at, int $now): float
    {
        return min($this->capacity, $budget + max(0, $now - $at) / 60 * $this->rate);
    }

    /**
     * Gives value back into a character's budget (never above its capacity):
     * a realtime server credit that came after the write that showed it,
     * which the budget paid for then (LedgerCredits::record).
     */
    public function refund(int $user, float $value, int $now): void
    {
        $state = $this->lock($user, $now);
        $budget = min($this->capacity, $this->refilled($state['budget'], $state['budget_at'], $now) + max(0.0, $value));
        $this->db->execute('UPDATE ' . $this->db->table(self::TABLE) . ' SET budget = ?, budget_at = ? WHERE user_id = ?', [$budget, $now, $user]);
    }

    public function log(int $user, string $kind, array $detail, int $now): void
    {
        $this->db->execute('INSERT INTO ' . $this->db->table(self::LOG) . ' (user_id, at, kind, detail) VALUES (?, ?, ?, ?)', [$user, gmdate('Y-m-d H:i:s', $now), $kind, json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }

    private function logClamp(int $user, array $detail, int $now): void
    {
        $t = $this->db->table(self::LOG);
        $last = $this->db->row("SELECT id, detail FROM {$t} WHERE user_id = ? AND kind = 'clamp' AND at >= ? ORDER BY id DESC LIMIT 1", [$user, gmdate('Y-m-d H:i:s', $now - self::CLAMP_LOG_EVERY)]);
        if ($last !== null) {
            $was = json_decode((string) $last['detail'], true);
            $was = is_array($was) ? $was : [];
            $was['repeats'] = (int) ($was['repeats'] ?? 0) + 1;
            $was['last'] = $detail;
            $this->db->execute("UPDATE {$t} SET detail = ? WHERE id = ?", [json_encode($was, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int) $last['id']]);
            return;
        }
        $this->log($user, 'clamp', $detail, $now);
    }

    // —— Settled trades a save has not applied ——

    /**
     * Settled trades this character has not applied, as LedgerMath::view
     * takes them, after stamping those the save lists as applied and those
     * older than PENDING_FOR. $applied: the save's applied trade ids, or
     * null to stamp nothing (a read).
     *
     * @return list<array{trade: string, gave: array, got: array}>
     */
    public function pending(int $user, ?array $applied, int $now): array
    {
        $t = $this->db->table(self::TRADES);
        $out = [];
        $rows = $this->db->rows("SELECT trade_id, a_id, b_id, a_gave, b_gave, a_applied_at, b_applied_at, created_at FROM {$t} WHERE status = 'settled' AND ((a_id = ? AND a_applied_at IS NULL) OR (b_id = ? AND b_applied_at IS NULL)) ORDER BY created_at, trade_id", [$user, $user]);
        foreach ($rows as $r) {
            $mine = (int) $r['a_id'] === $user && $r['a_applied_at'] === null ? 'a' : 'b';
            $gaveA = LedgerMath::goods(json_decode((string) $r['a_gave'], true));
            $gaveB = LedgerMath::goods(json_decode((string) $r['b_gave'], true));
            $id = (string) $r['trade_id'];
            $old = strtotime((string) $r['created_at'] . ' UTC') < $now - self::PENDING_FOR;
            if ($applied !== null && (in_array($id, $applied, true) || $old)) {
                $this->stamp($id, $mine, $now);
                continue;
            }
            $out[] = $mine === 'a' ? ['trade' => $id, 'gave' => $gaveA, 'got' => $gaveB] : ['trade' => $id, 'gave' => $gaveB, 'got' => $gaveA];
        }
        return $out;
    }

    public function stamp(string $trade, string $side, int $now): void
    {
        $col = $side === 'a' ? 'a_applied_at' : 'b_applied_at';
        $this->db->execute('UPDATE ' . $this->db->table(self::TRADES) . " SET {$col} = ? WHERE trade_id = ? AND {$col} IS NULL", [gmdate('Y-m-d H:i:s', $now), $trade]);
    }

    // —— Save writes and reads ——

    /**
     * Checks a save write against the ledger and moves the ledger by what
     * stands. Returns the entries to store (with anything clamped taken
     * out), what was clamped and the words for the player.
     *
     * @return array{entries: array, clamped: array, text: string}
     */
    public function write(int $user, array $entries, int $now): array
    {
        $state = $this->lock($user, $now);
        $ledger = ['items' => $state['items'], 'currants' => $state['currants']];
        $day = gmdate('Y-m-d', $now);
        $credited = $this->credited($user, $day);
        if (!isset($credited['base'])) {
            // First checked write (or the first since slice 2): what the save had done already is marked, not paid.
            $stored = $this->db->value('SELECT data FROM ' . $this->db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ?', [$user]);
            $was = is_string($stored) ? json_decode($stored, true) : null;
            $base = is_array($was['entries'] ?? null) ? array_filter($was['entries'], 'is_string') : $entries;
            $this->credit($user, LedgerMath::baseline($base, $this->economy), $day, $now);
            $credited = $this->credited($user, $day);
        }
        $pending = $this->pending($user, LedgerMath::applied($entries), $now);
        $budget = $this->refilled($state['budget'], $state['budget_at'], $now);
        // Slice 3: what the realtime server credited and the save has not shown yet (LedgerCredits).
        $receipts = LedgerCredits::open($this->db, $user, $now);
        $c = LedgerMath::check($ledger, $credited, $pending, $entries, $budget, $this->capacity, $this->economy, $receipts['goods']);
        $r = $c['reconciled'];
        $this->store($user, $ledger, $c['after'], $r['budget'], $now);
        $this->credit($user, $c['rows'], $day, $now);
        LedgerCredits::used($this->db, $user, $receipts, $c['allowed'], LedgerCredits::charged($r), $now);
        $credits = $c['credits'];
        if ($credits['detail'] !== []) {
            $this->log($user, 'credit', $credits['detail'] + ['goods' => ['items' => (object) $credits['goods']['items'], 'currants' => $credits['goods']['currants']]], $now);
        }
        $text = '';
        if (!LedgerMath::nothing($r['clamped'])) {
            $entries = LedgerMath::remove($entries, $r['clamped']);
            $text = LedgerMath::correctedText($r['clamped'], $this->economy);
            $this->logClamp($user, ['clamped' => ['items' => (object) $r['clamped']['items'], 'currants' => $r['clamped']['currants']], 'budget' => round($budget, 1), 'kept' => round($r['gained'], 1), 'made' => (object) $r['made']], $now);
        }
        return ['entries' => $entries, 'clamped' => $r['clamped'], 'text' => $text];
    }

    // —— Credited markers (slice 2) ——

    /**
     * What this character has been credited: kind -> ref -> n. Daily rows
     * (cap: a capped class's gains, cardday: a card's credited purchases,
     * lvlday: level-ups credited) come back for today only, their ref without the day; older ones are
     * deleted.
     *
     * @return array<string, array<string, int>>
     */
    private function credited(int $user, string $day): array
    {
        $t = $this->db->table(self::CREDITED);
        $out = [];
        $stale = false;
        foreach ($this->db->rows("SELECT kind, ref, n FROM {$t} WHERE user_id = ?", [$user]) as $r) {
            $kind = (string) $r['kind'];
            $ref = (string) $r['ref'];
            if (in_array($kind, LedgerMath::DAILY_KINDS, true)) {
                $parts = explode(':', $ref, 2);
                if ($parts[0] !== $day || !isset($parts[1])) {
                    $stale = true;
                    continue;
                }
                $ref = $parts[1];
            }
            $out[$kind][$ref] = (int) $r['n'];
        }
        if ($stale) {
            $this->db->execute("DELETE FROM {$t} WHERE user_id = ? AND kind IN ('cap', 'cardday', 'lvlday') AND ref < ?", [$user, $day]);
        }
        return $out;
    }

    /** Stores credited rows ([kind, ref, n], n the new total); daily kinds get today's date in front of their ref. */
    private function credit(int $user, array $rows, string $day, int $now): void
    {
        $t = $this->db->table(self::CREDITED);
        $at = gmdate('Y-m-d H:i:s', $now);
        foreach (array_chunk($rows, 100) as $chunk) {
            $sql = [];
            $args = [];
            foreach ($chunk as [$kind, $ref, $n]) {
                $sql[] = '(?, ?, ?, ?, ?)';
                array_push($args, $user, $kind, in_array($kind, LedgerMath::DAILY_KINDS, true) ? "{$day}:{$ref}" : $ref, max(0, (int) $n), $at);
            }
            $this->db->execute("INSERT INTO {$t} (user_id, kind, ref, n, at) VALUES " . implode(', ', $sql) . ' ON DUPLICATE KEY UPDATE n = VALUES(n), at = VALUES(at)', $args);
        }
    }

    /**
     * Applies to a stored save every settled trade it has not applied (a
     * boot read: the page holds no copy yet). Returns the entries and
     * whether anything changed.
     *
     * @return array{entries: array, changed: bool}
     */
    public function catchUp(int $user, array $entries, int $now): array
    {
        $this->lock($user, $now);
        $pending = $this->pending($user, LedgerMath::applied($entries), $now);
        foreach ($pending as $p) {
            // G332: a give carries a note's writing or a wrapped box (LedgerGift), which a count alone cannot; a furniture sale takes that very piece out of the house (LedgerFurniture); a picked-up tool its wear (LedgerDrop).
            $entries = match (true) {
                LedgerGift::isGift($p['trade']) => LedgerGift::catchUp($this->db, $entries, $user, $p),
                LedgerFurniture::isSale($p['trade']) => LedgerFurniture::catchUp($this->db, $entries, $user, $p),
                // A tool picked up keeps its wear (the stack's points), as a live pickup does.
                LedgerDrop::isTake($p['trade']) => LedgerDrop::catchUp($this->db, $entries, $user, $p),
                default => LedgerMath::applyTrade($entries, $p['trade'], $p['gave'], $p['got']),
            };
            $this->stamp($p['trade'], $this->sideOf($p['trade'], $user), $now);
            $this->log($user, 'credit', ['trade' => $p['trade'], 'applied' => 'on load'], $now);
        }
        return ['entries' => $entries, 'changed' => $pending !== []];
    }

    private function sideOf(string $trade, int $user): string
    {
        $row = $this->db->row('SELECT a_id, a_applied_at FROM ' . $this->db->table(self::TRADES) . ' WHERE trade_id = ?', [$trade]);
        return $row !== null && (int) $row['a_id'] === $user && $row['a_applied_at'] === null ? 'a' : 'b';
    }

    // —— Settling trades (LedgerService) ——

    /**
     * Settles a trade the realtime server finished: both givers' ledgers
     * must hold what they give, then both move and the trade is recorded
     * once. A trade already recorded answers as it was recorded.
     *
     * @param array{0: int, 1: int} $ids
     * @param array{0: array, 1: array} $gave the realtime server's goods for each side
     * @return array{status: string, short?: array}
     */
    public function settle(string $trade, array $ids, array $gave, int $now): array
    {
        $t = $this->db->table(self::TRADES);
        $held = $this->db->row("SELECT status FROM {$t} WHERE trade_id = ? FOR UPDATE", [$trade]);
        if ($held !== null) {
            return ['status' => (string) $held['status']];
        }
        $goods = [LedgerMath::goods($gave[0]), LedgerMath::goods($gave[1])];
        $states = [];
        foreach ($ids[0] < $ids[1] ? [0, 1] : [1, 0] as $i) {
            $states[$i] = $this->lock($ids[$i], $now);
        }
        $short = null;
        foreach ([0, 1] as $i) {
            foreach ($goods[$i]['items'] as $c => $n) {
                if (!$this->economy->tradeable((string) $c) || ($states[$i]['items'][$c] ?? 0) < $n) {
                    $short ??= ['id' => $ids[$i], 'c' => (string) $c];
                }
            }
            if ($short === null && $states[$i]['currants'] < $goods[$i]['currants']) {
                $short = ['id' => $ids[$i], 'currants' => true];
            }
        }
        $row = [$trade, $ids[0], $ids[1], json_encode($gave[0], JSON_UNESCAPED_SLASHES), json_encode($gave[1], JSON_UNESCAPED_SLASHES), gmdate('Y-m-d H:i:s', $now)];
        if ($short !== null) {
            $this->db->execute("INSERT INTO {$t} (trade_id, status, a_id, b_id, a_gave, b_gave, created_at) VALUES (?, 'void', ?, ?, ?, ?, ?)", $row);
            $this->log((int) $short['id'], 'refuse', ['trade' => $trade, 'short' => $short, 'gave' => $goods[$ids[0] === $short['id'] ? 0 : 1]], $now);
            return ['status' => 'refused', 'short' => $short];
        }
        foreach ([0, 1] as $i) {
            $o = 1 - $i;
            $before = ['items' => $states[$i]['items'], 'currants' => $states[$i]['currants']];
            $delta = ['items' => [], 'currants' => $goods[$o]['currants'] - $goods[$i]['currants']];
            foreach ($goods[$i]['items'] as $c => $n) {
                $delta['items'][$c] = ($delta['items'][$c] ?? 0) - $n;
            }
            foreach ($goods[$o]['items'] as $c => $n) {
                $delta['items'][$c] = ($delta['items'][$c] ?? 0) + $n;
            }
            $after = LedgerMath::add($before, $delta);
            $this->db->execute('UPDATE ' . $this->db->table(self::TABLE) . ' SET currants = ?, updated_at = ? WHERE user_id = ?', [$after['currants'], gmdate('Y-m-d H:i:s', $now), $ids[$i]]);
            $this->writeItems($ids[$i], $before['items'], $after['items']);
            $this->log($ids[$i], 'trade', ['trade' => $trade, 'with' => $ids[$o], 'gave' => ['items' => (object) $goods[$i]['items'], 'currants' => $goods[$i]['currants']], 'got' => ['items' => (object) $goods[$o]['items'], 'currants' => $goods[$o]['currants']]], $now);
        }
        $this->db->execute("INSERT INTO {$t} (trade_id, status, a_id, b_id, a_gave, b_gave, created_at) VALUES (?, 'settled', ?, ?, ?, ?, ?)", $row);
        return ['status' => 'settled'];
    }

    /**
     * Makes sure a trade never settles from now on, unless it already has:
     * the answer is its final status, with what each side gave when settled.
     * $ids name the two players (kept on the void row, for the record).
     *
     * @return array{status: string, ids?: array, gave?: array}
     */
    public function void(string $trade, array $ids, int $now): array
    {
        $t = $this->db->table(self::TRADES);
        $row = $this->db->row("SELECT status, a_id, b_id, a_gave, b_gave FROM {$t} WHERE trade_id = ? FOR UPDATE", [$trade]);
        if ($row === null) {
            $this->db->execute("INSERT INTO {$t} (trade_id, status, a_id, b_id, a_gave, b_gave, created_at) VALUES (?, 'void', ?, ?, '{}', '{}', ?)", [$trade, max(0, (int) $ids[0]), max(0, (int) $ids[1]), gmdate('Y-m-d H:i:s', $now)]);
            return ['status' => 'void'];
        }
        if ($row['status'] !== 'settled') {
            return ['status' => (string) $row['status']];
        }
        return ['status' => 'settled', 'ids' => [(int) $row['a_id'], (int) $row['b_id']], 'gave' => [json_decode((string) $row['a_gave']), json_decode((string) $row['b_gave'])]];
    }
}
