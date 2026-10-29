<?php

declare(strict_types=1);

namespace Groddle\Minn;

/**
 * The restoration report's section menu (2026-10-29): a compact bar at the
 * top of the page that stays at the top of the window while the page scrolls
 * (about 14,600 px on a desktop, 29,700 on a phone), one link per band.
 *
 * It is the theme's page chrome, drawn over the rendered post content
 * (Extension's filterContent), so the page reads and jumps without
 * JavaScript and nothing is written to the page: the bands are each
 * <section class="... band ..." id="..."> the content has, in their order,
 * and each link's words are the masthead's own jump link to that band
 * (p.jump, edited in Minn Admin), else the band's h2. The masthead's jump
 * paragraph moves into the bar (its other links, such as Other remakes
 * compared, follow the bands), so the words are never shown twice. A band
 * added in Minn Admin gets a link by itself. Fewer than three bands, or no
 * masthead, and the content comes back as it was.
 *
 * Plain PHP with no engine classes, so tests run it with php -r.
 */
final class ReportMenu
{
    /** Bands a page needs before it gets a menu. */
    private const MIN_BANDS = 3;

    public static function insert(string $html): string
    {
        if (preg_match('#<header\b[^>]*\bclass="[^"]*\bmasthead\b[^"]*"[^>]*>#i', $html, $head, PREG_OFFSET_CAPTURE) !== 1) {
            return $html;
        }
        $headAt = (int) $head[0][1];
        $headEnd = stripos($html, '</header>', $headAt);
        if ($headEnd === false) {
            return $html;
        }
        $bands = self::bands($html);
        if (count($bands) < self::MIN_BANDS) {
            return $html;
        }

        // The masthead's jump links: their words label the bands they point at.
        $labels = [];
        $others = [];
        $jump = null;
        $masthead = substr($html, $headAt, $headEnd - $headAt);
        if (preg_match('#<p\b[^>]*\bclass="[^"]*\bjump\b[^"]*"[^>]*>.*?</p>#is', $masthead, $p, PREG_OFFSET_CAPTURE) === 1) {
            $jump = [$headAt + (int) $p[0][1], strlen($p[0][0])];
            preg_match_all('#<a\b[^>]*\bhref="([^"]*)"[^>]*>(.*?)</a>#is', $p[0][0], $links, PREG_SET_ORDER);
            foreach ($links as [, $href, $words]) {
                $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
                $text = self::text($words);
                if ($text === '' || !self::safeHref($href)) {
                    continue;
                }
                if (str_starts_with($href, '#') && isset($bands[substr($href, 1)])) {
                    $labels[substr($href, 1)] ??= $text;
                } else {
                    $others[] = [$href, $text];
                }
            }
        }

        $items = '';
        foreach ($bands as $id => $heading) {
            $items .= '<li><a href="#' . self::attr($id) . '">' . self::esc($labels[$id] ?? $heading) . '</a></li>';
        }
        foreach ($others as [$href, $text]) {
            $items .= '<li class="report-menu-out"><a href="' . self::attr($href) . '">' . self::esc($text) . '</a></li>';
        }
        $nav = '<nav class="report-menu" aria-label="On this page"><div class="wrap report-menu-row">'
            . '<ul class="report-menu-links">' . $items . '</ul></div></nav>' . "\n";

        if ($jump !== null) {
            $html = substr($html, 0, $jump[0]) . substr($html, $jump[0] + $jump[1]);
        }
        // Before the masthead, as the content's first child: sticky for the whole page.
        return substr($html, 0, $headAt) . $nav . substr($html, $headAt);
    }

    /**
     * The page's bands in order: id => the words of its first h2.
     *
     * @return array<string, string>
     */
    public static function bands(string $html): array
    {
        preg_match_all('#<section\b([^>]*)>#i', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $found = [];
        foreach ($tags as $tag) {
            $attrs = $tag[1][0];
            if (preg_match('#\bclass="([^"]*)"#i', $attrs, $class) !== 1 || preg_match('#(?:^|\s)band(?:\s|$)#', $class[1]) !== 1) {
                continue;
            }
            if (preg_match('#\bid="([A-Za-z][\w-]*)"#', $attrs, $id) !== 1 || isset($found[$id[1]])) {
                continue;
            }
            $found[$id[1]] = (int) $tag[0][1] + strlen($tag[0][0]);
        }
        $ids = array_keys($found);
        $out = [];
        foreach ($ids as $i => $id) {
            $from = $found[$id];
            $to = isset($ids[$i + 1]) ? $found[$ids[$i + 1]] : strlen($html);
            $heading = preg_match('#<h2\b[^>]*>(.*?)</h2>#is', substr($html, $from, $to - $from), $h) === 1 ? self::text($h[1]) : '';
            $out[$id] = $heading !== '' ? $heading : ucfirst(str_replace(['-', '_'], ' ', $id));
        }
        return $out;
    }

    /** Visible words of a piece of markup, entities decoded, spaces folded. */
    private static function text(string $markup): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($markup), ENT_QUOTES | ENT_HTML5)));
    }

    /** An in-page anchor, a path on the site, or a web address. */
    private static function safeHref(string $href): bool
    {
        return preg_match('#^(?:\#[A-Za-z][\w-]*|/(?!/)[^\s"<>]*|https?://[^\s"<>]+)$#', $href) === 1;
    }

    private static function esc(string $s): string
    {
        return self::attr($s);
    }

    private static function attr(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
