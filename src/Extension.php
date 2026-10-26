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
 * (Restoration), the Musicblocks and Street music pages' players
 * (Musicblocks, StreetMusic), the header's menu (SiteNav), and the built game
 * and generated pages served beside the content. For players: the
 * groddle/v1 REST routes (Player, MultiplayerSave, SoloBackups) over the
 * tables minn.json declares, and the realtime server's ticket
 * (RealtimeTicket), signed-in players only, nonce-checked.
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
        // The editor's block previews wear the same stylesheets (the theme's own style.css it loads already).
        $minn->editorStyles(...array_slice(self::STYLESHEETS, 0, -1));
        // The Notes list under month headings (the query loop draws one list).
        $minn->filterBlocks(static fn (Block $block, string $html): string => $block->name === 'core/post-template' && str_contains($html, 'note-cards') ? NoteMonths::group($html) : $html);
        $root = StaticRoot::fromSite($minn);
        $minn->routes(new StaticPages($minn, $root));
        $minn->rest(new Player($minn), new MultiplayerSave($minn), new SoloBackups($minn), new RealtimeTicket($minn));
        Blocks::register($minn);
        Restoration::register($minn, $root);
        Musicblocks::register($minn, $root);
        StreetMusic::register($minn, $root);
        SiteNav::register($minn);
    }

    private static function head(): string
    {
        $out = '';
        foreach (self::STYLESHEETS as $href) {
            $out .= '<link rel="stylesheet" href="' . $href . '" />' . "\n";
        }
        return $out
            // The colour theme before anything paints, and the header's theme button (/site/theme.js).
            . '<script src="/site/theme.js"></script>' . "\n"
            . '<link rel="icon" href="/favicon.svg?v=quoin" type="image/svg+xml" />' . "\n"
            . '<link rel="icon" href="/favicon.ico?v=quoin" sizes="32x32" />' . "\n"
            . '<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=quoin" />' . "\n";
    }
}
