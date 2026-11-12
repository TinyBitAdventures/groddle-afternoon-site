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
use Minn\RestError;

/**
 * The administrators' console's data (the page is AdminConsolePage, /console/):
 * groddle/v1/admin/*, every route manage_options only (players are
 * subscribers: 401 signed out, 403 signed in). What reaches a player live
 * goes through the realtime server's signed calls (RealtimeCall,
 * groddle-afternoon-server admin.go); every action writes one line in
 * groddle_admin_actions (the teleport too, AdminTeleport).
 *
 *   GET  admin/online           {"server":"up"|"down","players":[Row + {"since":unix}]}   who is connected now
 *                               ?sync=1 first sends the server the whole ban list (admin.go "bans")
 *   GET  admin/players?q=…      {"players":[Row]}  characters by name (the start of it), online or not
 *   GET  admin/players/{id}     {"player":Row,"login":{"id","accountDays","characters":[{id,name,first,retired,level,savedAt,banned}]}}
 *                               one character and every character of its login (Logins; administrators only)
 *   POST admin/players/{id}/rename {"name":"…"}               {"player":{id,name},"was","text"}  the sign-up's name rules; the old
 *                               /players/ address answers a 301 to the new one (groddle_player_renames, PlayerPages);
 *                               chat lines, community posts and Hi signs said under the old name keep it (copies)
 *   GET  admin/bans             {"bans":[{"player":{id,name},"reason","by":{id,name},"at"}]}
 *   GET  admin/log?before=<id>  {"lines":[{"id","at","kind","by":{id,name},"player":{id,name},"detail","delivery"}],"more":bool}
 *   GET  admin/chatlog?player=&q=&kind=&since=&until=&refused=1&before=
 *                               {"server":"up"|"down"|"off","lines":[Chat],"next":"…"|"","dropped":n,"days":n}
 *                               the realtime server's chat log, newest first (chatQuery, below)
 *   GET  admin/health?range=24h|7d|30d|90d|all  (or ?since=&until=, unix seconds)
 *                               {"server":"up"|"down"|"off","range","since","until","first","bucket":seconds,
 *                                "machine":{host,os,cpus,mem,disk,version,started,uptime,boot},"now":Sample|null,
 *                                "fields":["t","p",…],"samples":[[…],…]}   the realtime server's health a minute
 *                               (groddle-afternoon-server health.go), bucketed by the server to at most HEALTH_POINTS rows
 *   GET  admin/analytics?range=30d|90d|365d|all
 *                               {"range","unit":"day"|"week"|"month","since","today","days":["Y-m-d",…] (each bucket's first day),
 *                                "series":{"signups","players","active","active_new","returning","wau","mau","peak",
 *                                          "play_seconds","pageviews"} (one value a bucket, null where nothing was counted yet),
 *                                "totals":{"players","signups_today","active_today","active_week","active_month",
 *                                          "pageviews_today","pageviews_range","play_today","peak_today"},
 *                                "first":{"players","active","pageviews","tally","peak"} (the first day of each),
 *                                "pages":[{"section","views","today"}] (the range's most read, at most 15),
 *                                "counters":[{"key","label","today","total","series","record":{"n","day"}|null}],
 *                                "records":{"peak","active","pageviews","signups"} ({"n","day"(,"at")}|null),
 *                                "server":"up"|"down"|"off"}   the Analytics tab: daily counts, UTC days (Analytics),
 *                               counts only; asks the realtime server's health for the days since the last peak kept
 *   GET  admin/catalog          {"items":[{"c","name","plural"}]}   what a give may carry (the ledger's tradeable classes)
 *   GET  admin/streets          {"streets":[{"tsid","label","hub"}]}  the game's street index, for teleport
 *   GET  admin/rooks            {"server":"up"|"down","attacks":[Rook]}   every Rook over a street now (the server's rooks)
 *   POST admin/message {"player":id,"text":"…"}               delivery live | queued (offline: at their next connect) | down
 *   POST admin/broadcast {"text":"…"}                         {"sent":n,"live":n,"failed":n,"text"}  everyone online now (below)
 *   POST admin/boot    {"player":id,"reason":"…"}             delivery live | offline | down
 *   POST admin/ban     {"player":id,"reason":"…"}             stored; delivery live (signed out now) | stored
 *   POST admin/unban   {"player":id}
 *   POST admin/give    {"player":id,"items":[{"c","n"}],"currants":n}   through the ledger; delivery live | saved
 *   POST admin/refill  {"player":id}                          mood and energy to full; delivery live | offline | down
 *   POST admin/interrogation {"player":id}                    an online administrator into the Interrogation room; delivery live | offline | down
 *   POST admin/rook_end {"street":tsid}                       the Rook flies off, nothing paid; delivery live | ending | none | down
 *   Row: {"id","name","level","street":{tsid,label}|null,"savedAt","banned":bool,"admin":bool}
 *   Rook: {"street":{tsid,label},"phase","angry","health","maxHealth","stun","maxStun","wingBeats","clock",
 *          "orbs","primed","scheduled","players":[{id,name}]}
 *   Sample: one line of the server's health log (health.go's header names the keys: t, p players, c CPU %, l1 load, …)
 *   Chat: {"at","kind":"local"|"channel"|"party","where":{"id","label"},"player":{id,name},"text",
 *          "delivered":bool,"why":""|"slow_down"|"silenced","skipped":n,"to":[{id,name}],
 *          "deleted":null|{"at","by":{id,name}}}   deleted: an administrator deleted the line for everyone
 *          (the website's Delete line); the log keeps it as it was said
 *
 * The chat log (Austin 2026-10-31, for spam and abuse moderation) lives on the
 * realtime server (groddle-afternoon-server chatlog.go: a JSON file a UTC day,
 * kept 30 days), never in this site's database: admin/chatlog asks the
 * server's signed chatlog call and names what it answers (streets from the
 * street index, channels in the game's words, groups from groddle_groups,
 * players as the game names them now; the line keeps the name they spoke under).
 *
 * Server health (Austin 2026-11-11: "review the server health over time ... see the count of active
 * players on the game at that same moment ... upgrade proactively ... lightweight so we can keep all
 * historical info") lives on the realtime server too (health.go: a sample a minute, a JSON line each in
 * a file a UTC month, kept forever). admin/health asks the server's signed health call for a range and
 * passes its rows on as they are (columns named by fields, nulls where the machine could not read a
 * value), so a year of history stays one small answer; the console's Server tab draws it.
 *
 * Analytics (Austin 2026-11-11: "players over time, active players per day, pageviews and even quirky things like
 * trees harvested, mines mined, piggies nibbled, butterflies milked ... Lightweight, keep all history") are daily
 * counts on the site (Analytics: who signed up, who played and for how long from the play-time batches, the pages'
 * pageview beacon, the world's tallies the realtime server rides on those batches), kept forever; admin/analytics
 * also keeps each day's peak online from the server's health. The console's Analytics tab draws them.
 *
 * A broadcast (Austin 2026-10-30: "a broadcast option, to blast everyone at
 * once") is admin/message to each player on the realtime server's online list
 * at that moment: the server has no broadcast call of its own, so the site
 * loops (sent: how many it sent to, live: how many the server reached, failed:
 * the rest, someone who left in between or a server that stopped answering).
 * Nobody offline is queued for: the server's queue is per player and keeps a
 * message for 7 days (admin.go adminQueueFor), so "players who connect in the
 * next few minutes" would need a server change; it is left out. One
 * broadcast a minute (BROADCAST_GAP), for the whole site. One log line (kind
 * broadcast, player 0, the text and the counts).
 *
 * Bans (groddle_bans, one row per suspended login: a ban is the login's, Logins, stored on the login's id and sent
 * to the realtime server as it, whichever character it was given from; banned() reads every character's row, so a
 * row from before on an extra character still counts): a banned account gets
 * no realtime ticket (RealtimeTicket), cannot load or write its multiplayer
 * save (MultiplayerSave), is told so by groddle/v1/me (Player: suspended),
 * and the realtime server refuses it at auth and signs it out if connected.
 * An administrator (manage_options) is never banned, nor is the caller.
 *
 * Ending a Rook attack (Austin 2026-11-03, after three players on Groddle
 * Forest Junction could not fight one: with others on the street a Focusing
 * Orb needs Martial Imagination) is the server's rook_end
 * (groddle-afternoon-server rook_admin.go): the Rook flies off the way an
 * abandoned fight ends after its third wing beat, not as a defeat, so nobody
 * gets iMG or a badge; what it rooked stays rooked. One log line (kind
 * rook_end, player 0, the street and what the server said).
 *
 * The Interrogation room (Austin 2026-11-08: "build it as a moderators' room
 * opened from the console"; game js/interrogation-room.js, register G352) is a
 * street of the game's index that no door leads into. admin/interrogation sends
 * an administrator's connected game there with the realtime server's teleport
 * (teleport.go, the call AdminTeleport makes), landing in the booth
 * (INTERROGATION_ARRIVE, the street's screen space). Only administrators
 * (manage_options) go, themselves or another, and only while connected: the
 * game keeps the street and spot they came from, and the room's door takes them
 * back there. One log line (kind interrogate).
 *
 * A give is a settled trade from nobody (groddle_trades admin-give-<16 hex>:
 * a_id the player, a_gave nothing, b_id 0, b_gave the goods, b's side applied),
 * the way starter bags arrive (LedgerStarter): the goods go into the ledger at
 * once, so until the save lists the id as applied a write is checked as if
 * they were not there yet; a connected game applies it once through the trade
 * slice's applied list (the realtime server's admin_give, ledgerReceipt), and
 * otherwise the next load applies it to the stored save (Ledger::catchUp).
 * Only the ledger's tradeable classes and currants: iMG, mood and energy are
 * not in the ledger. Admin gives are never counted applied by age
 * (Ledger::pending), so a player away for weeks still gets them.
 */
final readonly class AdminConsole
{
    public const ACTIONS = 'groddle_admin_actions';
    public const BANS = 'groddle_bans';
    public const RENAMES = 'groddle_player_renames';

    public const GIVE_PREFIX = 'admin-give-';

    /** Characters, reasons and stacks an action may carry. */
    public const TEXT_MAX = 500;
    public const REASON_MAX = 200;
    public const GIVE_STACKS = 10;
    public const GIVE_EACH = 999;
    public const GIVE_CURRANTS = 100000;

    /** Seconds between two broadcasts, site-wide. */
    public const BROADCAST_GAP = 60;

    public const LOG_PAGE = 50;

    /** Chat log lines a page (the server allows up to 200). */
    public const CHAT_PAGE = 100;

    /** admin/health's ranges (seconds back from now; all: from the first sample kept), and how many rows at most. */
    public const HEALTH_RANGES = ['24h' => 86400, '7d' => 604800, '30d' => 2592000, '90d' => 7776000, 'all' => 0];
    public const HEALTH_POINTS = 1500;
    /** A long range reads months of lines on the server: more time, and a bigger answer, than the other calls. */
    public const HEALTH_TIMEOUT = 15.0;
    public const HEALTH_BYTES = 1048576;

    /** The chat channels in the game's words (realtime server channels.go rooms). */
    public const CHANNELS = ['global' => 'Global Chat', 'trade' => 'Trade Channel', 'help' => 'Live Help', 'newbie' => 'Live Help (new players)'];
    public const SEARCH_MAX = 25;

    public const SUSPENDED = 'This account is suspended.';

    /** A street id as the realtime server takes one (hub.go streetRe). */
    public const STREET = '/^[A-Za-z0-9_-]{1,32}$/';

    /** The phases of a Rook attack (groddle-afternoon-server rook.go). */
    public const ROOK_PHASES = ['herald', 'build_up', 'angry', 'stunned', 'dead', 'departed'];

    private const ADMIN = 'manage_options';

    /** The Interrogation room (game js/interrogation-room.js INTERROGATION_TSID) and where a visitor lands, in its screen space (INTERROGATION_ARRIVE_SCREEN). */
    public const INTERROGATION_TSID = 'GIFLFPPBUUT11SP';
    public const INTERROGATION_ARRIVE = ['x' => 585, 'y' => 978];

    public function __construct(private Seams $minn)
    {
    }

    // —— Reading ——

    #[Route(Method::Get, '/groddle/v1/admin/online', policy: new Policy(Access::Cap, self::ADMIN))]
    public function online(Request $request): Response
    {
        $call = new RealtimeCall($this->minn);
        if ($request->query('sync') === '1') {
            $call->post('bans', ['players' => self::bannedLogins($this->minn->db)]);
        }
        $answer = $call->post('online', []);
        if (!is_array($answer['players'] ?? null)) {
            return Response::json(['server' => 'down', 'players' => []]);
        }
        $since = [];
        $streets = [];
        foreach ($answer['players'] as $p) {
            if (is_array($p) && is_int($p['id'] ?? null) && $p['id'] > 0) {
                $since[$p['id']] = (int) ($p['since'] ?? 0);
                $streets[$p['id']] = is_string($p['street'] ?? null) ? $p['street'] : '';
            }
        }
        $rows = $this->rows(array_keys($since));
        $index = $this->streetIndex();
        $out = [];
        foreach ($rows as $row) {
            $live = $streets[$row['id']] ?? '';
            $row['street'] = $live === '' ? null : ['tsid' => $live, 'label' => (string) ($index[$live]['label'] ?? $live)];
            $row['since'] = $since[$row['id']] ?? 0;
            $out[] = $row;
        }
        return Response::json(['server' => 'up', 'players' => $out]);
    }

    #[Route(Method::Get, '/groddle/v1/admin/players', policy: new Policy(Access::Cap, self::ADMIN))]
    public function players(Request $request): Response
    {
        $q = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($request->query('q') ?? '')) ?? ''));
        if ($q === '') {
            return Response::json(['players' => []]);
        }
        $db = $this->minn->db;
        $like = addcslashes(substr($q, 0, 32), '%_\\') . '%';
        $ids = array_map(static fn (array $r): int => (int) $r['user_id'], $db->rows('SELECT user_id FROM ' . $db->table(Characters::TABLE) . ' WHERE name_key LIKE ? ORDER BY name_key = ? DESC, name_key LIMIT ' . self::SEARCH_MAX, [$like, $q]));
        // Accounts with no character name go by their display name in the game (Characters::nameOf).
        if (count($ids) < self::SEARCH_MAX) {
            $more = $db->rows('SELECT u.ID FROM ' . $db->table('users') . ' u LEFT JOIN ' . $db->table(Characters::TABLE) . ' c ON c.user_id = u.ID WHERE c.user_id IS NULL AND LOWER(u.display_name) LIKE ? ORDER BY u.display_name LIMIT ' . (self::SEARCH_MAX - count($ids)), [$like]);
            $ids = [...$ids, ...array_map(static fn (array $r): int => (int) $r['ID'], $more)];
        }
        return Response::json(['players' => $this->rows($ids)]);
    }

    /** One character and every character of its login (Logins): the console's "Characters on this login". */
    #[Route(Method::Get, '/groddle/v1/admin/players/{id:\d+}', policy: new Policy(Access::Cap, self::ADMIN))]
    public function player(Request $request, string $id): Response
    {
        $row = $this->rows([(int) $id])[0] ?? null;
        if ($row === null) {
            throw new RestError('groddle_admin_no_player', 'No player has that id.', 404);
        }
        $login = Moderation::loginOut($this->minn->db, (int) $id);
        $rows = [];
        foreach ($this->rows(array_map(static fn (array $c): int => $c['id'], $login['characters'])) as $r) {
            $rows[$r['id']] = $r;
        }
        foreach ($login['characters'] as $i => $c) {
            $r = $rows[$c['id']] ?? [];
            $login['characters'][$i] += ['level' => $r['level'] ?? 1, 'savedAt' => $r['savedAt'] ?? null, 'banned' => $r['banned'] ?? false];
        }
        return Response::json(['player' => $row, 'login' => $login])->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * An administrator renames a character (Austin 2026-11-12: admin-only for now): the sign-up's rules
     * (PlayerAccount::NAME, WordFilter, no other character or account has it), the character's row and its
     * account's display name; the old /players/ address is remembered (groddle_player_renames) and answers a
     * 301 to the new one (PlayerPages). What was said under the old name keeps it.
     */
    #[Route(Method::Post, '/groddle/v1/admin/players/{id:\d+}/rename', policy: new Policy(Access::Cap, self::ADMIN))]
    public function rename(Request $request, string $id): Response
    {
        $admin = $this->minn->user() ?? throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        $player = (int) $id;
        $db = $this->minn->db;
        $characters = new Characters($db);
        $was = $characters->namesOf([$player])[$player] ?? null;
        if ($was === null) {
            throw new RestError('groddle_admin_no_player', 'No player has that id.', 404);
        }
        $name = trim(preg_replace('/\s+/', ' ', is_string($request->json()['name'] ?? null) ? $request->json()['name'] : '') ?? '');
        $refusal = self::renameRefusal($db, $player, $was, $name);
        if ($refusal !== null) {
            throw new RestError($refusal[0], $refusal[1], $refusal[2]);
        }
        $oldSlug = Profiles::slugs($db)[$player] ?? Profiles::slug($was);
        if (!$characters->rename($player, $name)) {
            throw new RestError('groddle_admin_name_taken', 'Someone already has that name.', 409);
        }
        $now = time();
        $db->execute('INSERT INTO ' . $db->table(self::RENAMES) . ' (user_id, old_name, old_slug, new_name, admin_user_id, renamed_at) VALUES (?, ?, ?, ?, ?, ?)', [$player, mb_substr($was, 0, 32), substr($oldSlug, 0, 64), $name, $admin->id, gmdate('Y-m-d H:i:s', $now)]);
        self::recordAction($db, $admin->id, $player, 'rename', ['from' => $was, 'to' => $name], 'stored', $now);
        return Response::json(['player' => ['id' => $player, 'name' => $name], 'was' => $was, 'text' => "Renamed {$was} to {$name}."]);
    }

    /**
     * Why a rename may not go ahead: [code, message, status], or null. The sign-up's rules; the same name
     * (or only its letter case changed) is fine for the character itself.
     *
     * @return array{0: string, 1: string, 2: int}|null
     */
    public static function renameRefusal(Db $db, int $player, string $was, string $name): ?array
    {
        if (preg_match(PlayerAccount::NAME, $name) !== 1) {
            return ['groddle_admin_name', 'Names are 3 to 19 letters, numbers, spaces, - or _, starting and ending with a letter or number.', 400];
        }
        if (WordFilter::has($name)) {
            return ['groddle_admin_name', WordFilter::NAME_REFUSED, 400];
        }
        if ($name === $was) {
            return ['groddle_admin_name_same', "{$was} already has that name.", 409];
        }
        if (strtolower($name) !== strtolower($was) && (new Characters($db))->taken($name)) {
            return ['groddle_admin_name_taken', 'Someone already has that name.', 409];
        }
        return null;
    }

    #[Route(Method::Get, '/groddle/v1/admin/bans', policy: new Policy(Access::Cap, self::ADMIN))]
    public function bans(Request $request): Response
    {
        $db = $this->minn->db;
        $rows = $db->rows('SELECT user_id, reason, admin_user_id, created_at FROM ' . $db->table(self::BANS) . ' ORDER BY created_at DESC, user_id');
        $names = (new Characters($db))->namesOf(array_merge(array_map(static fn (array $r): int => (int) $r['user_id'], $rows), array_map(static fn (array $r): int => (int) $r['admin_user_id'], $rows)));
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'player' => ['id' => (int) $r['user_id'], 'name' => $this->nameOr($names, (int) $r['user_id'])],
                'reason' => (string) $r['reason'],
                'by' => ['id' => (int) $r['admin_user_id'], 'name' => $this->nameOr($names, (int) $r['admin_user_id'])],
                'at' => SaveShape::time((string) $r['created_at']),
            ];
        }
        return Response::json(['bans' => $out]);
    }

    #[Route(Method::Get, '/groddle/v1/admin/log', policy: new Policy(Access::Cap, self::ADMIN))]
    public function log(Request $request): Response
    {
        $db = $this->minn->db;
        $before = (int) ($request->query('before') ?? '0');
        $where = $before > 0 ? 'WHERE id < ' . $before : '';
        $rows = $db->rows('SELECT * FROM ' . $db->table(self::ACTIONS) . " {$where} ORDER BY id DESC LIMIT " . (self::LOG_PAGE + 1));
        $more = count($rows) > self::LOG_PAGE;
        $rows = array_slice($rows, 0, self::LOG_PAGE);
        $names = (new Characters($db))->namesOf(array_merge(array_map(static fn (array $r): int => (int) $r['admin_user_id'], $rows), array_map(static fn (array $r): int => (int) $r['player_user_id'], $rows)));
        $lines = [];
        foreach ($rows as $r) {
            $detail = json_decode((string) $r['detail'], true);
            $lines[] = [
                'id' => (int) $r['id'],
                'at' => SaveShape::time((string) $r['at']),
                'kind' => (string) $r['kind'],
                'by' => ['id' => (int) $r['admin_user_id'], 'name' => $this->nameOr($names, (int) $r['admin_user_id'])],
                'player' => ['id' => (int) $r['player_user_id'], 'name' => $this->nameOr($names, (int) $r['player_user_id'])],
                'detail' => is_array($detail) ? $detail : new \stdClass(),
                'delivery' => (string) $r['delivery'],
            ];
        }
        return Response::json(['lines' => $lines, 'more' => $more]);
    }

    #[Route(Method::Get, '/groddle/v1/admin/chatlog', policy: new Policy(Access::Cap, self::ADMIN))]
    public function chatlog(Request $request): Response
    {
        $query = [];
        foreach (['player', 'q', 'kind', 'since', 'until', 'refused', 'before'] as $k) {
            $query[$k] = $request->query($k);
        }
        return Response::json($this->chatlogAnswer($query));
    }

    /**
     * admin/chatlog's answer for a search (chatQuery's words); the moderators' mod/chatlog
     * (Moderation) gives the same. The chat log never holds an IM (chatlog.go).
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function chatlogAnswer(array $query): array
    {
        $answer = (new RealtimeCall($this->minn))->post('chatlog', self::chatQuery($query));
        $status = $answer['status'] ?? null;
        if ($status === 'off') {
            return ['server' => 'off', 'lines' => [], 'next' => '', 'dropped' => 0, 'days' => 0];
        }
        if ($status !== 'ok' || !is_array($answer['lines'] ?? null)) {
            return ['server' => 'down', 'lines' => [], 'next' => '', 'dropped' => 0, 'days' => 0];
        }
        $raw = array_values(array_filter($answer['lines'], 'is_array'));
        $ids = [];
        $groups = [];
        foreach ($raw as $l) {
            $ids[] = (int) ($l['id'] ?? 0);
            $ids[] = (int) ($l['deleted']['by'] ?? 0);
            foreach (is_array($l['to'] ?? null) ? $l['to'] : [] as $to) {
                $ids[] = (int) $to;
            }
            if (preg_match('/^group:([1-9]\d{0,18})$/', (string) ($l['where'] ?? ''), $m) === 1) {
                $groups[] = (int) $m[1];
            }
        }
        $names = (new Characters($this->minn->db))->namesOf(array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0))));
        $groupNames = [];
        if ($groups !== []) {
            foreach ($this->minn->db->rows('SELECT id, name FROM ' . $this->minn->db->table(Groups::TABLE) . ' WHERE id IN (?)', [array_values(array_unique($groups))]) as $g) {
                $groupNames[(int) $g['id']] = (string) $g['name'];
            }
        }
        $index = $this->streetIndex();
        $lines = [];
        foreach ($raw as $l) {
            $id = (int) ($l['id'] ?? 0);
            $kind = (string) ($l['kind'] ?? '');
            $where = (string) ($l['where'] ?? '');
            $lines[] = [
                'at' => (string) ($l['at'] ?? ''),
                'kind' => $kind,
                'where' => ['id' => $where, 'label' => self::chatWhere($kind, $where, $index, $groupNames)],
                'player' => ['id' => $id, 'name' => (string) ($names[$id] ?? ($l['name'] ?? "#{$id}"))],
                'spokeAs' => (string) ($l['name'] ?? ''),
                'text' => (string) ($l['text'] ?? ''),
                // The word filter (the remake's own addition, groddle-afternoon-server wordfilter.go): text is what was typed, shown what players saw.
                'filtered' => ($l['filtered'] ?? false) === true,
                'shown' => (string) ($l['shown'] ?? ''),
                'delivered' => ($l['delivered'] ?? false) === true,
                'why' => (string) ($l['why'] ?? ''),
                'skipped' => max(0, (int) ($l['skipped'] ?? 0)),
                'to' => array_map(fn (mixed $to): array => ['id' => (int) $to, 'name' => $this->nameOr($names, (int) $to)], is_array($l['to'] ?? null) ? $l['to'] : []),
                'deleted' => $this->chatDeleted($l['deleted'] ?? null, $names),
            ];
        }
        return [
            'server' => 'up',
            'lines' => $lines,
            'next' => is_string($answer['next'] ?? null) ? $answer['next'] : '',
            'dropped' => max(0, (int) ($answer['dropped'] ?? 0)),
            'days' => max(0, (int) ($answer['days'] ?? 0)),
        ];
    }

    /**
     * A chat log line's deletion as the console shows it (groddle-afternoon-server chatdelete.go: an
     * administrator deleted it for everyone): when, and who as the game names them now. Null when not deleted.
     *
     * @param array<int, string> $names
     * @return array{at: string, by: array{id: int, name: string}}|null
     */
    private function chatDeleted(mixed $d, array $names): ?array
    {
        if (!is_array($d)) {
            return null;
        }
        $by = (int) ($d['by'] ?? 0);
        return ['at' => (string) ($d['at'] ?? ''), 'by' => ['id' => $by, 'name' => (string) ($names[$by] ?? ($d['by_name'] ?? "#{$by}"))]] + (($d['purge'] ?? false) === true ? ['purge' => true] : []);
    }

    /**
     * The console's search as the realtime server's chatlog call takes it (chatlog.go chatQuery):
     * player is "#7" or "7" for an id, anything else a piece of the name; q a piece of the text;
     * kind local, channel or party; since and until unix milliseconds (the viewer's local days,
     * console.js chatlogParams); refused "1" for refused lines only; before the server's cursor.
     * Anything that does not fit is left out rather than refused.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public static function chatQuery(array $query): array
    {
        $text = static fn (mixed $v, int $max): string => is_string($v) ? mb_substr(trim(preg_replace('/\s+/u', ' ', $v) ?? ''), 0, $max) : '';
        $body = ['limit' => self::CHAT_PAGE];
        $player = $text($query['player'] ?? null, 64);
        if (preg_match('/^#?([1-9]\d{0,18})$/', $player, $m) === 1) {
            $body['player'] = (int) $m[1];
        } elseif ($player !== '') {
            $body['name'] = $player;
        }
        $q = $text($query['q'] ?? null, 200);
        if ($q !== '') {
            $body['q'] = $q;
        }
        $kind = $query['kind'] ?? null;
        if (in_array($kind, ['local', 'channel', 'party'], true)) {
            $body['kind'] = $kind;
        }
        foreach (['since', 'until'] as $k) {
            $v = $query[$k] ?? null;
            if (is_string($v) && preg_match('/^[1-9]\d{0,15}$/', $v) === 1) {
                $body[$k] = (int) $v;
            }
        }
        if (($query['refused'] ?? null) === '1') {
            $body['undelivered'] = true;
        }
        $before = $query['before'] ?? null;
        if (is_string($before) && preg_match('/^\d{4}-\d{2}-\d{2}:\d{1,9}$/', $before) === 1) {
            $body['before'] = $before;
        }
        return $body;
    }

    /**
     * Where a chat line was said, in the game's words.
     *
     * @param array<string, array<string, mixed>> $streets the street index
     * @param array<int, string> $groups group names by id
     */
    public static function chatWhere(string $kind, string $where, array $streets, array $groups): string
    {
        if ($kind === 'local') {
            return (string) ($streets[$where]['label'] ?? $where);
        }
        if ($kind === 'party') {
            return 'Party';
        }
        if (preg_match('/^group:(\d+)$/', $where, $m) === 1) {
            return $groups[(int) $m[1]] ?? "Group {$m[1]}";
        }
        return self::CHANNELS[$where] ?? $where;
    }

    #[Route(Method::Get, '/groddle/v1/admin/catalog', policy: new Policy(Access::Cap, self::ADMIN))]
    public function catalog(Request $request): Response
    {
        $file = dirname(__DIR__) . '/data/economy.json';
        $table = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $out = [];
        foreach (is_array($table['items'] ?? null) ? $table['items'] : [] as $c => $row) {
            if (is_string($c) && is_array($row)) {
                $out[] = ['c' => $c, 'name' => (string) ($row['n'] ?? $c), 'plural' => (string) ($row['p'] ?? $row['n'] ?? $c)];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return Response::json(['items' => $out])->withHeader('Cache-Control', 'private, max-age=300');
    }

    #[Route(Method::Get, '/groddle/v1/admin/streets', policy: new Policy(Access::Cap, self::ADMIN))]
    public function streets(Request $request): Response
    {
        $out = [];
        foreach ($this->streetIndex() as $tsid => $s) {
            if (is_string($tsid) && is_array($s)) {
                $out[] = ['tsid' => $tsid, 'label' => (string) ($s['label'] ?? $tsid), 'hub' => (string) ($s['hubName'] ?? '')];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']) ?: strcmp($a['tsid'], $b['tsid']));
        return Response::json(['streets' => $out])->withHeader('Cache-Control', 'private, max-age=300');
    }

    #[Route(Method::Get, '/groddle/v1/admin/health', policy: new Policy(Access::Cap, self::ADMIN))]
    public function health(Request $request): Response
    {
        $q = self::healthQuery($request->query('range'), $request->query('since'), $request->query('until'), time());
        $answer = (new RealtimeCall($this->minn))->post('health', $q['call'], self::HEALTH_TIMEOUT, self::HEALTH_BYTES);
        return Response::json(self::healthAnswer($answer, $q['range']));
    }

    /**
     * The health call's body for a range (HEALTH_RANGES) or explicit since/until (unix seconds), and the range named.
     *
     * @return array{range: string, call: array<string, int>}
     */
    public static function healthQuery(mixed $range, mixed $since, mixed $until, int $now): array
    {
        $num = static fn (mixed $v): ?int => is_string($v) && preg_match('/^\d{1,12}$/', $v) === 1 ? (int) $v : null;
        $s = $num($since);
        $u = $num($until);
        if ($s !== null && ($u === null || $u > $s)) {
            return ['range' => 'custom', 'call' => ['since' => $s, 'until' => $u ?? $now + 60, 'points' => self::HEALTH_POINTS]];
        }
        $range = is_string($range) && array_key_exists($range, self::HEALTH_RANGES) ? $range : '24h';
        $span = self::HEALTH_RANGES[$range];
        return ['range' => $range, 'call' => ['since' => $span === 0 ? 0 : max(0, $now - $span), 'until' => $now + 60, 'points' => self::HEALTH_POINTS]];
    }

    /**
     * What admin/health answers for the server's answer: its rows as they are, checked (a field name a
     * short key, every row as long as fields, every value a number or null), and the machine's few facts.
     *
     * @param array<string, mixed>|null $answer
     * @return array<string, mixed>
     */
    public static function healthAnswer(?array $answer, string $range): array
    {
        $status = $answer['status'] ?? null;
        $empty = ['range' => $range, 'since' => 0, 'until' => 0, 'first' => 0, 'bucket' => 0, 'machine' => null, 'now' => null, 'fields' => [], 'samples' => []];
        if ($status === 'off') {
            return ['server' => 'off'] + $empty;
        }
        $fields = $answer['fields'] ?? null;
        if ($status !== 'ok' || !is_array($fields) || !is_array($answer['rows'] ?? null) || !array_is_list($fields) || ($fields[0] ?? null) !== 't') {
            return ['server' => 'down'] + $empty;
        }
        foreach ($fields as $f) {
            if (!is_string($f) || preg_match('/^[a-z][a-z0-9]{0,3}$/', $f) !== 1) {
                return ['server' => 'down'] + $empty;
            }
        }
        $isNum = static fn (mixed $v): bool => $v === null || is_int($v) || is_float($v);
        $samples = [];
        foreach ($answer['rows'] as $row) {
            if (is_array($row) && array_is_list($row) && count($row) === count($fields) && is_int($row[0]) && count(array_filter($row, $isNum)) === count($row)) {
                $samples[] = $row;
            }
        }
        $now = null;
        if (is_array($answer['now'] ?? null)) {
            $now = [];
            foreach ($answer['now'] as $k => $v) {
                if (is_string($k) && preg_match('/^[a-z][a-z0-9]{0,3}$/', $k) === 1 && $v !== null && $isNum($v)) {
                    $now[$k] = $v;
                }
            }
        }
        $m = is_array($answer['machine'] ?? null) ? $answer['machine'] : [];
        $int = static fn (mixed $v): ?int => is_int($v) || is_float($v) ? (int) $v : null;
        $str = static fn (mixed $v): string => is_string($v) ? mb_substr($v, 0, 64) : '';
        return [
            'server' => 'up',
            'range' => $range,
            'since' => (int) ($answer['since'] ?? 0),
            'until' => (int) ($answer['until'] ?? 0),
            'first' => (int) ($answer['first'] ?? 0),
            'bucket' => max(60, (int) ($answer['bucket'] ?? 60)),
            'machine' => [
                'host' => $str($m['host'] ?? null),
                'os' => $str($m['os'] ?? null),
                'cpus' => $int($m['cpus'] ?? null),
                'mem' => $int($m['mem'] ?? null),
                'disk' => is_int($m['disk'] ?? null) || is_float($m['disk'] ?? null) ? (float) $m['disk'] : null,
                'version' => $str($m['version'] ?? null),
                'started' => $int($m['started'] ?? null),
                'uptime' => $int($m['uptime'] ?? null),
                'boot' => $int($m['boot'] ?? null),
            ],
            'now' => $now,
            'fields' => $fields,
            'samples' => $samples,
        ];
    }

    #[Route(Method::Get, '/groddle/v1/admin/analytics', policy: new Policy(Access::Cap, self::ADMIN))]
    public function analytics(Request $request): Response
    {
        $db = $this->minn->db;
        $now = time();
        // Each day's most players online at once: the realtime server's health from the start of the last day kept.
        $answer = (new RealtimeCall($this->minn))->post('health', ['since' => Analytics::peakSince($db), 'until' => $now + 60, 'points' => self::HEALTH_POINTS], self::HEALTH_TIMEOUT, self::HEALTH_BYTES);
        $health = self::healthAnswer($answer, 'custom');
        if ($health['server'] === 'up') {
            Analytics::keepPeaks($db, Analytics::peaksOf($health['fields'], $health['samples'], $health['bucket']));
        }
        $range = $request->query('range');
        return Response::json(Analytics::report($db, is_string($range) ? $range : '30d', $now) + ['server' => $health['server']])
            ->withHeader('Cache-Control', 'private, no-store');
    }

    #[Route(Method::Get, '/groddle/v1/admin/rooks', policy: new Policy(Access::Cap, self::ADMIN))]
    public function rooks(Request $request): Response
    {
        $answer = (new RealtimeCall($this->minn))->post('rooks', []);
        if (($answer['status'] ?? null) !== 'ok' || !is_array($answer['attacks'] ?? null)) {
            return Response::json(['server' => 'down', 'attacks' => []]);
        }
        return Response::json(['server' => 'up', 'attacks' => self::rookRows($answer['attacks'], $this->streetIndex())]);
    }

    /**
     * The server's attacks as the console lists them: the street named from the street index,
     * numbers as numbers, players as {id, name}; anything that is not an attack is left out.
     *
     * @param array<mixed> $attacks the server's rooks answer
     * @param array<string, array<string, mixed>> $streets the street index
     * @return list<array<string, mixed>>
     */
    public static function rookRows(array $attacks, array $streets): array
    {
        $out = [];
        $int = static fn (mixed $v): int => is_int($v) || is_float($v) ? max(0, (int) $v) : 0;
        foreach ($attacks as $a) {
            $tsid = is_array($a) ? ($a['street'] ?? null) : null;
            if (!is_string($tsid) || preg_match(self::STREET, $tsid) !== 1) {
                continue;
            }
            $players = [];
            foreach (is_array($a['players'] ?? null) ? $a['players'] : [] as $p) {
                if (is_array($p) && is_int($p['id'] ?? null) && $p['id'] > 0) {
                    $players[] = ['id' => $p['id'], 'name' => is_string($p['name'] ?? null) ? $p['name'] : "#{$p['id']}"];
                }
            }
            $out[] = [
                'street' => ['tsid' => $tsid, 'label' => (string) ($streets[$tsid]['label'] ?? $tsid)],
                'phase' => in_array($a['phase'] ?? null, self::ROOK_PHASES, true) ? $a['phase'] : 'build_up',
                'angry' => is_string($a['angry'] ?? null) ? $a['angry'] : '',
                'health' => $int($a['health'] ?? 0),
                'maxHealth' => $int($a['maxHealth'] ?? 0),
                'stun' => $int($a['stun'] ?? 0),
                'maxStun' => $int($a['maxStun'] ?? 0),
                'wingBeats' => $int($a['wingBeats'] ?? 0),
                'clock' => $int($a['clock'] ?? 0),
                'orbs' => $int($a['orbs'] ?? 0),
                'primed' => ($a['primed'] ?? false) === true,
                'scheduled' => ($a['scheduled'] ?? false) === true,
                'players' => $players,
            ];
        }
        usort($out, static fn (array $x, array $y): int => strcasecmp($x['street']['label'], $y['street']['label']) ?: strcmp($x['street']['tsid'], $y['street']['tsid']));
        return $out;
    }

    // —— Acting ——

    #[Route(Method::Post, '/groddle/v1/admin/message', policy: new Policy(Access::Cap, self::ADMIN))]
    public function message(Request $request): Response
    {
        [$admin, $player, $body] = $this->target($request);
        $text = self::messageText($body['text'] ?? '');
        if ($text === '') {
            throw new RestError('groddle_admin_empty', 'Write a message first.', 400);
        }
        $id = 'am-' . bin2hex(random_bytes(8));
        $answer = (new RealtimeCall($this->minn))->post('message', ['player' => $player['id'], 'id' => $id, 'text' => $text]);
        $delivery = match ($answer['status'] ?? null) {
            'sent' => 'live',
            'queued' => 'queued',
            default => 'down',
        };
        $this->record($admin, $player['id'], 'message', ['text' => $text], $delivery);
        return $this->done($player, $delivery, match ($delivery) {
            'live' => "{$player['name']} has your message.",
            'queued' => "{$player['name']} is not online. They will see it when they next connect.",
            default => "The realtime server is not answering, so {$player['name']} did not get it.",
        });
    }

    #[Route(Method::Post, '/groddle/v1/admin/broadcast', policy: new Policy(Access::Cap, self::ADMIN))]
    public function broadcast(Request $request): Response
    {
        $admin = $this->minn->user();
        if ($admin === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        $text = self::messageText($request->json()['text'] ?? '');
        if ($text === '') {
            throw new RestError('groddle_admin_empty', 'Write a message first.', 400);
        }
        $db = $this->minn->db;
        $now = time();
        $last = $db->value('SELECT at FROM ' . $db->table(self::ACTIONS) . " WHERE kind = 'broadcast' ORDER BY id DESC LIMIT 1");
        if (is_string($last) && ($wait = self::BROADCAST_GAP - ($now - (int) strtotime($last . ' UTC'))) > 0) {
            throw new RestError('groddle_admin_broadcast_slow', "One message to everyone a minute: try again in {$wait} s.", 429, ['wait' => $wait]);
        }
        $call = new RealtimeCall($this->minn);
        $online = $call->post('online', []);
        if (!is_array($online['players'] ?? null)) {
            throw new RestError('groddle_admin_down', 'The realtime server is not answering, so nobody got it.', 503);
        }
        $ids = [];
        foreach ($online['players'] as $p) {
            if (is_array($p) && is_int($p['id'] ?? null) && $p['id'] > 0) {
                $ids[$p['id']] = true;
            }
        }
        $sent = $live = 0;
        foreach (array_keys($ids) as $player) {
            $answer = $call->post('message', ['player' => $player, 'id' => 'am-' . bin2hex(random_bytes(8)), 'text' => $text]);
            $sent++;
            if (($answer['status'] ?? null) === 'sent') {
                $live++;
            }
        }
        $failed = $sent - $live;
        $delivery = $sent === 0 ? 'none' : ($failed === 0 ? 'live' : ($live === 0 ? 'down' : 'some'));
        self::recordAction($db, $admin->id, 0, 'broadcast', ['text' => $text, 'count' => $sent, 'live' => $live, 'failed' => $failed], $delivery, $now);
        $words = static fn (int $n): string => $n === 1 ? '1 player' : "{$n} players";
        return Response::json(['sent' => $sent, 'live' => $live, 'failed' => $failed, 'delivery' => $delivery, 'text' => match (true) {
            $sent === 0 => 'Nobody is online, so nobody got it.',
            $failed === 0 => 'Sent to ' . $words($live) . ' online.',
            default => 'Sent to ' . $words($live) . ' online; ' . $words($failed) . ' had left or did not answer.',
        }]);
    }

    #[Route(Method::Post, '/groddle/v1/admin/boot', policy: new Policy(Access::Cap, self::ADMIN))]
    public function boot(Request $request): Response
    {
        [$admin, $player, $body] = $this->target($request);
        if ($player['id'] === $admin->id) {
            throw new RestError('groddle_admin_self', 'You cannot sign yourself out from here.', 409);
        }
        $reason = self::reasonText($body['reason'] ?? '');
        $answer = (new RealtimeCall($this->minn))->post('boot', ['player' => $player['id'], 'reason' => $reason]);
        $delivery = match ($answer['status'] ?? null) {
            'sent' => 'live',
            'offline' => 'offline',
            default => 'down',
        };
        $this->record($admin, $player['id'], 'boot', ['reason' => $reason], $delivery);
        return $this->done($player, $delivery, match ($delivery) {
            'live' => "Signed {$player['name']} out.",
            'offline' => "{$player['name']} is not online.",
            default => 'The realtime server is not answering.',
        });
    }

    #[Route(Method::Post, '/groddle/v1/admin/ban', policy: new Policy(Access::Cap, self::ADMIN))]
    public function ban(Request $request): Response
    {
        [$admin, $player, $body] = $this->target($request);
        $db = $this->minn->db;
        $logins = new Logins($db);
        // Nor any character of your own login (Logins).
        if ($player['id'] === $admin->id || $logins->sameLogin($player['id'], $admin->id)) {
            throw new RestError('groddle_admin_self', 'You cannot ban yourself.', 409);
        }
        $caps = Capabilities::fromDb($db);
        // A login with an administrator among its characters is never banned.
        if ($caps->can($player['id'], self::ADMIN) || array_filter($logins->siblings($player['id']), static fn (int $id): bool => $caps->can($id, self::ADMIN)) !== []) {
            throw new RestError('groddle_admin_ban_admin', "{$player['name']} is an administrator and cannot be banned.", 409);
        }
        $reason = self::reasonText($body['reason'] ?? '');
        // A ban is the login's (Logins): stored on the login's id and sent to the realtime server as it, whichever
        // character it was given from; the server boots whichever character plays and refuses them all (the ticket's acct).
        $login = $logins->loginOf($player['id']);
        $db->execute('INSERT INTO ' . $db->table(self::BANS) . ' (user_id, reason, admin_user_id, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE reason = VALUES(reason), admin_user_id = VALUES(admin_user_id)', [$login, $reason, $admin->id, gmdate('Y-m-d H:i:s')]);
        $answer = (new RealtimeCall($this->minn))->post('ban', ['player' => $login, 'reason' => $reason]);
        $delivery = ($answer['status'] ?? null) === 'sent' ? 'live' : 'stored';
        $this->record($admin, $player['id'], 'ban', ['reason' => $reason] + ($login === $player['id'] ? [] : ['login' => $login]), $delivery);
        return $this->done($player, $delivery, $delivery === 'live' ? "Banned {$player['name']} and signed them out." : "Banned {$player['name']}.");
    }

    #[Route(Method::Post, '/groddle/v1/admin/unban', policy: new Policy(Access::Cap, self::ADMIN))]
    public function unban(Request $request): Response
    {
        [$admin, $player] = $this->target($request);
        $login = self::unbanLogin($this->minn->db, $player['id']);
        $answer = (new RealtimeCall($this->minn))->post('unban', ['player' => $login]);
        $delivery = ($answer['status'] ?? null) === 'unbanned' ? 'live' : 'stored';
        $this->record($admin, $player['id'], 'unban', [], $delivery);
        return $this->done($player, $delivery, "{$player['name']} can play again.");
    }

    #[Route(Method::Post, '/groddle/v1/admin/give', policy: new Policy(Access::Cap, self::ADMIN))]
    public function give(Request $request): Response
    {
        [$admin, $player, $body] = $this->target($request);
        $economy = Economy::shared();
        $goods = self::giveGoods($body, static fn (string $c): bool => $economy->tradeable($c));
        if (is_string($goods)) {
            throw new RestError('groddle_admin_give', $goods, 400);
        }
        $now = time();
        $op = self::GIVE_PREFIX . bin2hex(random_bytes(8));
        Ledger::atomically($this->minn->db, fn () => $this->grant($player['id'], $op, $goods, $now));
        $parts = [];
        foreach ($goods['items'] as $c => $n) {
            $parts[] = $economy->label((string) $c, $n);
        }
        if ($goods['currants'] > 0) {
            $parts[] = number_format($goods['currants']) . ($goods['currants'] === 1 ? ' currant' : ' currants');
        }
        $what = self::listWords($parts);
        $items = [];
        foreach ($goods['items'] as $c => $n) {
            $items[] = ['c' => (string) $c, 'n' => $n];
        }
        $answer = (new RealtimeCall($this->minn))->post('give', ['player' => $player['id'], 'op' => $op, 'items' => $items, 'currants' => $goods['currants'], 'text' => "An administrator gave you {$what}."]);
        $delivery = ($answer['status'] ?? null) === 'sent' ? 'live' : 'saved';
        $this->record($admin, $player['id'], 'give', ['op' => $op, 'items' => (object) $goods['items'], 'currants' => $goods['currants'], 'text' => $what], $delivery);
        return $this->done($player, $delivery, $delivery === 'live' ? "Gave {$player['name']} {$what}." : "Gave {$player['name']} {$what}. They get it when their game next loads.") ->withHeader('X-Groddle-Give', $op);
    }

    #[Route(Method::Post, '/groddle/v1/admin/refill', policy: new Policy(Access::Cap, self::ADMIN))]
    public function refill(Request $request): Response
    {
        [$admin, $player] = $this->target($request);
        $answer = (new RealtimeCall($this->minn))->post('refill', ['player' => $player['id']]);
        $delivery = match ($answer['status'] ?? null) {
            'sent' => 'live',
            'offline' => 'offline',
            default => 'down',
        };
        $this->record($admin, $player['id'], 'refill', [], $delivery);
        return $this->done($player, $delivery, match ($delivery) {
            'live' => "Filled {$player['name']}'s mood and energy.",
            'offline' => "{$player['name']} is not online. Refill works on a connected game.",
            default => 'The realtime server is not answering.',
        });
    }

    #[Route(Method::Post, '/groddle/v1/admin/interrogation', policy: new Policy(Access::Cap, self::ADMIN))]
    public function interrogation(Request $request): Response
    {
        [$admin, $player] = $this->target($request);
        if (!Capabilities::fromDb($this->minn->db)->can($player['id'], self::ADMIN)) {
            throw new RestError('groddle_admin_not_admin', 'Only administrators go to the Interrogation room.', 409);
        }
        $tsid = self::INTERROGATION_TSID;
        $index = $this->streetIndex();
        if (!isset($index[$tsid])) {
            throw new RestError('groddle_admin_no_room', 'The game on this site has no Interrogation room yet.', 404);
        }
        $answer = (new RealtimeCall($this->minn))->post('teleport', ['player' => $player['id'], 'street' => $tsid] + self::INTERROGATION_ARRIVE);
        $delivery = match ($answer['status'] ?? null) {
            'sent' => 'live',
            'offline' => 'offline',
            default => 'down',
        };
        $this->record($admin, $player['id'], 'interrogate', ['to' => ['tsid' => $tsid, 'label' => (string) ($index[$tsid]['label'] ?? $tsid)]], $delivery);
        $self = $player['id'] === $admin->id;
        return $this->done($player, $delivery, match ($delivery) {
            'live' => $self ? 'Taking you to the Interrogation room.' : "Took {$player['name']} to the Interrogation room.",
            'offline' => "{$player['name']} is not online. The Interrogation room opens for a connected game.",
            default => 'The realtime server is not answering.',
        });
    }

    #[Route(Method::Post, '/groddle/v1/admin/rook_end', policy: new Policy(Access::Cap, self::ADMIN))]
    public function rookEnd(Request $request): Response
    {
        $admin = $this->minn->user();
        if ($admin === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        $tsid = $request->json()['street'] ?? null;
        if (!is_string($tsid) || preg_match(self::STREET, $tsid) !== 1) {
            throw new RestError('groddle_admin_no_street', 'Choose a street first.', 400);
        }
        $label = (string) ($this->streetIndex()[$tsid]['label'] ?? $tsid);
        $answer = (new RealtimeCall($this->minn))->post('rook_end', ['street' => $tsid]);
        $delivery = match ($answer['status'] ?? null) {
            'ended' => 'live',
            'ending' => 'ending',
            'none' => 'none',
            default => 'down',
        };
        $detail = ['street' => ['tsid' => $tsid, 'label' => $label]];
        if ($delivery === 'live') {
            $detail['phase'] = is_string($answer['phase'] ?? null) ? $answer['phase'] : '';
            $detail['players'] = max(0, (int) ($answer['players'] ?? 0));
        }
        $this->record($admin, 0, 'rook_end', $detail, $delivery);
        return Response::json(['street' => $detail['street'], 'delivery' => $delivery, 'text' => self::rookEndText($label, $delivery, (int) ($detail['players'] ?? 0))]);
    }

    /** What the console says after an end. */
    public static function rookEndText(string $label, string $delivery, int $players): string
    {
        $who = match ($players) {
            0 => 'nobody was there to see it',
            1 => '1 player there saw it go',
            default => "{$players} players there saw it go",
        };
        return match ($delivery) {
            'live' => "The Rook is flying off {$label}; {$who}. Nobody gets iMG for it, and what it rooked stays rooked.",
            'ending' => "The Rook over {$label} is already leaving.",
            'none' => "There is no Rook over {$label} now.",
            default => 'The realtime server is not answering, so the Rook is still there.',
        };
    }

    // —— Shared ——

    /**
     * Whether an account is suspended, and why: null when it is not. A ban covers the whole login
     * (Logins): any character of it banned (a row on the login, or one on a character from before)
     * suspends them all.
     *
     * @return array{reason: string}|null
     */
    public static function banned(Db $db, int $user): ?array
    {
        if ($user <= 0) {
            return null;
        }
        $reason = $db->value('SELECT reason FROM ' . $db->table(self::BANS) . ' WHERE user_id IN (?) ORDER BY created_at DESC LIMIT 1', [(new Logins($db))->siblings($user)]);
        return $reason === null ? null : ['reason' => (string) $reason];
    }

    /** Bans the login a character belongs to (one row, on the login): the login's id. */
    public static function banLogin(Db $db, int $player, string $reason, int $by): int
    {
        $login = (new Logins($db))->loginOf($player);
        $db->execute('INSERT INTO ' . $db->table(self::BANS) . ' (user_id, reason, admin_user_id, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE reason = VALUES(reason), admin_user_id = VALUES(admin_user_id)', [$login, $reason, $by, gmdate('Y-m-d H:i:s')]);
        return $login;
    }

    /** Lifts every ban on a character's login (its own row and any on its characters): the login's id. */
    public static function unbanLogin(Db $db, int $player): int
    {
        $logins = new Logins($db);
        $db->execute('DELETE FROM ' . $db->table(self::BANS) . ' WHERE user_id IN (?)', [$logins->siblings($player)]);
        return $logins->loginOf($player);
    }

    /**
     * The banned logins, as the realtime server's bans list takes them (a row on a character counts as its login).
     *
     * @return list<int>
     */
    public static function bannedLogins(Db $db): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['user_id'], $db->rows('SELECT user_id FROM ' . $db->table(self::BANS)));
        return array_values(array_unique(array_values((new Logins($db))->loginsOf($ids))));
    }

    /** One audit line (every console action and the teleport). */
    public static function recordAction(Db $db, int $admin, int $player, string $kind, array $detail, string $delivery, ?int $now = null): void
    {
        $db->execute('INSERT INTO ' . $db->table(self::ACTIONS) . ' (at, admin_user_id, player_user_id, kind, detail, delivery) VALUES (?, ?, ?, ?, ?, ?)', [
            gmdate('Y-m-d H:i:s', $now ?? time()), $admin, $player, substr($kind, 0, 12), self::detailJson($detail), substr($delivery, 0, 8),
        ]);
    }

    /** Whether a trade id is an administrator's give (Ledger::pending never stamps these by age). */
    public static function isGive(string $trade): bool
    {
        return preg_match('/^admin-give-[0-9a-f]{16}$/', $trade) === 1;
    }

    /** A message as players get it: plain text, line breaks kept (at most one blank line), at most TEXT_MAX characters. */
    public static function messageText(mixed $text): string
    {
        $text = is_string($text) ? $text : '';
        $text = (string) preg_replace('/[^\P{C}\n]+/u', '', str_replace(["\r\n", "\r"], "\n", mb_scrub($text, 'UTF-8')));
        $lines = array_map(static fn (string $l): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", $text));
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
        return trim(mb_substr($text, 0, self::TEXT_MAX));
    }

    /** A reason: one plain line of at most REASON_MAX characters. */
    public static function reasonText(mixed $text): string
    {
        $text = is_string($text) ? mb_scrub($text, 'UTF-8') : '';
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', ' ', $text)));
        return trim(mb_substr($text, 0, self::REASON_MAX));
    }

    /**
     * What a give asks for, checked: the goods (class => n, ksorted, and
     * currants), or the words for what is wrong.
     *
     * @param callable(string): bool $tradeable
     * @return array{items: array<string, int>, currants: int}|string
     */
    public static function giveGoods(array $body, callable $tradeable): array|string
    {
        $list = $body['items'] ?? [];
        $currants = $body['currants'] ?? 0;
        if (!is_array($list) || !array_is_list($list) || !is_int($currants)) {
            return 'That is not a give.';
        }
        if ($currants < 0 || $currants > self::GIVE_CURRANTS) {
            return 'Currants go from 0 to ' . number_format(self::GIVE_CURRANTS) . '.';
        }
        if (count($list) > self::GIVE_STACKS) {
            return 'At most ' . self::GIVE_STACKS . ' kinds of thing in one give.';
        }
        $items = [];
        foreach ($list as $it) {
            $c = is_array($it) ? ($it['c'] ?? null) : null;
            $n = is_array($it) ? ($it['n'] ?? null) : null;
            if (!is_string($c) || preg_match('/^[a-z0-9_]{1,64}$/', $c) !== 1 || !is_int($n)) {
                return 'That is not a give.';
            }
            if (!$tradeable($c)) {
                return "{$c} is not something the ledger carries, so it cannot be given.";
            }
            if ($n < 1 || $n > self::GIVE_EACH) {
                return 'Give 1 to ' . self::GIVE_EACH . ' of each thing.';
            }
            if (isset($items[$c])) {
                return 'Name each thing once.';
            }
            $items[$c] = $n;
        }
        if ($items === [] && $currants === 0) {
            return 'Choose something to give.';
        }
        ksort($items);
        return ['items' => $items, 'currants' => $currants];
    }

    /** "a", "a and b", "a, b and c". @param list<string> $parts */
    public static function listWords(array $parts): string
    {
        if (count($parts) < 2) {
            return $parts[0] ?? '';
        }
        $last = array_pop($parts);
        return implode(', ', $parts) . ' and ' . $last;
    }

    /** The goods into the ledger and the settled trade from nobody (see the class comment). */
    private function grant(int $user, string $op, array $goods, int $now): void
    {
        $db = $this->minn->db;
        $ledger = Ledger::fromSeams($this->minn);
        $state = $ledger->lock($user, $now);
        $after = LedgerMath::add(['items' => $state['items'], 'currants' => $state['currants']], ['items' => $goods['items'], 'currants' => $goods['currants']]);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $t = $db->table(Ledger::ITEMS);
        foreach ($goods['items'] as $c => $_) {
            $db->execute("INSERT INTO {$t} (user_id, class_tsid, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)", [$user, (string) $c, (int) $after['items'][$c]]);
        }
        $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET currants = ?, updated_at = ? WHERE user_id = ?', [$after['currants'], $stamp, $user]);
        // Stored as every trade's goods are, a list of {c, n} (LedgerMath::goods).
        $list = [];
        foreach ($goods['items'] as $c => $n) {
            $list[] = ['c' => (string) $c, 'n' => $n];
        }
        $gave = json_encode(['items' => $list, 'currants' => $goods['currants']], JSON_UNESCAPED_SLASHES);
        $db->execute('INSERT INTO ' . $db->table(Ledger::TRADES) . " (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$op, $user, '{"items":[],"currants":0}', $gave, $stamp, $stamp]);
        $ledger->log($user, 'admin', ['trade' => $op, 'by' => $this->minn->user()?->id, 'got' => ['items' => (object) $goods['items'], 'currants' => $goods['currants']]], $now);
    }

    /**
     * The caller and the character a POST names ({"player": id}).
     *
     * @return array{0: \Minn\Content\UserRecord, 1: array{id: int, name: string}, 2: array<string, mixed>}
     */
    private function target(Request $request): array
    {
        $admin = $this->minn->user();
        if ($admin === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        $body = $request->json();
        $id = $body['player'] ?? null;
        $name = is_int($id) && $id > 0 ? ((new Characters($this->minn->db))->namesOf([$id])[$id] ?? null) : null;
        if ($name === null) {
            throw new RestError('groddle_admin_no_player', 'No player has that id.', 404);
        }
        return [$admin, ['id' => (int) $id, 'name' => (string) $name], $body];
    }

    private function record(\Minn\Content\UserRecord $admin, int $player, string $kind, array $detail, string $delivery): void
    {
        self::recordAction($this->minn->db, $admin->id, $player, $kind, $detail, $delivery);
    }

    private function done(array $player, string $delivery, string $text): Response
    {
        return Response::json(['player' => $player, 'delivery' => $delivery, 'text' => $text]);
    }

    /** A detail as JSON that fits the column (a long message is cut, never the JSON). */
    private static function detailJson(array $detail): string
    {
        $json = (string) json_encode($detail === [] ? new \stdClass() : $detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 2000 && is_string($detail['text'] ?? null)) {
            $detail['text'] = mb_substr($detail['text'], 0, 300) . '…';
            $json = (string) json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $json;
    }

    /**
     * Characters as the console lists them, in the order of $ids.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function rows(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $db = $this->minn->db;
        $loc = "JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(JSON_EXTRACT(s.data, '$.entries.location')), '$.tsid'))";
        // From the accounts, not the characters: an account that never claimed a character name
        // (an administrator playing under their display name) still plays, named as the game names it.
        $names = (new Characters($db))->namesOf($ids);
        $found = $db->rows('SELECT u.ID AS user_id, s.updated_at, ' . Profiles::levelSql('s') . " AS level, {$loc} AS tsid, b.user_id AS banned FROM " . $db->table('users') . ' u LEFT JOIN ' . $db->table(MultiplayerSave::TABLE) . ' s ON s.user_id = u.ID LEFT JOIN ' . $db->table(self::BANS) . " b ON b.user_id = u.ID WHERE u.ID IN (?)", [$ids]);
        $by = [];
        foreach ($found as $r) {
            $r['name'] = $names[(int) $r['user_id']] ?? '';
            $by[(int) $r['user_id']] = $r;
        }
        $caps = Capabilities::fromDb($db);
        $index = $this->streetIndex();
        $out = [];
        foreach ($ids as $id) {
            $r = $by[$id] ?? null;
            if ($r === null) {
                continue;
            }
            $tsid = is_string($r['tsid'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $r['tsid']) === 1 ? $r['tsid'] : null;
            $out[] = [
                'id' => $id,
                'name' => (string) $r['name'],
                'level' => max(1, (int) ($r['level'] ?? 1)),
                'street' => $tsid === null ? null : ['tsid' => $tsid, 'label' => (string) ($index[$tsid]['label'] ?? $tsid)],
                'savedAt' => $r['updated_at'] === null ? null : SaveShape::time((string) $r['updated_at']),
                'banned' => $r['banned'] !== null || self::banned($db, $id) !== null,
                'admin' => $caps->can($id, self::ADMIN),
            ];
        }
        return $out;
    }

    private function nameOr(array $names, int $id): string
    {
        return (string) ($names[$id] ?? "#{$id}");
    }

    /** The game's street index (tsid => row), or empty. @return array<string, array<string, mixed>> */
    private function streetIndex(): array
    {
        $file = StaticRoot::fromSite($this->minn)->dir . '/assets/streets/index.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data['streets'] ?? null) ? $data['streets'] : [];
    }
}
