<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Blocks\Block;
use Minn\Blocks\Parser;
use Minn\Extension\Seams;
use Minn\Html;

/**
 * The site header (blocks/site-nav): the wordmark, then the links of a
 * Navigation menu, a wp_navigation post edited in Minn Admin under Design,
 * Navigation. `ref` names the menu; 0 takes the newest published one. Each
 * link is a core/navigation-link; its className rides along (site-nav-play
 * makes Play the button). A core/navigation-submenu is a link with a
 * dropdown of the links inside it (one level; deeper ones join that list):
 * the link, a toggle button and a hidden panel, all on one line, which
 * site/theme.js opens. The link whose section holds the path being read
 * is marked aria-current, as the built pages mark theirs; a link in
 * SECTIONS, or a submenu through its links, also stands for the pages it
 * gathers, and the panel marks its own link to the page too. The sync writes
 * the same menu into the built pages (groddle-afternoon tools/site-nav/apply.mjs), so one
 * edit changes every page.
 */
final class SiteNav
{
    /** The links when no menu exists yet: the header the built pages ship with. */
    public const FALLBACK = [
        ['Restoration', '/restoration/', ''],
        ['Compare', '/compare/', ''],
        ['Items', '/encyclopedia/items/', ''],
        ['Notes', '/changelog/', ''],
        ['Play', '/play/', 'site-nav-play'],
    ];

    /**
     * Pages a link stands for besides its own path, so its section is
     * marked on them too: Project gathers the restoration story (Austin
     * 2026-10-28, register G338). A link straight to the page still wins.
     * groddle-afternoon tools/site-nav/apply.mjs keeps the same list.
     */
    public const SECTIONS = [
        '/project/' => ['/restoration/', '/compare/', '/changelog/', '/code/'],
        '/encyclopedia/items/' => ['/encyclopedia/'],
        '/items/' => ['/encyclopedia/'],
    ];

    public static function register(Seams $minn): void
    {
        $minn->block('blocks/site-nav', static fn (array $a): string => self::nav(self::links($minn, (int) $a['ref']), $minn->request->path, self::badges($minn)));
    }

    /**
     * Counts the header shows on links for the signed-in reader, href =>
     * n: the Community link's unread notifications (a reply to your topic, a
     * quote, replies under your chat line, IMs you haven't answered, buddy
     * adds, the team's answer to a report; Notifications::unread), the same
     * count as the chat app's bell. Only pages drawn here carry them; the
     * built pages' copy of the menu (tools/site-nav/apply.mjs) has none.
     *
     * @return array<string, int>
     */
    public static function badges(Seams $minn): array
    {
        $reader = $minn->reader->userId;
        if ($reader <= 0) {
            return [];
        }
        $n = (new Notifications($minn))->unread($reader);
        return $n > 0 ? [self::COMMUNITY => $n] : [];
    }

    /** The Community boards' address (CommunityPages), whose menu link carries the unread badge. */
    public const COMMUNITY = '/chat/';

    /**
     * The menu's top-level links as [label, href, class], a submenu's with
     * its links as a fourth item, [[label, href, class], ...].
     *
     * @return list<array{0: string, 1: string, 2: string, 3?: list<array{0: string, 1: string, 2: string}>}>
     */
    public static function links(Seams $minn, int $ref): array
    {
        $db = $minn->db;
        $posts = $db->table('posts');
        $row = $ref > 0
            ? $db->row("SELECT post_content FROM {$posts} WHERE ID = ? AND post_type = 'wp_navigation' AND post_status = 'publish'", [$ref])
            : $db->row("SELECT post_content FROM {$posts} WHERE post_type = 'wp_navigation' AND post_status = 'publish' ORDER BY post_date DESC, ID DESC LIMIT 1");
        $links = self::parse((string) ($row['post_content'] ?? ''));
        return $links === [] ? self::FALLBACK : $links;
    }

    /**
     * The top-level links of wp_navigation block markup (links() without the database).
     *
     * @return list<array{0: string, 1: string, 2: string, 3?: list<array{0: string, 1: string, 2: string}>}>
     */
    public static function parse(string $markup): array
    {
        $links = [];
        foreach (Parser::parse($markup) as $block) {
            $link = self::link($block);
            if ($link === null) {
                continue;
            }
            $children = [];
            foreach (self::inner($block) as $inner) {
                $child = self::link($inner);
                if ($child !== null) {
                    $children[] = $child;
                }
            }
            $links[] = $children === [] ? $link : [...$link, $children];
        }
        return $links;
    }

    /** @return list<Block> A submenu's blocks, deeper submenus' links in their place after them. */
    private static function inner(Block $block): array
    {
        if ($block->name !== 'core/navigation-submenu') {
            return [];
        }
        $out = [];
        foreach ($block->innerBlocks as $inner) {
            $out = [...$out, $inner, ...self::inner($inner)];
        }
        return $out;
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private static function link(Block $block): ?array
    {
        if ($block->name !== 'core/navigation-link' && $block->name !== 'core/navigation-submenu') {
            return null;
        }
        $label = trim(html_entity_decode(strip_tags((string) $block->attr('label', '')), ENT_QUOTES | ENT_HTML5));
        $href = trim((string) $block->attr('url', ''));
        return $label === '' || $href === '' ? null : [$label, $href, trim((string) $block->attr('className', ''))];
    }

    /**
     * The header. $app draws the chat app's layout instead (Austin 2026-11-04, /chat/ only,
     * CommunityPages): the same block, links and theme button, full width in one compact row,
     * the wordmark over the app's left rail, and for a signed-in reader ($app['bell'], the unread
     * count) the notifications bell; on a phone the links fold behind a menu button
     * (community.js). site/site-nav.css names the header, its mark and name, the links and the theme
     * button for the page-to-page View Transition, so the centred header stretches into this one and back.
     *
     * @param list<array{0: string, 1: string, 2: string, 3?: list<array{0: string, 1: string, 2: string}>}> $links
     * @param array<string, int> $badges href => a count its link shows (badges())
     * @param array{bell?: int|null}|null $app
     */
    public static function nav(array $links, string $path, array $badges = [], ?array $app = null): string
    {
        $current = self::current($links, $path);
        $items = '';
        foreach ($links as $i => $link) {
            $item = self::item($link, $i, $i === $current, $path);
            $n = $badges[rtrim((string) (parse_url($link[1], PHP_URL_PATH) ?? ''), '/') . '/'] ?? 0;
            if ($n > 0) {
                // The count inside the link, read with it ("Community, 2 new").
                $item = (string) preg_replace('~</a>~', self::badge($n) . '</a>', $item, 1);
            }
            $items .= "\n      " . $item;
        }
        $wordmark = '    <a class="wordmark" href="/"' . ($path === '/' ? ' aria-current="page"' : '') . '><img src="/favicon.svg?v=arch" alt="" width="28" height="28" /><span>Groddle Afternoon</span></a>' . "\n";
        $theme = '    <button class="site-nav-theme" type="button" aria-label="Colour theme"></button>' . "\n  </div>\n</nav>";
        if ($app === null) {
            return '<nav class="site-nav" aria-label="Site">' . "\n"
                . '  <div class="wrap site-nav-row">' . "\n"
                . $wordmark
                . '    <div class="site-nav-links">' . $items . "\n    </div>\n"
                . $theme;
        }
        $bell = isset($app['bell']) ? self::bell((int) $app['bell']) . "\n" : '';
        return '<nav class="site-nav site-nav-app" aria-label="Site">' . "\n"
            . '  <div class="site-nav-row">' . "\n"
            . $wordmark
            . '    <button class="site-nav-menu" type="button" aria-expanded="false" aria-controls="site-nav-links" aria-label="Menu" hidden></button>' . "\n"
            . '    <div class="site-nav-links" id="site-nav-links">' . $items . "\n    </div>\n"
            . $bell
            . $theme;
    }

    /** The chat app's notifications bell with its unread count (community/notifications.js opens its panel). */
    public static function bell(int $n): string
    {
        return '    <div class="site-nav-bell" data-notif><button class="site-nav-bell-btn" type="button" aria-haspopup="dialog" aria-expanded="false" aria-controls="site-nav-notif" data-notif-open>'
            . '<span class="visually-hidden">Notifications</span><span class="site-nav-bell-n" data-notif-n' . ($n > 0 ? '' : ' hidden') . '><span class="visually-hidden">, </span><span data-notif-count>' . ($n > 9 ? '9+' : ($n > 0 ? (string) $n : '')) . '</span><span class="visually-hidden"> new</span></span></button></div>';
    }

    /**
     * One top-level item on one line (the built pages' rewrite reads a line
     * per item): a link, or a submenu's link, toggle and panel. The same
     * markup as renderItem in groddle-afternoon tools/site-nav/apply.mjs.
     *
     * @param array{0: string, 1: string, 2: string, 3?: list<array{0: string, 1: string, 2: string}>} $link
     */
    public static function item(array $link, int $index, bool $current, string $path): string
    {
        [$label, $href, $class] = $link;
        $a = self::anchor($label, $href, $class, $current);
        $children = $link[3] ?? [];
        if ($children === []) {
            return $a;
        }
        $id = 'site-nav-sub-' . $index;
        $child = $current ? self::currentChild($children, $path) : null;
        $panel = '';
        foreach ($children as $j => [$childLabel, $childHref, $childClass]) {
            $panel .= self::anchor($childLabel, $childHref, $childClass, $j === $child);
        }
        return '<div class="site-nav-sub">' . $a
            . '<button class="site-nav-toggle" type="button" aria-expanded="false" aria-controls="' . $id . '" aria-label="' . Html::attr('More in ' . $label) . '"></button>'
            . '<div class="site-nav-panel" id="' . $id . '" hidden>' . $panel . '</div></div>';
    }

    /** A link's count badge: the number shown, ", N new" for a screen reader. */
    public static function badge(int $n): string
    {
        return '<span class="site-nav-badge"><span class="visually-hidden">, </span>' . ($n > 9 ? '9+' : $n) . '<span class="visually-hidden"> new</span></span>';
    }

    private static function anchor(string $label, string $href, string $class, bool $current): string
    {
        return '<a' . ($class === '' ? '' : ' class="' . Html::attr($class) . '"') . ' href="' . Html::attr($href) . '"' . ($current ? ' aria-current="page"' : '') . '>' . Html::esc($label) . '</a>';
    }

    /**
     * The link whose section holds the path: the longest site path that
     * starts it (the link's own, one it stands for in SECTIONS, or one of a
     * submenu's links; the others lose a tie to a link's own), never the
     * home page or the play button.
     *
     * @param list<array{0: string, 1: string, 2: string, 3?: list<array{0: string, 1: string, 2: string}>}> $links
     */
    public static function current(array $links, string $path): ?int
    {
        $best = null;
        $score = 0;
        $page = rtrim($path, '/') . '/';
        foreach ($links as $i => $link) {
            [, $href, $class] = $link;
            if (!self::sitePath($href) || str_contains($class, 'site-nav-play')) {
                continue;
            }
            $own = rtrim($href, '/') . '/';
            $sections = [$own, ...(self::SECTIONS[$own] ?? [])];
            foreach ($link[3] ?? [] as [, $childHref]) {
                if (self::sitePath($childHref)) {
                    $sections[] = rtrim($childHref, '/') . '/';
                }
            }
            foreach ($sections as $section) {
                $points = strlen($section) * 2 + ($section === $own ? 1 : 0);
                if (str_starts_with($page, $section) && $points > $score) {
                    $best = $i;
                    $score = $points;
                }
            }
        }
        return $best;
    }

    /**
     * The submenu link whose path holds the page (the longest), or none.
     *
     * @param list<array{0: string, 1: string, 2: string}> $children
     */
    public static function currentChild(array $children, string $path): ?int
    {
        $best = null;
        $length = 0;
        $page = rtrim($path, '/') . '/';
        foreach ($children as $j => [, $href]) {
            $own = rtrim($href, '/') . '/';
            if (self::sitePath($href) && str_starts_with($page, $own) && strlen($own) > $length) {
                $best = $j;
                $length = strlen($own);
            }
        }
        return $best;
    }

    /** A path on this site other than the home page. */
    private static function sitePath(string $href): bool
    {
        return $href !== '/' && str_starts_with($href, '/') && !str_starts_with($href, '//');
    }
}
