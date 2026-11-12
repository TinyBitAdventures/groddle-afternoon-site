<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Auth\Capabilities;
use Minn\Auth\Nonce;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * The Community boards (Austin 2026-11-04: "threaded longer form
 * conversations ... a true community for the game"): six boards of threads,
 * public to read, written by players. Glitch had the same at
 * glitchthegame.com/forum/ (CommunityText::BOARDS). The pages are
 * CommunityPages (/chat/); these are their data and the routes their script
 * (community/community.js) and the game call.
 *
 *   GET  community/boards                         {"boards":[Board]}
 *   GET  community/boards/{board}?page=n          {"board":Board,"threads":[Thread],"page","pages"}
 *   GET  community/threads/{id}?page=n            {"thread":Thread,"posts":[Post],"page","pages"}
 *   GET  community/threads/{id}/updates?after=<post id>&since=<ms>
 *        {"posts":[{"id","html","new":bool}],"thread":{status,locked,pinned,posts},"now":ms,"more":bool}
 *   POST community/boards/{board}/threads {"title","body"}   201 {"thread":Thread,"post":Post,"url"}
 *   POST community/threads/{id}/posts {"body","quote"?}      201 {"post":Post,"html","url"}
 *   PUT  community/posts/{id} {"body","title"?}               {"post":Post,"html"}  (the writer, EDIT_WINDOW)
 *   DELETE community/posts/{id}                               {"post":Post,"html"}  (the writer: a soft delete)
 *   POST community/posts/{id}/reactions {"e","on"}            {"reactions":[...],"html"}
 *   POST community/posts/{id}/report {"why"}                  201 {"status":"reported","ticket"} | 200 {"status":"already"}
 *   GET  community/notices                                    {"unread":n,"notices":[Notice]}
 *   POST community/notices/read {"thread"?}                   {"unread":n}
 *   POST community/notices/game                               {"notices":[Notice]}  (the game's Activity: each once)
 *
 * Reads are open to everyone (a signed-in reader with the REST nonce gets
 * their own view: blocked players' posts folded away, their reactions
 * marked, Edit and Delete on their own posts). Writes need a player (a
 * character in groddle_players; administrators too), not suspended
 * (AdminConsole bans) and not stopped from posting (groddle_community_bans,
 * set from the console, AdminCommunity), under the limits in CommunityText
 * (posts a minute, an hour and a day; tighter for a character less than a day
 * old; threads on top; the same words twice in a thread; reactions a
 * minute). Words go through the word filter (WordFilter) when written.
 *
 * Moderation from day one: Report on every post makes a ticket (TicketStore,
 * the console's Reports tab) under the bug report limits, once a player a
 * post (groddle_community_reports); the console's Community tab
 * (AdminCommunity) removes and restores posts, pins and locks threads, and
 * stops a player posting for a while. Nothing is ever hard deleted: a
 * writer's delete and a removal are statuses, and an edit keeps the earlier
 * words in groddle_community_edits.
 *
 * Notices: a reply to your thread, or a reply that quotes you, makes a notice
 * for you (never for your own posts, nor from a player you block): the
 * Community link in the header carries how many are unread (SiteNav), the
 * game's Activity shows each once (POST notices/game, the game's
 * features/community-mp-feature.js), and opening the thread reads them.
 * Replies to one thread you have not read yet are one notice with a count.
 */
final readonly class Community
{
    public const BOARDS_T = 'groddle_community_boards';
    public const THREADS = 'groddle_community_threads';
    public const POSTS = 'groddle_community_posts';
    public const REACTIONS = 'groddle_community_reactions';
    public const REPORTS = 'groddle_community_reports';
    public const EDITS = 'groddle_community_edits';
    public const BANS = 'groddle_community_bans';
    public const NOTICES = 'groddle_community_notices';

    /** The game's Activity shows notices at most this old (seconds). */
    public const GAME_NOTICE_FOR = 7 * 86400;

    /** Notices a list carries. */
    public const NOTICES_SHOWN = 20;

    private const ADMIN = 'manage_options';

    public function __construct(private Seams $minn)
    {
    }

    // —— Reading ——

    #[Route(Method::Get, '/groddle/v1/community/boards', policy: new Policy(Access::Public))]
    public function boardsRoute(Request $request): Response
    {
        return self::fresh(Response::json(['boards' => array_map([self::class, 'boardOut'], $this->boards())]));
    }

    #[Route(Method::Get, '/groddle/v1/community/boards/{board:[a-z-]+}', policy: new Policy(Access::Public))]
    public function boardRoute(Request $request, string $board): Response
    {
        $row = $this->board($board) ?? throw new RestError('groddle_community_no_board', CommunityText::said('groddle_community_no_board'), 404);
        [$threads, $page, $pages] = $this->threads($board, (int) ($request->query('page') ?? '1'));
        return self::fresh(Response::json(['board' => self::boardOut($row), 'threads' => array_map([self::class, 'threadOut'], $threads), 'page' => $page, 'pages' => $pages]));
    }

    #[Route(Method::Get, '/groddle/v1/community/threads/{id:\d+}', policy: new Policy(Access::Public))]
    public function threadRoute(Request $request, string $id): Response
    {
        $viewer = $this->viewer();
        $thread = $this->thread((int) $id, $viewer) ?? throw new RestError('groddle_community_no_thread', CommunityText::said('groddle_community_no_thread'), 404);
        [$posts, $page, $pages] = $this->posts($thread, (int) ($request->query('page') ?? '1'), $viewer);
        return self::fresh(Response::json(['thread' => self::threadOut($thread), 'posts' => array_map(static fn (array $p): array => self::postOut($p, $viewer), $posts), 'page' => $page, 'pages' => $pages]));
    }

    /**
     * What changed in a thread since the page drew it: posts after the last
     * one it has, and posts changed (edited, deleted, removed, restored,
     * reacted to) since its clock, drawn for the reader.
     */
    #[Route(Method::Get, '/groddle/v1/community/threads/{id:\d+}/updates', policy: new Policy(Access::Public))]
    public function updates(Request $request, string $id): Response
    {
        $viewer = $this->viewer();
        $thread = $this->thread((int) $id, $viewer) ?? throw new RestError('groddle_community_no_thread', CommunityText::said('groddle_community_no_thread'), 404);
        $after = max(0, (int) ($request->query('after') ?? '0'));
        $since = max(0, (int) ($request->query('since') ?? '0'));
        $now = self::ms();
        $db = $this->minn->db;
        $t = $db->table(self::POSTS);
        $new = $db->rows("SELECT * FROM {$t} WHERE thread_id = ? AND id > ? ORDER BY id LIMIT " . (CommunityText::POSTS_PAGE + 1), [$thread['id'], $after]);
        $more = count($new) > CommunityText::POSTS_PAGE;
        $new = array_slice($new, 0, CommunityText::POSTS_PAGE);
        $changed = $since > 0 ? $db->rows("SELECT * FROM {$t} WHERE thread_id = ? AND id <= ? AND changed_ms > ? ORDER BY id LIMIT 100", [$thread['id'], $after, $since]) : [];
        $rows = [...$changed, ...$new];
        $drawn = $this->decorate($rows, $viewer);
        $out = [];
        foreach ($drawn as $p) {
            $out[] = ['id' => (int) $p['id'], 'new' => (int) $p['id'] > $after, 'html' => CommunityPages::postHtml($p, $thread, $viewer)];
        }
        if ($viewer['id'] > 0 && $new !== []) {
            $this->readThread($viewer['id'], (int) $thread['id']);
        }
        return self::fresh(Response::json([
            'posts' => $out,
            'thread' => ['status' => $thread['status'], 'locked' => (bool) $thread['locked'], 'pinned' => (bool) $thread['pinned'], 'posts' => (int) $thread['posts']],
            'now' => $now,
            'more' => $more,
        ]));
    }

    // —— Writing ——

    #[Route(Method::Post, '/groddle/v1/community/boards/{board:[a-z-]+}/threads', policy: new Policy(Access::SignedIn))]
    public function createRoute(Request $request, string $board): Response
    {
        $body = $request->json();
        [$thread, $post] = $this->createThread($this->viewer(), $board, $body['title'] ?? '', $body['body'] ?? '');
        $url = CommunityText::threadPath($thread['board'], (int) $thread['id'], $thread['title']);
        return Response::json(['thread' => self::threadOut($thread), 'post' => self::postOut($post, $this->viewer()), 'url' => $url], 201);
    }

    #[Route(Method::Post, '/groddle/v1/community/threads/{id:\d+}/posts', policy: new Policy(Access::SignedIn))]
    public function replyRoute(Request $request, string $id): Response
    {
        $viewer = $this->viewer();
        $body = $request->json();
        [$thread, $post, $url] = $this->reply($viewer, (int) $id, $body['body'] ?? '', (int) ($body['quote'] ?? 0));
        $drawn = $this->decorate([$post], $viewer)[0];
        return Response::json(['post' => self::postOut($drawn, $viewer), 'html' => CommunityPages::postHtml($drawn, $thread, $viewer), 'url' => $url], 201);
    }

    #[Route(Method::Put, '/groddle/v1/community/posts/{id:\d+}', policy: new Policy(Access::SignedIn))]
    public function editRoute(Request $request, string $id): Response
    {
        $viewer = $this->viewer();
        $body = $request->json();
        $post = $this->edit($viewer, (int) $id, $body['body'] ?? '', $body['title'] ?? null);
        return $this->postAnswer($post, $viewer);
    }

    #[Route(Method::Delete, '/groddle/v1/community/posts/{id:\d+}', policy: new Policy(Access::SignedIn))]
    public function deleteRoute(Request $request, string $id): Response
    {
        $viewer = $this->viewer();
        return $this->postAnswer($this->delete($viewer, (int) $id), $viewer);
    }

    #[Route(Method::Post, '/groddle/v1/community/posts/{id:\d+}/reactions', policy: new Policy(Access::SignedIn))]
    public function reactRoute(Request $request, string $id): Response
    {
        $viewer = $this->viewer();
        $body = $request->json();
        $post = $this->react($viewer, (int) $id, $body['e'] ?? null, ($body['on'] ?? true) !== false);
        $drawn = $this->decorate([$post], $viewer)[0];
        return Response::json(['reactions' => $drawn['reactions'], 'html' => CommunityPages::reactionsHtml($drawn, $viewer)]);
    }

    #[Route(Method::Post, '/groddle/v1/community/posts/{id:\d+}/report', policy: new Policy(Access::SignedIn))]
    public function reportRoute(Request $request, string $id): Response
    {
        $ticket = $this->report($this->viewer(), (int) $id, $request->json()['why'] ?? '');
        return $ticket === 0 ? Response::json(['status' => 'already']) : Response::json(['status' => 'reported', 'ticket' => $ticket], 201);
    }

    // —— Notices ——

    #[Route(Method::Get, '/groddle/v1/community/notices', policy: new Policy(Access::SignedIn))]
    public function noticesRoute(Request $request): Response
    {
        $me = $this->viewer()['id'];
        return self::fresh(Response::json(['unread' => $this->unread($me), 'notices' => $this->notices($me, false)]));
    }

    #[Route(Method::Post, '/groddle/v1/community/notices/read', policy: new Policy(Access::SignedIn))]
    public function readRoute(Request $request): Response
    {
        $me = $this->viewer()['id'];
        $thread = (int) ($request->json()['thread'] ?? 0);
        $db = $this->minn->db;
        if ($thread > 0) {
            $this->readThread($me, $thread);
        } else {
            $db->execute('UPDATE ' . $db->table(self::NOTICES) . ' SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [gmdate('Y-m-d H:i:s'), $me]);
        }
        return self::fresh(Response::json(['unread' => $this->unread($me)]));
    }

    /** The game's Activity: unread notices it has not shown, each handed over once. */
    #[Route(Method::Post, '/groddle/v1/community/notices/game', policy: new Policy(Access::SignedIn))]
    public function gameRoute(Request $request): Response
    {
        $me = $this->viewer()['id'];
        $list = $this->notices($me, true);
        if ($list !== []) {
            $db = $this->minn->db;
            $db->execute('UPDATE ' . $db->table(self::NOTICES) . ' SET game_at = ? WHERE user_id = ? AND id IN (?)', [gmdate('Y-m-d H:i:s'), $me, array_column($list, 'id')]);
        }
        return self::fresh(Response::json(['notices' => $list]));
    }

    // —— The reader ——

    /**
     * Who is reading: id (0 signed out), name, player (has a character),
     * admin, their blocks (ids they block), and a REST nonce for the page's
     * script.
     *
     * @return array{id: int, name: string, player: bool, admin: bool, blocks: array<int, true>, nonce: string, homeHosts: list<string>}
     */
    public function viewer(): array
    {
        $hosts = CommunityText::homeHosts($this->minn->request->host);
        $user = $this->minn->user();
        if ($user === null) {
            return ['id' => 0, 'name' => '', 'player' => false, 'admin' => false, 'blocks' => [], 'nonce' => '', 'homeHosts' => $hosts];
        }
        $db = $this->minn->db;
        $blocks = [];
        foreach ($db->rows('SELECT ignored_id FROM ' . $db->table(Buddies::IGNORES) . ' WHERE user_id = ?', [$user->id]) as $r) {
            $blocks[(int) $r['ignored_id']] = true;
        }
        $reader = $this->minn->reader;
        return [
            'id' => $user->id,
            'name' => (new Characters($db))->nameOf($user),
            'player' => $db->value('SELECT 1 FROM ' . $db->table(Characters::TABLE) . ' WHERE user_id = ?', [$user->id]) !== null,
            'admin' => Capabilities::fromDb($db)->can($user->id, self::ADMIN),
            'blocks' => $blocks,
            'nonce' => $reader->userId === $user->id && $reader->sessionToken !== '' ? Nonce::create($user->id, $reader->sessionToken) : '',
            'homeHosts' => $hosts,
        ];
    }

    // —— Boards and threads ——

    /** The six boards, seeded once from CommunityText::BOARDS, each with its counts and newest thread. @return list<array<string, mixed>> */
    public function boards(): array
    {
        $db = $this->minn->db;
        $b = $db->table(self::BOARDS_T);
        $rows = $db->rows("SELECT slug, name, about, position FROM {$b} ORDER BY position, slug");
        if (count($rows) < count(CommunityText::BOARDS)) {
            $this->seedBoards();
            $rows = $db->rows("SELECT slug, name, about, position FROM {$b} ORDER BY position, slug");
        }
        $t = $db->table(self::THREADS);
        $stats = [];
        foreach ($db->rows("SELECT board, COUNT(*) AS threads, SUM(posts) AS posts, MAX(last_post_at) AS last_at FROM {$t} WHERE status = 'open' GROUP BY board") as $r) {
            $stats[(string) $r['board']] = $r;
        }
        $latest = [];
        foreach ($rows as $row) {
            $slug = (string) $row['slug'];
            if (isset($stats[$slug])) {
                $latest[$slug] = $db->row("SELECT id, board, title, last_author_id, last_post_at, last_post_id FROM {$t} WHERE board = ? AND status = 'open' ORDER BY last_post_at DESC, id DESC LIMIT 1", [$slug]);
            }
        }
        $ids = array_values(array_filter(array_map(static fn (?array $r): int => (int) ($r['last_author_id'] ?? 0), $latest)));
        $names = $ids === [] ? [] : (new Characters($db))->namesOf($ids);
        $out = [];
        foreach ($rows as $row) {
            $slug = (string) $row['slug'];
            if (!CommunityText::isBoard($slug)) {
                continue;
            }
            $l = $latest[$slug] ?? null;
            $out[] = [
                'slug' => $slug,
                'name' => (string) $row['name'],
                'about' => (string) $row['about'],
                'threads' => (int) ($stats[$slug]['threads'] ?? 0),
                'posts' => (int) ($stats[$slug]['posts'] ?? 0),
                'latest' => $l === null ? null : [
                    'id' => (int) $l['id'],
                    'title' => (string) $l['title'],
                    'url' => CommunityText::threadPath($slug, (int) $l['id'], (string) $l['title']) . '#post-' . (int) $l['last_post_id'],
                    'by' => $names[(int) $l['last_author_id']] ?? '',
                    'at' => self::unix((string) $l['last_post_at']),
                ],
            ];
        }
        return $out;
    }

    /** One board's row, or null. @return array<string, mixed>|null */
    public function board(string $slug): ?array
    {
        if (!CommunityText::isBoard($slug)) {
            return null;
        }
        foreach ($this->boards() as $b) {
            if ($b['slug'] === $slug) {
                return $b;
            }
        }
        return null;
    }

    private function seedBoards(): void
    {
        $db = $this->minn->db;
        $b = $db->table(self::BOARDS_T);
        $i = 0;
        foreach (CommunityText::BOARDS as $slug => [$name, $about]) {
            $db->execute("INSERT IGNORE INTO {$b} (slug, name, about, position, created_at) VALUES (?, ?, ?, ?, ?)", [$slug, $name, $about, $i++, gmdate('Y-m-d H:i:s')]);
        }
    }

    /**
     * A page of a board's open threads, pinned first, then newest activity.
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    public function threads(string $board, int $page): array
    {
        $db = $this->minn->db;
        $t = $db->table(self::THREADS);
        $total = (int) $db->value("SELECT COUNT(*) FROM {$t} WHERE board = ? AND status = 'open'", [$board]);
        $pages = max(1, (int) ceil($total / CommunityText::THREADS_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $db->rows("SELECT * FROM {$t} WHERE board = ? AND status = 'open' ORDER BY pinned DESC, last_post_at DESC, id DESC LIMIT ? OFFSET ?", [$board, CommunityText::THREADS_PAGE, ($page - 1) * CommunityText::THREADS_PAGE]);
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['author_id'];
            $ids[] = (int) $r['last_author_id'];
        }
        $names = (new Characters($db))->namesOf(array_values(array_unique(array_filter($ids))));
        // The newest post's first words, for the topic's row (/chat/ shows topics as the Chats list does).
        $lastIds = array_values(array_filter(array_map(static fn (array $r): int => (int) ($r['last_post_id'] ?? 0), $rows)));
        $snips = [];
        if ($lastIds !== []) {
            foreach ($db->rows('SELECT id, body FROM ' . $db->table(self::POSTS) . " WHERE status = 'shown' AND id IN (" . implode(',', $lastIds) . ')') as $p) {
                $snips[(int) $p['id']] = CommunityText::snippet(CommunityText::plain((string) $p['body']), 90);
            }
        }
        foreach ($rows as &$r) {
            $r['author_name'] = $names[(int) $r['author_id']] ?? '';
            $r['last_author_name'] = $names[(int) $r['last_author_id']] ?? '';
            $r['last_snippet'] = $snips[(int) ($r['last_post_id'] ?? 0)] ?? '';
        }
        unset($r);
        return [$rows, $page, $pages];
    }

    /**
     * A thread a reader may open, with its author's and board's names; null
     * when there is none, or it was removed (administrators still open it) or
     * deleted.
     *
     * @param array<string, mixed>|null $viewer
     * @return array<string, mixed>|null
     */
    public function thread(int $id, ?array $viewer = null): ?array
    {
        $db = $this->minn->db;
        $row = $db->row('SELECT * FROM ' . $db->table(self::THREADS) . ' WHERE id = ?', [$id]);
        if ($row === null || !CommunityText::isBoard($row['board'])) {
            return null;
        }
        if ($row['status'] !== 'open' && !($viewer['admin'] ?? false)) {
            return null;
        }
        $row['author_name'] = (new Characters($db))->namesOf([(int) $row['author_id']])[(int) $row['author_id']] ?? '';
        $row['board_name'] = CommunityText::BOARDS[$row['board']][0];
        return $row;
    }

    /**
     * A page of a thread's posts in order (every status: a deleted or removed
     * post keeps its place), decorated for the reader.
     *
     * @param array<string, mixed> $thread
     * @param array<string, mixed> $viewer
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    public function posts(array $thread, int $page, array $viewer): array
    {
        $db = $this->minn->db;
        $t = $db->table(self::POSTS);
        $total = (int) $db->value("SELECT COUNT(*) FROM {$t} WHERE thread_id = ?", [$thread['id']]);
        $pages = max(1, (int) ceil($total / CommunityText::POSTS_PAGE));
        $page = $page < 1 ? $pages : min($page, $pages);
        $rows = $db->rows("SELECT * FROM {$t} WHERE thread_id = ? ORDER BY id LIMIT ? OFFSET ?", [$thread['id'], CommunityText::POSTS_PAGE, ($page - 1) * CommunityText::POSTS_PAGE]);
        return [$this->decorate($rows, $viewer), $page, $pages];
    }

    /** Which page of its thread a post is on. */
    public function pageOf(int $thread, int $post): int
    {
        $db = $this->minn->db;
        $n = (int) $db->value('SELECT COUNT(*) FROM ' . $db->table(self::POSTS) . ' WHERE thread_id = ? AND id <= ?', [$thread, $post]);
        return max(1, (int) ceil($n / CommunityText::POSTS_PAGE));
    }

    /**
     * Posts with what drawing them needs: the writer (name now, profile path
     * when their profile is public, tile picture, staff), the quoted post,
     * the reactions (as the reader sees them: none from players they block),
     * and whether the reader blocks the writer.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $viewer
     * @return list<array<string, mixed>>
     */
    public function decorate(array $rows, array $viewer): array
    {
        if ($rows === []) {
            return [];
        }
        $db = $this->minn->db;
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $quoteIds = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) $r['quote_id'], $rows))));
        $quotes = [];
        if ($quoteIds !== []) {
            foreach ($db->rows('SELECT id, thread_id, author_id, author_name, body, status FROM ' . $db->table(self::POSTS) . ' WHERE id IN (?)', [$quoteIds]) as $q) {
                $quotes[(int) $q['id']] = $q;
            }
        }
        $reactions = [];
        foreach ($db->rows('SELECT post_id, reaction, user_id FROM ' . $db->table(self::REACTIONS) . ' WHERE post_id IN (?) ORDER BY created_at, user_id', [$ids]) as $r) {
            $reactions[(int) $r['post_id']][] = $r;
        }
        $people = [];
        foreach ($rows as $r) {
            $people[] = (int) $r['author_id'];
        }
        foreach ($quotes as $q) {
            $people[] = (int) $q['author_id'];
        }
        foreach ($reactions as $list) {
            foreach ($list as $r) {
                $people[] = (int) $r['user_id'];
            }
        }
        $people = array_values(array_unique(array_filter($people)));
        $names = (new Characters($db))->namesOf($people);
        $authors = $this->authors(array_values(array_unique(array_map(static fn (array $r): int => (int) $r['author_id'], $rows))));
        $blocks = $viewer['blocks'] ?? [];
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $author = (int) $r['author_id'];
            $r['name'] = $names[$author] ?? (string) $r['author_name'];
            $r['author'] = $authors[$author] ?? ['profile' => null, 'tile' => null, 'staff' => false];
            $r['blocked'] = isset($blocks[$author]);
            $r['mine'] = ($viewer['id'] ?? 0) > 0 && $author === $viewer['id'];
            $q = $quotes[(int) $r['quote_id']] ?? null;
            $r['quote'] = $q === null || (int) $q['thread_id'] !== (int) $r['thread_id'] ? null : [
                'id' => (int) $q['id'],
                'name' => $names[(int) $q['author_id']] ?? (string) $q['author_name'],
                'text' => $q['status'] === 'shown' && !isset($blocks[(int) $q['author_id']]) ? CommunityText::quoteText(CommunityText::plain((string) $q['body'])) : '',
                'gone' => $q['status'] !== 'shown',
                'blocked' => isset($blocks[(int) $q['author_id']]),
            ];
            $by = [];
            foreach ($reactions[$id] ?? [] as $x) {
                $who = (int) $x['user_id'];
                if (isset($blocks[$who]) || !CommunityText::isReaction($x['reaction'])) {
                    continue;
                }
                $by[(string) $x['reaction']][] = ['id' => $who, 'name' => $names[$who] ?? ''];
            }
            $list = [];
            foreach (array_keys(CommunityText::reactions()) as $key) {
                if (isset($by[$key])) {
                    $list[] = ['e' => $key, 'by' => $by[$key], 'mine' => in_array($viewer['id'] ?? 0, array_column($by[$key], 'id'), true)];
                }
            }
            $r['reactions'] = $list;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Writers as posts show them: their profile's path when it is public
     * (level Profiles::LISTED_LEVEL and up), their tile picture's address
     * then, and whether they are staff (administrators).
     *
     * @param list<int> $ids
     * @return array<int, array{profile: ?string, tile: ?string, staff: bool}>
     */
    public function authors(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $db = $this->minn->db;
        $levels = [];
        foreach ($db->rows('SELECT s.user_id, ' . Profiles::levelSql('s') . ' AS level, p.avatar_at FROM ' . $db->table(MultiplayerSave::TABLE) . ' s LEFT JOIN ' . $db->table(Profiles::TABLE) . ' p ON p.user_id = s.user_id WHERE s.user_id IN (?)', [$ids]) as $r) {
            $levels[(int) $r['user_id']] = [(int) $r['level'], is_string($r['avatar_at'] ?? null) ? $r['avatar_at'] : null];
        }
        $slugs = Profiles::slugs($db);
        $caps = Capabilities::fromDb($db);
        $out = [];
        foreach ($ids as $id) {
            [$level, $at] = $levels[$id] ?? [0, null];
            $public = $level >= Profiles::LISTED_LEVEL && isset($slugs[$id]);
            $out[$id] = [
                'profile' => $public ? '/players/' . $slugs[$id] . '/' : null,
                'tile' => $public && $at !== null ? Profiles::imageUrl($id, 'tile', $at) : null,
                'staff' => $caps->can($id, self::ADMIN),
            ];
        }
        return $out;
    }

    // —— Posting ——

    /**
     * Why the reader may not post now (a CommunityText::SAID code), or null.
     * $thread: whether it would start a thread (the thread limits count too).
     *
     * @param array<string, mixed> $viewer
     */
    public function postRefusal(array $viewer, bool $thread, ?int $now = null): ?string
    {
        if ($viewer['id'] <= 0) {
            return 'groddle_community_signed_out';
        }
        $db = $this->minn->db;
        if (AdminConsole::banned($db, $viewer['id']) !== null) {
            return 'groddle_community_suspended';
        }
        if (!$viewer['player'] && !$viewer['admin']) {
            return 'groddle_community_not_player';
        }
        if ($this->stopped($viewer['id']) !== null) {
            return 'groddle_community_banned';
        }
        if ($viewer['admin']) {
            return null;
        }
        $now ??= time();
        $created = $db->value('SELECT created_at FROM ' . $db->table(Characters::TABLE) . ' WHERE user_id = ?', [$viewer['id']]);
        $new = is_string($created) && $now - self::unix($created) < CommunityText::NEW_FOR;
        $counts = [];
        $threads = [];
        $p = $db->table(self::POSTS);
        $t = $db->table(self::THREADS);
        foreach (CommunityText::windows() as $s) {
            $since = gmdate('Y-m-d H:i:s', $now - $s);
            $counts[$s] = (int) $db->value("SELECT COUNT(*) FROM {$p} WHERE author_id = ? AND created_at >= ?", [$viewer['id'], $since]);
            if ($thread) {
                $threads[$s] = (int) $db->value("SELECT COUNT(*) FROM {$t} WHERE author_id = ? AND created_at >= ?", [$viewer['id'], $since]);
            }
        }
        return CommunityText::limitRefusal($new, $counts, $thread ? $threads : null);
    }

    /**
     * When a player's stop from posting (AdminCommunity) ends: a unix time,
     * 0 for until lifted, or null when they are not stopped.
     */
    public function stopped(int $user): ?int
    {
        $db = $this->minn->db;
        // A stop covers the login (Logins): any character of it stopped stops them all, until the longest ends.
        $longest = null;
        foreach ($db->rows('SELECT until FROM ' . $db->table(self::BANS) . ' WHERE user_id IN (?)', [(new Logins($db))->siblings($user)]) as $row) {
            if ($row['until'] === null) {
                return 0;
            }
            $until = self::unix((string) $row['until']);
            $longest = $until > time() && $until > (int) $longest ? $until : $longest;
        }
        return $longest;
    }

    /**
     * Starts a thread: [thread, its first post].
     *
     * @param array<string, mixed> $viewer
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function createThread(array $viewer, string $board, mixed $title, mixed $body): array
    {
        if (!CommunityText::isBoard($board)) {
            throw self::refuse('groddle_community_no_board', 404);
        }
        $this->mayPost($viewer, true);
        $title = CommunityText::title($title);
        $body = CommunityText::body($body);
        if (($code = CommunityText::titleRefusal($title) ?? CommunityText::bodyRefusal($body)) !== null) {
            throw self::refuse($code, 400);
        }
        $db = $this->minn->db;
        $t = $db->table(self::THREADS);
        $p = $db->table(self::POSTS);
        // The same title from the same writer in the last ten minutes: the first one again (a double click).
        $again = $db->value("SELECT id FROM {$t} WHERE author_id = ? AND title = ? AND created_at >= ? AND status = 'open' ORDER BY id DESC LIMIT 1", [$viewer['id'], $title, gmdate('Y-m-d H:i:s', time() - CommunityText::SAME_AGAIN)]);
        if ($again !== null) {
            throw self::refuse('groddle_community_same', 409);
        }
        $now = gmdate('Y-m-d H:i:s');
        $ms = self::ms();
        [$threadId, $postId] = Ledger::atomically($db, function () use ($db, $t, $p, $board, $viewer, $title, $body, $now, $ms): array {
            $db->execute("INSERT INTO {$t} (board, author_id, title, status, created_at, last_post_at) VALUES (?, ?, ?, 'open', ?, ?)", [$board, $viewer['id'], $title, $now, $now]);
            $threadId = $db->insertId();
            $db->execute("INSERT INTO {$p} (thread_id, author_id, author_name, body, status, created_at, changed_ms) VALUES (?, ?, ?, ?, 'shown', ?, ?)", [$threadId, $viewer['id'], mb_substr($viewer['name'], 0, 80), $body, $now, $ms]);
            $postId = $db->insertId();
            $db->execute("UPDATE {$t} SET first_post_id = ? WHERE id = ?", [$postId, $threadId]);
            return [$threadId, $postId];
        });
        $this->refreshThread($threadId);
        $this->live($board, $threadId, $postId, (int) $viewer['id'], 'thread');
        return [$this->thread($threadId, $viewer) ?? [], $this->post($postId) ?? []];
    }

    /**
     * A reply: [thread, post, its address]. $quote: a post in the same thread it quotes (0: none).
     *
     * @param array<string, mixed> $viewer
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
     */
    public function reply(array $viewer, int $threadId, mixed $body, int $quote = 0): array
    {
        $thread = $this->thread($threadId, $viewer);
        if ($thread === null) {
            throw self::refuse('groddle_community_no_thread', 404);
        }
        $this->mayPost($viewer, false);
        if ((int) $thread['locked'] === 1 && !$viewer['admin']) {
            throw self::refuse('groddle_community_locked', 409);
        }
        $body = CommunityText::body($body);
        if (($code = CommunityText::bodyRefusal($body)) !== null) {
            throw self::refuse($code, 400);
        }
        $db = $this->minn->db;
        $p = $db->table(self::POSTS);
        $now = time();
        if ($db->value("SELECT 1 FROM {$p} WHERE thread_id = ? AND author_id = ? AND body = ? AND created_at >= ? LIMIT 1", [$threadId, $viewer['id'], $body, gmdate('Y-m-d H:i:s', $now - CommunityText::SAME_AGAIN)]) !== null) {
            throw self::refuse('groddle_community_same', 409);
        }
        $quoted = null;
        if ($quote > 0) {
            $quoted = $db->row("SELECT id, author_id, status FROM {$p} WHERE id = ? AND thread_id = ?", [$quote, $threadId]);
            if ($quoted === null || $quoted['status'] !== 'shown') {
                $quoted = null;
            }
        }
        $db->execute("INSERT INTO {$p} (thread_id, author_id, author_name, body, quote_id, status, created_at, changed_ms) VALUES (?, ?, ?, ?, ?, 'shown', ?, ?)", [$threadId, $viewer['id'], mb_substr($viewer['name'], 0, 80), $body, $quoted === null ? 0 : (int) $quoted['id'], gmdate('Y-m-d H:i:s', $now), self::ms()]);
        $postId = $db->insertId();
        $this->refreshThread($threadId);
        $this->notify($viewer['id'], $thread, $postId, $quoted === null ? 0 : (int) $quoted['author_id']);
        $this->live((string) $thread['board'], $threadId, $postId, (int) $viewer['id'], 'reply');
        $post = $this->post($postId) ?? [];
        $url = CommunityText::threadPath($thread['board'], $threadId, $thread['title']);
        $page = $this->pageOf($threadId, $postId);
        return [$this->thread($threadId, $viewer) ?? $thread, $post, $url . ($page > 1 ? "?page={$page}" : '') . "#post-{$postId}"];
    }

    /**
     * Tells the realtime server a post is new (its signed community_note, web.go), so signed-in
     * readers on /chat/ see it at once; best effort, a short wait: the post stands either way and
     * the pages still poll when the socket is down.
     */
    private function live(string $board, int $thread, int $post, int $author, string $kind): void
    {
        (new RealtimeCall($this->minn))->post('community_note', ['board' => $board, 'thread' => $thread, 'post' => $post, 'author' => $author, 'kind' => $kind], 0.8);
    }

    /**
     * The writer's edit, within EDIT_WINDOW of posting: the words (and the
     * thread's title, for its first post). The earlier words are kept.
     *
     * @param array<string, mixed> $viewer
     * @return array<string, mixed>
     */
    public function edit(array $viewer, int $id, mixed $body, mixed $title = null): array
    {
        $post = $this->ownPost($viewer, $id);
        if (!CommunityText::editable(self::unix((string) $post['created_at']), time())) {
            throw self::refuse('groddle_community_too_late', 409);
        }
        if (($code = $this->postRefusal($viewer, false)) !== null && !in_array($code, ['groddle_community_slow', 'groddle_community_new_slow'], true)) {
            throw self::refuse($code, 403);
        }
        $body = CommunityText::body($body);
        if (($code = CommunityText::bodyRefusal($body)) !== null) {
            throw self::refuse($code, 400);
        }
        $db = $this->minn->db;
        $thread = $db->row('SELECT id, first_post_id, title FROM ' . $db->table(self::THREADS) . ' WHERE id = ?', [$post['thread_id']]);
        $newTitle = null;
        if ($title !== null && $thread !== null && (int) $thread['first_post_id'] === $id) {
            $newTitle = CommunityText::title($title);
            if (($code = CommunityText::titleRefusal($newTitle)) !== null) {
                throw self::refuse($code, 400);
            }
            if ($newTitle === (string) $thread['title']) {
                $newTitle = null;
            }
        }
        if ($body === (string) $post['body'] && $newTitle === null) {
            return $post;
        }
        $now = gmdate('Y-m-d H:i:s');
        Ledger::atomically($db, function () use ($db, $post, $id, $viewer, $body, $now, $newTitle, $thread): void {
            $db->execute('INSERT INTO ' . $db->table(self::EDITS) . ' (post_id, editor_id, body, edited_at) VALUES (?, ?, ?, ?)', [$id, $viewer['id'], $newTitle === null ? (string) $post['body'] : 'Title: ' . $thread['title'] . "\n\n" . $post['body'], $now]);
            $db->execute('UPDATE ' . $db->table(self::POSTS) . ' SET body = ?, edited_at = ?, changed_ms = ? WHERE id = ?', [$body, $now, self::ms(), $id]);
            if ($newTitle !== null) {
                $db->execute('UPDATE ' . $db->table(self::THREADS) . ' SET title = ? WHERE id = ?', [$newTitle, $thread['id']]);
            }
        });
        return $this->post($id) ?? $post;
    }

    /**
     * The writer takes their post down (it stays, marked deleted; a thread
     * whose only post this was leaves its board).
     *
     * @param array<string, mixed> $viewer
     * @return array<string, mixed>
     */
    public function delete(array $viewer, int $id): array
    {
        $post = $this->ownPost($viewer, $id);
        $db = $this->minn->db;
        $db->execute("UPDATE " . $db->table(self::POSTS) . " SET status = 'deleted', changed_ms = ? WHERE id = ? AND status = 'shown'", [self::ms(), $id]);
        $this->refreshThread((int) $post['thread_id']);
        return $this->post($id) ?? $post;
    }

    /**
     * A reaction on (or off) a post.
     *
     * @param array<string, mixed> $viewer
     * @return array<string, mixed> the post
     */
    public function react(array $viewer, int $id, mixed $key, bool $on): array
    {
        if (!CommunityText::isReaction($key)) {
            throw self::refuse('groddle_community_reaction', 400);
        }
        $post = $this->post($id);
        if ($post === null || $post['status'] !== 'shown' || $this->thread((int) $post['thread_id'], $viewer) === null) {
            throw self::refuse('groddle_community_gone', 404);
        }
        if (($code = $this->postRefusal($viewer, false)) !== null && !in_array($code, ['groddle_community_slow', 'groddle_community_new_slow'], true)) {
            throw self::refuse($code, 403);
        }
        $db = $this->minn->db;
        $r = $db->table(self::REACTIONS);
        $now = time();
        if ($on) {
            if ((int) $db->value("SELECT COUNT(*) FROM {$r} WHERE user_id = ? AND created_at >= ?", [$viewer['id'], gmdate('Y-m-d H:i:s', $now - 60)]) >= CommunityText::REACTIONS_PER_MINUTE) {
                throw self::refuse('groddle_community_react_slow', 429);
            }
            // One reaction of a kind a login (Logins): another character of it giving the same one would count twice.
            $others = array_values(array_diff((new Logins($db))->siblings((int) $viewer['id']), [(int) $viewer['id']]));
            if ($others !== [] && $db->value("SELECT 1 FROM {$r} WHERE post_id = ? AND reaction = ? AND user_id IN (?) LIMIT 1", [$id, $key, $others]) !== null) {
                throw self::refuse('groddle_community_react_login', 409);
            }
            $changed = $db->execute("INSERT IGNORE INTO {$r} (post_id, user_id, reaction, created_at) VALUES (?, ?, ?, ?)", [$id, $viewer['id'], $key, gmdate('Y-m-d H:i:s', $now)]);
        } else {
            $changed = $db->execute("DELETE FROM {$r} WHERE post_id = ? AND user_id = ? AND reaction = ?", [$id, $viewer['id'], $key]);
        }
        if ($changed > 0) {
            $db->execute('UPDATE ' . $db->table(self::POSTS) . ' SET changed_ms = ? WHERE id = ?', [self::ms(), $id]);
        }
        return $this->post($id) ?? $post;
    }

    /**
     * A player's report of a post: a ticket with the post's words (the
     * console's Reports tab), once a player a post. 0 when they already had.
     *
     * @param array<string, mixed> $viewer
     */
    public function report(array $viewer, int $id, mixed $why): int
    {
        if ($viewer['id'] <= 0) {
            throw self::refuse('groddle_community_signed_out', 401);
        }
        $db = $this->minn->db;
        $post = $this->post($id);
        $thread = $post === null ? null : $this->thread((int) $post['thread_id'], $viewer);
        if ($post === null || $thread === null || $post['status'] === 'removed') {
            throw self::refuse('groddle_community_gone', 404);
        }
        $reports = $db->table(self::REPORTS);
        // Once a login a post (Logins): another character of the same login has already reported it.
        if ($db->value("SELECT 1 FROM {$reports} WHERE post_id = ? AND reporter_id IN (?) LIMIT 1", [$id, (new Logins($db))->siblings((int) $viewer['id'])]) !== null) {
            return 0;
        }
        $store = new TicketStore($db);
        $now = time();
        if ($store->openedSince($viewer['id'], BugReports::WINDOW, $now) >= BugReports::PER_WINDOW || $store->openedSince($viewer['id'], 86400, $now) >= BugReports::PER_DAY) {
            throw self::refuse('groddle_community_report_slow', 429);
        }
        $by = (new Characters($db))->namesOf([(int) $post['author_id']])[(int) $post['author_id']] ?? (string) $post['author_name'];
        $url = CommunityText::threadPath($thread['board'], (int) $thread['id'], (string) $thread['title']);
        $message = self::reportMessage((string) $thread['board_name'], (string) $thread['title'], $by, (string) $post['body'], CommunityText::why($why), $url . '#post-' . $id, $id);
        $ticket = $store->open($viewer['id'], $message, BugReports::context(['page' => "community-post:{$id}"]), $now);
        if ($ticket <= 0) {
            throw self::refuse('groddle_community_failed', 500);
        }
        if ($db->execute("INSERT IGNORE INTO {$reports} (post_id, reporter_id, ticket_id, created_at) VALUES (?, ?, ?, ?)", [$id, $viewer['id'], $ticket, gmdate('Y-m-d H:i:s', $now)]) > 0) {
            $db->execute('UPDATE ' . $db->table(self::POSTS) . ' SET reports = reports + 1 WHERE id = ?', [$id]);
        }
        return $ticket;
    }

    /** A report's first message: where (its first line is the ticket's subject), the thread, who wrote it, the words, why, the link. */
    public static function reportMessage(string $board, string $title, string $by, string $body, string $why, string $path, int $id): string
    {
        $lines = ["Reporting a Community post in {$board}", '', "Thread: {$title}", "Written by: {$by}", '', $body === '' ? '(The post is blank.)' : $body, ''];
        $lines[] = $why === '' ? 'Why: (no reason given)' : "Why: {$why}";
        $lines[] = "Post: {$path} (#{$id})";
        return mb_substr(implode("\n", $lines), 0, BugReports::MAX_MESSAGE);
    }

    // —— Notices ——

    /** How many notices the player has not read. */
    public function unread(int $user): int
    {
        if ($user <= 0) {
            return 0;
        }
        $db = $this->minn->db;
        return (int) $db->value('SELECT COUNT(*) FROM ' . $db->table(self::NOTICES) . ' WHERE user_id = ? AND read_at IS NULL', [$user]);
    }

    /** Reads the player's notices for one thread (opening it). */
    public function readThread(int $user, int $thread): void
    {
        $db = $this->minn->db;
        $db->execute('UPDATE ' . $db->table(self::NOTICES) . ' SET read_at = ? WHERE user_id = ? AND thread_id = ? AND read_at IS NULL', [gmdate('Y-m-d H:i:s'), $user, $thread]);
    }

    /**
     * The player's unread notices, newest first: all of them, or ($game) the
     * ones the game's Activity has not shown, at most GAME_NOTICE_FOR old.
     *
     * @return list<array{id: int, kind: string, count: int, from: string, thread: array{id: int, title: string, board: string}, url: string, at: int, text: string}>
     */
    public function notices(int $user, bool $game): array
    {
        $db = $this->minn->db;
        $n = $db->table(self::NOTICES);
        $t = $db->table(self::THREADS);
        $where = $game ? ' AND x.game_at IS NULL AND x.created_at >= ?' : '';
        $params = $game ? [$user, gmdate('Y-m-d H:i:s', time() - self::GAME_NOTICE_FOR)] : [$user];
        $rows = $db->rows("SELECT x.*, t.board, t.title, t.status AS thread_status FROM {$n} x JOIN {$t} t ON t.id = x.thread_id WHERE x.user_id = ? AND x.read_at IS NULL{$where} ORDER BY x.created_at DESC, x.id DESC LIMIT " . self::NOTICES_SHOWN, $params);
        $names = (new Characters($db))->namesOf(array_values(array_unique(array_map(static fn (array $r): int => (int) $r['from_id'], $rows))));
        $out = [];
        foreach ($rows as $r) {
            if ($r['thread_status'] !== 'open' || !CommunityText::isBoard($r['board'])) {
                continue;
            }
            $from = $names[(int) $r['from_id']] ?? 'Someone';
            $thread = ['id' => (int) $r['thread_id'], 'title' => (string) $r['title'], 'board' => (string) $r['board']];
            $page = $this->pageOf((int) $r['thread_id'], (int) $r['post_id']);
            $out[] = [
                'id' => (int) $r['id'],
                'kind' => (string) $r['kind'],
                'count' => (int) $r['count'],
                'from' => $from,
                'thread' => $thread,
                'url' => CommunityText::threadPath($thread['board'], $thread['id'], $thread['title']) . ($page > 1 ? "?page={$page}" : '') . '#post-' . (int) $r['post_id'],
                'at' => self::unix((string) $r['created_at']),
                'text' => self::noticeText((string) $r['kind'], (int) $r['count'], $from, $thread['title']),
            ];
        }
        return $out;
    }

    /**
     * The player's notices since $since (read ones too), newest first, for the notifications panel
     * (Notifications): [id, kind, count, from {id, name}, title, url, at (unix), read].
     *
     * @return list<array{id: int, kind: string, count: int, from: array{id: int, name: string}, title: string, url: string, at: int, read: bool}>
     */
    public function recentNotices(int $user, int $since): array
    {
        $db = $this->minn->db;
        $n = $db->table(self::NOTICES);
        $t = $db->table(self::THREADS);
        $rows = $db->rows("SELECT x.*, t.board, t.title, t.status AS thread_status FROM {$n} x JOIN {$t} t ON t.id = x.thread_id WHERE x.user_id = ? AND x.created_at >= ? ORDER BY x.created_at DESC, x.id DESC LIMIT " . self::NOTICES_SHOWN, [$user, gmdate('Y-m-d H:i:s', $since)]);
        $names = (new Characters($db))->namesOf(array_values(array_unique(array_map(static fn (array $r): int => (int) $r['from_id'], $rows))));
        $out = [];
        foreach ($rows as $r) {
            if ($r['thread_status'] !== 'open' || !CommunityText::isBoard($r['board'])) {
                continue;
            }
            $page = $this->pageOf((int) $r['thread_id'], (int) $r['post_id']);
            $out[] = [
                'id' => (int) $r['id'],
                'kind' => (string) $r['kind'],
                'count' => (int) $r['count'],
                'from' => ['id' => (int) $r['from_id'], 'name' => $names[(int) $r['from_id']] ?? 'Someone'],
                'title' => (string) $r['title'],
                'url' => CommunityText::threadPath((string) $r['board'], (int) $r['thread_id'], (string) $r['title']) . ($page > 1 ? "?page={$page}" : '') . '#post-' . (int) $r['post_id'],
                'at' => self::unix((string) $r['created_at']),
                'read' => $r['read_at'] !== null,
            ];
        }
        return $out;
    }

    /** Reads one of the player's notices (the panel), or all of them (Mark all read). */
    public function readNotices(int $user, ?int $id = null): void
    {
        $db = $this->minn->db;
        $db->execute('UPDATE ' . $db->table(self::NOTICES) . ' SET read_at = ? WHERE user_id = ? AND read_at IS NULL' . ($id === null ? '' : ' AND id = ?'), $id === null ? [gmdate('Y-m-d H:i:s'), $user] : [gmdate('Y-m-d H:i:s'), $user, $id]);
    }

    /** A notice in words (the badge's list and the game's Activity line). */
    public static function noticeText(string $kind, int $count, string $from, string $title): string
    {
        if ($kind === 'quote') {
            return "{$from} quoted you in “{$title}” on the Community boards.";
        }
        return $count > 1
            ? "{$count} new replies to your thread “{$title}” on the Community boards."
            : "{$from} replied to your thread “{$title}” on the Community boards.";
    }

    /**
     * The notices a reply makes: the thread's writer (a reply), the quoted
     * writer (a quote; one notice when they are the same person), never the
     * replier and never from a player the reader blocks. Unread replies to
     * one thread are one notice that counts them.
     *
     * @param array<string, mixed> $thread
     */
    private function notify(int $from, array $thread, int $post, int $quoted): void
    {
        $db = $this->minn->db;
        $n = $db->table(self::NOTICES);
        $ignores = $db->table(Buddies::IGNORES);
        $now = gmdate('Y-m-d H:i:s');
        $to = [];
        $owner = (int) $thread['author_id'];
        if ($quoted > 0 && $quoted !== $from) {
            $to[$quoted] = 'quote';
        }
        if ($owner > 0 && $owner !== $from && !isset($to[$owner])) {
            $to[$owner] = 'reply';
        }
        foreach ($to as $user => $kind) {
            if ($db->value("SELECT 1 FROM {$ignores} WHERE user_id = ? AND ignored_id = ?", [$user, $from]) !== null) {
                continue;
            }
            if ($kind === 'reply') {
                $open = $db->value("SELECT id FROM {$n} WHERE user_id = ? AND thread_id = ? AND kind = 'reply' AND read_at IS NULL ORDER BY id DESC LIMIT 1", [$user, $thread['id']]);
                if ($open !== null) {
                    $db->execute("UPDATE {$n} SET count = count + 1, post_id = ?, from_id = ?, created_at = ?, game_at = NULL WHERE id = ?", [$post, $from, $now, $open]);
                    continue;
                }
            }
            $db->execute("INSERT INTO {$n} (user_id, kind, thread_id, post_id, from_id, count, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)", [$user, $kind, $thread['id'], $post, $from, $now]);
        }
    }

    // —— Shared ——

    /** A post's row, or null. @return array<string, mixed>|null */
    public function post(int $id): ?array
    {
        $db = $this->minn->db;
        return $db->row('SELECT * FROM ' . $db->table(self::POSTS) . ' WHERE id = ?', [$id]);
    }

    /**
     * A thread's counts and newest post again, from its shown posts; a
     * thread left with none leaves its board (deleted), and one that has
     * some again comes back. A removal by the console is AdminCommunity's.
     */
    public function refreshThread(int $id): void
    {
        $db = $this->minn->db;
        $p = $db->table(self::POSTS);
        $t = $db->table(self::THREADS);
        $n = (int) $db->value("SELECT COUNT(*) FROM {$p} WHERE thread_id = ? AND status = 'shown'", [$id]);
        $last = $db->row("SELECT id, author_id, created_at FROM {$p} WHERE thread_id = ? AND status = 'shown' ORDER BY id DESC LIMIT 1", [$id]);
        $status = $db->value("SELECT status FROM {$t} WHERE id = ?", [$id]);
        $next = match (true) {
            $status === 'open' && $n === 0 => 'deleted',
            $status === 'deleted' && $n > 0 => 'open',
            default => (string) $status,
        };
        if ($last === null) {
            $db->execute("UPDATE {$t} SET posts = 0, status = ? WHERE id = ?", [$next, $id]);
            return;
        }
        $db->execute("UPDATE {$t} SET posts = ?, last_post_id = ?, last_author_id = ?, last_post_at = ?, status = ? WHERE id = ?", [$n, $last['id'], $last['author_id'], $last['created_at'], $next, $id]);
    }

    /** @param array<string, mixed> $viewer */
    private function mayPost(array $viewer, bool $thread): void
    {
        $code = $this->postRefusal($viewer, $thread);
        if ($code !== null) {
            throw self::refuse($code, match ($code) {
                'groddle_community_signed_out' => 401,
                'groddle_community_slow', 'groddle_community_new_slow', 'groddle_community_threads_slow' => 429,
                default => 403,
            });
        }
    }

    /**
     * The reader's own post that is still shown, or a refusal.
     *
     * @param array<string, mixed> $viewer
     * @return array<string, mixed>
     */
    private function ownPost(array $viewer, int $id): array
    {
        if ($viewer['id'] <= 0) {
            throw self::refuse('groddle_community_signed_out', 401);
        }
        $post = $this->post($id);
        if ($post === null || $post['status'] !== 'shown' || $this->thread((int) $post['thread_id'], $viewer) === null) {
            throw self::refuse('groddle_community_gone', 404);
        }
        if ((int) $post['author_id'] !== $viewer['id']) {
            throw self::refuse('groddle_community_not_yours', 403);
        }
        return $post;
    }

    /** @param array<string, mixed> $post @param array<string, mixed> $viewer */
    private function postAnswer(array $post, array $viewer): Response
    {
        $thread = $this->thread((int) $post['thread_id'], $viewer) ?? ['id' => (int) $post['thread_id'], 'board' => 'general', 'title' => '', 'locked' => 0, 'first_post_id' => 0, 'status' => 'deleted'];
        $drawn = $this->decorate([$post], $viewer)[0];
        return Response::json(['post' => self::postOut($drawn, $viewer), 'html' => CommunityPages::postHtml($drawn, $thread, $viewer), 'thread' => ['status' => $thread['status'], 'title' => $thread['title']]]);
    }

    public static function refuse(string $code, int $status): RestError
    {
        return new RestError($code, CommunityText::said($code), $status);
    }

    /** @param array<string, mixed> $b */
    public static function boardOut(array $b): array
    {
        return ['slug' => $b['slug'], 'name' => $b['name'], 'about' => $b['about'], 'url' => "/chat/{$b['slug']}/", 'threads' => $b['threads'], 'posts' => $b['posts'], 'latest' => $b['latest']];
    }

    /** @param array<string, mixed> $t */
    public static function threadOut(array $t): array
    {
        return [
            'id' => (int) $t['id'],
            'board' => (string) $t['board'],
            'title' => (string) $t['title'],
            'url' => CommunityText::threadPath((string) $t['board'], (int) $t['id'], (string) $t['title']),
            'author' => ['id' => (int) $t['author_id'], 'name' => (string) ($t['author_name'] ?? '')],
            'replies' => max(0, (int) $t['posts'] - 1),
            'pinned' => (int) $t['pinned'] === 1,
            'locked' => (int) $t['locked'] === 1,
            'status' => (string) $t['status'],
            'createdAt' => self::unix((string) $t['created_at']),
            'lastAt' => self::unix((string) $t['last_post_at']),
            'lastBy' => (string) ($t['last_author_name'] ?? ''),
        ];
    }

    /**
     * A post as JSON (never the writer's login or email: the character's name).
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed> $viewer
     */
    public static function postOut(array $p, array $viewer): array
    {
        $shown = $p['status'] === 'shown' || ($viewer['admin'] ?? false);
        return [
            'id' => (int) $p['id'],
            'thread' => (int) $p['thread_id'],
            'author' => ['id' => (int) $p['author_id'], 'name' => (string) ($p['name'] ?? $p['author_name']), 'profile' => $p['author']['profile'] ?? null],
            'body' => $shown ? (string) $p['body'] : '',
            'status' => (string) $p['status'],
            'quote' => $p['quote'] ?? null,
            'createdAt' => self::unix((string) $p['created_at']),
            'editedAt' => $p['edited_at'] === null ? null : self::unix((string) $p['edited_at']),
            'reactions' => $p['reactions'] ?? [],
        ];
    }

    /** A stored UTC time as unix seconds. */
    public static function unix(string $at): int
    {
        return $at === '' ? 0 : (int) strtotime($at . ' UTC');
    }

    public static function ms(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private static function fresh(Response $r): Response
    {
        return $r->withHeader('Cache-Control', 'no-store');
    }
}
