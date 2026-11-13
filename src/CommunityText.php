<?php

declare(strict_types=1);

namespace Groddle\Minn;

/**
 * The Community boards' pure rules (no database): the boards, the
 * reactions, the limits, how a title and a post are cleaned, how a post is
 * drawn (paragraphs and safe links), slugs and the refusals' words. Community
 * (REST and the tables) and CommunityPages (/chat/) use it; the game's
 * tests/community.test.js runs it through php -r.
 *
 * The boards are Glitch's own six (the glitchthegame.com forum root, Wayback
 * research/wayback/raw/forum/index_root.html: Announcements & Events, General,
 * Ideas, Marketplace, Bugs and Off Topic, in that order); the descriptions are
 * ours. The reactions are the chat's set: data/reactions.json, written by the
 * game repo's tools/build-reactions.mjs from its js/chat-reactions.js REACTIONS
 * (the keys the realtime server's reactions.go takes), so the boards and the
 * chat share one set.
 *
 * Links follow the chat's rule (the game's js/chat-links.js, linkSegments and
 * readLink, ported step for step): http and https only, bare www. and
 * groddleafternoon.com become https, an address with a user name or password
 * stays words, trailing punctuation and an unopened ) or ] are left out, at
 * most LINK_MAX characters. A link to this site opens as any link here; a link
 * anywhere else carries data-chat-link="away" and opens in a new tab after
 * community/community.js asks "This link goes to <host>. Open it?" (without
 * JavaScript it is a plain nofollow ugc link). The text is escaped piece by
 * piece; nothing a player writes is ever HTML.
 */
final class CommunityText
{
    /**
     * The boards in Glitch's order: slug => [name, what it is for]. Our own words.
     */
    public const BOARDS = [
        'announcements' => ['Announcements & Events', 'Parties, group meetups, contests and anything else players are organising in the game.'],
        'general' => ['General', 'Playing the game: questions, tips, and talk about the latest changes.'],
        'ideas' => ['Ideas', 'Suggestions for the game. We read them all, though not everything gets built.'],
        'marketplace' => ['Marketplace', 'Buying, selling and trading with other players.'],
        'bugs' => ['Bugs', 'Something not working? See whether someone has posted it already. For trouble with your own account, write to us from Feedback, ideas and bugs in the game.'],
        'off-topic' => ['Off Topic', 'Anything that isn\'t the game: shows, holidays, pets, the weather.'],
    ];

    /**
     * Boards where only the team (administrators, manage_options) starts topics; every signed-in
     * player may still reply (Austin 2026-11-13: "only staff can post new threads on it. Everyone
     * can respond"). Glitch's own Announcements forum was the team's too.
     */
    public const STAFF_BOARDS = ['announcements'];

    /** @var array<string, array{0: string, 1: string, 2: string}>|null */
    private static ?array $reactions = null;

    private static int $namesShown = 8;

    public const TITLE_MIN = 3;
    public const TITLE_MAX = 100;
    public const BODY_MAX = 4000;
    /** A report's own words, at most. */
    public const WHY_MAX = 300;
    /** How much of a quoted post a reply shows. */
    public const QUOTE_SHOWN = 280;

    /** Threads on one page of a board, posts on one page of a thread. */
    public const THREADS_PAGE = 30;
    public const POSTS_PAGE = 40;

    /** A post may be edited by its writer for this long (seconds); an edited post says so. */
    public const EDIT_WINDOW = 900;

    /** A character younger than this (seconds) posts under the new player limits. */
    public const NEW_FOR = 86400;

    /**
     * Posting limits (threads and replies both count as posts): [seconds, at most],
     * for everyone and for new characters; threads have their own on top.
     */
    public const POST_LIMITS = [[60, 5], [3600, 40], [86400, 200]];
    public const NEW_POST_LIMITS = [[60, 2], [3600, 12], [86400, 40]];
    public const THREAD_LIMITS = [[3600, 4], [86400, 12]];
    public const NEW_THREAD_LIMITS = [[3600, 1], [86400, 3]];
    /** Reactions put on (each counts) in a minute. */
    public const REACTIONS_PER_MINUTE = 40;
    /** The same words again in one thread within this long are refused. */
    public const SAME_AGAIN = 600;

    /** The longest address that becomes a link (js/chat-links.js LINK_MAX). */
    public const LINK_MAX = 400;
    public const LINK_REL = 'noopener noreferrer nofollow ugc';
    /** The game's own site: links here open without asking (js/chat-links.js HOME_HOSTS). */
    public const HOME_HOSTS = ['groddleafternoon.com', 'www.groddleafternoon.com'];
    /** The local copy, a home host only while the page is served from it (js/chat-links.js DEV_HOSTS). */
    public const DEV_HOSTS = ['groddle.localhost'];

    private const URL_CHARS = "A-Za-z0-9\\-._~:\\/?#\\[\\]@!$&'()*+,;=%";
    private const HOST_OK = '/^(?:(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})|localhost|\d{1,3}(?:\.\d{1,3}){3})$/';

    /** What a refusal says, by code (the REST answers and the pages' ?error= both use these). */
    public const SAID = [
        'groddle_community_signed_out' => 'Sign in to post.',
        'groddle_community_not_player' => 'Only players can post. Make a character in the game first.',
        'groddle_community_staff_only' => 'Only the team starts topics here. Open one to reply.',
        'groddle_community_banned' => 'You can\'t post on the boards right now.',
        'groddle_community_suspended' => AdminConsole::SUSPENDED,
        'groddle_community_slow' => 'You are posting very quickly. Wait a little and try again.',
        'groddle_community_new_slow' => 'New players can post a few times an hour at first. Wait a little and try again.',
        'groddle_community_threads_slow' => 'You have started a few threads just now. Reply in one, or wait a while before starting another.',
        'groddle_community_same' => 'You already posted that.',
        'groddle_community_title' => 'Give the thread a title of 3 to 100 characters.',
        'groddle_community_body' => 'Write something first.',
        'groddle_community_long' => 'That is too long: a post can be up to 4,000 characters.',
        'groddle_community_locked' => 'This thread is locked, so nobody can reply.',
        'groddle_community_gone' => 'That post is no longer there.',
        'groddle_community_no_thread' => 'That thread is no longer there.',
        'groddle_community_no_board' => 'There is no board by that name.',
        'groddle_community_not_yours' => 'You can only change your own posts.',
        'groddle_community_too_late' => 'A post can only be edited for 15 minutes after it goes up.',
        'groddle_community_reaction' => 'That reaction isn\'t one of the set.',
        'groddle_community_react_slow' => 'Slow down a little with the reactions.',
        'groddle_community_react_login' => 'One of your other characters already gave this reaction.',
        'groddle_community_report_slow' => 'Thanks! You have sent a few reports just now. Try again in a little while.',
        'groddle_community_failed' => 'That didn\'t work. Try again in a moment.',
        'groddle_community_nonce' => 'This page was open a long time. Try again.',
    ];

    /** A refusal's words, or the general one. */
    public static function said(string $code): string
    {
        return self::SAID[$code] ?? self::SAID['groddle_community_failed'];
    }

    /** Whether a board slug is one of the six. */
    public static function isBoard(mixed $slug): bool
    {
        return is_string($slug) && isset(self::BOARDS[$slug]);
    }

    /** Whether only the team starts topics on the board (STAFF_BOARDS). */
    public static function staffOnly(string $board): bool
    {
        return in_array($board, self::STAFF_BOARDS, true);
    }

    /** Whether a reaction key is one of the set. */
    public static function isReaction(mixed $key): bool
    {
        return is_string($key) && isset(self::reactions()[$key]);
    }

    /**
     * The chat's reactions in the order they show: key => [glyph ('' for art), label, art path or ''].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function reactions(): array
    {
        if (self::$reactions !== null) {
            return self::$reactions;
        }
        $file = dirname(__DIR__) . '/data/reactions.json';
        $data = json_decode(is_file($file) ? (string) file_get_contents($file) : '', true);
        if (!is_array($data['reactions'] ?? null)) {
            throw new \RuntimeException('data/reactions.json is missing: run the game repo\'s tools/build-reactions.mjs');
        }
        $out = [];
        foreach ($data['reactions'] as $r) {
            if (is_string($r['key'] ?? null) && preg_match('/^[a-z]{1,12}$/', $r['key']) === 1) {
                $out[$r['key']] = [(string) ($r['glyph'] ?? ''), (string) ($r['label'] ?? $r['key']), (string) ($r['art'] ?? '')];
            }
        }
        self::$namesShown = max(1, (int) ($data['namesShown'] ?? 8));
        return self::$reactions = $out;
    }

    // —— Words ——

    /** A title: one plain line, spaces made one, at most TITLE_MAX characters, the word filter applied. */
    public static function title(mixed $text): string
    {
        $text = is_string($text) ? mb_scrub($text, 'UTF-8') : '';
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', ' ', $text)));
        return WordFilter::text(trim(mb_substr($text, 0, self::TITLE_MAX)));
    }

    /**
     * A post: plain text, line breaks kept (at most two blank lines in a row),
     * spaces at line ends gone, the word filter applied. Not cut: a post over
     * BODY_MAX is refused (bodyRefusal), so nobody loses the end of what they wrote.
     */
    public static function body(mixed $text): string
    {
        $text = is_string($text) ? $text : '';
        $text = str_replace(["\r\n", "\r"], "\n", mb_scrub($text, 'UTF-8'));
        $text = (string) preg_replace('/[^\P{C}\n\t]+/u', '', $text);
        $text = str_replace("\t", '    ', $text);
        $lines = array_map(static fn (string $l): string => rtrim((string) preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{3000}]/u', ' ', $l)), explode("\n", $text));
        $text = trim((string) preg_replace("/\n{4,}/", "\n\n\n", implode("\n", $lines)), "\n");
        // Leading spaces on the first line go; inside, a line's indent stays (a list someone typed).
        return WordFilter::text(ltrim($text, ' '));
    }

    /** Why a cleaned post can't go up (a code of SAID), or null. */
    public static function bodyRefusal(string $body): ?string
    {
        if (trim($body) === '') {
            return 'groddle_community_body';
        }
        return mb_strlen($body) > self::BODY_MAX ? 'groddle_community_long' : null;
    }

    /** Why a cleaned title can't be a thread's, or null. */
    public static function titleRefusal(string $title): ?string
    {
        return mb_strlen($title) < self::TITLE_MIN ? 'groddle_community_title' : null;
    }

    /** A report's reason: one plain line of at most WHY_MAX characters. */
    public static function why(mixed $text): string
    {
        return BugReports::clean(is_string($text) ? (string) preg_replace('/\s+/u', ' ', $text) : '', self::WHY_MAX, false);
    }

    /** The thread's path word from its title: a-z, 0-9 and hyphens, at most 60 characters, cut at a hyphen. */
    public static function slug(string $title): string
    {
        $s = strtolower(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $s = str_replace(['&', "'", '’'], ['and', '', ''], $s);
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
        if (strlen($s) > 60) {
            $cut = substr($s, 0, 61);
            $at = strrpos($cut, '-');
            $s = rtrim($at !== false && $at > 20 ? substr($cut, 0, $at) : substr($s, 0, 60), '-');
        }
        return $s;
    }

    /** A thread's address: /chat/<board>/<id>-<slug>/ (or /chat/<board>/<id>/ when the title has no letters). */
    public static function threadPath(string $board, int $id, string $title): string
    {
        $slug = self::slug($title);
        return "/chat/{$board}/{$id}" . ($slug === '' ? '' : "-{$slug}") . '/';
    }

    /** What a quote shows of a post: its words on one line, cut at QUOTE_SHOWN characters with an ellipsis. */
    public static function quoteText(string $body): string
    {
        $one = trim((string) preg_replace('/\s+/u', ' ', $body));
        return mb_strlen($one) > self::QUOTE_SHOWN ? rtrim(mb_substr($one, 0, self::QUOTE_SHOWN - 1)) . '…' : $one;
    }

    /** A post's words on one line, cut at $max characters (descriptions, the console, notices). */
    public static function snippet(string $body, int $max = 150): string
    {
        $one = trim((string) preg_replace('/\s+/u', ' ', $body));
        return mb_strlen($one) > $max ? rtrim(mb_substr($one, 0, $max - 1)) . '…' : $one;
    }

    // —— Limits ——

    /**
     * Which limit a new post breaks, or null: $counts are the writer's posts
     * (threads and replies) in each window of the limits it falls under, as
     * [seconds => n]; $threads the same for threads, null for a reply.
     *
     * @param array<int, int> $counts
     * @param array<int, int>|null $threads
     */
    public static function limitRefusal(bool $new, array $counts, ?array $threads): ?string
    {
        foreach ($new ? self::NEW_POST_LIMITS : self::POST_LIMITS as [$seconds, $max]) {
            if (($counts[$seconds] ?? 0) >= $max) {
                return $new ? 'groddle_community_new_slow' : 'groddle_community_slow';
            }
        }
        if ($threads !== null) {
            foreach ($new ? self::NEW_THREAD_LIMITS : self::THREAD_LIMITS as [$seconds, $max]) {
                if (($threads[$seconds] ?? 0) >= $max) {
                    return 'groddle_community_threads_slow';
                }
            }
        }
        return null;
    }

    /** Every window the limits count over, in seconds. @return list<int> */
    public static function windows(): array
    {
        $all = [];
        foreach ([...self::POST_LIMITS, ...self::NEW_POST_LIMITS, ...self::THREAD_LIMITS, ...self::NEW_THREAD_LIMITS] as [$s]) {
            $all[$s] = $s;
        }
        sort($all);
        return array_values($all);
    }

    /** Whether a post made at $created (unix) may still be edited at $now. */
    public static function editable(int $created, int $now): bool
    {
        return $now - $created <= self::EDIT_WINDOW;
    }

    // —— Drawing ——

    /** The hosts that open without asking on a page served from $pageHost (js/chat-links.js homeHostsFor). @return list<string> */
    public static function homeHosts(string $pageHost): array
    {
        $h = strtolower($pageHost);
        return in_array($h, self::DEV_HOSTS, true) ? [...self::HOME_HOSTS, $h] : self::HOME_HOSTS;
    }

    /** The address without the punctuation after it (js/chat-links.js trimTrailing). */
    public static function trimTrailing(string $s): string
    {
        while ($s !== '') {
            $c = $s[strlen($s) - 1];
            if (str_contains(".,!?;:'\"*", $c)) {
                $s = substr($s, 0, -1);
            } elseif ($c === ')' && substr_count($s, ')') > substr_count($s, '(')) {
                $s = substr($s, 0, -1);
            } elseif ($c === ']' && substr_count($s, ']') > substr_count($s, '[')) {
                $s = substr($s, 0, -1);
            } else {
                break;
            }
        }
        return $s;
    }

    /**
     * What an address is as a link, or null when it may not be one
     * (js/chat-links.js readLink): href, host (what the confirm names), home.
     *
     * @param list<string> $homeHosts
     * @return array{href: string, host: string, home: bool}|null
     */
    public static function readLink(string $typed, array $homeHosts = self::HOME_HOSTS): ?array
    {
        if ($typed === '' || strlen($typed) > self::LINK_MAX) {
            return null;
        }
        $bare = preg_match('~^https?://~i', $typed) !== 1;
        if ($bare && preg_match('/^(?:www\.|groddleafternoon\.com(?![A-Za-z0-9-]))/i', $typed) !== 1) {
            return null;
        }
        $parts = parse_url($bare ? "https://{$typed}" : $typed);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $hostname = strtolower($parts['host']);
        if (preg_match(self::HOST_OK, $hostname) !== 1) {
            return null;
        }
        if ($bare && preg_match('/^www\./i', $typed) !== 1 && $hostname !== 'groddleafternoon.com') {
            return null;
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }
        $host = $hostname . ($port === null ? '' : ":{$port}");
        $href = "{$scheme}://{$host}" . (($parts['path'] ?? '') === '' ? '/' : $parts['path'])
            . (isset($parts['query']) ? '?' . $parts['query'] : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
        return ['href' => $href, 'host' => $host, 'home' => in_array($hostname, $homeHosts, true)];
    }

    /**
     * A text cut into words and links, in order (js/chat-links.js linkSegments):
     * ['text' => …] and ['text' => …, 'href', 'host', 'home'].
     *
     * @param list<string> $homeHosts
     * @return list<array<string, mixed>>
     */
    public static function linkSegments(string $s, array $homeHosts = self::HOME_HOSTS): array
    {
        $out = [];
        $words = static function (string $w) use (&$out): void {
            if ($w === '') {
                return;
            }
            $last = count($out) - 1;
            if ($last >= 0 && !isset($out[$last]['href'])) {
                $out[$last]['text'] .= $w;
            } else {
                $out[] = ['text' => $w];
            }
        };
        // Braces as the delimiters: the address characters hold ~ and #.
        $re = '{(^|[^A-Za-z0-9_@.\-/:%+])((?:https?://|www\.|groddleafternoon\.com(?![A-Za-z0-9-]))[' . self::URL_CHARS . ']*)}i';
        $at = 0;
        $from = 0;
        $n = strlen($s);
        while ($from <= $n && preg_match($re, $s, $m, PREG_OFFSET_CAPTURE, $from) === 1) {
            $start = $m[2][1];
            $raw = self::trimTrailing($m[2][0]);
            $link = $raw === '' ? null : self::readLink($raw, $homeHosts);
            if ($link === null) {
                $from = $start + 1;
                continue;
            }
            $words(substr($s, $at, $start - $at));
            $out[] = ['text' => $raw] + $link;
            $at = $start + strlen($raw);
            $from = $at;
        }
        $words(substr($s, $at));
        return $out;
    }

    /** Text for HTML, in a text node or a quoted attribute. */
    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** One line of a post as HTML: its words escaped, its addresses links. @param list<string> $homeHosts */
    public static function lineHtml(string $line, array $homeHosts): string
    {
        $out = '';
        foreach (self::linkSegments($line, $homeHosts) as $seg) {
            if (!isset($seg['href'])) {
                $out .= self::esc($seg['text']);
                continue;
            }
            $out .= $seg['home']
                ? '<a class="chat-link" data-chat-link="home" href="' . self::esc($seg['href']) . '" rel="ugc">' . self::esc($seg['text']) . '</a>'
                : '<a class="chat-link" data-chat-link="away" data-host="' . self::esc($seg['host']) . '" href="' . self::esc($seg['href']) . '" target="_blank" rel="' . self::LINK_REL . '" title="' . self::esc('Opens ' . $seg['host']) . '">' . self::esc($seg['text']) . '</a>';
        }
        return $out;
    }

    /**
     * A post's words as HTML, with basic Markdown (Austin 2026-11-11): paragraphs at blank
     * lines and line breaks kept, as before; **bold** (or __bold__), *italic* (or _italic_),
     * ~~struck~~, `code`, [words](link), lists ("- ", "* " or "1. " at a line's start), quotes
     * ("> ") and ``` fenced code blocks. Everything a player wrote is still escaped piece by
     * piece and nothing they write is ever HTML; a [words](link) link must pass readLink, as a
     * bare address does, or it stays as typed. A mark with no partner stays as typed, and an _
     * inside a word (snake_case) is a letter. Chat lines keep lineHtml (no Markdown).
     *
     * @param list<string> $homeHosts
     */
    public static function bodyHtml(string $body, array $homeHosts = self::HOME_HOSTS): string
    {
        $lines = explode("\n", trim($body, "\n"));
        $out = '';
        $para = [];
        $quote = [];
        $list = null; // 'ul' | 'ol'
        $items = [];
        $inline = static fn (string $l): string => self::inlineHtml($l, $homeHosts);
        $flush = static function () use (&$out, &$para, &$quote, &$list, &$items, $inline): void {
            if ($para !== []) {
                $out .= '<p>' . implode('<br />', array_map($inline, $para)) . '</p>';
                $para = [];
            }
            if ($quote !== []) {
                $out .= '<blockquote><p>' . implode('<br />', array_map($inline, $quote)) . '</p></blockquote>';
                $quote = [];
            }
            if ($list !== null) {
                $out .= "<{$list}>" . implode('', array_map(static fn (string $i): string => '<li>' . $inline($i) . '</li>', $items)) . "</{$list}>";
                $list = null;
                $items = [];
            }
        };
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];
            // A fence opens a code block only when a closing fence follows.
            if (preg_match('/^\s*```/', $line) === 1) {
                $close = null;
                for ($j = $i + 1; $j < $n; $j++) {
                    if (preg_match('/^\s*```\s*$/', $lines[$j]) === 1) {
                        $close = $j;
                        break;
                    }
                }
                if ($close !== null) {
                    $flush();
                    $out .= '<pre><code>' . self::esc(implode("\n", array_slice($lines, $i + 1, $close - $i - 1))) . '</code></pre>';
                    $i = $close;
                    continue;
                }
            }
            if (trim($line) === '') {
                $flush();
                continue;
            }
            if (preg_match('/^\s*[-*+]\s+(\S.*)$/', $line, $m) === 1 || preg_match('/^\s*\d{1,3}[.)]\s+(\S.*)$/', $line, $m) === 1) {
                $kind = preg_match('/^\s*[-*+]\s/', $line) === 1 ? 'ul' : 'ol';
                if ($list !== $kind || $para !== [] || $quote !== []) {
                    $flush();
                }
                $list = $kind;
                $items[] = $m[1];
                continue;
            }
            if (preg_match('/^\s*>\s?(.*)$/', $line, $m) === 1) {
                if ($quote === []) {
                    $flush();
                }
                $quote[] = $m[1];
                continue;
            }
            if ($list !== null || $quote !== []) {
                $flush();
            }
            $para[] = $line;
        }
        $flush();
        return $out;
    }

    /**
     * One line of a post with its inline Markdown: `code` first (its words exactly as typed),
     * then [words](link), then bare addresses (linkSegments), then the emphasis marks, paired
     * across the pieces between them so **a https://x.com link** stays bold around the link.
     *
     * @param list<string> $homeHosts
     */
    public static function inlineHtml(string $line, array $homeHosts = self::HOME_HOSTS): string
    {
        /** @var list<array{0: 'text'|'html', 1: string}> $toks */
        $toks = [];
        foreach (preg_split('/(`[^`\n]+`)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            if (preg_match('/^`[^`\n]+`$/', $piece) === 1) {
                $toks[] = ['html', '<code>' . self::esc(substr($piece, 1, -1)) . '</code>'];
                continue;
            }
            foreach (preg_split('/(\[[^\]\n]{1,200}\]\([^)\s]{1,600}\))/', $piece, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (preg_match('/^\[([^\]\n]{1,200})\]\(([^)\s]{1,600})\)$/', $part, $m) === 1 && ($link = self::readLink($m[2], $homeHosts)) !== null) {
                    $toks[] = ['html', self::anchor($link, self::emphasisHtml([['text', $m[1]]]))];
                    continue;
                }
                foreach (self::linkSegments($part, $homeHosts) as $seg) {
                    $toks[] = isset($seg['href']) ? ['html', self::anchor($seg, self::esc($seg['text']))] : ['text', $seg['text']];
                }
            }
        }
        return self::emphasisHtml($toks);
    }

    /** A link's tag around $inner (already HTML): home links open here, others after a confirm. */
    private static function anchor(array $link, string $inner): string
    {
        return $link['home']
            ? '<a class="chat-link" data-chat-link="home" href="' . self::esc($link['href']) . '" rel="ugc">' . $inner . '</a>'
            : '<a class="chat-link" data-chat-link="away" data-host="' . self::esc($link['host']) . '" href="' . self::esc($link['href']) . '" target="_blank" rel="' . self::LINK_REL . '" title="' . self::esc('Opens ' . $link['host']) . '">' . $inner . '</a>';
    }

    /**
     * The text pieces' emphasis marks as tags, everything else escaped; html pieces pass
     * through. A mark opens before a non-space and closes after one; _ and __ also need a
     * word edge (snake_case stays); pairs match last-opened first, so tags always nest, and
     * an unpaired or empty mark stays as typed.
     *
     * @param list<array{0: 'text'|'html', 1: string}> $toks
     */
    private static function emphasisHtml(array $toks): string
    {
        $tags = ['**' => 'strong', '__' => 'strong', '*' => 'em', '_' => 'em', '~~' => 'del'];
        $space = static fn (string $c): bool => $c === '' || preg_match('/\s/u', $c) === 1;
        $word = static fn (string $c): bool => $c !== '' && preg_match('/[\p{L}\p{N}]/u', $c) === 1;
        // Marks: [token, offset, mark, canOpen, canClose].
        $marks = [];
        $count = count($toks);
        foreach ($toks as $t => [$kind, $text]) {
            if ($kind !== 'text') {
                continue;
            }
            preg_match_all('/\*\*|__|~~|\*|_/', $text, $found, PREG_OFFSET_CAPTURE);
            foreach ($found[0] as [$mark, $at]) {
                $before = $at > 0 ? mb_substr(substr($text, 0, $at), -1) : ($t > 0 ? ($toks[$t - 1][0] === 'html' ? 'a' : mb_substr($toks[$t - 1][1], -1)) : '');
                $rest = substr($text, $at + strlen($mark));
                $after = $rest !== '' ? mb_substr($rest, 0, 1) : ($t + 1 < $count ? ($toks[$t + 1][0] === 'html' ? 'a' : mb_substr($toks[$t + 1][1], 0, 1)) : '');
                $open = !$space($after);
                $close = !$space($before);
                if ($mark[0] === '_') {
                    $open = $open && !$word($before);
                    $close = $close && !$word($after);
                }
                $marks[] = [$t, $at, $mark, $open, $close];
            }
        }
        // Pair them: a closer takes the nearest open mark of its kind; marks opened after it stay as typed.
        $paired = [];
        $stack = [];
        foreach ($marks as $k => $m) {
            if ($m[4]) {
                for ($s = count($stack) - 1; $s >= 0; $s--) {
                    $o = $marks[$stack[$s]];
                    if ($o[2] !== $m[2]) {
                        continue;
                    }
                    // Nothing between them: no tag.
                    if ($o[0] === $m[0] && $o[1] + strlen($o[2]) === $m[1]) {
                        break;
                    }
                    $paired[$stack[$s]] = 'open';
                    $paired[$k] = 'close';
                    $stack = array_slice($stack, 0, $s);
                    continue 2;
                }
            }
            if ($m[3]) {
                $stack[] = $k;
            }
        }
        // Where each paired mark sits: [token][offset] => [mark, open|close].
        $at = [];
        foreach ($paired as $k => $role) {
            $at[$marks[$k][0]][$marks[$k][1]] = [$marks[$k][2], $role];
        }
        $out = '';
        foreach ($toks as $t => [$kind, $text]) {
            if ($kind === 'html') {
                $out .= $text;
                continue;
            }
            $pos = 0;
            $here = $at[$t] ?? [];
            ksort($here);
            foreach ($here as $offset => [$mark, $role]) {
                $out .= self::esc(substr($text, $pos, $offset - $pos)) . ($role === 'open' ? "<{$tags[$mark]}>" : "</{$tags[$mark]}>");
                $pos = $offset + strlen($mark);
            }
            $out .= self::esc(substr($text, $pos));
        }
        return $out;
    }

    /**
     * A post's words without its Markdown marks, for one-line snippets and quotes: [words](link)
     * becomes words, `code` its words, and the emphasis marks, list and quote marks go.
     */
    public static function plain(string $body): string
    {
        $s = (string) preg_replace('/\[([^\]\n]{1,200})\]\(([^)\s]{1,600})\)/', '$1', $body);
        $s = (string) preg_replace('/^\s*```.*$/m', '', $s);
        $s = (string) preg_replace('/`([^`\n]+)`/', '$1', $s);
        $s = (string) preg_replace('/^\s*(?:[-*+]|\d{1,3}[.)]|>)\s+/m', '', $s);
        $s = (string) preg_replace('/(\*\*|__|~~)(?=\S)(.+?)(?<=\S)\1/u', '$2', $s);
        $s = (string) preg_replace('/(?<![\p{L}\p{N}*])\*(?=\S)(.+?)(?<=\S)\*(?![\p{L}\p{N}*])/u', '$1', $s);
        $s = (string) preg_replace('/(?<![\p{L}\p{N}_])_(?=\S)(.+?)(?<=\S)_(?![\p{L}\p{N}_])/u', '$1', $s);
        return $s;
    }

    /** "2 minutes ago", "3 hours ago", "Nov 4" (and the year when it is not this one), for $at seen at $now (unix). */
    public static function ago(int $at, int $now): string
    {
        $d = max(0, $now - $at);
        if ($d < 60) {
            return 'just now';
        }
        if ($d < 3600) {
            $m = intdiv($d, 60);
            return $m === 1 ? '1 minute ago' : "{$m} minutes ago";
        }
        if ($d < 86400) {
            $h = intdiv($d, 3600);
            return $h === 1 ? '1 hour ago' : "{$h} hours ago";
        }
        if ($d < 7 * 86400) {
            $days = intdiv($d, 86400);
            return $days === 1 ? 'yesterday' : "{$days} days ago";
        }
        return gmdate('Y', $at) === gmdate('Y', $now) ? gmdate('M j', $at) : gmdate('M j, Y', $at);
    }

    /** "1 reply", "12 replies". */
    public static function count(int $n, string $one, string $many): string
    {
        return $n === 1 ? "1 {$one}" : number_format($n) . " {$many}";
    }

    /**
     * A reaction chip's label: "Thumbs up: Ada, Bo and 3 others" (names in the order given).
     *
     * @param list<string> $names
     */
    public static function reactionLabel(string $key, array $names): string
    {
        $label = self::reactions()[$key][1] ?? $key;
        $shown = array_slice($names, 0, self::$namesShown);
        $rest = count($names) - count($shown);
        if ($rest > 0) {
            $shown[] = $rest === 1 ? '1 other' : "{$rest} others";
        }
        return $shown === [] ? $label : "{$label}: " . AdminConsole::listWords($shown);
    }
}
