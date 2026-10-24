<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Blocks\Block;
use Minn\Extension\Extension as MinnExtension;
use Minn\Extension\Seams;

/**
 * Groddle Afternoon on Minn, the theme's own extension (its minn.json): on
 * while Groddle is the theme. The templates draw notes, pages and posts in
 * the site's look; this adds what templates cannot: the site's stylesheets,
 * the note blocks (Blocks), the restoration report's data sections
 * (Restoration), the Musicblocks entry's players (Musicblocks), the wiki's
 * topic strip (Wiki), and the built game and generated pages served beside
 * the content.
 */
final class Extension implements MinnExtension
{
    /** The built site's stylesheets the templates are drawn against, then the theme's own fixes. */
    private const STYLESHEETS = [
        '/restoration/restoration.css',
        '/changelog/changelog.css',
        '/site/site-nav.css',
        '/wp-content/themes/groddle/style.css',
    ];

    public function register(Seams $minn): void
    {
        $minn->head(static fn (): string => self::head());
        $minn->bodyClass('notes');
        // The nav marks the section being read, as the built pages do (site-nav.css styles aria-current).
        $minn->filterDocument(static fn (string $html): string => self::current($html, $minn->request->path));
        // The editor's block previews wear the same stylesheets (the theme's own style.css it loads already).
        $minn->editorStyles(...array_slice(self::STYLESHEETS, 0, -1));
        // The Notes list under month headings (the query loop draws one list).
        $minn->filterBlocks(static fn (Block $block, string $html): string => $block->name === 'core/post-template' && str_contains($html, 'note-cards') ? NoteMonths::group($html) : $html);
        $root = StaticRoot::fromSite($minn);
        $minn->routes(new StaticPages($minn, $root));
        Blocks::register($minn);
        Restoration::register($minn, $root);
        Musicblocks::register($minn, $root);
        Wiki::register($minn);
    }

    /** aria-current on the first nav link whose section holds the path (the nav comes first in the document). */
    private static function current(string $html, string $path): string
    {
        foreach (['/restoration/', '/compare/', '/wiki/', '/changelog/'] as $section) {
            if (str_starts_with($path, $section)) {
                $link = '<a href="' . $section . '">';
                $at = strpos($html, $link);
                return $at === false ? $html : substr_replace($html, '<a href="' . $section . '" aria-current="page">', $at, strlen($link));
            }
        }
        return $html;
    }

    private static function head(): string
    {
        $out = '';
        foreach (self::STYLESHEETS as $href) {
            $out .= '<link rel="stylesheet" href="' . $href . '" />' . "\n";
        }
        return $out
            . '<link rel="icon" href="/favicon.svg?v=quoin" type="image/svg+xml" />' . "\n"
            . '<link rel="icon" href="/favicon.ico?v=quoin" sizes="32x32" />' . "\n"
            . '<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=quoin" />' . "\n";
    }
}
