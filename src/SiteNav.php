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
 * makes Play the button). The link whose section holds the path being read
 * is marked aria-current, as the built pages mark theirs; a link in
 * SECTIONS also stands for the pages it gathers. The sync writes
 * the same menu into the built pages (groddle-afternoon tools/site-nav/apply.mjs), so one
 * edit changes every page.
 */
final class SiteNav
{
    /** The links when no menu exists yet: the header the built pages ship with. */
    public const FALLBACK = [
        ['Restoration', '/restoration/', ''],
        ['Compare', '/compare/', ''],
        ['Items', '/items/', ''],
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
    ];

    public static function register(Seams $minn): void
    {
        $minn->block('blocks/site-nav', static fn (array $a): string => self::nav(self::links($minn, (int) $a['ref']), $minn->request->path));
    }

    /**
     * The menu's links as [label, href, class], top level only.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function links(Seams $minn, int $ref): array
    {
        $db = $minn->db;
        $posts = $db->table('posts');
        $row = $ref > 0
            ? $db->row("SELECT post_content FROM {$posts} WHERE ID = ? AND post_type = 'wp_navigation' AND post_status = 'publish'", [$ref])
            : $db->row("SELECT post_content FROM {$posts} WHERE post_type = 'wp_navigation' AND post_status = 'publish' ORDER BY post_date DESC, ID DESC LIMIT 1");
        $links = [];
        foreach (Parser::parse((string) ($row['post_content'] ?? '')) as $block) {
            $link = self::link($block);
            if ($link !== null) {
                $links[] = $link;
            }
        }
        return $links === [] ? self::FALLBACK : $links;
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

    /** @param list<array{0: string, 1: string, 2: string}> $links */
    public static function nav(array $links, string $path): string
    {
        $current = self::current($links, $path);
        $items = '';
        foreach ($links as $i => [$label, $href, $class]) {
            $items .= "\n      <a" . ($class === '' ? '' : ' class="' . Html::attr($class) . '"') . ' href="' . Html::attr($href) . '"' . ($i === $current ? ' aria-current="page"' : '') . '>' . Html::esc($label) . '</a>';
        }
        return '<nav class="site-nav" aria-label="Site">' . "\n"
            . '  <div class="wrap site-nav-row">' . "\n"
            . '    <a class="wordmark" href="/"' . ($path === '/' ? ' aria-current="page"' : '') . '><img src="/favicon.svg?v=quoin" alt="" width="28" height="28" /><span>Groddle Afternoon</span></a>' . "\n"
            . '    <div class="site-nav-links">' . $items . "\n    </div>\n"
            . '    <button class="site-nav-theme" type="button" aria-label="Colour theme"></button>' . "\n  </div>\n</nav>";
    }

    /**
     * The link whose section holds the path: the longest site path that
     * starts it (the link's own, or one it stands for in SECTIONS, which
     * loses a tie to a link's own), never the home page or the play button.
     *
     * @param list<array{0: string, 1: string, 2: string}> $links
     */
    public static function current(array $links, string $path): ?int
    {
        $best = null;
        $score = 0;
        $page = rtrim($path, '/') . '/';
        foreach ($links as $i => [, $href, $class]) {
            if ($href === '/' || !str_starts_with($href, '/') || str_contains($class, 'site-nav-play')) {
                continue;
            }
            $own = rtrim($href, '/') . '/';
            foreach ([$own, ...(self::SECTIONS[$own] ?? [])] as $section) {
                $points = strlen($section) * 2 + ($section === $own ? 1 : 0);
                if (str_starts_with($page, $section) && $points > $score) {
                    $best = $i;
                    $score = $points;
                }
            }
        }
        return $best;
    }
}
