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

/**
 * The console's Ledger tab (G335 E0, glitch-audit research/coverage/g335-slice2-plan.md section 3; Austin
 * 2026-11-13: build E0 this cycle). A watch, read only: it changes nothing a player sees and writes nothing.
 * Per character per UTC day it shows what the ledger credited, by kind (quests, badges, level-ups, item cards,
 * the realtime server's receipts, other), the budget's refill, clamps, and the value that left for other
 * characters (trades, mail, auctions), with a plain flag for anything past the honest maxima below.
 *
 *   GET admin/ledger?days=1..14 (7)   manage_options only
 *       {"today","since","days","maxima":[{key,n,words}],"truncated":bool,
 *        "characters":[{"player":{id,name},"budget","seeded","created","drawn","online_s","flags":[Flag],"flagged",
 *                       "days":[Day]}]}
 *       Day:  {"day","credits":{"quests":{n,currants,write},"badges":{n,currants},"levels":{gained,paid,currants,jump},
 *              "cards":{n,value},"server":{n,currants,value},"other":{value},"value"},"budget":{refill,online_s,late},
 *              "clamps":{n,value},"outbound":{"trade":{gave,got},"mail":{gave,got},"auction":{gave,got},"net"},
 *              "flags":[Flag]}
 *       Flag: {"kind","text"}
 *
 * Where each figure comes from (no new tables; read() adds nothing):
 * - groddle_ledger_log "credit" rows. A save write's row (Ledger::write) carries what LedgerMath::credits paid:
 *   quests {id: currants}, badges {id: currants}, level [was, now, currants], waited {level: currants} (paying
 *   levels an earlier day's count left waiting), deferred {level: [...]} (past today's count), cards {id: n} and
 *   goods. A realtime server row (LedgerCredits::logCredits, one a minute merged) carries server {why: n}, got,
 *   late (budget given back) and, for connected time, online_s and refill (what those seconds put in the budget,
 *   after the cap). A machine row (HomeMachines) carries got. Rows applying a settled trade carry nothing paid.
 * - groddle_ledger_log "clamp" rows (Ledger::logClamp): one a minute per character, repeats counted on it with
 *   the last clamp's detail, so a clamp row's value is the first clamp plus the last (at least what was taken).
 * - groddle_trades, settled rows: a trade between two characters (both ids set), mail (mail-<id>-s sent, -c
 *   collected, LedgerMail::op) and auctions (auc-<id>-l|b|p|d, LedgerAuction::op). Value is the economy table's
 *   (Economy::value, currants at 1).
 * - groddle_ledger: the budget now and how the ledger was seeded; groddle_players: when the character was made.
 *
 * The budget drawn is not logged per write (Ledger::write keeps only the budget left), so the page gives what the
 * logs prove: the budget refilled and given back each day, and over the range "drawn at least" = refilled + given
 * back - the budget now (the budget at the range's start is unknown, between 0 and the capacity, so the real
 * figure can only be higher).
 *
 * Indexes: the log is read by time for everyone and trades by when they were made, so minn.json adds KEY at to
 * groddle_ledger_log and KEY created_at to groddle_trades (added indexes only; nothing else in the schema changes).
 */
final readonly class LedgerWatch
{
    // The honest maxima, in one place. Estimates until a copy of the live tables measures them (plan section 1.6
    // and question 3, E1 to E3, next cycle); flags read "worth a look", never "cheat".

    /**
     * Level-ups a UTC day: measured on live 2026-11-12 (LedgerMath::LEVELS_DAILY's comment: in 221 player-days the
     * most anyone gained in a day was 12). Counted here from the save's own levels (paid, waiting or free), so a
     * jump past the daily credit count still shows.
     */
    public const LEVELS_DAY = LedgerMath::LEVELS_DAILY;

    /**
     * Levels one save write can carry: the largest xp one quest gives (master_milker, 1,400; assets/content/
     * quests.json rewards.xp, times the best reward multiplier 1.3) crosses at most 2 levels from any level of the
     * xp table (economy.json credits.xp, js/progression.js XP_FOR_LEVEL); 1 more for the ordinary xp around it.
     * A save goes out 2.5 s after a change (js/save-store.js debounceMs), so a bigger jump means one write was held
     * a long time or the levels were typed in. The game repo's test recomputes the 2 from the data.
     */
    public const LEVEL_JUMP = 3;

    /**
     * Quests one save write can carry: a quest completes at its turn-in, one at a time (js/quest-offers.js
     * turnInQuest), and the write follows within 2.5 s, so a write names 1, now and then 2 or 3 (a chain that
     * completes the next one at once). Forging adds them all in one write.
     */
    public const QUESTS_WRITE = 3;

    /**
     * Quests a UTC day: a guess at a long honest day (about one an hour over 12 hours of play), not derived. The
     * data alone allows far more: of the 160 credited quests 86 are offered on learning a skill, and at their base
     * study costs (skills.json pointCost, seconds) 29 skills fit in 24 hours, offering 32 quests; 12 levels offer 6
     * more by level and 6 by xp. The live copy's quests-per-day query (plan section 1.6) sets it next cycle.
     */
    public const QUESTS_DAY = 12;

    /**
     * Hours connected to the realtime server in a UTC day: a guess at a very long day of play. A socket held open
     * all day earns 24 hours of budget (Ledger::RATE 100 a minute, 144,000 a day; plan section 1.3), and E1 moves the
     * refill to active play.
     */
    public const ONLINE_HOURS_DAY = 16;

    /**
     * What honest play draws from the budget a connected minute: 38.7 at most, measured with two honest players
     * (g335-economy.md section 7; Ledger::RATE's comment), the reason RATE is 100.
     */
    public const DRAWN_PER_MINUTE = 38.7;

    /** The first UTC days of a character (plan section 1.3's worst case is a fresh character's first 3). */
    public const NEW_DAYS = 3;

    /** Ranges the tab offers (days). */
    public const DAYS_MAX = 14;
    public const DAYS_DEFAULT = 7;

    /** Log rows read at most (in pages): past it the answer says truncated. */
    public const PAGE = 5000;
    public const ROWS_MAX = 400000;
    public const TRADES_MAX = 100000;

    private const ADMIN = 'manage_options';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/admin/ledger', policy: new Policy(Access::Cap, self::ADMIN))]
    public function index(Request $request): Response
    {
        $days = $request->query('days');
        return Response::json(self::read($this->minn->db, self::days($days), time(), Economy::shared()))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** The range asked for: whole days from 1 to DAYS_MAX, else the default. */
    public static function days(mixed $days): int
    {
        $n = is_string($days) && ctype_digit($days) ? (int) $days : (is_int($days) ? $days : self::DAYS_DEFAULT);
        return $n >= 1 && $n <= self::DAYS_MAX ? $n : self::DAYS_DEFAULT;
    }

    /** The maxima as the page lists them. @return list<array{key: string, n: int|float, words: string}> */
    public static function maxima(): array
    {
        return [
            ['key' => 'quests_day', 'n' => self::QUESTS_DAY, 'words' => 'quests credited in a day (a guess at a long day; the live copy measures it next cycle)'],
            ['key' => 'quests_write', 'n' => self::QUESTS_WRITE, 'words' => 'quests in one save (they complete one at a time, and a save goes out 2.5 seconds later)'],
            ['key' => 'levels_day', 'n' => self::LEVELS_DAY, 'words' => 'levels in a day (measured on live 2026-11-12)'],
            ['key' => 'level_jump', 'n' => self::LEVEL_JUMP, 'words' => 'levels in one save (the biggest quest xp crosses 2, plus 1)'],
            ['key' => 'online_day', 'n' => self::ONLINE_HOURS_DAY, 'words' => 'hours connected in a day (a guess; a held socket gets 24)'],
            ['key' => 'drawn_minute', 'n' => self::DRAWN_PER_MINUTE, 'words' => 'budget drawn a connected minute (measured with two honest players)'],
            ['key' => 'new_days', 'n' => self::NEW_DAYS, 'words' => 'first days of a character, when sending out more than the day brought in is flagged'],
        ];
    }

    // Reading (the route)

    /** The whole answer from the database. */
    public static function read(Db $db, int $days, int $now, Economy $economy): array
    {
        $since = gmdate('Y-m-d', $now - ($days - 1) * 86400);
        $from = $since . ' 00:00:00';
        $acc = [];
        $truncated = false;
        $log = $db->table(Ledger::LOG);
        $first = $db->value("SELECT MIN(id) FROM {$log} WHERE at >= ?", [$from]);
        if ($first !== null) {
            $next = (int) $first;
            $read = 0;
            do {
                $rows = $db->rows("SELECT id, user_id, at, kind, detail FROM {$log} WHERE id >= ? AND kind IN ('credit', 'clamp') ORDER BY id LIMIT " . self::PAGE, [$next]);
                foreach ($rows as $r) {
                    $next = (int) $r['id'] + 1;
                    self::foldLog($acc, $r, $economy);
                }
                $read += count($rows);
                if ($read >= self::ROWS_MAX && count($rows) === self::PAGE) {
                    $truncated = true;
                    break;
                }
            } while (count($rows) === self::PAGE);
        }
        $trades = $db->rows('SELECT trade_id, a_id, b_id, a_gave, b_gave, created_at FROM ' . $db->table(Ledger::TRADES)
            . " WHERE created_at >= ? AND status = 'settled' AND (trade_id LIKE 'mail-%' OR trade_id LIKE 'auc-%' OR (a_id > 0 AND b_id > 0)) LIMIT " . self::TRADES_MAX, [$from]);
        $truncated = $truncated || count($trades) === self::TRADES_MAX;
        foreach ($trades as $t) {
            self::foldTrade($acc, $t, $economy);
        }
        $ids = array_keys($acc);
        $context = ['names' => [], 'ledger' => [], 'created' => []];
        if ($ids !== []) {
            $context['names'] = (new Characters($db))->namesOf($ids);
            foreach ($db->rows('SELECT user_id, budget, seeded FROM ' . $db->table(Ledger::TABLE) . ' WHERE user_id IN (?)', [$ids]) as $r) {
                $context['ledger'][(int) $r['user_id']] = ['budget' => (float) $r['budget'], 'seeded' => (string) $r['seeded']];
            }
            foreach ($db->rows('SELECT user_id, created_at FROM ' . $db->table(Characters::TABLE) . ' WHERE user_id IN (?)', [$ids]) as $r) {
                $context['created'][(int) $r['user_id']] = substr((string) $r['created_at'], 0, 10);
            }
        }
        return self::report($acc, $context, $since, gmdate('Y-m-d', $now), $days) + ['truncated' => $truncated];
    }

    // Folding rows (pure)

    /** An empty day. */
    public static function blankDay(string $day): array
    {
        return [
            'day' => $day,
            'credits' => [
                'quests' => ['n' => 0, 'currants' => 0, 'write' => 0],
                'badges' => ['n' => 0, 'currants' => 0],
                'levels' => ['gained' => 0, 'paid' => 0, 'currants' => 0, 'jump' => 0],
                'cards' => ['n' => 0, 'value' => 0],
                'server' => ['n' => 0, 'currants' => 0, 'value' => 0],
                'other' => ['value' => 0],
                'value' => 0,
            ],
            'budget' => ['refill' => 0.0, 'online_s' => 0, 'late' => 0.0],
            'clamps' => ['n' => 0, 'value' => 0],
            'outbound' => ['trade' => ['gave' => 0, 'got' => 0], 'mail' => ['gave' => 0, 'got' => 0], 'auction' => ['gave' => 0, 'got' => 0], 'net' => 0],
            'flags' => [],
        ];
    }

    /** Adds one groddle_ledger_log row (id, user_id, at, kind, detail) to $acc[user][day]. */
    public static function foldLog(array &$acc, array $row, Economy $economy): void
    {
        $user = (int) ($row['user_id'] ?? 0);
        $day = substr((string) ($row['at'] ?? ''), 0, 10);
        if ($user <= 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return;
        }
        $d = json_decode((string) ($row['detail'] ?? ''), true);
        if (!is_array($d)) {
            return;
        }
        $acc[$user][$day] ??= self::blankDay($day);
        $x = &$acc[$user][$day];
        if (($row['kind'] ?? '') === 'clamp') {
            $x['clamps']['n'] += 1 + max(0, (int) ($d['repeats'] ?? 0));
            $x['clamps']['value'] += (int) round(self::worth($d['clamped'] ?? null, $economy) + (isset($d['last']) && is_array($d['last']) ? self::worth($d['last']['clamped'] ?? null, $economy) : 0));
            return;
        }
        if (($row['kind'] ?? '') !== 'credit') {
            return;
        }
        $c = &$x['credits'];
        $sum = static fn (mixed $map): int => is_array($map) ? (int) array_sum(array_map('intval', $map)) : 0;
        if (is_array($d['server'] ?? null)) {
            // The realtime server's receipts (quoins, shards, races, takes, Charades) and connected time.
            $got = self::goods($d['got'] ?? null);
            $worth = (int) round(self::worth($got, $economy));
            $c['server']['n'] += $sum(array_diff_key($d['server'], ['online' => 0]));
            $c['server']['currants'] += $got['currants'];
            $c['server']['value'] += $worth;
            $c['value'] += $worth;
            $x['budget']['refill'] += max(0.0, (float) ($d['refill'] ?? 0));
            $x['budget']['online_s'] += max(0, (int) ($d['online_s'] ?? 0));
            $x['budget']['late'] += max(0.0, (float) ($d['late'] ?? 0));
        } elseif (array_key_exists('goods', $d)) {
            // A save write's exact credits (LedgerMath::credits).
            $quests = is_array($d['quests'] ?? null) ? $d['quests'] : [];
            $c['quests']['n'] += count($quests);
            $c['quests']['currants'] += $sum($quests);
            $c['quests']['write'] = max($c['quests']['write'], count($quests));
            $badges = is_array($d['badges'] ?? null) ? $d['badges'] : [];
            $c['badges']['n'] += count($badges);
            $c['badges']['currants'] += $sum($badges);
            $levelCur = $sum($d['waited'] ?? null);
            $c['levels']['paid'] += is_array($d['waited'] ?? null) ? count($d['waited']) : 0;
            foreach ([$d['level'] ?? null, $d['deferred']['level'] ?? null] as $i => $lv) {
                if (is_array($lv) && count($lv) >= 3) {
                    $step = max(0, (int) $lv[1] - (int) $lv[0]);
                    $c['levels']['gained'] += $step;
                    $c['levels']['jump'] = max($c['levels']['jump'], $step);
                    if ($i === 0 && (int) $lv[2] > 0) {
                        $c['levels']['paid']++;
                        $levelCur += (int) $lv[2];
                    }
                }
            }
            $c['levels']['currants'] += $levelCur;
            $cards = is_array($d['cards'] ?? null) ? $d['cards'] : [];
            $c['cards']['n'] += $sum($cards);
            $all = (int) round(self::worth($d['goods'], $economy));
            if ($cards !== []) {
                // A card pays items: its value is what the goods hold beyond the currants named and the quests' items.
                $questItems = 0.0;
                $table = (array) ($economy->credits('quests') ?? []);
                foreach (array_keys($quests) as $q) {
                    foreach (is_array($table[$q]['i'] ?? null) ? $table[$q]['i'] : [] as $cl => $k) {
                        $questItems += $economy->value((string) $cl) * max(0, (int) $k);
                    }
                }
                $c['cards']['value'] += max(0, $all - $sum($quests) - $sum($badges) - $levelCur - (int) round($questItems));
            }
            $c['value'] += $all;
        } elseif (is_array($d['got'] ?? null)) {
            // A home machine's make, or anything else that names what it got.
            $worth = (int) round(self::worth($d['got'], $economy));
            $c['other']['value'] += $worth;
            $c['value'] += $worth;
        }
    }

    /** Adds one settled groddle_trades row to the outbound value of its characters. */
    public static function foldTrade(array &$acc, array $row, Economy $economy): void
    {
        $id = (string) ($row['trade_id'] ?? '');
        $day = substr((string) ($row['created_at'] ?? ''), 0, 10);
        $a = (int) ($row['a_id'] ?? 0);
        $b = (int) ($row['b_id'] ?? 0);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || $a <= 0) {
            return;
        }
        $gaveA = (int) round(self::worth(self::goods(json_decode((string) ($row['a_gave'] ?? ''), true)), $economy));
        $gaveB = (int) round(self::worth(self::goods(json_decode((string) ($row['b_gave'] ?? ''), true)), $economy));
        $kind = str_starts_with($id, 'mail-') ? 'mail' : (str_starts_with($id, 'auc-') ? 'auction' : ($b > 0 ? 'trade' : ''));
        if ($kind === '') {
            return;
        }
        $put = static function (int $user, int $gave, int $got) use (&$acc, $day, $kind): void {
            $acc[$user][$day] ??= self::blankDay($day);
            $o = &$acc[$user][$day]['outbound'];
            $o[$kind]['gave'] += $gave;
            $o[$kind]['got'] += $got;
            $o['net'] += $gave - $got;
        };
        $put($a, $gaveA, $gaveB);
        if ($kind === 'trade') {
            $put($b, $gaveB, $gaveA);
        }
    }

    // The report and its flags (pure)

    /**
     * Characters with their days (newest first) and flags, flagged first, then by value credited.
     *
     * @param array{names?: array<int,string>, ledger?: array<int,array{budget: float, seeded: string}>, created?: array<int,string>} $context
     */
    public static function report(array $acc, array $context, string $since, string $today, int $days): array
    {
        $out = [];
        foreach ($acc as $user => $byDay) {
            krsort($byDay);
            $ledger = $context['ledger'][$user] ?? null;
            $created = $context['created'][$user] ?? null;
            $refill = 0.0;
            $late = 0.0;
            $online = 0;
            $value = 0;
            $list = [];
            foreach ($byDay as $day => $x) {
                $x['budget']['refill'] = round($x['budget']['refill'], 1);
                $x['budget']['late'] = round($x['budget']['late'], 1);
                $x['flags'] = self::dayFlags($x, $created);
                $refill += $x['budget']['refill'];
                $late += $x['budget']['late'];
                $online += $x['budget']['online_s'];
                $value += $x['credits']['value'];
                $list[] = $x;
            }
            $budget = $ledger === null ? null : round($ledger['budget'], 1);
            $drawn = $budget === null ? null : round(max(0.0, $refill + $late - $budget), 1);
            $flags = self::rangeFlags($drawn, $online, $days);
            $flagged = count($flags) + array_sum(array_map(static fn (array $x): int => count($x['flags']), $list));
            $out[] = [
                'player' => ['id' => (int) $user, 'name' => (string) ($context['names'][$user] ?? "#{$user}")],
                'budget' => $budget,
                'seeded' => $ledger['seeded'] ?? null,
                'created' => $created,
                'drawn' => $drawn,
                'online_s' => $online,
                'value' => $value,
                'flags' => $flags,
                'flagged' => $flagged,
                'days' => $list,
            ];
        }
        usort($out, static fn (array $p, array $q): int => [$q['flagged'], $q['value'], $p['player']['id']] <=> [$p['flagged'], $p['value'], $q['player']['id']]);
        return ['today' => $today, 'since' => $since, 'days' => $days, 'maxima' => self::maxima(), 'characters' => $out];
    }

    /** A day's flags, in plain words. @return list<array{kind: string, text: string}> */
    public static function dayFlags(array $x, ?string $created): array
    {
        $f = [];
        $c = $x['credits'];
        $n = static fn (int|float $v): string => number_format($v);
        if ($c['quests']['n'] > self::QUESTS_DAY) {
            $f[] = ['kind' => 'quests_day', 'text' => "{$n($c['quests']['n'])} quests credited this day; honest play tops out near " . self::QUESTS_DAY . '.'];
        }
        if ($c['quests']['write'] > self::QUESTS_WRITE) {
            $f[] = ['kind' => 'quests_write', 'text' => "{$n($c['quests']['write'])} quests completed in one save; quests complete one at a time, so a save names at most " . self::QUESTS_WRITE . '.'];
        }
        if ($c['levels']['gained'] > self::LEVELS_DAY) {
            $f[] = ['kind' => 'levels_day', 'text' => "{$n($c['levels']['gained'])} levels this day; the most anyone has gained honestly in a day is " . self::LEVELS_DAY . '.'];
        }
        if ($c['levels']['jump'] > self::LEVEL_JUMP) {
            $f[] = ['kind' => 'level_jump', 'text' => "{$n($c['levels']['jump'])} levels in one save; one save of honest play carries at most " . self::LEVEL_JUMP . '.'];
        }
        $hours = $x['budget']['online_s'] / 3600;
        if ($hours > self::ONLINE_HOURS_DAY) {
            $f[] = ['kind' => 'online_day', 'text' => 'Connected ' . number_format($hours, 1) . " hours this day (budget refilled {$n(round($x['budget']['refill']))}); a long day of play is put at " . self::ONLINE_HOURS_DAY . ' hours, so the connection may be held open idle.'];
        }
        if ($x['clamps']['n'] > 0) {
            $f[] = ['kind' => 'clamps', 'text' => "Clamped {$n($x['clamps']['n'])} " . ($x['clamps']['n'] === 1 ? 'time' : 'times') . " (at least {$n($x['clamps']['value'])} of value taken back); honest play is not expected to be clamped."];
        }
        if ($created !== null && $x['day'] < gmdate('Y-m-d', strtotime($created . ' UTC') + self::NEW_DAYS * 86400)) {
            $in = $c['value'] + (int) round($x['budget']['refill'] + $x['budget']['late']);
            if ($x['outbound']['net'] > $in + (int) Ledger::NEW_BUDGET) {
                $f[] = ['kind' => 'outbound_new', 'text' => "Sent {$n($x['outbound']['net'])} of value to other characters (net) in its first " . self::NEW_DAYS . " days, more than the day brought in ({$n($in)}) plus a new character's start ({$n((int) Ledger::NEW_BUDGET)})."];
            }
        }
        return $f;
    }

    /** Flags over the whole range. @return list<array{kind: string, text: string}> */
    public static function rangeFlags(?float $drawn, int $online, int $days): array
    {
        if ($drawn === null || $drawn <= Ledger::NEW_BUDGET || $online <= 0) {
            return [];
        }
        $rate = $drawn / ($online / 60);
        if ($rate <= self::DRAWN_PER_MINUTE) {
            return [];
        }
        $span = $days === 1 ? 'today' : "in these {$days} days";
        return [['kind' => 'drawn_minute', 'text' => 'The budget paid at least ' . number_format($drawn) . " {$span}, " . number_format($rate, 1) . ' a connected minute; honest play draws at most ' . self::DRAWN_PER_MINUTE . '.']];
    }

    // Goods

    /** Goods as the ledger stores them, items a list [{c, n}] or a map {class: n}. @return array{items: array<string,int>, currants: int} */
    public static function goods(mixed $goods): array
    {
        $items = [];
        $raw = is_array($goods) && is_array($goods['items'] ?? null) ? $goods['items'] : [];
        foreach ($raw as $k => $v) {
            if (is_array($v)) {
                $c = $v['c'] ?? null;
                $n = LedgerMath::count($v['n'] ?? 0);
            } else {
                $c = $k;
                $n = LedgerMath::count($v);
            }
            if (is_string($c) && $c !== '' && $n > 0) {
                $items[$c] = ($items[$c] ?? 0) + $n;
            }
        }
        return ['items' => $items, 'currants' => is_array($goods) ? LedgerMath::count($goods['currants'] ?? 0) : 0];
    }

    /** Value in currants (Economy::value, a currant is 1). */
    public static function worth(mixed $goods, Economy $economy): float
    {
        $g = self::goods($goods);
        $v = (float) $g['currants'];
        foreach ($g['items'] as $c => $n) {
            $v += $n * $economy->value((string) $c);
        }
        return $v;
    }
}
