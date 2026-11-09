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
 * (Restoration) and its section menu (ReportMenu), the Musicblocks and Street music pages' players
 * (Musicblocks, StreetMusic), the Contribute page's shrine and open ledger
 * (Contribute) and its board of testers whose reports led to a change (ContributeCredits), the header's menu (SiteNav), and the built game
 * and generated pages served beside the content, with the old addresses of the
 * ones that moved (MovedPages). For players: the
 * groddle/v1 REST routes: the game's own sign-up and sign-in by email
 * (PlayerAccount) and with Google (GoogleSignIn), who is signed in (Player), the saves over the tables
 * minn.json declares (MultiplayerSave, SoloBackups), the realtime
 * server's ticket (RealtimeTicket), buddy lists (Buddies) and which buddies are online for the phone companion (BuddiesOnline), groups and their chat (Groups), profiles
 * (Profiles) and visiting each other's homes with house keys (Neighbours), the owner's Butler taking visitors' parcels (ButlerParcels) and handing them the owner's gift, noting who came (ButlerGifts) and key-holders tending their gardens (HomeTending), the one-time payout by mail of players' own plots when the public gardens became shared (GardenPayout, administrators only) and the hand-over of their own street Patches when those became shared (PatchPayout: one winner per Patch for the realtime server, the rest refunded by mail; administrators only), the Magic Rock's daily iMG from visitors (HomePool) and what they pet, dig and water on a home street (HomeYard), the machines an owner sets down there for everyone to use (HomeMachines), selling from a Storage Display Box and a key-holder's cabinet moves (LedgerBoxes), the Auction House (LedgerAuction), mail between players (LedgerMail), bug reports as threads with the administrators (Tickets), signed-in players only, nonce-checked; an administrator's teleport for a stuck player (AdminTeleport, manage_options only) and the administrators' console (AdminConsole, its Reports tab AdminTickets, the credit a report earned AdminReportCredits, AdminConsolePage at /console/, manage_options only), moderation (ModReports: players report an IM, the content-free signals' flags; Moderation: the moderators' feed and actions for Grok Bot's token, ModTokens, and the administrators, groddle/v1/mod/*), and the level builder's drafts, page and new art (AdminBuilder, AdminBuilderPage at /console/builder/, AdminBuilderAssets and BuilderArt, and for agents builder keys (AdminBuilderKeys, BuilderKeys, BuilderAuth) and the builder worker's validate, render, guide, catalog and style (AdminBuilderAgent, BuilderWorker), behind the option groddle_builder_enabled); the public
 * Players pages (PlayerPages), and the Community boards at /chat/ (CommunityPages; their data and
 * notices Community, the console's Community tab AdminCommunity, the chat app's bell Notifications), and the game's chat kept for good (ChatHistory: the realtime server's
 * batches in, channel lines and a player's own IMs out to signed-in players, a line from the website to the server), and each
 * player's play time in user meta for the administrators (PlayTime: the realtime server's signed batches in, a read-only
 * admin list and a one-time estimate from the saves, manage_options only; nothing a player sees).
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

    /** The theme's own files, served from this repo through the theme folder. */
    private const THEME_URL = '/wp-content/themes/groddle/';

    /** The built game's folder (StaticRoot), for the keys of the files head() links; set in register(). */
    private static string $staticDir = '';

    public function register(Seams $minn): void
    {
        $minn->head(static fn (): string => self::head());
        // Link previews (Open Graph and Twitter cards) on every page Minn renders.
        $home = (string) ($minn->site->option('home') ?? '');
        $path = $minn->request->path;
        $minn->filterDocument(static fn (string $html): string => SocialTags::insert($html, $home, $path));
        $minn->bodyClass('notes');
        // The editor's block previews wear the same stylesheets (the theme's own style.css it loads already).
        $minn->editorStyles(...array_slice(self::STYLESHEETS, 0, -1));
        // The Notes list under month headings (the query loop draws one list).
        $minn->filterBlocks(static fn (Block $block, string $html): string => $block->name === 'core/post-template' && str_contains($html, 'note-cards') ? NoteMonths::group($html) : $html);
        $root = StaticRoot::fromSite($minn);
        self::$staticDir = $root->dir;
        // Old addresses of built pages that moved (/items/ is /encyclopedia/items/ now), before the built folders.
        $minn->routes(new MovedPages());
        $minn->routes(new StaticPages($minn, $root));
        // The Players pages and the pictures on them (G338), after the built folders.
        $minn->routes(new PlayerPages($minn, $root));
        // The Community boards (/chat/; their data is Community's REST routes).
        $minn->routes(new CommunityPages($minn));
        // The administrators' console page (/console/; its data is AdminConsole's REST routes).
        $minn->routes(new AdminConsolePage($minn));
        // The level builder's page (/console/builder/; its drafts are AdminBuilder's REST routes), when groddle_builder_enabled is on.
        $minn->routes(new AdminBuilderPage($minn));
        // The level builder's new art (BuilderAssets), as image/webp to administrators, when the builder is on.
        $minn->routes(new BuilderArt($minn));
        // A bug report's picture of the game, to its player and the administrators.
        $minn->routes(new TicketPictures($minn));
        // /privacy goes to the privacy policy page.
        $minn->routes(new PrivacyLinks($minn));
        $minn->rest(new Player($minn), new PlayerAccount($minn), new MultiplayerSave($minn), new SoloBackups($minn), new RealtimeTicket($minn), new Buddies($minn), new BuddiesOnline($minn), new Profiles($minn), new LedgerService($minn), new Groups($minn), new LedgerSpend($minn), new Neighbours($minn), new ButlerParcels($minn), new ButlerGifts($minn), new HomeTending($minn), new GardenPayout($minn), new PatchPayout($minn), new HomePool($minn), new HomeYard($minn), new HomeMachines($minn), new Radiance($minn), new LedgerGift($minn), new LedgerAuction($minn), new LedgerMail($minn), new LedgerCrowns($minn), new LedgerMayhem($minn), new LedgerCredits($minn), new LedgerUse($minn), new LedgerDrop($minn), new LedgerBoard($minn), new BugReports($minn), new Tickets($minn), new LedgerHall($minn), new LedgerStarter($minn), new HomeSignpost($minn), new HomeStreet($minn), new LedgerFurniture($minn), new LedgerBoxes($minn), new Snaps($minn), new AdminTeleport($minn), new AdminConsole($minn), new AdminTickets($minn), new AdminBoards($minn), new Community($minn), new AdminCommunity($minn), new ChatHistory($minn), new PresenceSetting($minn), new PlayTime($minn), new ChatPeople($minn), new Notifications($minn), new AdminReportCredits($minn), new AdminBuilder($minn), new AdminBuilderAssets($minn), new AdminBuilderKeys($minn), new AdminBuilderAgent($minn), new GoogleSignIn($minn), new ModReports($minn), new Moderation($minn));
        Blocks::register($minn);
        Restoration::register($minn, $root);
        // The report's section menu, drawn over its rendered content (nothing is written to the page).
        $minn->filterContent(static fn (string $html): string => Restoration::drawn() ? ReportMenu::insert($html) : $html);
        Musicblocks::register($minn, $root);
        StreetMusic::register($minn, $root);
        Codebase::register($minn, $root);
        Contribute::register($minn, $root);
        ContributeCredits::register($minn);
        SiteNav::register($minn);
    }

    /**
     * `$href` with its file's content key (AssetKeys): the theme's own files from this repo, the
     * rest from the built game; as it is when the file is not there. Report 95: the stylesheets
     * went out with no version, so a browser kept an old style.css after a deploy and the
     * Contribute page's new boards came out unstyled.
     */
    public static function keyed(string $href): string
    {
        return str_starts_with($href, self::THEME_URL)
            ? AssetKeys::url($href, dirname(__DIR__), substr($href, strlen(self::THEME_URL)))
            : AssetKeys::url($href, self::$staticDir, ltrim($href, '/'));
    }

    /** The stylesheets, theme script and icons every page in the site's look carries (PlayerPages draws its own pages with it). */
    public static function head(): string
    {
        $out = '';
        foreach (self::STYLESHEETS as $href) {
            $out .= '<link rel="stylesheet" href="' . self::keyed($href) . '" />' . "\n";
        }
        return $out
            // The colour theme before anything paints, and the header's theme button (/site/theme.js).
            . '<script src="' . self::keyed('/site/theme.js') . '"></script>' . "\n"
            . '<link rel="icon" href="/favicon.svg?v=arch" type="image/svg+xml" />' . "\n"
            . '<link rel="icon" href="/favicon.ico?v=arch" sizes="32x32" />' . "\n"
            . '<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=arch" />' . "\n"
            // Fathom analytics, counted only on the live domain (groddle.localhost stays out).
            . '<!-- Fathom - beautiful, simple website analytics -->' . "\n"
            . '<script src="https://cdn.usefathom.com/script.js" data-site="OTSJXDRZ" data-included-domains="groddleafternoon.com" defer></script>' . "\n"
            . '<!-- / Fathom -->' . "\n";
    }
}
