<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Auth\Nonce;
use Minn\Extension\Seams;
use Minn\Html;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\Http\RouteMiss;
use Minn\RestError;

/**
 * The Community pages (Austin 2026-11-04), drawn whole on the server in the
 * site's look so they read and can be crawled without JavaScript:
 *
 *   /chat/                         the boards (and, for signed-in players, the game's channels)
 *   /chat/<board>/                 a board's threads, pinned first, then newest activity (?page=)
 *   /chat/<board>/new/             start a thread (signed-in players)
 *   /chat/<board>/<id>-<slug>/     a thread's posts, oldest first, with the reply box (?page=)
 *
 * Posting works without JavaScript too: the forms post to /chat/<board>/new/
 * and /chat/<board>/<id>-<slug>/reply/ with the page's REST nonce (as
 * PlayerPages::buddy) and come back to the post; a refusal draws the page
 * again with the words kept. community/community.js takes the forms over
 * (posting without a reload), adds reactions, Quote, Edit, Delete and
 * Report, asks before a link to another site opens, and brings in new and
 * changed posts while the thread is open (Community::updates).
 *
 * What visitors see: the boards. The game's channels are signed-in players'
 * alone (Austin 2026-11-04: "keep game communication for logged in users
 * only"): a visitor's /chat/ says to sign in, with nothing of the channels.
 *
 * Everything a player wrote is escaped (CommunityText::bodyHtml); writers
 * show by their character's name, linked to their profile when it is public.
 * Images: none in v1 (the reply box says so).
 *
 * The look (Austin 2026-11-04: "more like the chat interface but full
 * screen"): every page is the game's Social drawer laid out as a chat app
 * (shell()): a rail on the left (Channels, Chats, Buddies for a signed-in
 * player; the Boards for everyone), the conversation in the middle (a
 * channel's or an IM's lines, a board's topics as a list, a topic's posts as
 * chat lines) with the drawer's rounded input at the bottom, a thread panel
 * that slides in on the right, and Who's around beside it. On a phone the
 * rail is one screen and a conversation another (data-cx-view). The drawer's
 * look is copied into community/community.css (its tokens, rows, chips and
 * input; the type is the game's own Nunito from /assets/fonts/nunito/).
 * community.js makes it live for signed-in players (the realtime socket,
 * groddle-afternoon-server web.go, with polling when it can't connect).
 *
 * The header (Austin 2026-11-04: "a chat UI designed header just for this
 * page"): the site's header block and menu in the chat app's layout
 * (SiteNav::nav with $app), full width over the app's columns, with the
 * notifications bell for a signed-in reader (Notifications; the panel is
 * community/notifications.js). On a phone its links fold behind a menu
 * button. ?notifdemo=1 fills the panel with samples on a .localhost site only
 * (notifDemo). Right-click menus on players, lines, posts and the rail are
 * community/chat-menus.js (the game's context menu).
 *
 * The frame (Austin 2026-11-04: "minimal and integrated with the chat UI ...
 * remove the footer for this chat UI"): the app is the whole window. The page
 * itself never scrolls; under the header the rail, the conversation, the
 * thread panel and Who's around each scroll inside themselves, with the input
 * pinned at the foot of the conversation. There is no site footer: its
 * fan-remake credit, Credits and Privacy are one quiet row pinned at the foot
 * of the rail (RAIL_FOOT), which is also a phone's list screen.
 */
final readonly class CommunityPages
{
    public const ASSETS = '/wp-content/themes/groddle/community/';

    /** Channel lines the page draws before the script brings more. */
    public const CHANNEL_LINES = 40;

    /** Bumped when community.js or community.css changes. */
    public const ASSET_KEY = 'community-7';

    /** The words the pages share. */
    public const COPY = [
        'lead' => 'Talk with other players outside the game: questions, ideas, trades and stories. Anyone can read the boards; players sign in to post.',
        'channelsSignedOut' => 'Sign in to see what players are saying in the game.',
        'channelsHint' => 'Lines you send here go to everyone in the channel in the game, marked web. Local chat and IMs stay in the game.',
        'signIn' => 'Sign in to post. Reading is open to everyone; posting needs your Groddle Afternoon player account.',
        'notPlayer' => 'Only players can post. Make a character in the game first.',
        'noImages' => 'Words and links only: no pictures yet.',
        'locked' => 'This thread is locked, so nobody can reply.',
        'deleted' => 'This post was deleted by its writer.',
        'removed' => 'This post was removed by a moderator.',
        'blocked' => 'A post from a player you blocked',
        'noThreads' => 'No threads here yet. Start the first one.',
    ];

    /** The site footer's credit line, as one quiet row at the foot of the rail (parts/footer.html's first line). */
    public const RAIL_FOOT = '<p class="cx-rail-foot">A <a href="https://tinybitadventures.com">fan remake</a> of Tiny Speck’s Glitch · <a href="/credits/">Credits</a> · <a href="/privacy-policy/">Privacy</a></p>';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/chat', policy: new Policy(Access::Public))]
    public function bare(Request $request): Response
    {
        return Response::redirect('/chat/', 301);
    }

    #[Route(Method::Get, '/chat/', policy: new Policy(Access::Public))]
    public function index(Request $request): Response
    {
        $community = new Community($this->minn);
        $viewer = $community->viewer();
        // A signed-in player opens on a channel (?c=<key> the one asked for, else the first; ?im=<id> is the
        // app's own); a visitor on the boards. On a phone both start on the list screen: the rail, with the
        // boards, signing in and the credit row. community/chat-nav.js shows each of these in place.
        $asked = $request->query('c');
        $channels = $viewer['id'] > 0
            ? $this->channelsPanel($viewer, is_string($asked) ? $asked : '')
            : self::signedOutPanel() . $this->boardsList($community);
        $body = $this->shell($community, $viewer, $viewer['id'] > 0 ? 'chan' : 'boards', $channels, 'list', [], is_string($asked) ? $asked : '');
        return $this->page('Community', 'The Groddle Afternoon community boards: talk with other players about the game, ideas, trades and anything else.', $body, $viewer, '/chat/');
    }

    /** The boards as rows of the Chats list (a visitor's /chat/). */
    private function boardsList(Community $community): string
    {
        $now = time();
        $rows = '';
        foreach ($community->boards() as $b) {
            $latest = $b['latest'];
            $last = $latest === null ? 'Nothing yet' : ($latest['by'] === '' ? '' : $latest['by'] . ': ') . $latest['title'];
            $rows .= '<li><a class="cx-row" href="/chat/' . Html::attr($b['slug']) . '/">' . self::avatar((string) $b['name'], 'cx-av-lg')
                . '<span class="cx-txt"><span class="cx-name"><span class="cx-label">' . Html::esc($b['name']) . '</span><small class="cx-note">' . CommunityText::count($b['threads'], 'topic', 'topics') . '</small></span>'
                . '<span class="cx-last">' . Html::esc($b['about']) . '</span>'
                . '<span class="cx-last">' . Html::esc($last) . ($latest === null ? '' : ' · ' . self::time($latest['at'], $now)) . '</span></span></a></li>';
        }
        return '<section class="cx-conv cx-boards" aria-labelledby="cx-boards-title">' . self::head('Boards', 'Talk outside the game', 'cx-boards-title', '', false)
            . '<div class="cx-log cx-list-log"><ul class="cx-list">' . $rows . '</ul></div></section>';
    }

    /** What a visitor sees where the game's chat would be. */
    private static function signedOutPanel(): string
    {
        return '<aside class="cchannels cchannels-out cx-signin" aria-labelledby="cchannels-title"><h2 id="cchannels-title">In the game now</h2><p>' . Html::esc(self::COPY['channelsSignedOut']) . '</p>' . self::signInLinks() . '</aside>';
    }

    #[Route(Method::Get, '/chat/{board:[a-z-]+}', policy: new Policy(Access::Public))]
    public function bareBoard(Request $request, string $board): Response
    {
        if (!CommunityText::isBoard($board)) {
            throw new RouteMiss();
        }
        return Response::redirect("/chat/{$board}/" . self::query($request), 301);
    }

    /** A board's threads: its topics as the Chats list's rows. */
    #[Route(Method::Get, '/chat/{board:[a-z-]+}/', policy: new Policy(Access::Public))]
    public function board(Request $request, string $board): Response
    {
        $community = new Community($this->minn);
        $b = $community->board($board) ?? throw new RouteMiss();
        $viewer = $community->viewer();
        [$threads, $page, $pages] = $community->threads($board, (int) ($request->query('page') ?? '1'));
        $now = time();
        $rows = '';
        foreach ($threads as $t) {
            $url = CommunityText::threadPath($board, (int) $t['id'], (string) $t['title']);
            $replies = max(0, (int) $t['posts'] - 1);
            $marks = ((int) $t['pinned'] === 1 ? '<span class="ctag ctag-pin">Pinned</span>' : '') . ((int) $t['locked'] === 1 ? '<span class="ctag">Locked</span>' : '');
            $lastBy = (string) ($t['last_author_name'] ?? '');
            $rows .= "\n      <li class=\"cthread\"" . ((int) $t['pinned'] === 1 ? ' data-pinned=""' : '') . '><a class="cx-row cthread-row" href="' . Html::attr($url) . '">'
                . self::avatar((string) $t['author_name'], 'cx-av-lg')
                . '<span class="cx-txt"><span class="cx-name"><span class="cx-label cthread-title">' . Html::esc((string) $t['title']) . '</span>' . $marks . '</span>'
                . '<span class="cx-last">' . ($lastBy === '' ? '' : Html::esc($lastBy) . ': ') . Html::esc((string) ($t['last_snippet'] ?? '')) . '<span class="cthread-by"> Started by ' . Html::esc((string) $t['author_name']) . '</span></span></span>'
                . '<span class="cx-meta"><span class="cthread-count">' . number_format($replies) . ' ' . ($replies === 1 ? 'reply' : 'replies') . '</span>' . self::time(Community::unix((string) $t['last_post_at']), $now) . '</span></a></li>';
        }
        $start = $community->postRefusal($viewer, true) === 'groddle_community_signed_out'
            ? self::signInLinks()
            : ($viewer['player'] || $viewer['admin'] ? '<a class="cx-chip cbutton" href="/chat/' . Html::attr($board) . '/new/">Start a topic</a>' : '<p class="cmuted">' . Html::esc(self::COPY['notPlayer']) . '</p>');
        $main = '<section class="cx-conv cx-board" aria-labelledby="cx-conv-title" data-cx-board="' . Html::attr($board) . '">'
            . self::head((string) $b['name'], CommunityText::count($b['threads'], 'topic', 'topics'), 'cx-conv-title', '', true, (string) $b['about'])
            . '<div class="cx-log cx-list-log">'
            . ($rows === '' ? '<p class="cempty cx-empty">' . Html::esc(self::COPY['noThreads']) . '</p>' : "<ul class=\"cthread-list cx-list\">{$rows}\n    </ul>")
            . self::pager($page, $pages, static fn (int $n): string => "/chat/{$board}/" . ($n > 1 ? "?page={$n}" : ''))
            . '</div><div class="cx-compose cx-compose-bar">' . $start . '</div></section>';
        $body = $this->shell($community, $viewer, "board:{$board}", $main, 'main');
        $title = $b['name'] . ' – Community' . ($page > 1 ? " – page {$page}" : '');
        return $this->page($title, $b['about'], $body, $viewer, "/chat/{$board}/" . ($page > 1 ? "?page={$page}" : ''));
    }

    /** The form for a new thread. */
    #[Route(Method::Get, '/chat/{board:[a-z-]+}/new/', policy: new Policy(Access::Public))]
    public function newThread(Request $request, string $board): Response
    {
        return $this->newThreadPage($board, [], '');
    }

    /** A new thread from the form without JavaScript; back to it, or the form again with what was wrong. */
    #[Route(Method::Post, '/chat/{board:[a-z-]+}/new/', policy: new Policy(Access::Public))]
    public function createThread(Request $request, string $board): Response
    {
        if (!CommunityText::isBoard($board)) {
            throw new RouteMiss();
        }
        $form = $request->json();
        $community = new Community($this->minn);
        $viewer = $community->viewer();
        $error = $this->nonceRefusal($form, $viewer);
        if ($error === null) {
            try {
                [$thread] = $community->createThread($viewer, $board, $form['title'] ?? '', $form['body'] ?? '');
                return Response::redirect(CommunityText::threadPath($board, (int) $thread['id'], (string) $thread['title']), 303);
            } catch (RestError $e) {
                $error = $e->errorCode;
            }
        }
        return $this->newThreadPage($board, $form, $error);
    }

    /** @param array<string, mixed> $form */
    private function newThreadPage(string $board, array $form, string $error): Response
    {
        $community = new Community($this->minn);
        $b = $community->board($board) ?? throw new RouteMiss();
        $viewer = $community->viewer();
        $refusal = $community->postRefusal($viewer, true);
        $title = is_string($form['title'] ?? null) ? $form['title'] : '';
        $text = is_string($form['body'] ?? null) ? $form['body'] : '';
        if ($refusal === 'groddle_community_signed_out') {
            $box = '    <div class="cpanel">' . '<p>' . Html::esc(self::COPY['signIn']) . '</p>' . self::signInLinks() . "</div>\n";
        } elseif ($refusal !== null && !in_array($refusal, ['groddle_community_slow', 'groddle_community_new_slow', 'groddle_community_threads_slow'], true)) {
            $box = '    <div class="cpanel"><p>' . Html::esc(CommunityText::said($refusal)) . "</p></div>\n";
        } else {
            $box = '    <form class="cform" method="post" action="/chat/' . Html::attr($board) . '/new/" data-community-new="' . Html::attr($board) . "\">\n"
                . '      <input type="hidden" name="_wpnonce" value="' . Html::attr($viewer['nonce']) . "\" />\n"
                . '      <label class="visually-hidden" for="cthread-title">Title</label>' . "\n"
                . '      <input class="cinput cx-title-field" id="cthread-title" name="title" type="text" required minlength="' . CommunityText::TITLE_MIN . '" maxlength="' . CommunityText::TITLE_MAX . '" autocomplete="off" placeholder="Title" value="' . Html::attr($title) . "\" />\n"
                . '      <div class="cx-say"><label class="visually-hidden" for="cthread-body">What do you want to say?</label>'
                . '<textarea class="ctext cx-field" id="cthread-body" name="body" rows="1" required maxlength="' . CommunityText::BODY_MAX . '" placeholder="What do you want to say?">' . Html::esc($text) . '</textarea>'
                . '<button class="cx-send" type="submit" aria-label="Start the topic" title="Start the topic">' . self::ICON_SEND . "</button></div>\n"
                . '      <p class="chint">' . Html::esc(self::COPY['noImages']) . "</p>\n"
                . '      <p class="csaid" role="status" aria-live="polite"' . ($error === '' && $refusal === null ? '' : ' data-tone="bad"') . '>' . Html::esc($error !== '' ? CommunityText::said($error) : ($refusal !== null ? CommunityText::said($refusal) : '')) . "</p>\n"
                . "    </form>\n";
        }
        $main = '<section class="cx-conv cx-new" aria-labelledby="cx-conv-title">' . self::head('Start a topic', 'In ' . $b['name'], 'cx-conv-title', '', true, (string) $b['about'], "/chat/{$board}/")
            . '<div class="cx-log"></div><div class="cx-compose">' . $box . '</div></section>';
        $body = $this->shell($community, $viewer, "board:{$board}", $main, 'main');
        return $this->page('Start a thread – ' . $b['name'], $b['about'], $body, $viewer, null, $error === '' ? 200 : 400);
    }

    #[Route(Method::Get, '/chat/{board:[a-z-]+}/{thread:\d+(?:-[a-z0-9-]+)?}', policy: new Policy(Access::Public))]
    public function bareThread(Request $request, string $board, string $thread): Response
    {
        return Response::redirect("/chat/{$board}/{$thread}/" . self::query($request), 301);
    }

    /** A thread. */
    #[Route(Method::Get, '/chat/{board:[a-z-]+}/{thread:\d+(?:-[a-z0-9-]+)?}/', policy: new Policy(Access::Public))]
    public function thread(Request $request, string $board, string $thread): Response
    {
        $page = $request->query('page');
        return $this->threadPage($request, $board, $thread, $page === null ? 1 : (int) $page, [], '');
    }

    /** A reply from the form without JavaScript; back to it on the right page, or the thread again with the words kept. */
    #[Route(Method::Post, '/chat/{board:[a-z-]+}/{thread:\d+(?:-[a-z0-9-]+)?}/reply/', policy: new Policy(Access::Public))]
    public function reply(Request $request, string $board, string $thread): Response
    {
        $form = $request->json();
        $community = new Community($this->minn);
        $viewer = $community->viewer();
        $error = $this->nonceRefusal($form, $viewer);
        if ($error === null) {
            try {
                [, , $url] = $community->reply($viewer, (int) $thread, $form['body'] ?? '', (int) ($form['quote'] ?? 0));
                return Response::redirect($url, 303);
            } catch (RestError $e) {
                $error = $e->errorCode;
            }
        }
        return $this->threadPage($request, $board, $thread, 0, $form, $error);
    }

    /**
     * @param array<string, mixed> $form what the reply box held, when it is drawn again
     */
    private function threadPage(Request $request, string $board, string $slug, int $page, array $form, string $error): Response
    {
        $community = new Community($this->minn);
        $viewer = $community->viewer();
        $thread = $community->thread((int) $slug, $viewer);
        if ($thread === null) {
            throw new RouteMiss();
        }
        $canonical = CommunityText::threadPath((string) $thread['board'], (int) $thread['id'], (string) $thread['title']);
        if ($form === [] && ($thread['board'] !== $board || "/chat/{$board}/{$slug}/" !== $canonical)) {
            return Response::redirect($canonical . self::query($request), 301);
        }
        [$posts, $page, $pages] = $community->posts($thread, $page, $viewer);
        if ($viewer['id'] > 0) {
            $community->readThread($viewer['id'], (int) $thread['id']);
        }
        $items = '';
        $last = 0;
        foreach ($posts as $p) {
            $items .= "\n      " . self::postHtml($p, $thread, $viewer);
            $last = max($last, (int) $p['id']);
        }
        $quote = (int) ($form['quote'] ?? $request->query('quote') ?? 0);
        $replies = max(0, (int) $thread['posts'] - 1);
        $meta = 'Started by ' . Html::esc((string) $thread['author_name']) . ' · ' . CommunityText::count($replies, 'reply', 'replies')
            . ((int) $thread['locked'] === 1 ? ' · <span class="ctag">Locked</span>' : '') . ((int) $thread['pinned'] === 1 ? ' · <span class="ctag ctag-pin">Pinned</span>' : '')
            . ($thread['status'] !== 'open' ? ' · <span class="ctag ctag-bad">' . ($thread['status'] === 'removed' ? 'Removed' : 'Deleted') . '</span>' : '');
        $pagerLink = static fn (int $n): string => $canonical . ($n > 1 ? "?page={$n}" : '');
        $main = '<section class="cx-conv cx-topic" aria-labelledby="cx-conv-title">'
            . self::head((string) $thread['title'], (string) $thread['board_name'], 'cx-conv-title', '', true, '', "/chat/{$thread['board']}/", '<p class="cmeta">' . $meta . '</p>')
            . '<div class="cx-log" data-cx-log>'
            . self::pager($page, $pages, $pagerLink)
            . "<ol class=\"cposts\" data-community-posts>{$items}\n    </ol>\n"
            . '<p class="cnew" data-community-new-posts hidden></p>'
            . self::pager($page, $pages, $pagerLink)
            . '</div><div class="cx-compose">'
            . $this->replyBox($community, $thread, $viewer, $canonical, $quote, is_string($form['body'] ?? null) ? $form['body'] : '', $error)
            . '</div></section>';
        $body = $this->shell($community, $viewer, "board:{$thread['board']}", $main, 'main', ['thread' => (int) $thread['id'], 'after' => $last, 'since' => Community::ms(), 'last-page' => $page === $pages ? '1' : '0', 'board' => (string) $thread['board']]);
        $first = $posts !== [] && $page === 1 && $posts[0]['status'] === 'shown' ? CommunityText::snippet((string) $posts[0]['body'], 155) : '';
        $title = $thread['title'] . ' – ' . $thread['board_name'] . ($page > 1 ? " – page {$page}" : '');
        return $this->page($title, $first !== '' ? $first : 'A thread on the ' . $thread['board_name'] . ' board of the Groddle Afternoon community.', $body, $viewer, $pagerLink($page), $error === '' ? 200 : 400, $thread['status'] === 'open');
    }

    /** @param array<string, mixed> $thread @param array<string, mixed> $viewer */
    private function replyBox(Community $community, array $thread, array $viewer, string $canonical, int $quote, string $text, string $error): string
    {
        if ($thread['status'] !== 'open') {
            return '';
        }
        if ((int) $thread['locked'] === 1 && !$viewer['admin']) {
            return '    <div class="cpanel" id="reply"><p>' . Html::esc(self::COPY['locked']) . "</p></div>\n";
        }
        $refusal = $community->postRefusal($viewer, false);
        if ($refusal === 'groddle_community_signed_out') {
            return '    <div class="cpanel" id="reply"><p>' . Html::esc(self::COPY['signIn']) . '</p>' . self::signInLinks() . "</div>\n";
        }
        if ($refusal !== null && !in_array($refusal, ['groddle_community_slow', 'groddle_community_new_slow'], true)) {
            return '    <div class="cpanel" id="reply"><p>' . Html::esc(CommunityText::said($refusal)) . "</p></div>\n";
        }
        $quoted = $quote > 0 ? $community->post($quote) : null;
        if ($quoted !== null && ((int) $quoted['thread_id'] !== (int) $thread['id'] || $quoted['status'] !== 'shown')) {
            $quoted = null;
        }
        $quoteName = $quoted === null ? '' : ((new Characters($this->minn->db))->namesOf([(int) $quoted['author_id']])[(int) $quoted['author_id']] ?? (string) $quoted['author_name']);
        $said = $error !== '' ? CommunityText::said($error) : ($refusal !== null ? CommunityText::said($refusal) : '');
        return '    <form class="creply" id="reply" method="post" action="' . Html::attr($canonical . 'reply/') . '" data-community-reply="' . (int) $thread['id'] . "\">\n"
            . '      <input type="hidden" name="_wpnonce" value="' . Html::attr($viewer['nonce']) . "\" />\n"
            . '      <input type="hidden" name="quote" value="' . ($quoted === null ? '' : (int) $quoted['id']) . "\" data-quote-field />\n"
            . '      <div class="cquoting" data-quoting' . ($quoted === null ? ' hidden' : '') . '><span data-quoting-text>' . ($quoted === null ? '' : 'Quoting ' . Html::esc($quoteName) . ': ' . Html::esc(CommunityText::snippet((string) $quoted['body'], 90))) . '</span>'
            . '<a class="cquoting-off" href="' . Html::attr($canonical) . '#reply" data-unquote>Remove quote</a></div>' . "\n"
            . '      <div class="cx-say"><label class="visually-hidden" for="creply-body">Your reply</label>'
            . '<textarea class="ctext cx-field" id="creply-body" name="body" rows="1" required maxlength="' . CommunityText::BODY_MAX . '" placeholder="Reply as ' . Html::attr($viewer['name']) . '">' . Html::esc($text) . '</textarea>'
            . '<button class="cx-send" type="submit" aria-label="Post reply" title="Post reply">' . self::ICON_SEND . "</button></div>\n"
            . '      <p class="chint" data-reply-hint>' . Html::esc(self::COPY['noImages']) . "</p>\n"
            . '      <p class="csaid" role="status" aria-live="polite"' . ($said === '' ? '' : ' data-tone="bad"') . '>' . Html::esc($said) . "</p>\n"
            . "    </form>\n";
    }

    /**
     * The game's channels for a signed-in player (ChatHistory): a tab each for Global Chat, Live
     * Help and the Trade Channel as the game allows them, the newest lines of the first (drawn
     * here, so they read without JavaScript), and a line to say something. community.js keeps it
     * live, switches tabs and brings earlier lines. Never drawn for a visitor (index()).
     *
     * @param array<string, mixed> $viewer
     * @param string $asked the channel the address asked for (?c=), drawn when the reader may read it
     */
    private function channelsPanel(array $viewer, string $asked = ''): string
    {
        $history = new ChatHistory($this->minn);
        $open = $history->open((int) $viewer['id']);
        if ($open === []) {
            return '<aside class="cchannels cchannels-out cx-signin" aria-labelledby="cchannels-title"><h2 id="cchannels-title">In the game now</h2><p>' . Html::esc(self::COPY['notPlayer']) . '</p></aside>';
        }
        $first = isset($open[$asked]) ? $asked : array_key_first($open);
        [$lines, $more] = $history->latest((int) $viewer['id'], $open[$first][1], null, self::CHANNEL_LINES);
        $items = '';
        foreach (array_reverse($lines) as $l) {
            $items .= self::channelLineHtml($l, (int) $viewer['id'], $viewer['homeHosts'] ?? CommunityText::HOME_HOSTS);
        }
        $last = $lines === [] ? 0 : $lines[0]['lid'];
        $oldest = $lines === [] ? 0 : $lines[count($lines) - 1]['lid'];
        $label = $open[$first][0];
        $actions = '<button type="button" class="cx-chip" data-cx-around-open aria-controls="cx-around" aria-expanded="false">Who\'s around</button>';
        return '<section class="cx-conv cchannels" aria-labelledby="cx-conv-title" data-chat-live data-chan="' . $first . '" data-after="' . $last . '" data-before="' . $oldest . '" data-since="' . Community::ms() . '" data-me="' . (int) $viewer['id'] . '">'
            . self::head($label, 'Everyone', 'cx-conv-title', $actions, true)
            . '<div class="cx-log cchan-log" id="cchan-log" data-cx-log>'
            . '<button type="button" class="cx-chip cchan-more" data-chan-more' . ($more ? '' : ' hidden') . '>Earlier messages</button>'
            . '<ol class="cchan-lines" data-chan-lines role="log" aria-live="polite">' . $items . '</ol>'
            . '<p class="cchan-empty cmuted" data-chan-empty' . ($lines === [] ? '' : ' hidden') . '>Nobody has said anything here yet.</p>'
            . '</div>'
            . '<form class="cx-compose cchan-say" data-chan-say><div class="cx-say"><label class="visually-hidden" for="cchan-text">Say something</label><input class="cinput cx-field" id="cchan-text" name="text" type="text" maxlength="' . ChatHistory::TEXT_MAX . '" autocomplete="off" placeholder="Chat in ' . Html::attr($label) . '" />'
            . '<button class="cx-send" type="submit" aria-label="Send" title="Send">' . self::ICON_SEND . '</button></div>'
            . '<p class="chint">' . Html::esc(self::COPY['channelsHint']) . '</p><p class="csaid" role="status" aria-live="polite" data-chan-said></p></form>'
            . '</section>';
    }

    /**
     * One channel line (ChatHistory::lineOut): the name (with "web" when it was said here), the
     * words with links by the chat's rule, the time, the reactions as marks.
     *
     * @param array<string, mixed> $l
     * @param list<string> $hosts
     */
    public static function channelLineHtml(array $l, int $me, array $hosts, bool $threads = true): string
    {
        $chips = '';
        foreach ($l['r'] ?? [] as $r) {
            $label = CommunityText::reactionLabel((string) $r['e'], array_map(static fn (array $b): string => (string) $b['name'], $r['by']));
            $chips .= '<span class="cchip cchip-small" title="' . Html::attr($label) . '" role="img" aria-label="' . Html::attr($label) . '">' . self::glyph((string) $r['e']) . '<span aria-hidden="true">' . count($r['by']) . '</span></span>';
        }
        $at = intdiv((int) $l['at'], 1000);
        return '<li class="cchan-line' . ((int) $l['id'] === $me ? ' is-mine' : '') . '" data-lid="' . (int) $l['lid'] . '">'
            . '<time datetime="' . gmdate('Y-m-d\TH:i:s\Z', $at) . '" title="' . Html::attr(gmdate('M j, Y H:i', $at) . ' UTC') . '">' . gmdate('H:i', $at) . '</time> '
            . '<b class="cchan-name">' . Html::esc((string) $l['name']) . '</b>' . ($l['web'] ? '<span class="cchan-web" title="Said on the website">web</span>' : '') . ' '
            . '<span class="cchan-text">' . CommunityText::lineHtml((string) $l['text'], $hosts) . '</span>'
            . ($chips === '' ? '' : '<span class="cchan-chips">' . $chips . '</span>')
            . ($threads && isset($l['th']) ? '<a class="cx-replies" href="?c=' . Html::attr((string) ($l['key'] ?? '')) . '#t' . (int) $l['lid'] . '" data-cx-replies="' . (int) $l['lid'] . '">' . Html::esc(CommunityText::count((int) $l['th']['n'], 'reply', 'replies')) . ' · ' . Html::esc((string) $l['th']['last']['name']) . '</a>' : '')
            . '</li>';
    }

    // —— One post ——

    /**
     * One post as the reader sees it (the pages and Community's answers draw
     * the same markup). A post from a player the reader blocks is folded
     * away; a deleted or removed one keeps its place with a line saying so
     * (administrators still read a removed one).
     *
     * @param array<string, mixed> $p a decorated post (Community::decorate)
     * @param array<string, mixed> $thread
     * @param array<string, mixed> $viewer
     */
    public static function postHtml(array $p, array $thread, array $viewer): string
    {
        $id = (int) $p['id'];
        $now = time();
        $status = (string) $p['status'];
        $admin = (bool) ($viewer['admin'] ?? false);
        $name = (string) ($p['name'] ?? $p['author_name']);
        $author = $p['author'] ?? ['profile' => null, 'tile' => null, 'staff' => false];
        $face = $author['tile'] !== null
            ? '<img class="cpost-tile" src="' . Html::attr($author['tile']) . '" width="40" height="58" alt="" loading="lazy" decoding="async" />'
            : '<span class="cpost-initial" aria-hidden="true">' . Html::esc(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>';
        $who = $author['profile'] !== null ? '<a class="cpost-name" href="' . Html::attr($author['profile']) . '">' . Html::esc($name) . '</a>' : '<span class="cpost-name">' . Html::esc($name) . '</span>';
        $created = Community::unix((string) $p['created_at']);
        $head = '<header class="cpost-head">' . $who . ($author['staff'] ? '<span class="ctag ctag-staff">Staff</span>' : '')
            . '<a class="cpost-time" href="#post-' . $id . '">' . self::time($created, $now) . '</a>'
            . ($p['edited_at'] !== null && $status === 'shown' ? '<span class="cpost-edited" title="' . Html::attr('Edited ' . gmdate('M j, Y H:i', Community::unix((string) $p['edited_at'])) . ' UTC') . '">edited</span>' : '')
            . ($status === 'removed' && $admin ? '<span class="ctag ctag-bad">Removed</span>' : '')
            . '</header>';
        if ($status !== 'shown' && !($status === 'removed' && $admin)) {
            $main = $head . '<p class="cpost-gone">' . Html::esc($status === 'removed' ? self::COPY['removed'] : self::COPY['deleted']) . '</p>';
            return '<li class="cpost" id="post-' . $id . '" data-post="' . $id . '" data-status="' . Html::attr($status) . '"><div class="cpost-face">' . $face . '</div><div class="cpost-main">' . $main . '</div></li>';
        }
        $quote = '';
        if (is_array($p['quote'] ?? null)) {
            $q = $p['quote'];
            $quote = '<blockquote class="cquote"><a href="#post-' . (int) $q['id'] . '">' . Html::esc($q['name']) . ' said</a>'
                . ($q['gone'] ? '<p class="cmuted">That post is no longer there.</p>' : ($q['blocked'] ? '<p class="cmuted">' . Html::esc(self::COPY['blocked']) . '</p>' : '<p>' . Html::esc($q['text']) . '</p>')) . '</blockquote>';
        }
        $hosts = $viewer['homeHosts'] ?? CommunityText::HOME_HOSTS;
        $signed = ($viewer['id'] ?? 0) > 0;
        $mine = (bool) ($p['mine'] ?? false);
        $actions = '';
        if ($signed && $status === 'shown') {
            $canPost = ($viewer['player'] ?? false) || $admin;
            $open = $thread['status'] === 'open' && ((int) $thread['locked'] === 0 || $admin);
            $base = CommunityText::threadPath((string) $thread['board'], (int) $thread['id'], (string) $thread['title']);
            $actions = '<div class="cpost-actions">'
                . ($canPost ? '<button type="button" class="cact" data-react-open aria-haspopup="true" aria-expanded="false">React</button>' : '')
                . ($canPost && $open ? '<a class="cact" href="' . Html::attr($base . '?quote=' . $id . '#reply') . '" data-quote="' . $id . '" data-quote-name="' . Html::attr($name) . '">Quote</a>' : '')
                . ($mine && CommunityText::editable($created, $now) ? '<button type="button" class="cact" data-edit>Edit</button>' : '')
                . ($mine ? '<button type="button" class="cact" data-delete>Delete</button>' : '')
                . (!$mine ? '<button type="button" class="cact" data-report>Report</button>' : '')
                . '</div>';
        }
        $inner = $head . $quote
            . '<div class="cpost-body" data-post-body>' . CommunityText::bodyHtml((string) $p['body'], $hosts) . '</div>'
            . self::reactionsHtml($p, $viewer) . $actions;
        if ($p['blocked'] ?? false) {
            $inner = '<details class="cpost-blocked"><summary>' . Html::esc(self::COPY['blocked']) . '</summary>' . $inner . '</details>';
        }
        $data = ' data-post="' . $id . '" data-status="' . Html::attr($status) . '"' . ($signed ? ' data-author="' . (int) ($p['author_id'] ?? 0) . '" data-author-name="' . Html::attr($name) . '"' : '') . ($mine ? ' data-mine=""' : '') . ($p['blocked'] ?? false ? ' data-blocked=""' : '') . ' data-quote-text="' . Html::attr(CommunityText::snippet((string) $p['body'], 90)) . '"';
        return '<li class="cpost" id="post-' . $id . '"' . $data . '><div class="cpost-face">' . $face . '</div><div class="cpost-main">' . $inner . '</div></li>';
    }

    /**
     * A post's reaction chips: each reaction on it with how many and who
     * (players the reader blocks left out), pressed when one is the reader's.
     * Buttons for a signed-in reader, plain marks for a visitor.
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed> $viewer
     */
    public static function reactionsHtml(array $p, array $viewer): string
    {
        $signed = ($viewer['id'] ?? 0) > 0 && (($viewer['player'] ?? false) || ($viewer['admin'] ?? false));
        $chips = '';
        foreach ($p['reactions'] ?? [] as $r) {
            $key = (string) $r['e'];
            $def = CommunityText::reactions()[$key] ?? null;
            if ($def === null) {
                continue;
            }
            $label = CommunityText::reactionLabel($key, array_map(static fn (array $b): string => (string) $b['name'], $r['by']));
            $glyph = self::glyph($key);
            $n = count($r['by']);
            $chips .= $signed
                ? '<button type="button" class="cchip" data-react="' . Html::attr($key) . '" aria-pressed="' . ($r['mine'] ? 'true' : 'false') . '" title="' . Html::attr($label) . '" aria-label="' . Html::attr($label) . '">' . $glyph . '<span>' . $n . '</span></button>'
                : '<span class="cchip" title="' . Html::attr($label) . '" role="img" aria-label="' . Html::attr($label) . '">' . $glyph . '<span aria-hidden="true">' . $n . '</span></span>';
        }
        return '<div class="cpost-reactions" data-reactions="' . (int) $p['id'] . '"' . ($chips === '' ? ' data-empty=""' : '') . '>' . $chips . '</div>';
    }

    /** A reaction's mark: its emoji, or the Hi sign's picture. */
    public static function glyph(string $key): string
    {
        [$glyph, , $art] = CommunityText::reactions()[$key];
        // The Hi sign's letters are cut from its frame in community.css (as the game's .react-glyph-hi).
        return $art !== ''
            ? '<span class="cchip-art cchip-' . Html::attr($key) . '" aria-hidden="true"></span>'
            : '<span class="cchip-glyph" aria-hidden="true">' . $glyph . '</span>';
    }

    // —— The app's frame ——

    /** The drawer's send arrow and back chevron (js/ui/social-drawer.js ICONS). */
    private const ICON_SEND = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3.5 10 16.5 4l-4 12-2.5-5z"/></svg>';
    private const ICON_BACK = '<svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="m7.5 3-3 3 3 3"/></svg>';

    /** The drawer's colour slot for a name (js/ui/social-drawer.js avatarOf), 0-7. */
    public static function hue(string $name): int
    {
        $s = trim($name) === '' ? '?' : trim($name);
        $h = 0;
        foreach (mb_str_split($s) as $ch) {
            $h = ($h * 31 + mb_ord($ch)) & 0xFFFFFFFF;
        }
        return $h % 8;
    }

    /** A round avatar: the name's first letter in its colour (the drawer's .social-av). */
    public static function avatar(string $name, string $cls = ''): string
    {
        $s = trim($name) === '' ? '?' : trim($name);
        return '<span class="cx-av' . ($cls === '' ? '' : ' ' . $cls) . '" data-hue="' . self::hue($s) . '" aria-hidden="true">' . Html::esc(mb_strtoupper(mb_substr($s, 0, 1))) . '</span>';
    }

    /** The bar over a conversation: back to the list (phones), its avatar and name, a note, its buttons. */
    private static function head(string $name, string $note, string $id, string $actions, bool $back, string $about = '', string $backTo = '/chat/', string $extra = ''): string
    {
        return '<header class="cx-head">'
            . ($back ? '<a class="cx-back" href="' . Html::attr($backTo) . '" data-cx-back>' . self::ICON_BACK . '<span>Chats</span></a>' : '')
            . self::avatar($name, 'cx-av-md')
            . '<div class="cx-head-txt"><h2 class="cx-head-name" id="' . Html::attr($id) . '" data-cx-head-name>' . Html::esc($name) . '</h2>'
            . ($note !== '' ? '<small class="cx-note" data-cx-head-note>' . Html::esc($note) . '</small>' : '')
            . ($about !== '' ? '<p class="cx-about">' . Html::esc($about) . '</p>' : '') . $extra . '</div>'
            . ($actions !== '' ? '<div class="cx-head-acts" data-cx-head-acts>' . $actions . '</div>' : '')
            . '</header>';
    }

    /**
     * The whole app: the rail (Channels, Chats and Buddies for a signed-in player, the Boards for
     * everyone), the conversation, and for a signed-in player the thread panel and Who's around.
     * $active: what the rail marks (chan, boards, board:<slug>); $view: what a phone shows (list|main);
     * $channel: the channel asked for (?c=), marked when it is one the reader may read, else the first.
     *
     * @param array<string, mixed> $viewer
     * @param array<string, int|string> $extra main's data attributes
     */
    private function shell(Community $community, array $viewer, string $active, string $main, string $view, array $extra = [], string $channel = ''): string
    {
        $signed = ($viewer['id'] ?? 0) > 0;
        $rail = '';
        if ($signed) {
            $open = (new ChatHistory($this->minn))->open((int) $viewer['id']);
            $marked = isset($open[$channel]) ? $channel : array_key_first($open);
            $chans = '';
            foreach ($open as $key => [$label]) {
                $chans .= '<li><a class="cx-row" href="/chat/?c=' . $key . '" data-cx-open="chan:' . $key . '"' . ($active === 'chan' && $key === $marked ? ' aria-current="true"' : '') . '>'
                    . '<span class="cx-av cx-av-lg" data-hue="' . self::hue($label) . '" aria-hidden="true">' . ($key === 'help' ? '?' : '#') . '</span>'
                    . '<span class="cx-txt"><span class="cx-name"><span class="cx-label">' . Html::esc($label) . '</span><small class="cx-note">Everyone</small></span><span class="cx-last" data-cx-last></span></span>'
                    . '<span class="cx-badge" data-cx-unread hidden></span></a></li>';
            }
            if ($chans !== '') {
                $rail .= '<section class="cx-sec" aria-labelledby="cx-sec-channels"><h2 class="cx-sec-title" id="cx-sec-channels">Channels</h2><ul class="cx-list">' . $chans . '</ul></section>';
            }
            $rail .= '<section class="cx-sec" aria-labelledby="cx-sec-chats"><h2 class="cx-sec-title" id="cx-sec-chats">Chats</h2><ul class="cx-list" data-cx-ims><li class="cx-empty">Your IMs show here.</li></ul></section>'
                . '<section class="cx-sec" aria-labelledby="cx-sec-buddies"><h2 class="cx-sec-title" id="cx-sec-buddies">Buddies</h2><ul class="cx-list cx-people" data-cx-buddies><li class="cx-empty">Loading your buddies…</li></ul></section>';
        }
        $boards = '';
        foreach ($community->boards() as $b) {
            $current = $active === 'board:' . $b['slug'] ? ' aria-current="page"' : '';
            $boards .= '<li><a class="cx-row" href="/chat/' . Html::attr($b['slug']) . '/"' . $current . '>' . self::avatar((string) $b['name'], 'cx-av-lg')
                . '<span class="cx-txt"><span class="cx-name"><span class="cx-label">' . Html::esc($b['name']) . '</span></span><span class="cx-last">' . Html::esc($b['latest'] === null ? $b['about'] : $b['latest']['title']) . '</span></span></a></li>';
        }
        $rail .= '<section class="cx-sec" aria-labelledby="cx-sec-boards"><h2 class="cx-sec-title" id="cx-sec-boards">Boards</h2><ul class="cx-list">' . $boards . '</ul></section>';
        if (!$signed) {
            $rail .= '<div class="cx-sec cx-rail-signin"><p>' . Html::esc(self::COPY['channelsSignedOut']) . '</p>' . self::signInLinks() . '</div>';
        }
        $side = '';
        if ($signed) {
            $side = '<aside class="cx-side cx-thread" id="cx-thread" aria-labelledby="cx-thread-title" data-cx-thread hidden></aside>'
                . '<aside class="cx-side cx-around" id="cx-around" aria-labelledby="cx-around-title" data-cx-around>'
                . '<header class="cx-side-head"><h2 class="cx-side-title" id="cx-around-title">Who\'s around</h2><small class="cx-note" data-cx-around-count></small>'
                . '<button type="button" class="cx-x" data-cx-around-close aria-label="Close Who\'s around" title="Close">' . self::ICON_BACK . '</button></header>'
                . '<label class="cx-switch"><input type="checkbox" data-cx-offline disabled /> <span>Appear offline</span></label>'
                . '<p class="cx-note cx-switch-hint">Nobody sees you online, buddies included. You can still chat and post.</p>'
                . '<ul class="cx-list cx-people" data-cx-people><li class="cx-empty">Connecting…</li></ul>'
                . '</aside>';
        }
        $socket = RealtimeTicket::socketUrl() ?? '';
        return '<main class="cx community" ' . self::data($viewer, $extra + ['cx-active' => $active, 'cx-view' => $view, 'socket' => $socket, 'me' => (int) ($viewer['id'] ?? 0), 'me-name' => (string) ($viewer['name'] ?? '')]) . '>'
            . '<div class="cx-rail"><nav class="cx-rail-list" aria-label="Community">' . $rail . '</nav>' . self::RAIL_FOOT . '</div>'
            . '<div class="cx-pane" data-cx-pane>' . $main . '</div>'
            . $side
            . '</main>';
    }

    // —— Pieces ——

    /** @param list<array{0: string, 1: string}> $crumbs */
    private static function masthead(string $title, string $kicker, string $lead, array $crumbs, string $metaHtml = ''): string
    {
        $trail = '';
        if ($crumbs !== []) {
            $parts = array_map(static fn (array $c): string => '<a href="' . Html::attr($c[0]) . '">' . Html::esc($c[1]) . '</a>', $crumbs);
            $trail = '    <nav class="kicker ccrumbs" aria-label="Breadcrumb">' . implode('<span aria-hidden="true"> / </span>', $parts) . "</nav>\n";
        }
        return '<header class="masthead note-head community-head">' . "\n  <div class=\"wrap\">\n"
            . ($trail !== '' ? $trail : '    <p class="kicker">' . Html::esc($kicker) . "</p>\n")
            . '    <h1>' . Html::esc($title) . "</h1>\n"
            . ($lead !== '' ? '    <p class="lead">' . Html::esc($lead) . "</p>\n" : '')
            . ($metaHtml !== '' ? '    <p class="cmeta">' . $metaHtml . "</p>\n" : '')
            . "  </div>\n</header>\n";
    }

    /** The links for signing in or making a character (the game's gate), and the sign-in form community.js shows. */
    private static function signInLinks(): string
    {
        return '<p class="csignin" data-community-signin><a class="cbutton" href="/play/online/">Sign in</a> <a class="cbutton cbutton-quiet" href="/play/online/#new">New player</a></p>';
    }

    /** The main element's data for community.js. @param array<string, mixed> $viewer @param array<string, int|string> $extra */
    private static function data(array $viewer, array $extra): string
    {
        $out = 'data-community="" data-nonce="' . Html::attr($viewer['nonce']) . '" data-signed="' . (($viewer['id'] ?? 0) > 0 ? '1' : '0') . '"'
            . ' data-can-post="' . (($viewer['player'] ?? false) || ($viewer['admin'] ?? false) ? '1' : '0') . '"';
        if (($viewer['id'] ?? 0) > 0) {
            // The reaction picker's set (the chat's, data/reactions.json).
            $set = [];
            foreach (CommunityText::reactions() as $key => [$glyph, $label, $art]) {
                $set[] = ['key' => $key, 'glyph' => $glyph, 'label' => $label, 'art' => $art];
            }
            $out .= ' data-reactions="' . Html::attr((string) json_encode($set, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
        }
        foreach ($extra as $k => $v) {
            $out .= ' data-' . $k . '="' . Html::attr((string) $v) . '"';
        }
        return $out;
    }

    /**
     * PURE: whether ?notifdemo=1 shows the sample notifications: only that value, and only when both
     * the site's home and the request are on a .localhost host (never on the live site).
     */
    public static function notifDemo(string $home, string $host, ?string $flag): bool
    {
        $local = static fn (string $h): bool => preg_match('/(?:^|\.)localhost$/', strtolower((string) preg_replace('/:\d+$/', '', $h))) === 1;
        return $flag === '1' && $local((string) parse_url($home, PHP_URL_HOST)) && $local($host);
    }

    /** "2 hours ago" with the exact time for a hover and for machines. */
    private static function time(int $at, int $now): string
    {
        return '<time datetime="' . gmdate('Y-m-d\TH:i:s\Z', $at) . '" title="' . Html::attr(gmdate('M j, Y H:i', $at) . ' UTC') . '">' . Html::esc(CommunityText::ago($at, $now)) . '</time>';
    }

    private static function pager(int $page, int $pages, \Closure $link): string
    {
        if ($pages < 2) {
            return '';
        }
        return '    <nav class="players-pager cpager" aria-label="Pages">'
            . ($page > 1 ? '<a href="' . Html::attr($link($page - 1)) . '" rel="prev">Previous</a>' : '<span></span>')
            . "<span>Page {$page} of {$pages}</span>"
            . ($page < $pages ? '<a href="' . Html::attr($link($page + 1)) . '" rel="next">Next</a>' : '<span></span>')
            . "</nav>\n";
    }

    private static function query(Request $request): string
    {
        return $request->query === [] ? '' : '?' . http_build_query($request->query);
    }

    /** Why a form post is refused before it is read: signed out, or a nonce that is not this reader's. @param array<string, mixed> $form @param array<string, mixed> $viewer */
    private function nonceRefusal(array $form, array $viewer): ?string
    {
        if ($viewer['id'] <= 0) {
            return 'groddle_community_signed_out';
        }
        $reader = $this->minn->reader;
        $nonce = is_string($form['_wpnonce'] ?? null) ? $form['_wpnonce'] : '';
        return $nonce !== '' && $reader->sessionToken !== '' && Nonce::verify($nonce, $reader->userId, $reader->sessionToken) ? null : 'groddle_community_nonce';
    }

    /**
     * A whole page in the site's look (PlayerPages' frame): the head, the
     * chat app's header with the Community badge, the app, this page's
     * stylesheet and script. No site footer: the app fills the window, and
     * the footer's credit row sits at the foot of the rail (RAIL_FOOT).
     *
     * @param array<string, mixed> $viewer
     */
    private function page(string $title, string $description, string $body, array $viewer, ?string $canonical, int $status = 200, bool $index = true): Response
    {
        $path = $this->minn->request->path;
        $home = rtrim((string) ($this->minn->site->option('home') ?? ''), '/');
        $v = self::ASSET_KEY;
        $a = self::ASSETS;
        $badges = SiteNav::badges($this->minn);
        $signed = ($viewer['id'] ?? 0) > 0;
        // The local preview of the bell's panel (?notifdemo=1): sample notifications drawn by the script, on a .localhost site only.
        $demo = $signed && self::notifDemo((string) ($this->minn->site->option('home') ?? ''), $this->minn->request->host, $this->minn->request->query('notifdemo'));
        $html = "<!DOCTYPE html>\n<html lang=\"en-US\">\n<head>\n<meta charset=\"UTF-8\" />\n"
            // viewport-fit=cover: the app reaches the screen's edges and keeps its parts inside the safe area (community.css).
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1, viewport-fit=cover\" />\n"
            . '<title>' . Html::esc($title) . " &#8211; Groddle Afternoon</title>\n"
            . '<meta name="description" content="' . Html::attr($description) . "\" />\n"
            . ($canonical !== null ? '<link rel="canonical" href="' . Html::attr($home . $canonical) . "\" />\n" : '')
            . (!$index || $canonical === null || $status !== 200 ? "<meta name=\"robots\" content=\"noindex\" />\n" : '')
            . Extension::head()
            . "<link rel=\"stylesheet\" href=\"{$a}community.css?v={$v}\" />\n"
            . "</head>\n<body class=\"notes community-page\"" . ($demo ? ' data-notif-demo=""' : '') . ">\n"
            // The chat app's own header: the menu's links laid out over the app's columns, with the bell for a signed-in reader.
            . SiteNav::nav(SiteNav::links($this->minn, 0), $path, $badges, ['bell' => $signed ? ($badges[SiteNav::COMMUNITY] ?? 0) : null]) . "\n"
            . $body . "\n"
            . "<script type=\"module\" src=\"{$a}community.js?v={$v}\"></script>\n"
            . "</body>\n</html>\n";
        $html = SocialTags::insert($html, $home, $path);
        return Response::html($this->minn->frontBar($html), $status)->withHeader('Cache-Control', ($viewer['id'] ?? 0) > 0 ? 'private, no-cache' : 'no-cache');
    }
}
