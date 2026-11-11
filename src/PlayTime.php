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
 * Play time (Austin 2026-11-04): "A running total time stored in the user's
 * meta data for how long they've played. No need to make that visible
 * anywhere. I just want to collect that info for administrator purposes."
 * Nothing a player sees reads it, and there is no console tab.
 *
 * The realtime server measures each player's active time in the game
 * (groddle-afternoon-server playtime.go: a game socket from sign-in to close,
 * idle stretches past five minutes left out, several tabs counted once, the
 * website's /chat/ not counted) and sends it in signed batches (ServiceCall),
 * about once a minute and when a player leaves:
 *
 *   POST groddle/v1/play-time
 *        {"id":"pt-…","players":[{"user":7,"seconds":60,"last":1730000000},…],"counts":{"2026-11-11":{"pig:nibble":3}}}
 *        200 {"done":"pt-…","added":n}   added 0 and "again" true: the batch was taken before
 *
 * "counts" (optional) are the world's tallies the server rides on the batch (its tally.go: per UTC day, the acts
 * on the shared world that landed, everyone together): checked here (counts()) and kept by Analytics, once with
 * the batch. A batch may carry counts and no players. Analytics also takes each batch's players as that day's
 * active players and their seconds as the day's play time (Analytics::fromBatch, in the same transaction).
 *
 * Each player's seconds go onto user meta groddle_play_seconds (a running
 * integer, added in the database: UPDATE … + n, under a lock on the user's
 * row) and groddle_play_last (unix seconds, the end of the last stretch
 * counted, the later of the two). The batch id is kept (table
 * groddle_play_batches, KEEP days) in the same transaction, so a batch sent
 * again (a lost answer, a restart) adds nothing. No other meta is read or
 * written; a removed account's entry is skipped. Users 1 and 54 are counted
 * like any player: these two keys are their own play time.
 *
 * Before this was built, the online save is the only trace of play: the game
 * writes it at most every 15 seconds while a player plays online (save-store's
 * write-behind; the save row's version counts the writes). The backfill keeps
 * that estimate apart, in groddle_play_seconds_before = version x 15, set once
 * (only where it is absent), never added to the measured total:
 *
 *   POST groddle/v1/admin/play-time/backfill {"users":[ids]}?   (no body: every save)
 *        {"set":n,"kept":n,"skipped":n,"per_write":15}
 *
 * Reading it, administrators only (manage_options), no WP-CLI needed:
 *
 *   GET groddle/v1/admin/play-time?sort=seconds|before|total
 *        {"players":[{"id","name","seconds","before","total","last","last_at","hours":{"seconds","before","total"}}],
 *         "totals":{"players","seconds","before","total","hours":{…}},"sort"}
 *
 * sorted by the measured seconds (default), the estimate, or their sum.
 */
final readonly class PlayTime
{
    public const ROUTE = '/groddle/v1/play-time';
    public const TABLE = 'groddle_play_batches';

    public const META = 'groddle_play_seconds';
    public const LAST = 'groddle_play_last';
    public const BEFORE = 'groddle_play_seconds_before';

    /** Players in one batch (playtime.go sends at most playBatchMax, 500). */
    public const BATCH = 1000;

    /** The most one entry may add: a month (a site down that long, a player who never left), against a bug. */
    public const MOST = 31 * 86400;

    /** How far past the site's clock a "last" may be (the two clocks' slack, seconds). */
    public const SKEW = 60;

    /** A tally key (the realtime server's tally.go tallyKeyRe), a day's keys at most, a count at most, days a batch at most. */
    public const TALLY_KEY = '/^[a-z][a-z0-9_]{0,31}:[a-z][a-z0-9_]{0,23}$/';
    public const TALLY_KEYS = 256;
    public const TALLY_MOST = 1000000;
    public const TALLY_DAYS = 8;

    /** Batch ids are kept this long (days); the realtime server never resends one that old. */
    public const KEEP = 90;

    /** The online save's write interval (save-store write-behind), seconds a save version stands for. */
    public const PER_WRITE = 15;

    public const SORTS = ['seconds', 'before', 'total'];

    private const ADMIN = 'manage_options';

    public function __construct(private Seams $minn)
    {
    }

    // —— The realtime server's batches ——

    #[Route(Method::Post, self::ROUTE, policy: new Policy(Access::Public))]
    public function take(Request $request): Response
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', self::ROUTE, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
        $now = time();
        $batch = self::batch(json_decode($request->body, true), $now);
        if ($batch === null) {
            throw new RestError('groddle_play_invalid', 'Not a batch of play time.', 400);
        }
        $db = $this->minn->db;
        $added = Ledger::atomically($db, static fn (): ?int => self::record($db, $batch, $now));
        $out = ['done' => $batch['id'], 'added' => $added ?? 0];
        if ($added === null) {
            $out['again'] = true;
        }
        return Response::json($out);
    }

    /** playtime.go: pt-<process>-<n>, lowercase letters, digits and dashes, at most 40. */
    public static function id(mixed $id): bool
    {
        return is_string($id) && preg_match('/^pt-[a-z0-9-]{1,37}$/', $id) === 1;
    }

    /**
     * A batch as the realtime server sends it, checked: its good entries, one
     * per player (two for one player are summed), "last" never past the site's
     * clock plus SKEW. Null when it is not a batch at all.
     *
     * @return array{id: string, players: list<array{user: int, seconds: int, last: int}>, counts?: array<string, array<string, int>>}|null
     */
    public static function batch(mixed $body, int $now): ?array
    {
        if (!is_array($body) || !self::id($body['id'] ?? null) || !is_array($body['players'] ?? null)
            || !array_is_list($body['players']) || count($body['players']) > self::BATCH) {
            return null;
        }
        $by = [];
        foreach ($body['players'] as $e) {
            $user = $e['user'] ?? null;
            $secs = $e['seconds'] ?? null;
            $last = $e['last'] ?? null;
            if (!is_array($e) || !is_int($user) || $user <= 0 || !is_int($secs) || $secs <= 0 || $secs > self::MOST || !is_int($last) || $last <= 0) {
                continue; // never added; the batch is still answered, so the server stops sending it
            }
            $p = $by[$user] ?? ['user' => $user, 'seconds' => 0, 'last' => 0];
            $p['seconds'] = min(self::MOST, $p['seconds'] + $secs);
            $p['last'] = max($p['last'], min($last, $now + self::SKEW));
            $by[$user] = $p;
        }
        ksort($by);
        $out = ['id' => $body['id'], 'players' => array_values($by)];
        $counts = self::counts($body['counts'] ?? null, $now);
        if ($counts !== []) {
            $out['counts'] = $counts;
        }
        return $out;
    }

    /**
     * A batch's tallies, checked: days (Y-m-d) from KEEP days back to tomorrow, at most TALLY_DAYS of them, keys of
     * the server's shape, at most TALLY_KEYS a day, each count a whole number from 1 to TALLY_MOST. Anything else
     * is left out (the batch is still answered).
     *
     * @return array<string, array<string, int>>
     */
    public static function counts(mixed $counts, int $now): array
    {
        if (!is_array($counts)) {
            return [];
        }
        $out = [];
        foreach ($counts as $day => $keys) {
            $ts = is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? strtotime("{$day} 00:00:00 UTC") : false;
            if ($ts === false || gmdate('Y-m-d', $ts) !== $day || $ts < $now - self::KEEP * 86400 || $ts > $now + 86400 || !is_array($keys) || count($out) >= self::TALLY_DAYS) {
                continue;
            }
            $kept = [];
            foreach ($keys as $key => $n) {
                if (is_string($key) && preg_match(self::TALLY_KEY, $key) === 1 && is_int($n) && $n >= 1 && $n <= self::TALLY_MOST && count($kept) < self::TALLY_KEYS) {
                    $kept[$key] = $n;
                }
            }
            if ($kept !== []) {
                ksort($kept);
                $out[$day] = $kept;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Records a batch once, in the caller's transaction: its id, then each
     * player's seconds and last. Null when the id was taken before (nothing
     * changes); else the players added.
     *
     * @param array{id: string, players: list<array{user: int, seconds: int, last: int}>} $batch
     */
    public static function record(Db $db, array $batch, int $now): ?int
    {
        $t = $db->table(self::TABLE);
        $seconds = array_sum(array_column($batch['players'], 'seconds'));
        $fresh = $db->execute(
            "INSERT IGNORE INTO {$t} (id, players, seconds, created_at) VALUES (?, ?, ?, ?)",
            [$batch['id'], count($batch['players']), $seconds, gmdate('Y-m-d H:i:s', $now)],
        );
        if ($fresh === 0) {
            return null;
        }
        $db->execute("DELETE FROM {$t} WHERE created_at < ? LIMIT 100", [gmdate('Y-m-d H:i:s', $now - self::KEEP * 86400)]);
        $added = 0;
        foreach ($batch['players'] as $p) {
            if (self::add($db, $p['user'], $p['seconds'], $p['last'])) {
                $added++;
            }
        }
        // The console's Analytics tab: the day's active players and play time, and the world's tallies.
        Analytics::fromBatch($db, $batch, $now);
        return $added;
    }

    /** Adds one player's seconds and moves their last forward; false for an account that is gone. */
    public static function add(Db $db, int $user, int $seconds, int $last): bool
    {
        // The user's row, locked: one writer per player at a time, so a first row is made once.
        if ($db->value('SELECT ID FROM ' . $db->table('users') . ' WHERE ID = ? FOR UPDATE', [$user]) === null) {
            return false;
        }
        $meta = $db->table('usermeta');
        if (self::metaId($db, $user, self::META) === null) {
            $db->execute("INSERT INTO {$meta} (user_id, meta_key, meta_value) VALUES (?, ?, ?)", [$user, self::META, (string) $seconds]);
        } else {
            $db->execute("UPDATE {$meta} SET meta_value = CAST(meta_value AS UNSIGNED) + ? WHERE user_id = ? AND meta_key = ?", [$seconds, $user, self::META]);
        }
        if (self::metaId($db, $user, self::LAST) === null) {
            $db->execute("INSERT INTO {$meta} (user_id, meta_key, meta_value) VALUES (?, ?, ?)", [$user, self::LAST, (string) $last]);
        } else {
            $db->execute("UPDATE {$meta} SET meta_value = GREATEST(CAST(meta_value AS UNSIGNED), ?) WHERE user_id = ? AND meta_key = ?", [$last, $user, self::LAST]);
        }
        return true;
    }

    private static function metaId(Db $db, int $user, string $key): ?int
    {
        $id = $db->value('SELECT umeta_id FROM ' . $db->table('usermeta') . ' WHERE user_id = ? AND meta_key = ? LIMIT 1', [$user, $key]);
        return $id === null ? null : (int) $id;
    }

    // —— Administrators ——

    #[Route(Method::Get, '/groddle/v1/admin/play-time', policy: new Policy(Access::Cap, self::ADMIN))]
    public function index(Request $request): Response
    {
        $db = $this->minn->db;
        $rows = $db->rows(
            'SELECT user_id, meta_key, meta_value FROM ' . $db->table('usermeta') . ' WHERE meta_key IN (?)',
            [[self::META, self::LAST, self::BEFORE]],
        );
        $ids = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['user_id'], $rows)));
        $names = (new Characters($db))->namesOf($ids);
        $sort = $request->query('sort');
        return Response::json(self::report($rows, $names, is_string($sort) ? $sort : 'seconds'))->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * The administrators' list from the meta rows: one entry per account that
     * still exists (names: id => name, Characters::namesOf), sorted.
     *
     * @param list<array<string, mixed>> $rows user_id, meta_key, meta_value
     * @param array<int, string> $names
     * @return array{players: list<array<string, mixed>>, totals: array<string, mixed>, sort: string}
     */
    public static function report(array $rows, array $names, string $sort): array
    {
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'seconds';
        $by = [];
        foreach ($rows as $r) {
            $id = (int) $r['user_id'];
            if (!isset($names[$id])) {
                continue;
            }
            $v = max(0, (int) $r['meta_value']);
            $p = $by[$id] ?? ['id' => $id, 'name' => $names[$id], 'seconds' => 0, 'before' => 0, 'last' => null];
            match ((string) $r['meta_key']) {
                self::META => $p['seconds'] += $v,
                self::BEFORE => $p['before'] = max($p['before'], $v),
                self::LAST => $p['last'] = max((int) $p['last'], $v),
                default => null,
            };
            $by[$id] = $p;
        }
        $players = [];
        foreach ($by as $p) {
            $p['total'] = $p['seconds'] + $p['before'];
            $p['last_at'] = $p['last'] !== null && $p['last'] > 0 ? gmdate('Y-m-d\TH:i:s\Z', $p['last']) : null;
            $p['hours'] = self::hours($p);
            $players[] = $p;
        }
        usort($players, static fn (array $a, array $b): int => [$b[$sort], $b['total'], $a['id']] <=> [$a[$sort], $a['total'], $b['id']]);
        $totals = [
            'players' => count($players),
            'seconds' => array_sum(array_column($players, 'seconds')),
            'before' => array_sum(array_column($players, 'before')),
        ];
        $totals['total'] = $totals['seconds'] + $totals['before'];
        $totals['hours'] = self::hours($totals);
        return ['players' => $players, 'totals' => $totals, 'sort' => $sort];
    }

    /** @param array<string, mixed> $p @return array{seconds: float, before: float, total: float} */
    private static function hours(array $p): array
    {
        return [
            'seconds' => round($p['seconds'] / 3600, 1),
            'before' => round($p['before'] / 3600, 1),
            'total' => round($p['total'] / 3600, 1),
        ];
    }

    #[Route(Method::Post, '/groddle/v1/admin/play-time/backfill', policy: new Policy(Access::Cap, self::ADMIN))]
    public function backfill(Request $request): Response
    {
        $body = $request->body === '' ? [] : json_decode($request->body, true);
        $only = is_array($body) && isset($body['users']) ? $body['users'] : null;
        if ($only !== null && (!is_array($only) || !array_is_list($only) || array_filter($only, static fn ($id) => !is_int($id) || $id <= 0) !== [])) {
            throw new RestError('groddle_play_invalid', 'users is a list of account ids.', 400);
        }
        $db = $this->minn->db;
        $saves = $db->table(MultiplayerSave::TABLE);
        $users = $db->table('users');
        $sql = "SELECT s.user_id, s.version FROM {$saves} s JOIN {$users} u ON u.ID = s.user_id";
        $rows = $only === null ? $db->rows($sql . ' ORDER BY s.user_id') : ($only === [] ? [] : $db->rows($sql . ' WHERE s.user_id IN (?) ORDER BY s.user_id', [array_values(array_unique($only))]));
        $out = ['set' => 0, 'kept' => 0, 'skipped' => 0, 'per_write' => self::PER_WRITE];
        foreach ($rows as $r) {
            $before = self::beforeSeconds((int) $r['version']);
            if ($before <= 0) {
                $out['skipped']++;
                continue;
            }
            $set = Ledger::atomically($db, static fn (): bool => self::setBefore($db, (int) $r['user_id'], $before));
            $out[$set ? 'set' : 'kept']++;
        }
        return Response::json($out)->withHeader('Cache-Control', 'private, no-store');
    }

    /** The estimate of play before measuring: a save version counts one write, at most one each PER_WRITE seconds. */
    public static function beforeSeconds(int $version): int
    {
        return max(0, $version) * self::PER_WRITE;
    }

    /** Sets a player's estimate once: false (and nothing changes) when it is there already. */
    public static function setBefore(Db $db, int $user, int $seconds): bool
    {
        if ($db->value('SELECT ID FROM ' . $db->table('users') . ' WHERE ID = ? FOR UPDATE', [$user]) === null || self::metaId($db, $user, self::BEFORE) !== null) {
            return false;
        }
        $db->execute('INSERT INTO ' . $db->table('usermeta') . ' (user_id, meta_key, meta_value) VALUES (?, ?, ?)', [$user, self::BEFORE, (string) $seconds]);
        return true;
    }
}
