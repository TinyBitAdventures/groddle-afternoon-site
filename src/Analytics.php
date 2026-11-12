<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Auth\Capabilities;
use Minn\Db;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;

/**
 * The console's Analytics tab (Austin 2026-11-11: "Similar to the new /console/ server tab, could we do one for
 * Analytics? ... players over time, active players per day, pageviews and even quirky things like trees
 * harvested, mines mined, piggies nibbled, butterflies milked ... Lightweight, keep all history").
 *
 * Counts only, a day at a time (UTC days), in one small table kept forever (groddle_analytics_daily: day, metric,
 * k, value; one row a day for each metric and key, added to in the database: value = value + n):
 *
 *   active        players who played that day (k ''), from the play-time batches
 *   active_new    of those, players whose character was made that day (the rest are returning)
 *   wau, mau      players who played in the 7 (30) days ending that day, set at each batch of that day
 *   play_seconds  active seconds in the game that day, all players together
 *   peak, peak_at the most players online at once that day and when (unix s), from the realtime server's health
 *                 samples (AdminConsole::analytics takes the days since the last one kept)
 *   pageviews     pages of the website read that day: k '' all of them, k a section ('encyclopedia/items', 'chat')
 *   tally         the world's acts that landed that day, k the realtime server's key (groddle-afternoon-server
 *                 tally.go: 'pig:nibble', 'rock:mine', 'quoin:xp' …), everyone together
 *
 * Players (sign-ups and the running total) are read from the characters themselves (groddle_players.created_at),
 * so their whole history is there from the first sign-up. A login with alternate characters (Logins) is one login
 * and several characters: "players" counts characters, "logins" counts logins (a first character is its login), and
 * active_logins counts the logins that played that day; a character is a QA account by its login's email.
 * Who counts as a player, everywhere here: an account that still exists, is not an administrator (manage_options by role) and is not a QA account (an email starting
 * "zz-" or a character name starting "Zz", the local test scripts' accounts).
 *
 * Active players come from the realtime server's play-time batches (PlayTime::record calls fromBatch in its
 * transaction, so a batch sent again adds nothing here either): a player with active seconds on a UTC day (the day
 * of the entry's "last") is active that day. To count each player once a day and to give WAU and MAU exactly, the
 * player ids of the last SEEN_KEEP days are kept in groddle_analytics_seen (day, user_id) and older ones deleted;
 * only the counts stay. Nothing about a player is ever shown.
 *
 * Pageviews: the website's pages are served from Kinsta's cache, where PHP never runs, so the one script every
 * page loads (the game repo's public/site/theme.js) sends a beacon with the page's path, nothing else:
 *
 *   POST groddle/v1/hit  {"p":"/encyclopedia/items/apple/"}   (text/plain, navigator.sendBeacon)   204
 *
 * No cookie is set or read, no address or browser string is kept. The path is cut to its section (section());
 * a body over HIT_BYTES, a browser that says it is a bot (BOT), and a beacon from another site (its Origin) count
 * nothing. A day takes at most PAGEVIEWS_DAY_MAX pageviews and SECTIONS_DAY_MAX sections (more go to "other"), so
 * a script posting in a loop can push one day's count up to the cap and no further, and never grow the table past
 * a few rows a day. The script itself skips browsers that have been an administrator's (the Console link's hint),
 * automated browsers (navigator.webdriver) and the console and Minn Admin.
 *
 * The console reads it (AdminConsole::analytics, GET admin/analytics?range=), report() below.
 */
final readonly class Analytics
{
    public const TABLE = 'groddle_analytics_daily';
    public const SEEN = 'groddle_analytics_seen';

    /** How long the day's player ids are kept (days): MAU needs 30, a few more for late batches. */
    public const SEEN_KEEP = 40;

    /** admin/analytics's ranges (days; all: from the first day there is anything). */
    public const RANGES = ['30d' => 30, '90d' => 90, '365d' => 365, 'all' => 0];

    /** A beacon's body at most (bytes), the most pageviews a day, and the most sections a day. */
    public const HIT_BYTES = 512;
    public const PAGEVIEWS_DAY_MAX = 250000;
    public const SECTIONS_DAY_MAX = 80;

    /** The sections read one level deeper: /encyclopedia/items/… is 'encyclopedia/items'. */
    public const DEEP = ['encyclopedia'];

    /** A browser string that names a crawler, a preview fetcher or a script. */
    public const BOT = '/bot\b|crawl|spider|slurp|facebookexternalhit|embedly|preview|headless|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|curl\/|wget|python|go-http|java\/|okhttp|axios|node-fetch|scrapy/i';

    /** The site's administrators' capability. */
    private const ADMIN = 'manage_options';

    /**
     * The world's tallies the tab shows (Ur's day), in the game's words, each the sum of the realtime server's keys
     * (tally.go; a key ending ':*' takes every key with that start). The server counts what lands on its shared
     * streets: objects.go (rocks, trees, resource nodes, street animals), gardens.go (the public gardens) and
     * quoins.go. Others it sends are kept and can be shown later from their first day.
     */
    public const COUNTERS = [
        ['id' => 'piggies_nibbled', 'label' => 'Piggies nibbled', 'keys' => ['pig:nibble']],
        ['id' => 'piggies_petted', 'label' => 'Piggies petted', 'keys' => ['pig:pet']],
        ['id' => 'piggies_fed', 'label' => 'Piggies fed', 'keys' => ['pig:feed']],
        ['id' => 'butterflies_milked', 'label' => 'Butterflies milked', 'keys' => ['butterfly:milk']],
        ['id' => 'butterflies_massaged', 'label' => 'Butterflies massaged', 'keys' => ['butterfly:massage']],
        ['id' => 'chickens_squeezed', 'label' => 'Chickens squeezed', 'keys' => ['chicken:squeeze']],
        ['id' => 'eggs_incubated', 'label' => 'Eggs incubated', 'keys' => ['chicken:incubate']],
        ['id' => 'batterflies_fed', 'label' => 'Batterflies fed', 'keys' => ['batterfly:feed']],
        ['id' => 'guano_collected', 'label' => 'Guano piles collected', 'keys' => ['batterfly:pickup']],
        ['id' => 'kitties_petted', 'label' => 'Heli Kitties petted', 'keys' => ['kitty:pet']],
        ['id' => 'rocks_mined', 'label' => 'Rocks mined', 'keys' => ['rock:mine']],
        ['id' => 'trees_harvested', 'label' => 'Trees harvested', 'keys' => ['tree:harvest', 'wood:harvest', 'paper_tree:harvest']],
        ['id' => 'trees_petted', 'label' => 'Trees petted', 'keys' => ['tree:pet', 'paper_tree:pet']],
        ['id' => 'trees_watered', 'label' => 'Trees watered', 'keys' => ['tree:water', 'paper_tree:water']],
        ['id' => 'trees_poisoned', 'label' => 'Trees poisoned', 'keys' => ['tree:poison', 'wood:poison']],
        ['id' => 'peat_harvested', 'label' => 'Peat Bogs harvested', 'keys' => ['peat_1:harvest']],
        ['id' => 'dirt_dug', 'label' => 'Dirt Piles dug', 'keys' => ['dirt_pile:dig']],
        ['id' => 'barnacles_scraped', 'label' => 'Barnacles scraped', 'keys' => ['mortar_barnacle:scrape']],
        ['id' => 'ice_scraped', 'label' => 'Ice Knobs scraped', 'keys' => ['ice_knob:scrape']],
        ['id' => 'jellisacs_scooped', 'label' => 'Jellisacs scooped', 'keys' => ['jellisac:scoop']],
        ['id' => 'salmon_pocketed', 'label' => 'Salmon pocketed', 'keys' => ['npc_salmon:pocket']],
        ['id' => 'crops_planted', 'label' => 'Garden crops planted', 'keys' => ['garden:plant']],
        ['id' => 'crops_harvested', 'label' => 'Garden crops harvested', 'keys' => ['garden:harvest']],
        ['id' => 'quoins_grabbed', 'label' => 'Quoins grabbed', 'keys' => ['quoin:*']],
    ];

    public function __construct(private Seams $minn)
    {
    }

    // —— Pageviews ——

    #[Route(Method::Post, '/groddle/v1/hit', policy: new Policy(Access::Public))]
    public function hit(Request $request): Response
    {
        $none = Response::html('', 204)->withHeader('Cache-Control', 'no-store');
        if (strlen($request->body) > self::HIT_BYTES || self::isBot($request->header('User-Agent'))) {
            return $none;
        }
        $origin = $request->header('Origin');
        if (is_string($origin) && $origin !== '' && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== strtolower(explode(':', $request->host)[0])) {
            return $none;
        }
        $body = json_decode($request->body, true);
        $path = is_array($body) && is_string($body['p'] ?? null) ? $body['p'] : null;
        if ($path === null || $path === '' || $path[0] !== '/') {
            return $none;
        }
        $section = self::section($path);
        if ($section === null) {
            return $none;
        }
        self::countView($this->minn->db, $section, gmdate('Y-m-d'));
        return $none;
    }

    /**
     * A page's path as the section it is counted under: 'home' for /, the first folder (/players/123/ is 'players',
     * /changelog/a-note/ 'changelog'), two for DEEP ones ('encyclopedia/items'), 'other' for a folder name that is
     * not a plain lowercase slug. Null for the pages that are not the website's (the console, Minn Admin, the game).
     */
    public static function section(string $path): ?string
    {
        $path = strtolower(rawurldecode((string) preg_replace('/[?#].*$/s', '', $path)));
        $parts = array_values(array_filter(explode('/', $path), static fn (string $p): bool => $p !== '' && $p !== 'index.html'));
        if ($parts === []) {
            return 'home';
        }
        if (in_array($parts[0], ['console', 'minn-admin', 'wp-admin', 'wp-login.php', 'wp-json', 'play'], true)) {
            return null;
        }
        $slug = '/^[a-z0-9][a-z0-9-]{0,39}$/';
        if (preg_match($slug, $parts[0]) !== 1) {
            return 'other';
        }
        if (in_array($parts[0], self::DEEP, true) && isset($parts[1]) && preg_match($slug, $parts[1]) === 1) {
            return $parts[0] . '/' . $parts[1];
        }
        return $parts[0];
    }

    public static function isBot(?string $ua): bool
    {
        return $ua === null || trim($ua) === '' || preg_match(self::BOT, $ua) === 1;
    }

    /** One pageview: the day's total and its section, within the day's caps. */
    public static function countView(Db $db, string $section, string $day): void
    {
        $t = $db->table(self::TABLE);
        if ((int) $db->value("SELECT value FROM {$t} WHERE day = ? AND metric = 'pageviews' AND k = ''", [$day]) >= self::PAGEVIEWS_DAY_MAX) {
            return;
        }
        if ($db->value("SELECT value FROM {$t} WHERE day = ? AND metric = 'pageviews' AND k = ?", [$day, $section]) === null
            && (int) $db->value("SELECT COUNT(*) FROM {$t} WHERE day = ? AND metric = 'pageviews' AND k <> ''", [$day]) >= self::SECTIONS_DAY_MAX) {
            $section = 'other';
        }
        self::add($db, $day, 'pageviews', '', 1);
        self::add($db, $day, 'pageviews', $section, 1);
    }

    // —— The realtime server's play-time batches ——

    /**
     * A play-time batch's share (PlayTime::batch's shape, in PlayTime::record's transaction): each player's day
     * as active (once a day), their seconds, WAU and MAU for the days it touched, and the world's tallies.
     *
     * @param array{id: string, players: list<array{user: int, seconds: int, last: int}>, counts?: array<string, array<string, int>>} $batch
     */
    public static function fromBatch(Db $db, array $batch, int $now): void
    {
        $users = array_column($batch['players'], 'user');
        if ($users !== []) {
            $made = self::players($db, $users);
            $seen = $db->table(self::SEEN);
            $days = [];
            foreach ($batch['players'] as $p) {
                if (!array_key_exists($p['user'], $made)) {
                    continue;
                }
                $day = gmdate('Y-m-d', $p['last']);
                self::add($db, $day, 'play_seconds', '', $p['seconds']);
                if ($db->execute("INSERT IGNORE INTO {$seen} (day, user_id) VALUES (?, ?)", [$day, $p['user']]) === 1) {
                    self::add($db, $day, 'active', '', 1);
                    if ($made[$p['user']] === $day) {
                        self::add($db, $day, 'active_new', '', 1);
                    }
                }
                $days[$day] = true;
            }
            foreach (array_keys($days) as $day) {
                self::set($db, $day, 'wau', '', self::distinct($db, $day, 7));
                self::set($db, $day, 'mau', '', self::distinct($db, $day, 30));
                self::set($db, $day, 'active_logins', '', self::distinctLogins($db, $day));
            }
            $db->execute("DELETE FROM {$seen} WHERE day < ? LIMIT 500", [gmdate('Y-m-d', $now - self::SEEN_KEEP * 86400)]);
        }
        foreach ($batch['counts'] ?? [] as $day => $keys) {
            foreach ($keys as $key => $n) {
                self::add($db, (string) $day, 'tally', (string) $key, $n);
            }
        }
    }

    /** Players who played in the $days days ending $day (from the kept ids). */
    public static function distinct(Db $db, string $day, int $days): int
    {
        $from = gmdate('Y-m-d', (int) strtotime("{$day} 00:00:00 UTC") - ($days - 1) * 86400);
        return (int) $db->value('SELECT COUNT(DISTINCT user_id) FROM ' . $db->table(self::SEEN) . ' WHERE day BETWEEN ? AND ?', [$from, $day]);
    }

    /**
     * Of these accounts, the players (see the class note): id => the UTC day their character was made ('' with none).
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public static function players(Db $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_int($id) && $id > 0)));
        if ($ids === []) {
            return [];
        }
        $rows = $db->rows('SELECT u.ID, COALESCE(lu.user_email, u.user_email) AS user_email, c.name, c.created_at FROM ' . $db->table('users') . ' u LEFT JOIN ' . $db->table(Characters::TABLE) . ' c ON c.user_id = u.ID'
            . ' LEFT JOIN ' . $db->table(Logins::TABLE) . ' l ON l.user_id = u.ID LEFT JOIN ' . $db->table('users') . ' lu ON lu.ID = l.login_id WHERE u.ID IN (?)', [$ids]);
        $admins = self::adminIds($db, $ids);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['ID'];
            if (isset($admins[$id]) || self::isQa((string) $r['user_email'], (string) ($r['name'] ?? ''))) {
                continue;
            }
            $out[$id] = $r['created_at'] === null ? '' : substr((string) $r['created_at'], 0, 10);
        }
        return $out;
    }

    /** A local test script's account: an email starting zz- or a character name starting Zz. */
    public static function isQa(string $email, string $name): bool
    {
        return stripos($email, 'zz-') === 0 || stripos(ltrim($name), 'zz') === 0;
    }

    /**
     * The administrators among these accounts (or all, with null), by the role stored on them.
     *
     * @param list<int>|null $ids
     * @return array<int, true>
     */
    public static function adminIds(Db $db, ?array $ids = null): array
    {
        $caps = Capabilities::fromDb($db);
        $sql = 'SELECT user_id FROM ' . $db->table('usermeta') . ' WHERE meta_key = ?';
        $args = [$db->prefix() . 'capabilities'];
        if ($ids !== null) {
            $sql .= ' AND user_id IN (?)';
            $args[] = $ids;
        }
        $out = [];
        foreach ($db->rows($sql, $args) as $r) {
            $id = (int) $r['user_id'];
            if ($caps->can($id, self::ADMIN)) {
                $out[$id] = true;
            }
        }
        return $out;
    }

    // —— The rows ——

    public static function add(Db $db, string $day, string $metric, string $k, int $n): void
    {
        if ($n === 0) {
            return;
        }
        $db->execute('INSERT INTO ' . $db->table(self::TABLE) . ' (day, metric, k, value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = value + VALUES(value)', [$day, $metric, $k, $n]);
    }

    public static function set(Db $db, string $day, string $metric, string $k, int $n): void
    {
        $db->execute('INSERT INTO ' . $db->table(self::TABLE) . ' (day, metric, k, value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$day, $metric, $k, $n]);
    }

    // —— Peak online, from the realtime server's health ——

    /** Where the next health read starts (unix s): the start of the last day kept, so it is finished; 0 for none. */
    public static function peakSince(Db $db): int
    {
        $day = $db->value('SELECT MAX(day) FROM ' . $db->table(self::TABLE) . " WHERE metric = 'peak'");
        return is_string($day) && $day !== '' ? (int) strtotime("{$day} 00:00:00 UTC") : 0;
    }

    /**
     * PURE: each UTC day's most players online at once and when, from health rows (AdminConsole::healthAnswer's
     * fields and samples; p is a bucket's most). Empty when the buckets are longer than a day.
     *
     * @param list<string> $fields
     * @param list<list<int|float|null>> $samples
     * @return array<string, array{p: int, at: int}>
     */
    public static function peaksOf(array $fields, array $samples, int $bucket): array
    {
        $ti = array_search('t', $fields, true);
        $pi = array_search('p', $fields, true);
        if ($ti === false || $pi === false || $bucket > 86400) {
            return [];
        }
        $out = [];
        foreach ($samples as $row) {
            $t = $row[$ti] ?? null;
            $p = $row[$pi] ?? null;
            if (!is_int($t) || $p === null) {
                continue;
            }
            $day = gmdate('Y-m-d', $t);
            $p = (int) $p;
            if (!isset($out[$day]) || $p > $out[$day]['p']) {
                $out[$day] = ['p' => $p, 'at' => $t];
            }
        }
        return $out;
    }

    /** Keeps each day's peak when it is higher than the one kept (and when it was). */
    public static function keepPeaks(Db $db, array $peaks): void
    {
        if ($peaks === []) {
            return;
        }
        $t = $db->table(self::TABLE);
        $kept = [];
        foreach ($db->rows("SELECT day, value FROM {$t} WHERE metric = 'peak' AND day IN (?)", [array_keys($peaks)]) as $r) {
            $kept[(string) $r['day']] = (int) $r['value'];
        }
        foreach ($peaks as $day => $p) {
            if (isset($kept[$day]) && $kept[$day] >= $p['p']) {
                continue;
            }
            self::set($db, $day, 'peak', '', $p['p']);
            self::set($db, $day, 'peak_at', '', $p['at']);
        }
    }

    // —— The console's report ——

    /**
     * Everything the Analytics tab draws for a range (see AdminConsole's docblock for the shape).
     *
     * @return array<string, mixed>
     */
    public static function report(Db $db, string $range, int $now): array
    {
        $range = array_key_exists($range, self::RANGES) ? $range : '30d';
        $today = gmdate('Y-m-d', $now);
        $t = $db->table(self::TABLE);
        $signups = self::signupDays($db);
        $firstRow = $db->value("SELECT MIN(day) FROM {$t}");
        $first = array_filter([$signups[0] ?? null, is_string($firstRow) ? $firstRow : null]);
        $since = self::RANGES[$range] === 0
            ? ($first === [] ? $today : min($first))
            : gmdate('Y-m-d', $now - (self::RANGES[$range] - 1) * 86400);
        $since = min($since, $today);
        $rows = $db->rows("SELECT day, metric, k, value FROM {$t} WHERE day BETWEEN ? AND ? AND metric <> 'tally'", [$since, $today]);
        $tallies = $db->rows("SELECT day, k, value FROM {$t} WHERE metric = 'tally'");
        $firsts = [];
        foreach ($db->rows("SELECT metric, MIN(day) AS first FROM {$t} GROUP BY metric") as $r) {
            $firsts[(string) $r['metric']] = (string) $r['first'];
        }
        $live = [
            'week' => self::distinct($db, $today, 7),
            'month' => self::distinct($db, $today, 30),
        ];
        // The records are of all time: every day's peak (and when), active players and pageviews.
        $bests = $db->rows("SELECT day, metric, value FROM {$t} WHERE metric IN ('peak', 'peak_at', 'active', 'pageviews') AND k = '' ORDER BY day");
        return self::shape($range, $since, $today, $signups, $rows, $tallies, $firsts, $live, $bests, self::signupDays($db, true));
    }

    /**
     * The UTC days the players' characters were made, oldest first (one a player; see the class note for who).
     *
     * @return list<string>
     */
    public static function signupDays(Db $db, bool $loginsOnly = false): array
    {
        $rows = $db->rows('SELECT c.user_id, c.name, c.created_at, COALESCE(lu.user_email, u.user_email) AS user_email, l.user_id AS extra FROM ' . $db->table(Characters::TABLE) . ' c JOIN ' . $db->table('users') . ' u ON u.ID = c.user_id'
            . ' LEFT JOIN ' . $db->table(Logins::TABLE) . ' l ON l.user_id = c.user_id LEFT JOIN ' . $db->table('users') . ' lu ON lu.ID = l.login_id ORDER BY c.created_at');
        $admins = self::adminIds($db);
        $out = [];
        foreach ($rows as $r) {
            if ($loginsOnly && $r['extra'] !== null) {
                continue;
            }
            if (!isset($admins[(int) $r['user_id']]) && !self::isQa((string) $r['user_email'], (string) $r['name'])) {
                $out[] = substr((string) $r['created_at'], 0, 10);
            }
        }
        return $out;
    }

    /** The logins that played on a day (several characters of one login count once). */
    public static function distinctLogins(Db $db, string $day): int
    {
        return (int) $db->value('SELECT COUNT(DISTINCT COALESCE(l.login_id, s.user_id)) FROM ' . $db->table(self::SEEN) . ' s LEFT JOIN ' . $db->table(Logins::TABLE) . ' l ON l.user_id = s.user_id WHERE s.day = ?', [$day]);
    }

    /** PURE: the bucket for a span of days: a day up to 120 days, a week (from Monday) up to 730, else a month. */
    public static function unit(string $since, string $today): string
    {
        $days = (int) round(((int) strtotime("{$today} UTC") - (int) strtotime("{$since} UTC")) / 86400) + 1;
        return $days <= 120 ? 'day' : ($days <= 730 ? 'week' : 'month');
    }

    /** PURE: the first day of the bucket a day falls in. */
    public static function bucketOf(string $day, string $unit): string
    {
        $ts = (int) strtotime("{$day} 00:00:00 UTC");
        return match ($unit) {
            'week' => gmdate('Y-m-d', $ts - ((int) gmdate('N', $ts) - 1) * 86400),
            'month' => substr($day, 0, 7) . '-01',
            default => $day,
        };
    }

    /**
     * PURE: the report from the rows. $rows: day, metric, k, value in the range (no tallies); $tallies: day, k, value
     * of all time; $signups: every player's sign-up day, oldest first; $firsts: metric => its first day;
     * $live: week, month (players active in the 7 and 30 days ending today); $bests: day, metric, value of all time for
     * peak, peak_at, active and pageviews (k ''), oldest first, for the records.
     *
     * @param list<string> $signups
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $tallies
     * @param array<string, string> $firsts
     * @param array{week: int, month: int} $live
     * @param list<array<string, mixed>> $bests
     * @return array<string, mixed>
     */
    public static function shape(string $range, string $since, string $today, array $signups, array $rows, array $tallies, array $firsts, array $live, array $bests = [], ?array $logins = null): array
    {
        // Logins (Logins): their own sign-up days; without them (an older caller) every character is its own login.
        $logins ??= $signups;
        $unit = self::unit($since, $today);
        // The buckets, oldest first, and each day's bucket.
        $buckets = [];
        for ($ts = (int) strtotime("{$since} 00:00:00 UTC"), $end = (int) strtotime("{$today} 00:00:00 UTC"); $ts <= $end; $ts += 86400) {
            $buckets[self::bucketOf(gmdate('Y-m-d', $ts), $unit)] = true;
        }
        $days = array_keys($buckets);
        $index = array_flip($days);
        $n = count($days);
        $zeros = array_fill(0, $n, 0);
        $nulls = array_fill(0, $n, null);
        $at = static fn (string $day): ?int => $index[self::bucketOf($day, $unit)] ?? null;

        // Daily values by metric and day (and pageviews by section).
        $by = [];
        $sections = [];
        $todays = [];
        foreach ($rows as $r) {
            $day = (string) $r['day'];
            $metric = (string) $r['metric'];
            $k = (string) $r['k'];
            $v = (int) $r['value'];
            if ($metric === 'pageviews' && $k !== '') {
                $sections[$k]['views'] = ($sections[$k]['views'] ?? 0) + $v;
                if ($day === $today) {
                    $sections[$k]['today'] = $v;
                }
                continue;
            }
            $by[$metric][$day] = $v;
            if ($day === $today) {
                $todays[$metric] = $v;
            }
        }

        // Sign-ups: a bucket's new players, and the players there were at its end.
        $signupSeries = $zeros;
        $before = 0;
        foreach ($signups as $day) {
            if ($day < $since) {
                $before++;
            } elseif ($day <= $today && ($i = $at($day)) !== null) {
                $signupSeries[$i]++;
            }
        }
        $players = [];
        $running = $before;
        foreach ($signupSeries as $v) {
            $running += $v;
            $players[] = $running;
        }
        $loginSeries = $zeros;
        $loginsBefore = 0;
        foreach ($logins as $day) {
            if ($day < $since) {
                $loginsBefore++;
            } elseif ($day <= $today && ($i = $at($day)) !== null) {
                $loginSeries[$i]++;
            }
        }
        $loginTotals = [];
        $running = $loginsBefore;
        foreach ($loginSeries as $v) {
            $running += $v;
            $loginTotals[] = $running;
        }

        // A bucket of daily values: summed, the most, or the last day's (the days come oldest first).
        foreach (['active', 'active_new', 'active_logins', 'play_seconds', 'pageviews', 'wau', 'mau', 'peak', 'peak_at'] as $m) {
            $by[$m] ??= [];
            ksort($by[$m]);
        }
        $fold = static function (string $metric, string $how) use ($by, $at, $zeros, $nulls): array {
            $out = $how === 'sum' ? $zeros : $nulls;
            foreach ($by[$metric] as $day => $v) {
                $i = $at((string) $day);
                if ($i !== null) {
                    $out[$i] = match ($how) {
                        'sum' => $out[$i] + $v,
                        'max' => max($out[$i] ?? $v, $v),
                        default => $v,
                    };
                }
            }
            return $out;
        };
        // Active players: a day's count; in a week or a month the mean a day over its days since counting began
        // (a day nobody played is a 0 then). Before the first day counted nothing is known (null), not 0.
        $firstActive = $firsts['active'] ?? null;
        $counted = $zeros;
        for ($ts = (int) strtotime("{$since} 00:00:00 UTC"), $end = (int) strtotime("{$today} 00:00:00 UTC"); $ts <= $end; $ts += 86400) {
            $day = gmdate('Y-m-d', $ts);
            if ($firstActive !== null && $day >= $firstActive) {
                $counted[$at($day)]++;
            }
        }
        $activeAll = $fold('active', 'sum');
        $activeNew = $fold('active_new', 'sum');
        $loginsAll = $fold('active_logins', 'sum');
        $active = [];
        $activeLogins = [];
        $returning = [];
        foreach ($activeAll as $i => $v) {
            $known = $counted[$i] > 0;
            $active[] = !$known ? null : ($unit === 'day' ? $v : round($v / $counted[$i], 1));
            $activeLogins[] = !$known ? null : ($unit === 'day' ? $loginsAll[$i] : round($loginsAll[$i] / $counted[$i], 1));
            $returning[] = !$known ? null : max(0, $v - $activeNew[$i]);
            if (!$known) {
                $activeNew[$i] = null;
            }
        }
        $series = [
            'signups' => $signupSeries,
            'players' => $players,
            'active' => $active,
            'active_new' => $activeNew,
            'logins' => $loginTotals,
            'active_logins' => $activeLogins,
            'returning' => $returning,
            'wau' => $fold('wau', 'last'),
            'mau' => $fold('mau', 'last'),
            'peak' => $fold('peak', 'max'),
            'play_seconds' => self::fromFirst($fold('play_seconds', 'sum'), $days, $unit, $firsts['active'] ?? null),
            'pageviews' => self::fromFirst($fold('pageviews', 'sum'), $days, $unit, $firsts['pageviews'] ?? null),
        ];

        // Pages: the sections in the range, most read first.
        $pages = [];
        foreach ($sections as $k => $s) {
            $pages[] = ['section' => (string) $k, 'views' => $s['views'] ?? 0, 'today' => $s['today'] ?? 0];
        }
        usort($pages, static fn (array $a, array $b): int => [$b['views'], $a['section']] <=> [$a['views'], $b['section']]);

        // The world's tallies: each counter's day sums (all time), its series in the range, today, total and record.
        $tallyDays = [];
        foreach ($tallies as $r) {
            $tallyDays[(string) $r['k']][(string) $r['day']] = ($tallyDays[(string) $r['k']][(string) $r['day']] ?? 0) + (int) $r['value'];
        }
        $counters = [];
        foreach (self::COUNTERS as $c) {
            $perDay = [];
            foreach ($tallyDays as $key => $dayValues) {
                if (!self::matches($key, $c['keys'])) {
                    continue;
                }
                foreach ($dayValues as $day => $v) {
                    $perDay[$day] = ($perDay[$day] ?? 0) + $v;
                }
            }
            $s = $zeros;
            $record = null;
            foreach ($perDay as $day => $v) {
                if ($day >= $since && $day <= $today && ($i = $at((string) $day)) !== null) {
                    $s[$i] += $v;
                }
                if ($v > 0 && ($record === null || $v > $record['n'] || ($v === $record['n'] && $day < $record['day']))) {
                    $record = ['n' => $v, 'day' => (string) $day];
                }
            }
            $counters[] = ['key' => $c['id'], 'label' => $c['label'], 'today' => $perDay[$today] ?? 0, 'total' => array_sum($perDay), 'series' => self::fromFirst($s, $days, $unit, $firsts['tally'] ?? null), 'record' => $record];
        }

        // All-time records of the players' days (the first day of a tie).
        $records = ['peak' => null, 'active' => null, 'pageviews' => null];
        $peakAt = [];
        foreach ($bests as $r) {
            $metric = (string) $r['metric'];
            $v = (int) $r['value'];
            if ($metric === 'peak_at') {
                $peakAt[(string) $r['day']] = $v;
            } elseif (array_key_exists($metric, $records) && $v > 0 && ($records[$metric] === null || $v > $records[$metric]['n'])) {
                $records[$metric] = ['n' => $v, 'day' => (string) $r['day']];
            }
        }
        if ($records['peak'] !== null) {
            $records['peak']['at'] = $peakAt[$records['peak']['day']] ?? null;
        }
        $signupBest = null;
        foreach (array_count_values($signups) as $day => $v) {
            if ($signupBest === null || $v > $signupBest['n']) {
                $signupBest = ['n' => $v, 'day' => (string) $day];
            }
        }
        $records['signups'] = $signupBest;

        $playersNow = count($signups);
        return [
            'range' => $range,
            'unit' => $unit,
            'since' => $since,
            'today' => $today,
            'days' => $days,
            'series' => $series,
            'totals' => [
                'players' => $playersNow,
                'signups_today' => count(array_filter($signups, static fn (string $d): bool => $d === $today)),
                'active_today' => $todays['active'] ?? 0,
                'active_week' => $live['week'],
                'active_month' => $live['month'],
                'pageviews_today' => $todays['pageviews'] ?? 0,
                'pageviews_range' => array_sum($series['pageviews']),
                'play_today' => $todays['play_seconds'] ?? 0,
                'peak_today' => $todays['peak'] ?? null,
            ],
            'first' => [
                'players' => $signups[0] ?? null,
                'active' => $firsts['active'] ?? null,
                'pageviews' => $firsts['pageviews'] ?? null,
                'tally' => $firsts['tally'] ?? null,
                'peak' => $firsts['peak'] ?? null,
            ],
            // Logins (Logins): one email and password, up to three characters; players above count characters.
            'logins' => ['total' => count($logins), 'today' => count(array_filter($logins, static fn (string $d): bool => $d === $today)), 'active_today' => $todays['active_logins'] ?? 0],
            'pages' => array_slice($pages, 0, 15),
            'counters' => $counters,
            'records' => $records,
        ];
    }

    /** PURE: whether a tally key is one of a counter's (a key ending ':*' takes every key with that start). */
    public static function matches(string $key, array $keys): bool
    {
        foreach ($keys as $k) {
            if ($k === $key || (str_ends_with($k, ':*') && str_starts_with($key, substr($k, 0, -1)))) {
                return true;
            }
        }
        return false;
    }

    /**
     * PURE: a series with null for the buckets before its first day (nothing was counted yet, which is not 0).
     *
     * @param list<int|float|null> $series
     * @param list<string> $days
     * @return list<int|float|null>
     */
    private static function fromFirst(array $series, array $days, string $unit, ?string $first): array
    {
        foreach ($days as $i => $day) {
            $end = $unit === 'day' ? $day : gmdate('Y-m-d', (int) strtotime(($unit === 'week' ? "{$day} +6 days" : "{$day} +1 month -1 day") . ' UTC'));
            if ($first === null || $end < $first) {
                $series[$i] = null;
            }
        }
        return $series;
    }
}
