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
 * groddle/v1 REST routes: the game's own sign-up and sign-in by email
 * (PlayerAccount), who is signed in (Player), the saves over the tables
 * minn.json declares (MultiplayerSave, SoloBackups), the realtime
 * server's ticket (RealtimeTicket), buddy lists (Buddies), groups and their chat (Groups), profiles
 * (Profiles) and visiting each other's homes with house keys (Neighbours), the owner's Butler taking visitors' parcels (ButlerParcels) and key-holders tending their gardens (HomeTending), the Magic Rock's daily iMG from visitors (HomePool) and what they pet, dig and water on a home street (HomeYard), the Auction House (LedgerAuction), signed-in players only, nonce-checked; and the public
 * Players pages (PlayerPages).
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
        // The Players pages and the pictures on them (G338), after the built folders.
        $minn->routes(new PlayerPages($minn, $root));
        $minn->rest(new Player($minn), new PlayerAccount($minn), new MultiplayerSave($minn), new SoloBackups($minn), new RealtimeTicket($minn), new Buddies($minn), new Profiles($minn), new LedgerService($minn), new Groups($minn), new LedgerSpend($minn), new Neighbours($minn), new ButlerParcels($minn), new HomeTending($minn), new HomePool($minn), new HomeYard($minn), new Radiance($minn), new LedgerGift($minn), new LedgerAuction($minn), new LedgerCrowns($minn), new LedgerCredits($minn), new LedgerUse($minn), new LedgerDrop($minn), new BugReports($minn), new LedgerHall($minn), new LedgerStarter($minn), new HomeSignpost($minn), new HomeStreet($minn), new LedgerFurniture($minn), new Snaps($minn));
        Blocks::register($minn);
        Restoration::register($minn, $root);
        Musicblocks::register($minn, $root);
        StreetMusic::register($minn, $root);
        Codebase::register($minn, $root);
        SiteNav::register($minn);
    }

    /** The stylesheets, theme script and icons every page in the site's look carries (PlayerPages draws its own pages with it). */
    public static function head(): string
    {
        $out = '';
        foreach (self::STYLESHEETS as $href) {
            $out .= '<link rel="stylesheet" href="' . $href . '" />' . "\n";
        }
        return $out
            // The colour theme before anything paints, and the header's theme button (/site/theme.js).
            . '<script src="/site/theme.js"></script>' . "\n"
            . '<link rel="icon" href="/favicon.svg?v=arch" type="image/svg+xml" />' . "\n"
            . '<link rel="icon" href="/favicon.ico?v=arch" sizes="32x32" />' . "\n"
            . '<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=arch" />' . "\n";
    }
}
