<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * The game's chat kept for good (Austin 2026-11-04): the realtime server
 * stays the live relay and sends every channel and group line, every IM line
 * and every change to their reactions here in signed batches
 * (groddle-afternoon-server chatstore.go); this keeps them and serves them
 * to signed-in players, and carries a line written on the website's /chat/
 * to the server as a real channel line (webchat.go web_say).
 *
 *   POST chat/ingest {"entries":[…]}         the server's signed call (ServiceCall), idempotent:
 *                                            a line by its lid, an IM line by its pair, id and time; reactions as the whole set
 *   GET  chat/channels                       {"channels":[{"key","label","room"}],"silenced":[…]}  the ones this player may read
 *   GET  chat/channels/{key}?before=<lid>&limit=n           {"lines":[Line…] newest first,"more":bool}
 *   GET  chat/channels/{key}?after=<lid>&since=<ms>         {"lines":[Line…] oldest first,"changed":[{"lid","r"}],"now":ms}
 *   POST chat/channels/{key} {"text"}        said from the web: 201 {"line":Line} | 403/429 with the game's words
 *   GET  chat/ims/{with}?before_at=<ms>&limit=n             {"lines":[{"id","from","text","at","r","th"?}…] newest first,"more","blocked"}
 *   GET  chat/channels/{key}/threads/{lid}                  {"parent":Line|null,"lines":[Line…] oldest first,"now"}  a line's thread
 *   POST chat/channels/{key}/threads/{lid} {"text"}         a reply from the web (web_say with "parent"): 201 {"line":Line}
 *   GET  chat/ims/{with}/threads/{line}                     {"lines":[…] oldest first,"blocked"}  an IM line's thread, the two players only
 *
 *   key: global, help (Live Help: the newbie room under level 11, as the
 *   game), trade (with the Trade Channelling upgrade), group-<id> (a group's
 *   members; read only here, the game is where group chat is written).
 *   Line: {"lid","id","name","text","at","web","r":[{"e","by":[{id,name}]}],"th"?:{"n","last":{id,name}}}
 *
 * Threads (Austin 2026-11-04, groddle-afternoon-server threads.go): a reply
 * under a line is kept with its parent (parent_lid, an IM's parent_id) and is
 * never a line of the channel or conversation: the lists above leave replies
 * out and give each line with replies its "th" (how many, who replied last),
 * counted as the reader may see them (replies by players they block left
 * out). A reply changes its parent's changed_ms, so a page polling with
 * since= sees the count move.
 *
 * Who reads what (Austin 2026-11-04: "keep game communication for logged in
 * users only"): every route but ingest needs a signed-in player (a
 * character; administrators too) with the REST nonce, and answers private,
 * no-store; nothing here is ever in a page a visitor or a crawler gets. Kept:
 * channel and group lines for good, IMs for good but private: an IM
 * conversation is answered to its two players only, never to an
 * administrator (no console route reads groddle_im_lines). Local and party
 * chat are not kept. Blocks apply on reading as they do live: lines by a
 * player you block are left out, and so are their reactions; an IM
 * conversation with a player either of you blocks answers nothing. A removed
 * account's lines are left out at once (no user row), and its rows go with
 * the account, as every groddle_* row keyed to it does (author_id, from_id).
 */
final readonly class ChatHistory
{
    public const LINES = 'groddle_chat_lines';
    public const IMS = 'groddle_im_lines';

    public const PAGE = 50;
    public const PAGE_MAX = 100;
    /** Entries one ingest takes (the server sends at most 200). */
    public const INGEST_MAX = 500;
    public const TEXT_MAX = 1000;

    /** The channels by the website's key: [the game's label, the server's channel word]. */
    public const CHANNELS = ['global' => 'Global Chat', 'help' => 'Live Help', 'trade' => 'Trade Channel'];

    private const ROOM = '/^(?:global|help|newbie|trade|group:[1-9]\d{0,18})$/';

    public function __construct(private Seams $minn)
    {
    }

    // —— The server's batches ——

    #[Route(Method::Post, '/groddle/v1/chat/ingest', policy: new Policy(Access::Public))]
    public function ingest(Request $request): Response
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', '/groddle/v1/chat/ingest', $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
        $entries = $request->json()['entries'] ?? null;
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > self::INGEST_MAX) {
            throw new RestError('groddle_chat_bad', 'Not a batch.', 400);
        }
        $db = $this->minn->db;
        $stored = 0;
        Ledger::atomically($db, function () use ($db, $entries, &$stored): void {
            foreach ($entries as $e) {
                $stored += self::store($db, is_array($e) ? $e : []);
            }
        });
        return Response::json(['status' => 'ok', 'stored' => $stored]);
    }

    /** One entry into the tables; 1 when it changed something. Bad entries are skipped (the batch still lands). @param array<string, mixed> $e */
    public static function store(\Minn\Db $db, array $e): int
    {
        $ms = Community::ms();
        $int = static fn (mixed $v): int => is_int($v) && $v > 0 ? $v : 0;
        $react = static fn (mixed $r): ?string => self::reactionsJson($r);
        switch ($e['k'] ?? '') {
            case 'line':
                $lid = $int($e['lid'] ?? null);
                $id = $int($e['id'] ?? null);
                $room = is_string($e['room'] ?? null) && preg_match(self::ROOM, $e['room']) === 1 ? $e['room'] : '';
                $text = is_string($e['text'] ?? null) ? mb_substr($e['text'], 0, self::TEXT_MAX) : '';
                if ($lid === 0 || $id === 0 || $room === '' || $text === '') {
                    return 0;
                }
                $parent = $int($e['parent'] ?? null);
                $n = $db->execute('INSERT IGNORE INTO ' . $db->table(self::LINES) . ' (lid, room, author_id, name, text, at_ms, web, changed_ms, parent_lid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                    $lid, $room, $id, mb_substr(is_string($e['name'] ?? null) ? $e['name'] : '', 0, 80), $text, $int($e['at'] ?? null), ($e['web'] ?? false) === true ? 1 : 0, $ms, $parent,
                ]);
                if ($n > 0 && $parent > 0) {
                    // The parent's count moved: a reader polling with since= gets it again.
                    $db->execute('UPDATE ' . $db->table(self::LINES) . ' SET changed_ms = ? WHERE lid = ? AND room = ?', [$ms, $parent, $room]);
                }
                return $n;
            case 'react':
                $lid = $int($e['lid'] ?? null);
                $r = $react($e['r'] ?? null);
                if ($lid === 0 || $r === null) {
                    return 0;
                }
                return $db->execute('UPDATE ' . $db->table(self::LINES) . ' SET reactions = ?, changed_ms = ? WHERE lid = ?', [$r, $ms, $lid]);
            case 'im':
                [$a, $b] = [$int($e['a'] ?? null), $int($e['b'] ?? null)];
                $from = $int($e['from'] ?? null);
                $text = is_string($e['text'] ?? null) ? mb_substr($e['text'], 0, self::TEXT_MAX) : '';
                if ($a === 0 || $b <= $a || ($from !== $a && $from !== $b) || $int($e['line'] ?? null) === 0 || $text === '') {
                    return 0;
                }
                return $db->execute('INSERT IGNORE INTO ' . $db->table(self::IMS) . ' (a_id, b_id, line_id, from_id, text, at_ms, parent_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [$a, $b, $e['line'], $from, $text, $int($e['at'] ?? null), $int($e['parent'] ?? null)]);
            case 'im_react':
                [$a, $b] = [$int($e['a'] ?? null), $int($e['b'] ?? null)];
                $r = $react($e['r'] ?? null);
                if ($a === 0 || $b <= $a || $int($e['line'] ?? null) === 0 || $r === null) {
                    return 0;
                }
                return $db->execute('UPDATE ' . $db->table(self::IMS) . ' SET reactions = ? WHERE a_id = ? AND b_id = ? AND line_id = ? AND at_ms = ?', [$r, $a, $b, $e['line'], $int($e['at'] ?? null)]);
        }
        return 0;
    }

    /** A reaction set as kept: [{e, by:[ids]}], known reactions only, each player once; null when it is not a set. */
    public static function reactionsJson(mixed $r): ?string
    {
        if (!is_array($r) || !array_is_list($r)) {
            return null;
        }
        $out = [];
        foreach ($r as $x) {
            $e = is_array($x) ? ($x['e'] ?? null) : null;
            $by = is_array($x) ? ($x['by'] ?? null) : null;
            if (!CommunityText::isReaction($e) || !is_array($by)) {
                continue;
            }
            $ids = array_values(array_unique(array_filter($by, static fn (mixed $id): bool => is_int($id) && $id > 0)));
            if ($ids !== []) {
                $out[] = ['e' => $e, 'by' => $ids];
            }
        }
        return (string) json_encode($out);
    }

    // —— Reading ——

    #[Route(Method::Get, '/groddle/v1/chat/channels', policy: new Policy(Access::SignedIn))]
    public function channels(Request $request): Response
    {
        $me = $this->player();
        $c = (new Groups($this->minn))->channels($me);
        $out = [];
        foreach (self::CHANNELS as $key => $label) {
            $room = $this->roomFor($key, $c);
            if ($room !== null) {
                $out[] = ['key' => $key, 'label' => $label, 'room' => $room];
            }
        }
        return self::private(Response::json(['channels' => $out, 'silenced' => $c['silenced']]));
    }

    #[Route(Method::Get, '/groddle/v1/chat/channels/{key:global|help|trade|group-[1-9][0-9]{0,18}}', policy: new Policy(Access::SignedIn))]
    public function lines(Request $request, string $key): Response
    {
        $me = $this->player();
        $room = $this->readable($me, $key) ?? throw new RestError('groddle_chat_closed', 'That chat is not open to you.', 403);
        $db = $this->minn->db;
        $t = $db->table(self::LINES);
        $users = $db->table('users');
        $blocks = $this->blocks($me);
        $notBlocked = $blocks === [] ? '' : ' AND l.author_id NOT IN (' . implode(',', array_keys($blocks)) . ')';
        $from = "FROM {$t} l JOIN {$users} u ON u.ID = l.author_id WHERE l.room = ? AND l.parent_lid = 0{$notBlocked}";
        $after = $request->query('after');
        // ?html=1: each line also drawn as the website's /chat/ shows it (CommunityPages::channelLineHtml).
        $hosts = CommunityText::homeHosts($this->minn->request->host);
        $draw = $request->query('html') === '1'
            ? static fn (array $l): array => $l + ['html' => CommunityPages::channelLineHtml($l, $me, $hosts)]
            : static fn (array $l): array => $l;
        if ($after !== null) {
            $rows = $db->rows("SELECT l.* {$from} AND l.lid > ? ORDER BY l.lid ASC LIMIT " . self::PAGE_MAX, [$room, max(0, (int) $after)]);
            $since = max(0, (int) ($request->query('since') ?? '0'));
            $changed = $since > 0 ? $db->rows("SELECT l.* {$from} AND l.changed_ms > ? AND l.lid <= ? ORDER BY l.lid DESC LIMIT 200", [$room, $since, max(0, (int) $after)]) : [];
            $names = $this->names([...$rows, ...$changed]);
            $th = $this->threadSums($room, array_map(static fn (array $r): int => (int) $r['lid'], [...$rows, ...$changed]), $blocks);
            return self::private(Response::json([
                'lines' => array_map(static fn (array $r): array => $draw(self::lineOut($r, $names, $blocks, $th)), $rows),
                'changed' => array_map(static fn (array $r): array => $draw(self::lineOut($r, $names, $blocks, $th)), $changed),
                'now' => Community::ms(),
            ]));
        }
        $limit = min(self::PAGE_MAX, max(1, (int) ($request->query('limit') ?? (string) self::PAGE)));
        $before = $request->query('before');
        [$lines, $more] = $this->latest($me, $room, $before === null ? null : max(0, (int) $before), $limit);
        return self::private(Response::json(['lines' => array_map($draw, $lines), 'more' => $more, 'now' => Community::ms()]));
    }

    /**
     * A room's lines for a reader, newest first: before a lid (null: the newest), at most $limit, and
     * whether there are more (CommunityPages draws the first page with this).
     *
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    public function latest(int $me, string $room, ?int $before, int $limit): array
    {
        $db = $this->minn->db;
        $blocks = $this->blocks($me);
        $notBlocked = $blocks === [] ? '' : ' AND l.author_id NOT IN (' . implode(',', array_keys($blocks)) . ')';
        $from = 'FROM ' . $db->table(self::LINES) . ' l JOIN ' . $db->table('users') . " u ON u.ID = l.author_id WHERE l.room = ? AND l.parent_lid = 0{$notBlocked}";
        $rows = $before !== null
            ? $db->rows("SELECT l.* {$from} AND l.lid < ? ORDER BY l.lid DESC LIMIT " . ($limit + 1), [$room, $before])
            : $db->rows("SELECT l.* {$from} ORDER BY l.lid DESC LIMIT " . ($limit + 1), [$room]);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $names = $this->names($rows);
        $th = $this->threadSums($room, array_map(static fn (array $r): int => (int) $r['lid'], $rows), $blocks);
        return [array_map(static fn (array $r): array => self::lineOut($r, $names, $blocks, $th), $rows), $more];
    }

    /**
     * The channels a reader may read on the website, key => [label, room] (Global, Live Help, and the
     * Trade Channel with the upgrade); none for a reader who is not a player.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function open(int $me): array
    {
        if ($me <= 0) {
            return [];
        }
        try {
            $this->playerCheck($me);
        } catch (RestError) {
            return [];
        }
        $c = (new Groups($this->minn))->channels($me);
        $out = [];
        foreach (self::CHANNELS as $key => $label) {
            $room = $this->roomFor($key, $c);
            if ($room !== null) {
                $out[$key] = [$label, $room];
            }
        }
        return $out;
    }

    /** A line written on the website: the server makes it a real channel line (webchat.go web_say). */
    #[Route(Method::Post, '/groddle/v1/chat/channels/{key:global|help|trade}', policy: new Policy(Access::SignedIn))]
    public function say(Request $request, string $key): Response
    {
        return $this->sayFrom($request, $this->player(), $key, 0, false);
    }

    /** A line (or, with a parent, a thread reply) said from the website, through the realtime server. */
    private function sayFrom(Request $request, int $me, string $key, int $parent, bool $kept): Response
    {
        $db = $this->minn->db;
        if (AdminConsole::banned($db, $me) !== null) {
            throw new RestError('groddle_suspended', AdminConsole::SUSPENDED, 403);
        }
        $c = (new Groups($this->minn))->channels($me);
        if ($this->roomFor($key, $c) === null) {
            throw new RestError('groddle_chat_closed', $key === 'trade' ? 'The Trade Channel needs the Trade Channelling upgrade.' : 'That chat is not open to you.', 403);
        }
        $text = $request->json()['text'] ?? '';
        $text = is_string($text) ? trim((string) preg_replace('/\s+/u', ' ', $text)) : '';
        if ($text === '') {
            throw new RestError('groddle_chat_blank', 'Write something first.', 400);
        }
        $rooms = ['global', $c['help']];
        if ($c['trade']) {
            $rooms[] = 'trade';
        }
        $name = (new Characters($db))->namesOf([$me])[$me] ?? '';
        $call = ['player' => $me, 'name' => $name, 'channel' => $key, 'rooms' => implode(',', $rooms), 'silence' => implode(',', $c['silenced']), 'text' => mb_substr($text, 0, self::TEXT_MAX)];
        if ($parent > 0) {
            $call['parent'] = $parent;
            $call['kept'] = $kept;
        }
        $answer = (new RealtimeCall($this->minn))->post('web_say', $call);
        return match ($answer['status'] ?? null) {
            'sent' => self::private(Response::json(['line' => ($l = self::sentLine(is_array($answer['line'] ?? null) ? $answer['line'] : [])) + ['html' => CommunityPages::channelLineHtml($l, $me, CommunityText::homeHosts($this->minn->request->host), $parent === 0)]], 201)),
            'silenced' => throw new RestError('groddle_chat_silenced', is_string($answer['message'] ?? null) ? $answer['message'] : 'Sorry, you\'re currently suspended from this chat.', 403),
            'slow_down' => throw new RestError('groddle_chat_slow', 'Slow down a little.', 429),
            'banned' => throw new RestError('groddle_suspended', AdminConsole::SUSPENDED, 403),
            'closed' => throw new RestError('groddle_chat_closed', 'That chat is not open to you.', 403),
            'no_line' => throw new RestError('groddle_chat_no_line', 'That line is not there to reply to.', 404),
            default => throw new RestError('groddle_chat_down', 'The game\'s chat is not answering. Try again in a moment.', 503),
        };
    }

    /**
     * The reader's IM conversations, newest first (the drawer's Chats list on the website): the other
     * player, whether they are a buddy either way, the last line of the conversation (not a thread
     * reply) and when. Never one with a player either of them blocks, nor with an account that is gone.
     */
    #[Route(Method::Get, '/groddle/v1/chat/ims', policy: new Policy(Access::SignedIn))]
    public function conversations(Request $request): Response
    {
        $me = $this->player();
        $db = $this->minn->db;
        $t = $db->table(self::IMS);
        $ignores = $db->table(Buddies::IGNORES);
        $blocked = [];
        foreach ($db->rows("SELECT ignored_id AS id FROM {$ignores} WHERE user_id = ? UNION SELECT user_id AS id FROM {$ignores} WHERE ignored_id = ?", [$me, $me]) as $r) {
            $blocked[(int) $r['id']] = true;
        }
        $rows = $db->rows("SELECT a_id, b_id, MAX(at_ms) AS last_at FROM {$t} WHERE (a_id = ? OR b_id = ?) AND parent_id = 0 GROUP BY a_id, b_id ORDER BY last_at DESC LIMIT 100", [$me, $me]);
        $others = [];
        foreach ($rows as $r) {
            $other = (int) $r['a_id'] === $me ? (int) $r['b_id'] : (int) $r['a_id'];
            if (!isset($blocked[$other])) {
                $others[$other] = $r;
            }
        }
        $names = $others === [] ? [] : (new Characters($db))->namesOf(array_keys($others));
        $buddies = $db->table(Buddies::TABLE);
        $held = [];
        foreach ($db->rows("SELECT buddy_id AS id FROM {$buddies} WHERE user_id = ? UNION SELECT user_id AS id FROM {$buddies} WHERE buddy_id = ?", [$me, $me]) as $r) {
            $held[(int) $r['id']] = true;
        }
        $out = [];
        foreach ($others as $other => $r) {
            if (!isset($names[$other]) || count($out) >= 50) {
                continue;
            }
            $last = $db->row("SELECT from_id, text, at_ms FROM {$t} WHERE a_id = ? AND b_id = ? AND parent_id = 0 ORDER BY at_ms DESC, id DESC LIMIT 1", [(int) $r['a_id'], (int) $r['b_id']]);
            $out[] = ['with' => ['id' => $other, 'name' => $names[$other], 'buddy' => isset($held[$other])], 'last' => $last === null ? null : ['from' => (int) $last['from_id'], 'text' => (string) $last['text'], 'at' => (int) $last['at_ms']]];
        }
        return self::private(Response::json(['convs' => $out]));
    }

    /** An IM written on the website (or a reply in a line's thread): the realtime server sends it as the game would (web_im). */
    #[Route(Method::Post, '/groddle/v1/chat/ims/{with:[1-9][0-9]{0,18}}', policy: new Policy(Access::SignedIn))]
    public function imSay(Request $request, string $with): Response
    {
        $me = $this->player();
        $db = $this->minn->db;
        if (AdminConsole::banned($db, $me) !== null) {
            throw new RestError('groddle_suspended', AdminConsole::SUSPENDED, 403);
        }
        $body = $request->json();
        $text = is_string($body['text'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $body['text'])) : '';
        if ($text === '') {
            throw new RestError('groddle_chat_blank', 'Write something first.', 400);
        }
        $parent = is_int($body['parent'] ?? null) && $body['parent'] > 0 ? $body['parent'] : 0;
        $name = (new Characters($db))->namesOf([$me])[$me] ?? '';
        $answer = (new RealtimeCall($this->minn))->post('web_im', ['player' => $me, 'name' => $name, 'to' => (int) $with, 'text' => mb_substr($text, 0, self::TEXT_MAX), 'parent' => $parent]);
        return match ($answer['status'] ?? null) {
            'sent' => self::private(Response::json(['status' => 'sent'], 201)),
            'no' => throw new RestError('groddle_im_' . preg_replace('/[^a-z]+/', '_', (string) ($answer['reason'] ?? 'no')), is_string($answer['message'] ?? null) ? $answer['message'] : 'That IM did not go.', ($answer['reason'] ?? '') === 'slow_down' ? 429 : 403),
            'banned' => throw new RestError('groddle_suspended', AdminConsole::SUSPENDED, 403),
            default => throw new RestError('groddle_chat_down', 'The game\'s chat is not answering. Try again in a moment.', 503),
        };
    }

    /** An IM conversation, newest first: only for one of its two players. */
    #[Route(Method::Get, '/groddle/v1/chat/ims/{with:[1-9][0-9]{0,18}}', policy: new Policy(Access::SignedIn))]
    public function ims(Request $request, string $with): Response
    {
        $me = $this->player();
        $other = (int) $with;
        if ($other === $me) {
            throw new RestError('groddle_chat_self', 'You can\'t IM yourself.', 400);
        }
        $db = $this->minn->db;
        $ignores = $db->table(Buddies::IGNORES);
        if ($db->value("SELECT 1 FROM {$ignores} WHERE (user_id = ? AND ignored_id = ?) OR (user_id = ? AND ignored_id = ?) LIMIT 1", [$me, $other, $other, $me]) !== null) {
            return self::private(Response::json(['lines' => [], 'more' => false, 'blocked' => true]));
        }
        [$a, $b] = $me < $other ? [$me, $other] : [$other, $me];
        $limit = min(self::PAGE_MAX, max(1, (int) ($request->query('limit') ?? (string) self::PAGE)));
        $before = $request->query('before_at');
        $t = $db->table(self::IMS);
        $users = $db->table('users');
        $where = "FROM {$t} i JOIN {$users} u ON u.ID = i.from_id WHERE i.a_id = ? AND i.b_id = ? AND i.parent_id = 0";
        $rows = $before !== null
            ? $db->rows("SELECT i.* {$where} AND i.at_ms < ? ORDER BY i.at_ms DESC, i.id DESC LIMIT " . ($limit + 1), [$a, $b, max(0, (int) $before)])
            : $db->rows("SELECT i.* {$where} ORDER BY i.at_ms DESC, i.id DESC LIMIT " . ($limit + 1), [$a, $b]);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $names = (new Characters($db))->namesOf([$a, $b]);
        $th = [];
        $ids = array_map(static fn (array $r): int => (int) $r['line_id'], $rows);
        if ($ids !== []) {
            $in = implode(',', array_map('intval', $ids));
            foreach ($db->rows("SELECT i.parent_id, COUNT(*) AS n, MAX(i.id) AS last_row FROM {$t} i JOIN {$users} u ON u.ID = i.from_id WHERE i.a_id = ? AND i.b_id = ? AND i.parent_id IN ({$in}) GROUP BY i.parent_id", [$a, $b]) as $g) {
                $from = (int) $db->value("SELECT from_id FROM {$t} WHERE id = ?", [(int) $g['last_row']]);
                $th[(int) $g['parent_id']] = ['n' => (int) $g['n'], 'last' => ['id' => $from, 'name' => (string) ($names[$from] ?? '')]];
            }
        }
        $out = array_map(static fn (array $r): array => self::imOut($r, $names) + (isset($th[(int) $r['line_id']]) ? ['th' => $th[(int) $r['line_id']]] : []), $rows);
        return self::private(Response::json(['lines' => $out, 'more' => $more, 'blocked' => false]));
    }

    /** An IM line as one of its two players gets it. @param array<string, mixed> $r @param array<int, string> $names */
    private static function imOut(array $r, array $names): array
    {
        return [
            'id' => (int) $r['line_id'],
            'from' => (int) $r['from_id'],
            'text' => (string) $r['text'],
            'at' => (int) $r['at_ms'],
            'r' => self::reactionsOut((string) ($r['reactions'] ?? ''), $names, []),
        ] + ((int) ($r['parent_id'] ?? 0) > 0 ? ['parent' => (int) $r['parent_id']] : []);
    }

    // —— Threads ——

    /** A channel line's thread: the line and its replies, oldest first, as the reader may see them. */
    #[Route(Method::Get, '/groddle/v1/chat/channels/{key:global|help|trade|group-[1-9][0-9]{0,18}}/threads/{lid:[1-9][0-9]{0,18}}', policy: new Policy(Access::SignedIn))]
    public function thread(Request $request, string $key, string $lid): Response
    {
        $me = $this->player();
        $room = $this->readable($me, $key) ?? throw new RestError('groddle_chat_closed', 'That chat is not open to you.', 403);
        $db = $this->minn->db;
        $t = $db->table(self::LINES);
        $users = $db->table('users');
        $blocks = $this->blocks($me);
        $notBlocked = $blocks === [] ? '' : ' AND l.author_id NOT IN (' . implode(',', array_keys($blocks)) . ')';
        $parent = $db->row("SELECT l.* FROM {$t} l JOIN {$users} u ON u.ID = l.author_id WHERE l.room = ? AND l.lid = ? AND l.parent_lid = 0{$notBlocked}", [$room, (int) $lid]);
        $rows = $parent === null ? [] : $db->rows("SELECT l.* FROM {$t} l JOIN {$users} u ON u.ID = l.author_id WHERE l.room = ? AND l.parent_lid = ?{$notBlocked} ORDER BY l.lid ASC LIMIT 500", [$room, (int) $lid]);
        $names = $this->names($parent === null ? $rows : [$parent, ...$rows]);
        $hosts = CommunityText::homeHosts($this->minn->request->host);
        $draw = $request->query('html') === '1'
            ? static fn (array $l): array => $l + ['html' => CommunityPages::channelLineHtml($l, $me, $hosts, false)]
            : static fn (array $l): array => $l;
        $th = $parent === null ? [] : $this->threadSums($room, [(int) $lid], $blocks);
        return self::private(Response::json([
            'parent' => $parent === null ? null : $draw(self::lineOut($parent, $names, $blocks, $th)),
            'lines' => array_map(static fn (array $r): array => $draw(self::lineOut($r, $names, $blocks)), $rows),
            'now' => Community::ms(),
        ]));
    }

    /** A reply written on the website, under a line of the channel (the server's web_say with "parent"). */
    #[Route(Method::Post, '/groddle/v1/chat/channels/{key:global|help|trade}/threads/{lid:[1-9][0-9]{0,18}}', policy: new Policy(Access::SignedIn))]
    public function reply(Request $request, string $key, string $lid): Response
    {
        $me = $this->player();
        $db = $this->minn->db;
        $room = $this->readable($me, $key) ?? throw new RestError('groddle_chat_closed', 'That chat is not open to you.', 403);
        // A line said a moment ago may not be kept here yet (the server sends its batches every few
        // seconds): the server knows its own recent lines, and refuses one it has never had.
        $row = $db->row('SELECT parent_lid FROM ' . $db->table(self::LINES) . ' WHERE room = ? AND lid = ?', [$room, (int) $lid]);
        if ($row !== null && (int) $row['parent_lid'] !== 0) {
            throw new RestError('groddle_chat_no_line', 'A reply takes no thread of its own.', 404);
        }
        return $this->sayFrom($request, $me, $key, (int) $lid, $row !== null);
    }

    // —— Shared ——

    /** The signed-in player (a character, or an administrator), or a refusal. */
    private function player(): int
    {
        $user = $this->minn->user();
        if ($user === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        $this->playerCheck($user->id);
        return $user->id;
    }

    private function playerCheck(int $id): void
    {
        $db = $this->minn->db;
        $player = $db->value('SELECT 1 FROM ' . $db->table(Characters::TABLE) . ' WHERE user_id = ?', [$id]) !== null;
        if (!$player && !\Minn\Auth\Capabilities::fromDb($db)->can($id, 'manage_options')) {
            throw new RestError('groddle_chat_not_player', 'Only players can read the game\'s chat. Make a character in the game first.', 403);
        }
    }

    /** The room behind a website key for these channels (Groups::channels), or null. @param array<string, mixed> $c */
    private function roomFor(string $key, array $c): ?string
    {
        return match ($key) {
            'global' => 'global',
            'help' => (string) $c['help'],
            'trade' => $c['trade'] ? 'trade' : null,
            default => null,
        };
    }

    /** The room a player may read under a key, or null: a channel as the game allows it, a group by membership. */
    public function readable(int $me, string $key): ?string
    {
        if (preg_match('/^group-([1-9]\d{0,18})$/', $key, $m) === 1) {
            $db = $this->minn->db;
            return $db->value('SELECT 1 FROM ' . $db->table(Groups::MEMBERS) . ' WHERE group_id = ? AND user_id = ?', [(int) $m[1], $me]) !== null ? "group:{$m[1]}" : null;
        }
        return isset(self::CHANNELS[$key]) ? $this->roomFor($key, (new Groups($this->minn))->channels($me)) : null;
    }

    /** @return array<int, true> */
    private function blocks(int $me): array
    {
        $db = $this->minn->db;
        $out = [];
        foreach ($db->rows('SELECT ignored_id FROM ' . $db->table(Buddies::IGNORES) . ' WHERE user_id = ?', [$me]) as $r) {
            $out[(int) $r['ignored_id']] = true;
        }
        return $out;
    }

    /** Names for the lines' writers and reactors, as the game names them now. @param list<array<string, mixed>> $rows @return array<int, string> */
    private function names(array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            if (isset($r['author_id'])) {
                $ids[] = (int) $r['author_id'];
            }
            foreach (json_decode((string) ($r['reactions'] ?? ''), true) ?: [] as $x) {
                foreach ($x['by'] ?? [] as $id) {
                    $ids[] = (int) $id;
                }
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        return $ids === [] ? [] : (new Characters($this->minn->db))->namesOf($ids);
    }

    /** A kept line as the reader gets it. @param array<string, mixed> $r @param array<int, string> $names @param array<int, true> $blocks */
    public static function lineOut(array $r, array $names, array $blocks, array $threads = []): array
    {
        $id = (int) $r['author_id'];
        $lid = (int) $r['lid'];
        return (isset($threads[$lid]) ? ['th' => $threads[$lid]] : []) + [
            'lid' => (int) $r['lid'],
            'id' => $id,
            'name' => $names[$id] ?? (string) $r['name'],
            'text' => (string) $r['text'],
            'at' => (int) $r['at_ms'],
            'web' => (int) $r['web'] === 1,
            'r' => self::reactionsOut((string) ($r['reactions'] ?? ''), $names, $blocks),
        ];
    }

    /**
     * Kept reactions as the reader sees them: the set's order, names now, none by a player they block
     * (reactions.go shownTo), none by an account that is gone.
     *
     * @param array<int, string> $names
     * @param array<int, true> $blocks
     * @return list<array{e: string, by: list<array{id: int, name: string}>}>
     */
    public static function reactionsOut(string $json, array $names, array $blocks): array
    {
        $kept = json_decode($json, true);
        if (!is_array($kept)) {
            return [];
        }
        $by = [];
        foreach ($kept as $x) {
            if (!CommunityText::isReaction($x['e'] ?? null)) {
                continue;
            }
            foreach ($x['by'] ?? [] as $id) {
                $id = (int) $id;
                if ($id > 0 && !isset($blocks[$id]) && isset($names[$id])) {
                    $by[$x['e']][] = ['id' => $id, 'name' => $names[$id]];
                }
            }
        }
        $out = [];
        foreach (array_keys(CommunityText::reactions()) as $e) {
            if (isset($by[$e])) {
                $out[] = ['e' => $e, 'by' => $by[$e]];
            }
        }
        return $out;
    }

    /** The server's answer to web_say as a line. @param array<string, mixed> $l */
    private static function sentLine(array $l): array
    {
        return ['lid' => (int) ($l['lid'] ?? 0), 'id' => (int) ($l['id'] ?? 0), 'name' => (string) ($l['name'] ?? ''), 'text' => (string) ($l['text'] ?? ''), 'at' => (int) ($l['at'] ?? 0), 'web' => true, 'r' => []]
            + ((int) ($l['parent'] ?? 0) > 0 ? ['parent' => (int) $l['parent']] : []);
    }

    /**
     * The threads under some lines of a room, as a reader may see them: lid => {n, last:{id,name}}
     * (replies by players they block, and by accounts that are gone, not counted).
     *
     * @param list<int> $lids
     * @param array<int, true> $blocks
     * @return array<int, array{n: int, last: array{id: int, name: string}}>
     */
    public function threadSums(string $room, array $lids, array $blocks): array
    {
        $lids = array_values(array_unique(array_filter($lids, static fn (int $l): bool => $l > 0)));
        if ($lids === []) {
            return [];
        }
        $db = $this->minn->db;
        $t = $db->table(self::LINES);
        $users = $db->table('users');
        $notBlocked = $blocks === [] ? '' : ' AND l.author_id NOT IN (' . implode(',', array_keys($blocks)) . ')';
        $in = implode(',', array_map('intval', $lids));
        $groups = $db->rows("SELECT l.parent_lid, COUNT(*) AS n, MAX(l.lid) AS last_lid FROM {$t} l JOIN {$users} u ON u.ID = l.author_id WHERE l.room = ? AND l.parent_lid IN ({$in}){$notBlocked} GROUP BY l.parent_lid", [$room]);
        if ($groups === []) {
            return [];
        }
        $last = [];
        foreach ($db->rows("SELECT lid, author_id FROM {$t} WHERE lid IN (" . implode(',', array_map(static fn (array $g): int => (int) $g['last_lid'], $groups)) . ')') as $r) {
            $last[(int) $r['lid']] = (int) $r['author_id'];
        }
        $names = (new Characters($db))->namesOf(array_values(array_unique($last)));
        $out = [];
        foreach ($groups as $g) {
            $who = $last[(int) $g['last_lid']] ?? 0;
            $out[(int) $g['parent_lid']] = ['n' => (int) $g['n'], 'last' => ['id' => $who, 'name' => (string) ($names[$who] ?? '')]];
        }
        return $out;
    }

    /** An IM line's thread, oldest first: only for one of its two players. */
    #[Route(Method::Get, '/groddle/v1/chat/ims/{with:[1-9][0-9]{0,18}}/threads/{line:[1-9][0-9]{0,18}}', policy: new Policy(Access::SignedIn))]
    public function imThread(Request $request, string $with, string $line): Response
    {
        $me = $this->player();
        $other = (int) $with;
        if ($other === $me) {
            throw new RestError('groddle_chat_self', 'You can\'t IM yourself.', 400);
        }
        $db = $this->minn->db;
        $ignores = $db->table(Buddies::IGNORES);
        if ($db->value("SELECT 1 FROM {$ignores} WHERE (user_id = ? AND ignored_id = ?) OR (user_id = ? AND ignored_id = ?) LIMIT 1", [$me, $other, $other, $me]) !== null) {
            return self::private(Response::json(['lines' => [], 'blocked' => true]));
        }
        [$a, $b] = $me < $other ? [$me, $other] : [$other, $me];
        $t = $db->table(self::IMS);
        $users = $db->table('users');
        $rows = $db->rows("SELECT i.* FROM {$t} i JOIN {$users} u ON u.ID = i.from_id WHERE i.a_id = ? AND i.b_id = ? AND i.parent_id = ? ORDER BY i.at_ms ASC, i.id ASC LIMIT 500", [$a, $b, (int) $line]);
        $names = (new Characters($db))->namesOf([$a, $b]);
        return self::private(Response::json(['lines' => array_map(static fn (array $r): array => self::imOut($r, $names), $rows), 'blocked' => false]));
    }

    // —— For the notifications panel (Notifications) ——

    /**
     * Replies under the reader's own channel lines since $sinceMs (one entry a line, newest reply
     * first), in the channels they may read on the website (open()), by players they don't block and
     * accounts that still exist: [key, label, lid, text (their line), n, people, last {id, name},
     * at (the newest reply), mine (their own newest reply in it, 0 when none)].
     *
     * @return list<array{key: string, label: string, lid: int, text: string, n: int, people: int, last: array{id: int, name: string}, at: int, mine: int}>
     */
    public function threadNotices(int $me, int $sinceMs, int $limit = 20): array
    {
        $rooms = [];
        foreach ($this->open($me) as $key => [$label, $room]) {
            $rooms[$room] = [$key, $label];
        }
        if ($rooms === []) {
            return [];
        }
        $db = $this->minn->db;
        $t = $db->table(self::LINES);
        $users = $db->table('users');
        $blocks = $this->blocks($me);
        $notBlocked = $blocks === [] ? '' : ' AND r.author_id NOT IN (' . implode(',', array_keys($blocks)) . ')';
        $rows = $db->rows("SELECT p.lid, p.room, p.text, COUNT(*) AS n, COUNT(DISTINCT r.author_id) AS people, MAX(r.lid) AS last_lid, MAX(r.at_ms) AS last_at FROM {$t} p JOIN {$t} r ON r.room = p.room AND r.parent_lid = p.lid JOIN {$users} u ON u.ID = r.author_id WHERE p.author_id = ? AND p.parent_lid = 0 AND p.room IN (?) AND r.author_id <> ? AND r.at_ms >= ?{$notBlocked} GROUP BY p.lid, p.room ORDER BY last_at DESC LIMIT " . max(1, $limit), [$me, array_keys($rooms), $me, $sinceMs]);
        if ($rows === []) {
            return [];
        }
        $lastBy = [];
        foreach ($db->rows("SELECT lid, author_id FROM {$t} WHERE lid IN (" . implode(',', array_map(static fn (array $r): int => (int) $r['last_lid'], $rows)) . ')') as $r) {
            $lastBy[(int) $r['lid']] = (int) $r['author_id'];
        }
        $names = (new Characters($db))->namesOf(array_values(array_unique($lastBy)));
        $out = [];
        foreach ($rows as $r) {
            $who = $lastBy[(int) $r['last_lid']] ?? 0;
            $mine = (int) ($db->value("SELECT MAX(at_ms) FROM {$t} WHERE room = ? AND parent_lid = ? AND author_id = ?", [$r['room'], (int) $r['lid'], $me]) ?? 0);
            [$key, $label] = $rooms[(string) $r['room']];
            $out[] = ['key' => $key, 'label' => $label, 'lid' => (int) $r['lid'], 'text' => (string) $r['text'], 'n' => (int) $r['n'], 'people' => (int) $r['people'], 'last' => ['id' => $who, 'name' => (string) ($names[$who] ?? '')], 'at' => (int) $r['last_at'], 'mine' => $mine];
        }
        return $out;
    }

    /**
     * IMs the reader has not answered (lines from the other player after the reader's own last line
     * in the conversation, thread replies too), since $sinceMs: one entry a conversation, newest
     * first, never with a player either of them blocks nor an account that is gone. Only the reader's
     * own conversations are read, as every route here: [with {id, name}, n, fresh (how many came
     * after $seen[their id], the reader's last look), text and parent of the newest, at].
     *
     * @param array<int, int> $seen other player id => ms the reader last read the conversation
     * @param int $seenAll ms the reader last read them all
     * @return list<array{with: array{id: int, name: string}, n: int, fresh: int, text: string, parent: int, at: int}>
     */
    public function imNotices(int $me, int $sinceMs, int $limit = 20, array $seen = [], int $seenAll = 0): array
    {
        $db = $this->minn->db;
        $t = $db->table(self::IMS);
        $ignores = $db->table(Buddies::IGNORES);
        $blocked = [];
        foreach ($db->rows("SELECT ignored_id AS id FROM {$ignores} WHERE user_id = ? UNION SELECT user_id AS id FROM {$ignores} WHERE ignored_id = ?", [$me, $me]) as $r) {
            $blocked[(int) $r['id']] = true;
        }
        $rows = $db->rows("SELECT a_id, b_id, MAX(CASE WHEN from_id = ? THEN at_ms ELSE 0 END) AS mine, MAX(CASE WHEN from_id <> ? THEN at_ms ELSE 0 END) AS theirs FROM {$t} WHERE (a_id = ? OR b_id = ?) AND at_ms >= ? GROUP BY a_id, b_id ORDER BY theirs DESC LIMIT " . (max(1, $limit) * 2), [$me, $me, $me, $me, $sinceMs]);
        $others = [];
        foreach ($rows as $r) {
            $other = (int) $r['a_id'] === $me ? (int) $r['b_id'] : (int) $r['a_id'];
            if ((int) $r['theirs'] > (int) $r['mine'] && !isset($blocked[$other])) {
                $others[$other] = $r;
            }
        }
        $names = $others === [] ? [] : (new Characters($db))->namesOf(array_keys($others));
        $out = [];
        foreach ($others as $other => $r) {
            if (!isset($names[$other]) || count($out) >= $limit) {
                continue;
            }
            $after = max((int) $r['mine'], $sinceMs - 1);
            $n = (int) $db->value("SELECT COUNT(*) FROM {$t} WHERE a_id = ? AND b_id = ? AND from_id = ? AND at_ms > ?", [(int) $r['a_id'], (int) $r['b_id'], $other, $after]);
            $last = $db->row("SELECT text, parent_id, at_ms FROM {$t} WHERE a_id = ? AND b_id = ? AND from_id = ? ORDER BY at_ms DESC, id DESC LIMIT 1", [(int) $r['a_id'], (int) $r['b_id'], $other]);
            if ($last === null || $n < 1) {
                continue;
            }
            $look = max($seen[$other] ?? 0, $seenAll);
            $fresh = $look > $after
                ? (int) $db->value("SELECT COUNT(*) FROM {$t} WHERE a_id = ? AND b_id = ? AND from_id = ? AND at_ms > ?", [(int) $r['a_id'], (int) $r['b_id'], $other, $look])
                : $n;
            $out[] = ['with' => ['id' => $other, 'name' => $names[$other]], 'n' => $n, 'fresh' => $fresh, 'text' => (string) $last['text'], 'parent' => (int) $last['parent_id'], 'at' => (int) $last['at_ms']];
        }
        return $out;
    }

    private static function private(Response $r): Response
    {
        return $r->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Robots-Tag', 'noindex');
    }
}
