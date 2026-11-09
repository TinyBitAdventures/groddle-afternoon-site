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
 * Moderation (Austin 2026-11-09: "Put Grok Bot in charge of keeping the game safe": reviewing public
 * and private chat for abuse; "If someone spams folks over IM we need to be able to both block them
 * and remove the spam from people's chat windows"). groddle/v1/mod/*, curl-friendly JSON, for two
 * kinds of moderator:
 *
 *   - Grok Bot, with a moderator token (ModTokens: Authorization: Bearer gmod_…, made and revoked by
 *     an administrator in the console, shown once, stored hashed, capabilities "read" and
 *     "moderate"). It reaches these routes and nothing else (it signs nobody in).
 *   - an administrator (manage_options) with the session and its REST nonce: the console's
 *     Moderation tab uses the same routes. The token routes take the session only.
 *
 * Read (capability "read"):
 *   GET  mod/feed?status=open|all&since=<ISO time>    {"flags":[Report],"community":[Post],"notes":[Note],
 *                                                       "actions":[Action],"restrictions":[Restriction]}
 *   GET  mod/chatlog?player=&q=&kind=&since=&until=&refused=1&before=   the chat log (the console's admin/chatlog: Local,
 *                                                       channel, group and party lines for 30 days; never an IM)
 *   GET  mod/reports/{id}                              {"report":Report}  one row of the queue (a flag)
 *   GET  mod/players?id=7 | ?q=name                    {"players":[Player]}  name, level, account age, administrator,
 *                                                       banned, restrictions, open flags
 *   GET  mod/actions?before=<id>&actor=token|admin     {"actions":[Action],"more":bool}  the moderators' log
 * Act (capability "moderate"; every one names a reason, refuses administrators and answers what it did):
 *   POST mod/reports/{id}/resolve {"outcome","note"}   outcome: OUTCOMES
 *   POST mod/silence      {"player","scope":"chat"|"help"|"all","hours","reason"}  Global, Trade and Local chat /
 *                                                       Live Help / both; replaces a silence that stands
 *   POST mod/im_restrict  {"player","scope":"all"|"buddies","hours","reason"}      no IMs, or IMs to buddies only
 *   POST mod/lift         {"player","kind":"silence"|"im","reason"}                ends one now
 *   POST mod/ban          {"player","reason"}           suspended: signed out now, no ticket, no save (AdminConsole's ban)
 *   POST mod/unban        {"player","reason"}
 *   POST mod/delete_line  {"channel":"global"|"help"|"trade"|"group-<id>","lid","reason"}   one channel or group line for everyone
 *   POST mod/purge        {"player","hours":24|0,"reason"}   everything they said in the last hours (0: all): channel,
 *                                                       group, thread, Local and party lines and their side of every IM,
 *                                                       from the site, the server and every open window. FINAL: there is
 *                                                       no undo. Nobody reads a purged IM, the moderator included: the
 *                                                       answer is counts.
 *   POST mod/notice       {"player","text"}            an in-game message (the console's message: "Message from an
 *                                                       administrator", or kept for their next connect)
 *   POST mod/actions/{id}/undo {"reason"}              undoes a silence, an IM restriction or a ban; 409 for the rest
 *                                                       (a purge or a deleted line is final)
 * Tokens (the session only):
 *   GET mod/tokens · POST mod/tokens {"label","caps":["read","moderate"]} (201, the token once) · DELETE mod/tokens/{id}
 *
 * hours: 1 to TOKEN_HOURS_MAX for a token (Grok Bot's actions always end), 0 (never) to ADMIN_HOURS_MAX for an
 * administrator. Every call, reads too, is one line of groddle_mod_actions (who: the token's label or the
 * administrator; what; the player; the reason; what came of it), shown in the console's Moderation tab with
 * Grok Bot's lines marked and an Undo where one exists. Refusals of a token's calls are logged too.
 *
 * IMs: nothing here reads an IM, reported or not (Austin 2026-11-09: "maybe scrap looking at IMs. I
 * don't want to deal with privacy changes"; the privacy policy promises only an IM's two players read
 * it, administrators included). IM spam is handled from the content-free flags (ModReports), the
 * players' blocks and the IM pause, and a purge: it empties the player's own IM rows' words by player
 * id, and the realtime server takes them out of its own copy and every window (moderation.go), with
 * counts in the answer and nobody seeing a word. Restrictions reach the realtime server
 * (mod_restrict, the whole set each time) and every new ticket (claim()); they end by themselves.
 */
final readonly class Moderation
{
    public const RESTRICTIONS = 'groddle_mod_restrictions';
    public const ACTIONS = 'groddle_mod_actions';

    public const IM_PAUSED = 'A moderator has paused your IMs for now.';

    public const OUTCOMES = ['no_action', 'warned', 'silenced', 'im_restricted', 'purged', 'line_deleted', 'banned', 'escalated', 'duplicate'];
    public const SILENCE_SCOPES = ['chat' => 'chat', 'help' => 'help', 'all' => 'chat,help'];
    public const IM_SCOPES = ['all', 'buddies'];
    public const TOKEN_HOURS_MAX = 720;
    public const ADMIN_HOURS_MAX = 8760;
    public const PURGE_HOURS_MAX = 720;
    public const FEED_MAX = 50;
    public const LOG_PAGE = 50;
    /** Resolved reports, the moderators' log and ended restrictions are kept this long (days). */
    public const KEEP_DAYS = 365;
    public const PRUNE_OPTION = 'groddle_mod_prune_next';
    /** Who a token's actions are by in the realtime server's records when it needs a user id: the administrator who made the token. */
    private const ADMIN = 'manage_options';

    public function __construct(private Seams $minn)
    {
    }

    // —— Who is asking ——

    /**
     * The moderator behind a request: a token with $cap, or an administrator's session.
     *
     * @return array{kind: string, id: string, name: string, by: int}
     */
    private function actor(Request $request, ?string $cap): array
    {
        $token = ModTokens::fromHeaders($request->header('authorization'), $request->header(ModTokens::HEADER));
        if ($token !== null) {
            if ($cap === null) {
                throw new RestError('groddle_mod_session_only', 'This takes an administrator\'s session, never a moderator token.', 403);
            }
            $t = (new ModTokens($this->minn->db))->verify($token);
            if ($t === null) {
                throw new RestError('groddle_mod_token', 'That moderator token is not valid (revoked, or never made).', 401);
            }
            if (!in_array($cap, $t['caps'], true)) {
                throw new RestError('groddle_mod_cap', "This token cannot {$cap}.", 403);
            }
            return ['kind' => 'token', 'id' => $t['id'], 'name' => $t['label'], 'by' => $t['created_by']];
        }
        $user = $this->minn->user();
        if ($user === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        if (!Capabilities::fromDb($this->minn->db)->can($user->id, self::ADMIN)) {
            throw new RestError('rest_forbidden', 'Sorry, you are not allowed to do that.', 403);
        }
        $name = (new Characters($this->minn->db))->namesOf([$user->id])[$user->id] ?? '';
        return ['kind' => 'admin', 'id' => (string) $user->id, 'name' => $name !== '' ? $name : ($user->displayName ?? 'Administrator'), 'by' => $user->id];
    }

    /** The character an action names, refused for administrators (and the caller). @return array{id: int, name: string} */
    private function target(array $actor, mixed $id, string $action): array
    {
        $db = $this->minn->db;
        $name = is_int($id) && $id > 0 ? ((new Characters($db))->namesOf([$id])[$id] ?? null) : null;
        if ($name === null) {
            $this->refused($actor, $action, is_int($id) ? $id : 0, 'no player');
            throw new RestError('groddle_mod_no_player', 'No player has that id.', 404);
        }
        if (Capabilities::fromDb($db)->can($id, self::ADMIN) || ($actor['kind'] === 'admin' && (int) $actor['id'] === $id)) {
            $this->refused($actor, $action, $id, 'administrator');
            throw new RestError('groddle_mod_admin', "{$name} is an administrator. Moderation never acts on administrators.", 409);
        }
        return ['id' => $id, 'name' => (string) $name];
    }

    private function reason(array $actor, array $body, string $action, int $player): string
    {
        $reason = AdminConsole::reasonText($body['reason'] ?? '');
        if ($reason === '') {
            $this->refused($actor, $action, $player, 'no reason');
            throw new RestError('groddle_mod_reason', 'Say why (reason).', 400);
        }
        return $reason;
    }

    private function hours(array $actor, mixed $hours, string $action, int $player): int
    {
        $max = $actor['kind'] === 'token' ? self::TOKEN_HOURS_MAX : self::ADMIN_HOURS_MAX;
        $min = $actor['kind'] === 'token' ? 1 : 0;
        if (!is_int($hours) || $hours < $min || $hours > $max) {
            $this->refused($actor, $action, $player, 'hours');
            throw new RestError('groddle_mod_hours', "hours goes from {$min} to {$max}" . ($min === 0 ? ' (0: no end)' : '') . '.', 400);
        }
        return $hours;
    }

    // —— The log ——

    /** One line of the moderators' log; its id. */
    public static function record(Db $db, array $actor, string $action, int $target, string $reason, array $detail, string $result, int $undoOf = 0): int
    {
        $json = (string) json_encode($detail === [] ? new \stdClass() : $detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 4000) {
            $json = (string) json_encode(['cut' => true], JSON_UNESCAPED_SLASHES);
        }
        $db->execute('INSERT INTO ' . $db->table(self::ACTIONS) . ' (at, actor_kind, actor_ref, actor_name, action, target_id, reason, detail, result, undo_of) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            gmdate('Y-m-d H:i:s'), substr($actor['kind'], 0, 8), substr($actor['id'], 0, 24), mb_substr($actor['name'], 0, 80), substr($action, 0, 24), max(0, $target), mb_substr($reason, 0, 200), $json, substr($result, 0, 16), $undoOf,
        ]);
        return $db->insertId();
    }

    private function log(array $actor, string $action, int $target, string $reason, array $detail, string $result, int $undoOf = 0): int
    {
        return self::record($this->minn->db, $actor, $action, $target, $reason, $detail, $result, $undoOf);
    }

    private function refused(array $actor, string $action, int $target, string $why): void
    {
        $this->log($actor, $action, $target, '', ['refused' => $why], 'refused');
    }

    // —— Restrictions ——

    /**
     * The restrictions that stand for a player now: [silence words, silence ends (ms, 0 never),
     * IM scope ("" none), IM ends].
     *
     * @return array{silence: list<string>, silenceUntil: int, im: string, imUntil: int}
     */
    public static function standing(Db $db, int $user): array
    {
        $out = ['silence' => [], 'silenceUntil' => 0, 'im' => '', 'imUntil' => 0];
        if ($user <= 0) {
            return $out;
        }
        $rows = $db->rows('SELECT kind, scope, expires_at FROM ' . $db->table(self::RESTRICTIONS) . ' WHERE user_id = ? AND lifted_at IS NULL AND (expires_at IS NULL OR expires_at > ?) ORDER BY id DESC', [$user, gmdate('Y-m-d H:i:s')]);
        foreach ($rows as $r) {
            $until = $r['expires_at'] === null ? 0 : (int) strtotime((string) $r['expires_at'] . ' UTC') * 1000;
            if ($r['kind'] === 'silence' && $out['silence'] === []) {
                $out['silence'] = array_values(array_filter(explode(',', (string) $r['scope'])));
                $out['silenceUntil'] = $until;
            } elseif ($r['kind'] === 'im' && $out['im'] === '') {
                $out['im'] = (string) $r['scope'];
                $out['imUntil'] = $until;
            }
        }
        return $out;
    }

    /** A ticket's "mod" claim (RealtimeTicket), or null when nothing stands. @return array<string, mixed>|null */
    public static function claim(Db $db, int $user): ?array
    {
        $s = self::standing($db, $user);
        if ($s['silence'] === [] && $s['im'] === '') {
            return null;
        }
        return array_filter(['s' => implode(',', $s['silence']), 'su' => $s['silenceUntil'], 'im' => $s['im'], 'iu' => $s['imUntil']], static fn (mixed $v): bool => $v !== '' && $v !== 0);
    }

    /** A moderator's silence words for a player now ("chat", "help"). @return list<string> */
    public static function silenced(Db $db, int $user): array
    {
        return self::standing($db, $user)['silence'];
    }

    /** Whether a moderator's IM restriction stops $from writing to $to. */
    public static function imRestricted(Db $db, int $from, int $to): bool
    {
        $im = self::standing($db, $from)['im'];
        if ($im === '') {
            return false;
        }
        if ($im === 'buddies') {
            $t = $db->table(Buddies::TABLE);
            return $db->value("SELECT 1 FROM {$t} WHERE (user_id = ? AND buddy_id = ?) OR (user_id = ? AND buddy_id = ?) LIMIT 1", [$from, $to, $to, $from]) === null;
        }
        return true;
    }

    /** The realtime server told the whole set (moderation.go mod_restrict): live, stored (offline) or down. */
    private function push(int $user): string
    {
        $s = self::standing($this->minn->db, $user);
        $answer = (new RealtimeCall($this->minn))->post('mod_restrict', ['player' => $user, 'silence' => implode(',', $s['silence']), 'silence_until' => $s['silenceUntil'], 'im' => $s['im'], 'im_until' => $s['imUntil']]);
        return ($answer['status'] ?? null) === 'ok' ? (($answer['online'] ?? false) === true ? 'live' : 'stored') : 'down';
    }

    /** Ends the restrictions of a kind that stand (lifted_by: who); how many. */
    private function liftKind(int $user, string $kind, string $by): int
    {
        $db = $this->minn->db;
        return $db->execute('UPDATE ' . $db->table(self::RESTRICTIONS) . ' SET lifted_at = ?, lifted_by = ? WHERE user_id = ? AND kind = ? AND lifted_at IS NULL AND (expires_at IS NULL OR expires_at > ?)', [gmdate('Y-m-d H:i:s'), mb_substr($by, 0, 80), $user, $kind, gmdate('Y-m-d H:i:s')]);
    }

    private function restrict(array $actor, array $player, string $kind, string $scope, int $hours, string $reason, string $action): Response
    {
        $db = $this->minn->db;
        $this->liftKind($player['id'], $kind, $actor['name']);
        $now = time();
        $expires = $hours === 0 ? null : gmdate('Y-m-d H:i:s', $now + $hours * 3600);
        $db->execute('INSERT INTO ' . $db->table(self::RESTRICTIONS) . ' (user_id, kind, scope, reason, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)', [$player['id'], $kind, $scope, $reason, gmdate('Y-m-d H:i:s', $now), $expires]);
        $rid = $db->insertId();
        $delivery = $this->push($player['id']);
        $id = $this->log($actor, $action, $player['id'], $reason, ['scope' => $scope, 'hours' => $hours, 'until' => $expires === null ? null : SaveShape::time($expires), 'restriction' => $rid], $delivery);
        $db->execute('UPDATE ' . $db->table(self::RESTRICTIONS) . ' SET action_id = ? WHERE id = ?', [$id, $rid]);
        $what = $kind === 'silence' ? "Silenced {$player['name']} (" . str_replace(',', ' and ', $scope) . ')' : ($scope === 'all' ? "Paused {$player['name']}'s IMs" : "{$player['name']} can IM buddies only");
        return $this->answer($id, $player, $delivery, $what . ($hours === 0 ? ' with no end.' : " for {$hours} hours."), ['until' => $expires === null ? null : SaveShape::time($expires)]);
    }

    // —— Keeping ——

    /**
     * What moderation keeps, for KEEP_DAYS (the privacy policy says so): resolved reports and flags,
     * the moderators' log and ended restrictions older than that are deleted. Open reports stay until a
     * moderator resolves them. Runs from the moderators' feed at most once a day (option PRUNE_OPTION),
     * as the site has no scheduled job of its own.
     *
     * @return array{reports: int, actions: int, restrictions: int}|null null when not due
     */
    public static function pruneWhenDue(\Minn\Content\Site $site, Db $db, int $now): ?array
    {
        if ((int) ($site->option(self::PRUNE_OPTION) ?? 0) > $now) {
            return null;
        }
        $site->setOption(self::PRUNE_OPTION, (string) ($now + 86400));
        $cut = gmdate('Y-m-d H:i:s', $now - self::KEEP_DAYS * 86400);
        return [
            'reports' => $db->execute('DELETE FROM ' . $db->table(ModReports::TABLE) . " WHERE status = 'resolved' AND updated_at < ?", [$cut]),
            'actions' => $db->execute('DELETE FROM ' . $db->table(self::ACTIONS) . ' WHERE at < ?', [$cut]),
            'restrictions' => $db->execute('DELETE FROM ' . $db->table(self::RESTRICTIONS) . ' WHERE created_at < ? AND (lifted_at IS NOT NULL OR (expires_at IS NOT NULL AND expires_at < ?))', [$cut, $cut]),
        ];
    }

    // —— Read ——

    #[Route(Method::Get, '/groddle/v1/mod/feed', policy: new Policy(Access::Public))]
    public function feed(Request $request): Response
    {
        $actor = $this->actor($request, 'read');
        $db = $this->minn->db;
        self::pruneWhenDue($this->minn->site, $db, time());
        $all = $request->query('status') === 'all';
        $r = $db->table(ModReports::TABLE);
        $where = $all ? '' : "AND status = 'open'";
        $flags = ModReports::out($db, $db->rows("SELECT * FROM {$r} WHERE kind = 'flag' {$where} ORDER BY updated_at DESC, id DESC LIMIT " . self::FEED_MAX));
        $since = $request->query('since');
        $sinceAt = is_string($since) && strtotime($since) !== false ? gmdate('Y-m-d H:i:s', (int) strtotime($since)) : gmdate('Y-m-d H:i:s', time() - 86400);
        $out = [
            'flags' => $flags,
            'community' => $this->communityReported(),
            'notes' => $this->boardNotes($sinceAt),
            'actions' => $this->actions(0, null, 30)['actions'],
            'restrictions' => $this->restrictionsStanding(),
        ];
        $this->log($actor, 'read_feed', 0, '', ['flags' => count($flags)], 'ok');
        return self::private(Response::json($out));
    }

    #[Route(Method::Get, '/groddle/v1/mod/chatlog', policy: new Policy(Access::Public))]
    public function chatlog(Request $request): Response
    {
        $actor = $this->actor($request, 'read');
        $query = [];
        foreach (['player', 'q', 'kind', 'since', 'until', 'refused', 'before'] as $k) {
            $query[$k] = $request->query($k);
        }
        $answer = (new AdminConsole($this->minn))->chatlogAnswer($query);
        $this->log($actor, 'read_chatlog', ctype_digit(ltrim((string) ($query['player'] ?? ''), '#')) ? (int) ltrim((string) $query['player'], '#') : 0, '', array_filter($query, static fn (mixed $v): bool => $v !== null && $v !== ''), (string) ($answer['server'] ?? ''));
        return self::private(Response::json($answer));
    }

    #[Route(Method::Get, '/groddle/v1/mod/reports/{id:[1-9][0-9]{0,18}}', policy: new Policy(Access::Public))]
    public function report(Request $request, string $id): Response
    {
        $actor = $this->actor($request, 'read');
        $db = $this->minn->db;
        $row = $db->row('SELECT * FROM ' . $db->table(ModReports::TABLE) . ' WHERE id = ?', [(int) $id]);
        if ($row === null) {
            throw new RestError('groddle_mod_no_report', 'No report has that id.', 404);
        }
        $this->log($actor, 'read_report', (int) $row['reported_id'], '', ['report' => (int) $id], 'ok');
        return self::private(Response::json(['report' => ModReports::out($db, [$row])[0]]));
    }

    #[Route(Method::Get, '/groddle/v1/mod/players', policy: new Policy(Access::Public))]
    public function players(Request $request): Response
    {
        $actor = $this->actor($request, 'read');
        $db = $this->minn->db;
        $ids = [];
        $id = $request->query('id');
        if (is_string($id) && ctype_digit($id) && (int) $id > 0) {
            $ids = [(int) $id];
        } else {
            $q = strtolower(trim((string) ($request->query('q') ?? '')));
            if ($q !== '') {
                $like = addcslashes(substr($q, 0, 32), '%_\\') . '%';
                $ids = array_map(static fn (array $r): int => (int) $r['user_id'], $db->rows('SELECT user_id FROM ' . $db->table(Characters::TABLE) . ' WHERE name_key LIKE ? ORDER BY name_key = ? DESC, name_key LIMIT 10', [$like, $q]));
            }
        }
        $names = $ids === [] ? [] : (new Characters($db))->namesOf($ids);
        $caps = Capabilities::fromDb($db);
        $r = $db->table(ModReports::TABLE);
        $out = [];
        foreach ($ids as $pid) {
            if (!isset($names[$pid])) {
                continue;
            }
            $reg = $db->value('SELECT user_registered FROM ' . $db->table('users') . ' WHERE ID = ?', [$pid]);
            $level = $db->value('SELECT ' . Profiles::levelSql('s') . ' FROM ' . $db->table(MultiplayerSave::TABLE) . ' s WHERE s.user_id = ?', [$pid]);
            $s = self::standing($db, $pid);
            $out[] = [
                'id' => $pid,
                'name' => $names[$pid],
                'level' => max(1, (int) ($level ?? 1)),
                'accountDays' => $reg === null ? null : (int) floor((time() - (int) strtotime((string) $reg . ' UTC')) / 86400),
                'admin' => $caps->can($pid, self::ADMIN),
                'banned' => AdminConsole::banned($db, $pid) !== null,
                'silence' => $s['silence'] === [] ? null : ['scope' => implode(',', $s['silence']), 'until' => $s['silenceUntil'] ?: null],
                'im' => $s['im'] === '' ? null : ['scope' => $s['im'], 'until' => $s['imUntil'] ?: null],
                'openFlags' => (int) $db->value("SELECT COUNT(*) FROM {$r} WHERE reported_id = ? AND status = 'open' AND kind = 'flag'", [$pid]),
            ];
        }
        $this->log($actor, 'read_players', count($ids) === 1 ? $ids[0] : 0, '', ['found' => count($out)], 'ok');
        return self::private(Response::json(['players' => $out]));
    }

    #[Route(Method::Get, '/groddle/v1/mod/actions', policy: new Policy(Access::Public))]
    public function actionsRoute(Request $request): Response
    {
        $actor = $this->actor($request, 'read');
        $kind = $request->query('actor');
        $page = $this->actions(max(0, (int) ($request->query('before') ?? '0')), $kind === 'token' || $kind === 'admin' ? $kind : null, self::LOG_PAGE, $request->query('reads') === '1');
        if ($actor['kind'] === 'token') {
            $this->log($actor, 'read_actions', 0, '', [], 'ok');
        }
        return self::private(Response::json($page));
    }

    /** The moderators' log, newest first (reads left out unless asked). @return array{actions: list<array<string, mixed>>, more: bool} */
    private function actions(int $before, ?string $kind, int $limit, bool $reads = false): array
    {
        $db = $this->minn->db;
        $where = ['1=1'];
        $args = [];
        if ($before > 0) {
            $where[] = 'id < ?';
            $args[] = $before;
        }
        if ($kind !== null) {
            $where[] = 'actor_kind = ?';
            $args[] = $kind;
        }
        if (!$reads) {
            $where[] = "action NOT LIKE 'read\\_%'";
        }
        $rows = $db->rows('SELECT * FROM ' . $db->table(self::ACTIONS) . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . ($limit + 1), $args);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $names = (new Characters($db))->namesOf(array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) $r['target_id'], $rows)))));
        $out = [];
        foreach ($rows as $r) {
            $t = (int) $r['target_id'];
            $out[] = [
                'id' => (int) $r['id'],
                'at' => SaveShape::time((string) $r['at']),
                'by' => ['kind' => (string) $r['actor_kind'], 'name' => (string) $r['actor_name'], 'grok' => $r['actor_kind'] === 'token'],
                'action' => (string) $r['action'],
                'player' => $t > 0 ? ['id' => $t, 'name' => (string) ($names[$t] ?? "#{$t}")] : null,
                'reason' => (string) $r['reason'],
                'detail' => json_decode((string) $r['detail'], true) ?: new \stdClass(),
                'result' => (string) $r['result'],
                'undoable' => in_array($r['action'], ['silence', 'im_restrict', 'ban'], true) && $r['undone_at'] === null && $r['result'] !== 'refused',
                'undone' => $r['undone_at'] === null ? null : ['at' => SaveShape::time((string) $r['undone_at']), 'by' => (string) $r['undone_by']],
                'undoOf' => (int) $r['undo_of'] ?: null,
            ];
        }
        return ['actions' => $out, 'more' => $more];
    }

    /** @return list<array<string, mixed>> */
    private function restrictionsStanding(): array
    {
        $db = $this->minn->db;
        $rows = $db->rows('SELECT * FROM ' . $db->table(self::RESTRICTIONS) . ' WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > ?) ORDER BY id DESC LIMIT 200', [gmdate('Y-m-d H:i:s')]);
        $names = (new Characters($db))->namesOf(array_values(array_unique(array_map(static fn (array $r): int => (int) $r['user_id'], $rows))));
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'player' => ['id' => (int) $r['user_id'], 'name' => (string) ($names[(int) $r['user_id']] ?? "#{$r['user_id']}")],
            'kind' => (string) $r['kind'],
            'scope' => (string) $r['scope'],
            'reason' => (string) $r['reason'],
            'since' => SaveShape::time((string) $r['created_at']),
            'until' => $r['expires_at'] === null ? null : SaveShape::time((string) $r['expires_at']),
            'action' => (int) $r['action_id'],
        ], $rows);
    }

    /** Community posts players reported, still shown (public posts). @return list<array<string, mixed>> */
    private function communityReported(): array
    {
        $db = $this->minn->db;
        $p = $db->table(Community::POSTS);
        $t = $db->table(Community::THREADS);
        $rows = $db->rows("SELECT p.id, p.thread_id, p.author_id, p.author_name, p.body, p.reports, p.created_at, t.board, t.title FROM {$p} p JOIN {$t} t ON t.id = p.thread_id WHERE p.reports > 0 AND p.status = 'shown' ORDER BY p.reports DESC, p.id DESC LIMIT " . self::FEED_MAX);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'thread' => ['id' => (int) $r['thread_id'], 'board' => (string) $r['board'], 'title' => (string) $r['title']],
            'author' => ['id' => (int) $r['author_id'], 'name' => (string) $r['author_name']],
            'body' => mb_substr((string) $r['body'], 0, 1000),
            'reports' => (int) $r['reports'],
            'at' => SaveShape::time((string) $r['created_at']),
        ], $rows);
    }

    /** Notice board notes pinned since $since, reported first (the street's public boards). @return list<array<string, mixed>> */
    private function boardNotes(string $since): array
    {
        $db = $this->minn->db;
        $rows = $db->rows('SELECT id, street, author_id, title, body, status, reports, created_at FROM ' . $db->table(LedgerBoard::TABLE) . " WHERE status <> 'removed' AND (created_at >= ? OR reports > 0) ORDER BY reports DESC, created_at DESC LIMIT " . self::FEED_MAX, [$since]);
        $names = (new Characters($db))->namesOf(array_values(array_unique(array_map(static fn (array $r): int => (int) $r['author_id'], $rows))));
        return array_map(static fn (array $r): array => [
            'id' => (string) $r['id'],
            'street' => (string) $r['street'],
            'author' => ['id' => (int) $r['author_id'], 'name' => (string) ($names[(int) $r['author_id']] ?? '')],
            'title' => (string) $r['title'],
            'body' => mb_substr((string) $r['body'], 0, 1000),
            'status' => (string) $r['status'],
            'reports' => (int) $r['reports'],
            'at' => SaveShape::time((string) $r['created_at']),
        ], $rows);
    }

    // —— Act ——

    #[Route(Method::Post, '/groddle/v1/mod/reports/{id:[1-9][0-9]{0,18}}/resolve', policy: new Policy(Access::Public))]
    public function resolve(Request $request, string $id): Response
    {
        $actor = $this->actor($request, 'moderate');
        $db = $this->minn->db;
        $body = $request->json();
        $t = $db->table(ModReports::TABLE);
        $row = $db->row("SELECT id, reported_id, status FROM {$t} WHERE id = ?", [(int) $id]);
        if ($row === null) {
            throw new RestError('groddle_mod_no_report', 'No report has that id.', 404);
        }
        $outcome = is_string($body['outcome'] ?? null) && in_array($body['outcome'], self::OUTCOMES, true) ? $body['outcome'] : '';
        if ($outcome === '') {
            $this->refused($actor, 'resolve', (int) $row['reported_id'], 'outcome');
            throw new RestError('groddle_mod_outcome', 'outcome is one of ' . implode(', ', self::OUTCOMES) . '.', 400);
        }
        $note = ModReports::note($body['note'] ?? '');
        $db->execute("UPDATE {$t} SET status = 'resolved', outcome = ?, outcome_note = ?, resolved_at = ?, resolved_by = ?, updated_at = ? WHERE id = ?", [$outcome, $note, gmdate('Y-m-d H:i:s'), mb_substr($actor['name'], 0, 80), gmdate('Y-m-d H:i:s'), (int) $id]);
        $aid = $this->log($actor, 'resolve', (int) $row['reported_id'], $note, ['report' => (int) $id, 'outcome' => $outcome, 'was' => (string) $row['status']], 'ok');
        return self::private(Response::json(['action' => $aid, 'report' => (int) $id, 'status' => 'resolved', 'outcome' => $outcome]));
    }

    #[Route(Method::Post, '/groddle/v1/mod/silence', policy: new Policy(Access::Public))]
    public function silence(Request $request): Response
    {
        $actor = $this->actor($request, 'moderate');
        $body = $request->json();
        $player = $this->target($actor, $body['player'] ?? null, 'silence');
        $scope = self::SILENCE_SCOPES[is_string($body['scope'] ?? null) ? $body['scope'] : ''] ?? null;
        if ($scope === null) {
            $this->refused($actor, 'silence', $player['id'], 'scope');
            throw new RestError('groddle_mod_scope', 'scope is chat, help or all.', 400);
        }
        $hours = $this->hours($actor, $body['hours'] ?? null, 'silence', $player['id']);
        return $this->restrict($actor, $player, 'silence', $scope, $hours, $this->reason($actor, $body, 'silence', $player['id']), 'silence');
    }

    #[Route(Method::Post, '/groddle/v1/mod/im_restrict', policy: new Policy(Access::Public))]
    public function imRestrict(Request $request): Response
    {
        $actor = $this->actor($request, 'moderate');
        $body = $request->json();
        $player = $this->target($actor, $body['player'] ?? null, 'im_restrict');
        $scope = is_string($body['scope'] ?? null) && in_array($body['scope'], self::IM_SCOPES, true) ? $body['scope'] : null;
        if ($scope === null) {
            $this->refused($actor, 'im_restrict', $player['id'], 'scope');
            throw new RestError('groddle_mod_scope', 'scope is all or buddies.', 400);
        }
        $hours = $this->hours($actor, $body['hours'] ?? null, 'im_restrict', $player['id']);
        return $this->restrict($actor, $player, 'im', $scope, $hours, $this->reason($actor, $body, 'im_restrict', $player['id']), 'im_restrict');
    }

    #[Route(Method::Post, '/groddle/v1/mod/lift', policy: new Policy(Access::Public))]
    public function lift(Request $request): Response
    {
        $actor = $this->actor($request, 'moderate');
        $body = $request->json();
        $player = $this->target($actor, $body['player'] ?? null, 'lift');
        $kind = ($body['kind'] ?? null) === 'silence' ? 'silence' : (($body['kind'] ?? null) === 'im' ? 'im' : null);
        if ($kind === null) {
            $this->refused($actor, 'lift', $player['id'], 'kind');
            throw new RestError('groddle_mod_kind', 'kind is silence or im.', 400);
        }
        $reason = $this->reason($actor, $body, 'lift', $player['id']);
        $n = $this->liftKind($player['id'], $kind, $actor['name']);
        $delivery = $n > 0 ? $this->push($player['id']) : 'none';
        $id = $this->log($actor, 'lift', $player['id'], $reason, ['kind' => $kind, 'lifted' => $n], $delivery);
        return $this->answer($id, $player, $delivery, $n > 0 ? ($kind === 'silence' ? "{$player['name']} can chat again." : "{$player['name']} can IM again.") : "Nothing of that kind stands for {$player['name']}.");
    }

    #[Route(Method::Post, '/groddle/v1/mod/ban', policy: new Policy(Access::Public))]
    public function ban(Request $request): Response
    {
        $actor = $this->actor($request, 'moderate');
        $body = $request->json();
        $player = $this->target($actor, $body['player'] ?? null, 'ban');
        $reason = $this->reason($actor, $body, 'ban', $player['id']);
        $db = $this->minn->db;
        $db->execute('INSERT INTO ' . $db->table(AdminConsole::BANS) . ' (user_id, reason, admin_user_id, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE reason = VALUES(reason), admin_user_id = VALUES(admin_user_id)', [$player['id'], $actor['kind'] === 'token' ? mb_substr("{$actor['name']}: {$reason}", 0, AdminConsole::REASON_MAX) : $reason, $actor['by'], gmdate('Y-m-d H:i:s')]);
        $answer = (new RealtimeCall($this->minn))->post('ban', ['player' => $player['id'], 'reason' => $reason]);
        $delivery = ($answer['status'] ?? null) === 'sent' ? 'live' : 'stored';
        $id = $this->log($actor, 'ban', $player['id'], $reason, [], $delivery);
        return $this->answer($id, $player, $delivery, $delivery === 'live' ? "Banned {$player['name']} and signed them out." : "Banned {$player['name']}.");
    }

    #[Route(Method::Post, '/groddle/v1/mod/unban', policy: new Policy(Access::Public))]
    public function unban(Request $request): Response
    {
        $actor = $this->actor($request, 'moderate');
        $body = $request->json();
        $player = $this->target($actor, $body['player'] ?? null, 'unban');
        $reason = $this->reason($actor, $body, 'unban', $player['id']);
        return $this->unbanAs($actor, $player, $reason, 0);
    }

    private function unbanAs(array $actor, array $player, string $reason, int $undoOf): Response
    {
        $db = $this->minn->db;
        $db->execute('DELETE FROM ' . $db->table(AdminConsole::BANS) . ' WHERE user_id = ?', [$player['id']]);
        $answer = (new RealtimeCall($this->minn))->post('unban', ['player' => $player['id']]);
        $delivery = ($answer['status'] ?? null) === 'unbanned' ? 'live' : 'stored';
        $id = $this->log($actor, $undoOf > 0 ? 'undo' : 'unban', $player['id'], $reason, $undoOf > 0 ? ['undid' => 'ban'] : [], $delivery, $undoOf);
        return $this->answer($id, $player, $delivery, "{$player['name']} can play again.");
    }

    #[Route(Method::Post, '/groddle/v1/mod/delete_line', policy: new Policy(Access::Public))]
    public function deleteLine(Request $request): Response
    {
        $actor = $this->actor($request, 'moderate');
        $db = $this->minn->db;
        $body = $request->json();
        $key = is_string($body['channel'] ?? null) ? $body['channel'] : '';
        $lid = is_int($body['lid'] ?? null) ? $body['lid'] : 0;
        if ($lid <= 0 || preg_match('/^(?:global|help|trade|group-[1-9]\d{0,18})$/', $key) !== 1) {
            $this->refused($actor, 'delete_line', 0, 'line');
            throw new RestError('groddle_mod_line', 'Name a channel (global, help, trade, group-<id>) and a lid.', 400);
        }
        $author = (int) ($db->value('SELECT author_id FROM ' . $db->table(ChatHistory::LINES) . ' WHERE lid = ?', [$lid]) ?? 0);
        if ($author > 0) {
            $this->target($actor, $author, 'delete_line');
        }
        $reason = $this->reason($actor, $body, 'delete_line', $author);
        $done = (new ChatHistory($this->minn))->deleteAs($actor['by'], $actor['name'], $key, $lid);
        if ($done['author'] > 0 && $author === 0) {
            $this->target($actor, $done['author'], 'delete_line');
        }
        $id = $this->log($actor, 'delete_line', $done['author'], $reason, ['channel' => $key, 'lid' => $lid, 'reply' => $done['parent'] > 0, 'again' => $done['again']], $done['live'] ? 'live' : 'down');
        $name = $done['author'] > 0 ? ((new Characters($db))->namesOf([$done['author']])[$done['author']] ?? '') : '';
        return $this->answer($id, ['id' => $done['author'], 'name' => $name], $done['live'] ? 'live' : 'down', 'The line is gone for everyone.');
    }

    #[Route(Method::Post, '/groddle/v1/mod/purge', policy: new Policy(Access::Public))]
    public function purge(Request $request): Response
    {
        $actor = $this->actor($request, 'moderate');
        $body = $request->json();
        $player = $this->target($actor, $body['player'] ?? null, 'purge');
        $hours = $body['hours'] ?? null;
        if (!is_int($hours) || $hours < 0 || $hours > self::PURGE_HOURS_MAX) {
            $this->refused($actor, 'purge', $player['id'], 'hours');
            throw new RestError('groddle_mod_hours', 'hours goes from 1 to ' . self::PURGE_HOURS_MAX . ', or 0 for everything.', 400);
        }
        $reason = $this->reason($actor, $body, 'purge', $player['id']);
        $since = $hours === 0 ? 0 : (int) (microtime(true) * 1000) - $hours * 3_600_000;
        $site = ChatHistory::purge($this->minn->db, $player['id'], $since, $actor['by']);
        $answer = (new RealtimeCall($this->minn))->post('mod_purge', ['player' => $player['id'], 'since' => $since, 'by' => $actor['by']]);
        $live = ($answer['status'] ?? null) === 'purged';
        $server = $live ? ['channel' => (int) ($answer['channel'] ?? 0), 'replies' => (int) ($answer['replies'] ?? 0), 'ims' => (int) ($answer['ims'] ?? 0)] : null;
        $id = $this->log($actor, 'purge', $player['id'], $reason, ['hours' => $hours, 'since' => $since, 'site' => $site, 'server' => $server], $live ? 'live' : 'down');
        $words = $hours === 0 ? 'everything' : "the last {$hours} hours of what";
        return $this->answer($id, $player, $live ? 'live' : 'down', "Removed {$words} {$player['name']} said: {$site['lines']} chat lines and {$site['ims']} IMs kept on the site" . ($live ? ', and every open window.' : '. The realtime server did not answer, so open windows keep them until they reload.') . ' A purge is final.', ['final' => true, 'site' => $site, 'server' => $server]);
    }

    #[Route(Method::Post, '/groddle/v1/mod/notice', policy: new Policy(Access::Public))]
    public function notice(Request $request): Response
    {
        $actor = $this->actor($request, 'moderate');
        $body = $request->json();
        $player = $this->target($actor, $body['player'] ?? null, 'notice');
        $text = AdminConsole::messageText($body['text'] ?? '');
        if ($text === '') {
            $this->refused($actor, 'notice', $player['id'], 'text');
            throw new RestError('groddle_mod_text', 'Write the notice (text).', 400);
        }
        $answer = (new RealtimeCall($this->minn))->post('message', ['player' => $player['id'], 'id' => 'am-' . bin2hex(random_bytes(8)), 'text' => $text]);
        $delivery = match ($answer['status'] ?? null) {
            'sent' => 'live',
            'queued' => 'queued',
            default => 'down',
        };
        $id = $this->log($actor, 'notice', $player['id'], '', ['text' => $text], $delivery);
        return $this->answer($id, $player, $delivery, match ($delivery) {
            'live' => "{$player['name']} has the notice.",
            'queued' => "{$player['name']} is not online. They will see it when they next connect.",
            default => "The realtime server is not answering, so {$player['name']} did not get it.",
        });
    }

    #[Route(Method::Post, '/groddle/v1/mod/actions/{id:[1-9][0-9]{0,18}}/undo', policy: new Policy(Access::Public))]
    public function undo(Request $request, string $id): Response
    {
        $actor = $this->actor($request, 'moderate');
        $db = $this->minn->db;
        $row = $db->row('SELECT * FROM ' . $db->table(self::ACTIONS) . ' WHERE id = ?', [(int) $id]);
        if ($row === null) {
            throw new RestError('groddle_mod_no_action', 'No action has that id.', 404);
        }
        $player = $this->target($actor, (int) $row['target_id'], 'undo');
        if (!in_array($row['action'], ['silence', 'im_restrict', 'ban'], true) || $row['result'] === 'refused') {
            $this->refused($actor, 'undo', $player['id'], (string) $row['action']);
            throw new RestError('groddle_mod_final', $row['action'] === 'purge' ? 'A purge is final: the lines are gone and do not come back.' : 'That action has no undo.', 409);
        }
        if ($row['undone_at'] !== null) {
            throw new RestError('groddle_mod_undone', 'That action was undone already.', 409);
        }
        $reason = AdminConsole::reasonText($request->json()['reason'] ?? '') ?: 'Undone';
        $db->execute('UPDATE ' . $db->table(self::ACTIONS) . ' SET undone_at = ?, undone_by = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), mb_substr($actor['name'], 0, 80), (int) $id]);
        if ($row['action'] === 'ban') {
            return $this->unbanAs($actor, $player, $reason, (int) $id);
        }
        $n = $db->execute('UPDATE ' . $db->table(self::RESTRICTIONS) . ' SET lifted_at = ?, lifted_by = ? WHERE action_id = ? AND lifted_at IS NULL', [gmdate('Y-m-d H:i:s'), mb_substr($actor['name'], 0, 80), (int) $id]);
        $delivery = $n > 0 ? $this->push($player['id']) : 'none';
        $aid = $this->log($actor, 'undo', $player['id'], $reason, ['undid' => (string) $row['action'], 'lifted' => $n], $delivery, (int) $id);
        return $this->answer($aid, $player, $delivery, $n > 0 ? 'Undone.' : 'It had ended already; nothing was standing.');
    }

    // —— Tokens (the session only) ——

    #[Route(Method::Get, '/groddle/v1/mod/tokens', policy: new Policy(Access::Public))]
    public function tokens(Request $request): Response
    {
        $this->actor($request, null);
        return self::private(Response::json(['tokens' => (new ModTokens($this->minn->db))->list()]));
    }

    #[Route(Method::Post, '/groddle/v1/mod/tokens', policy: new Policy(Access::Public))]
    public function createToken(Request $request): Response
    {
        $actor = $this->actor($request, null);
        $body = $request->json();
        $caps = is_array($body['caps'] ?? null) ? array_values(array_filter($body['caps'], 'is_string')) : ModTokens::CAPS;
        try {
            $t = (new ModTokens($this->minn->db))->create(is_string($body['label'] ?? null) ? $body['label'] : '', $caps, $actor['by']);
        } catch (\InvalidArgumentException $e) {
            throw new RestError('groddle_mod_token_bad', $e->getMessage(), 400);
        }
        $this->log($actor, 'token_create', 0, '', ['token' => $t['id'], 'label' => $t['label'], 'caps' => $t['caps']], 'ok');
        return self::private(Response::json($t + ['note' => 'Shown once. Keep it in Grok Bot\'s settings as its bearer token.'], 201));
    }

    #[Route(Method::Delete, '/groddle/v1/mod/tokens/{id:[0-9a-f]{16}}', policy: new Policy(Access::Public))]
    public function revokeToken(Request $request, string $id): Response
    {
        $actor = $this->actor($request, null);
        $done = (new ModTokens($this->minn->db))->revoke($id);
        $this->log($actor, 'token_revoke', 0, '', ['token' => $id], $done ? 'ok' : 'none');
        return self::private(Response::json(['status' => $done ? 'revoked' : 'none']));
    }

    // —— Shared ——

    private function answer(int $action, array $player, string $delivery, string $text, array $more = []): Response
    {
        return self::private(Response::json(['action' => $action, 'player' => $player, 'delivery' => $delivery, 'text' => $text] + $more));
    }

    private static function private(Response $r): Response
    {
        return $r->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Robots-Tag', 'noindex');
    }
}
