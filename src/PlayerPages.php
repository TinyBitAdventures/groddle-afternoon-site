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
use Minn\Json;
use Minn\RestError;

/**
 * The Players pages (register G338): /players/, a grid of every multiplayer
 * character in their own look, and /players/<name>/, one character's
 * profile: the avatar large, the name and level, the description they
 * wrote, a picture of their home street, their newest badges and skills and
 * some buddies, with a page each for all of them: /players/<name>/skills/,
 * /achievements/ and /buddies/. Glitch's website had the same (a profile
 * per player at /profiles/<tsid>/: the avatar's _172 still, level, latest
 * badge and count, skills count, the profile-bio, "View <name>'s Home
 * Street", and buddy grids of _100 avatars; and /skills/, /achievements/ and
 * /friends/ under it; Wayback captures in research/wayback/raw/profiles/).
 * Snaps lived on the profile too (GlitchrSavedDialog.as 160-225 gave the
 * photo's page): here the Snaps a player made public (G1240, Snaps), newest
 * first, on the profile and at /players/<name>/snaps/, each with a page of
 * its own at /players/<name>/snaps/<id>/; their pictures come through
 * snapPicture, which asks Snaps::viewable.
 * The data is Profiles', the save's and Buddies'; the pictures are the ones
 * the game uploaded, served here.
 *
 * A signed-in player looking at someone else's page gets "Add to buddy list"
 * (or "Remove from buddy list"), as glitch.com's profiles had (the archived
 * profile in research/wayback/raw/profiles/, friendAdd and friendRemove): a
 * one-way add, as in the game (Buddies), which works whether or not they are
 * online; the game tells them at their next sign-in. Nothing is offered when
 * either of them blocks the other. With JavaScript (players/buddy.js) the
 * button calls groddle/v1/buddies with the page's REST nonce; without, the
 * form posts here (buddy()) and comes back to the page. The save keeps no times for skills or
 * badges, so "newest" is the order they came in, with no dates.
 * Beside it, "Send them an IM" opens the game on an IM to them
 * (/play/online/#im=<id>&name=<name>; the realtime server's im.go decides).
 *
 * Drawn whole on the server in the site's look (the header menu, the
 * restoration report's tokens, light and dark) so they read without
 * JavaScript. Everything a player wrote is escaped. Only characters show:
 * accounts with a row in Characters' table, never an email or a username.
 */
final readonly class PlayerPages
{
    /** Ours: seconds a versioned picture is kept by browsers and the CDN (no longer immutable: a ban must reach it). */
    public const IMAGE_KEEP = 3600;

    /** Ours: seconds a suspended player's picture's 404 is kept. */
    public const MISS_KEEP = 60;

    /** Tiles on one page of the grid. */
    public const PER_PAGE = 60;

    /** A picture's path word => [its column, the column it was saved at, the column naming its type (null: PNG)]. */
    private const KINDS = [
        'avatar' => ['avatar', 'avatar_at', null],
        'tile' => ['tile', 'avatar_at', null],
        'home' => ['home', 'home_at', 'home_type'],
        'home-small' => ['home_small', 'home_at', 'home_type'],
    ];

    /**
     * How wide the home street shows (srcset sizes): the page's column,
     * restoration.css .wrap (1160 less 32 px gutters, 16 px under 641 px).
     */
    private const HOME_SIZES = '(min-width: 1160px) 1096px, (min-width: 641px) calc(100vw - 64px), calc(100vw - 32px)';

    /** A profile's pages: path under /players/<name>/ => tab label (the path keeps the original site's word). */
    private const TABS = ['' => 'Profile', 'snaps' => 'Snaps', 'skills' => 'Skills', 'achievements' => 'Badges', 'buddies' => 'Buddies'];

    /** Public Snaps the profile shows before "See all" (G1240). */
    public const SNAPS_SHOWN = 6;

    /** How wide a Snap card shows (srcset sizes): a third of the page column, the whole column on a phone. */
    private const SNAP_SIZES = '(min-width: 1160px) 352px, (min-width: 641px) calc((100vw - 96px) / 3), calc(100vw - 32px)';

    /** Seconds a public Snap's picture may be kept before asking again (0: every view asks, so private is private at once). */
    public const SNAP_KEEP = 0;

    /** Badges and skills the profile shows before "See all". */
    public const LATEST = 8;

    /** Buddies the profile shows before "See all". */
    public const BUDDIES_SHOWN = 12;

    /** The buddy button's script (the theme folder is the site repo); the key is bumped when it changes. */
    public const BUDDY_JS = '/wp-content/themes/groddle/players/buddy.js';

    /** The home street's camera (players/home-pan.js, a module); the key is bumped when it changes. */
    public const HOME_PAN_JS = '/wp-content/themes/groddle/players/home-pan.js';

    public const HOME_PAN_JS_KEY = 'pan-2';

    public const BUDDY_JS_KEY = 'buddy-1';

    /** The buddy button's labels, glitch.com's (the archived profile's buddyadd and buddyremove). */
    public const BUDDY_LABELS = ['add' => 'Add to buddy list', 'remove' => 'Remove from buddy list'];

    /** The client's player info had "Send them an IM" (PlayerInfoDialog.as 172-175): it opens the game on that IM. */
    public const IM_LABEL = 'Send them an IM';

    /** The game opens the IM from the link's fragment (its js/player-im.js imLinkFrom); the fragment never reaches a server log. */
    public static function imLink(int $id, string $name): string
    {
        return '/play/online/#im=' . $id . '&name=' . rawurlencode($name);
    }

    /**
     * What the page says after the form posted without JavaScript (?buddy=),
     * in the game's words (js/buddies.js BUDDY_TEXT added, removed, limit, failed).
     */
    private const BUDDY_SAID = [
        'added' => '%s is on your buddy list now.',
        'removed' => '%s is off your buddy list.',
        'limit' => 'You currently have the maximum number of friends (' . Buddies::BUDDY_LIMIT . ' or more!)',
        'failed' => 'That didn\'t work. Try again in a moment.',
    ];

    public function __construct(private Seams $minn, private StaticRoot $root)
    {
    }

    #[Route(Method::Get, '/players', policy: new Policy(Access::Public))]
    public function bare(Request $request): Response
    {
        return Response::redirect('/players/' . self::query($request), 301);
    }

    /** The grid: newest first, or by name. */
    #[Route(Method::Get, '/players/', policy: new Policy(Access::Public))]
    public function index(Request $request): Response
    {
        $db = $this->minn->db;
        $byName = $request->query('sort') === 'name';
        $page = max(1, (int) ($request->query('page') ?? '1'));
        $characters = $db->table(Characters::TABLE);
        $users = $db->table('users');
        $profiles = $db->table(Profiles::TABLE);
        $saves = $db->table(MultiplayerSave::TABLE);
        // Only characters at Profiles::LISTED_LEVEL or above are listed.
        $listed = "JOIN {$saves} s ON s.user_id = c.user_id AND " . Profiles::levelSql('s') . ' >= ' . Profiles::LISTED_LEVEL;
        // Retired characters (Logins) are hidden.
        $listed .= ' AND NOT EXISTS (SELECT 1 FROM ' . $db->table(Logins::TABLE) . ' rl WHERE rl.user_id = c.user_id AND rl.retired_at IS NOT NULL)';
        // So are suspended ones (a ban on any character of the login, AdminConsole::banned).
        $listed .= ' AND NOT ' . AdminConsole::bannedSql($db, 'c.user_id');
        $total = (int) $db->value("SELECT COUNT(*) FROM {$characters} c JOIN {$users} u ON u.ID = c.user_id {$listed}");
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $order = $byName ? 'c.name_key ASC, c.user_id ASC' : 'c.created_at DESC, c.user_id DESC';
        $rows = $db->rows("SELECT c.user_id, c.name, p.avatar_at FROM {$characters} c JOIN {$users} u ON u.ID = c.user_id {$listed} LEFT JOIN {$profiles} p ON p.user_id = c.user_id ORDER BY {$order} LIMIT ? OFFSET ?", [self::PER_PAGE, ($page - 1) * self::PER_PAGE]);
        $slugs = Profiles::slugs($db);

        $tiles = '';
        foreach ($rows as $row) {
            $tiles .= self::tile((int) $row['user_id'], (string) $row['name'], $row['avatar_at'] ?? null, $slugs, '      ');
        }
        $sort = static fn (bool $name, string $label) => $name === $byName
            ? '<span aria-current="true">' . $label . '</span>'
            : '<a href="/players/' . ($name ? '?sort=name' : '') . '">' . $label . '</a>';
        $count = $total === 1 ? '1 character' : number_format($total) . ' characters';
        $body = '<header class="masthead note-head">' . "\n  <div class=\"wrap\">\n"
            . "    <p class=\"kicker\">Multiplayer</p>\n"
            . "    <h1>Players</h1>\n"
            . "    <p class=\"lead\">Every character in Groddle Afternoon's multiplayer from level " . Profiles::LISTED_LEVEL . " up, each in their own look. Pick one to see their home and badges.</p>\n"
            . "  </div>\n</header>\n"
            . "<main class=\"band players\">\n  <div class=\"wrap\">\n"
            . '    <p class="players-bar"><span>' . $count . '</span><span class="players-sort">' . $sort(false, 'Newest') . ' · ' . $sort(true, 'By name') . "</span></p>\n";
        $body .= $tiles === ''
            ? "    <p class=\"players-empty\">No characters here yet. A character appears once it reaches level " . Profiles::LISTED_LEVEL . ".</p>\n"
            : "    <ul class=\"players-grid\">{$tiles}\n    </ul>\n";
        $body .= self::pager($page, $pages, static fn (int $n): string => '/players/' . (($q = http_build_query(array_filter(['sort' => $byName ? 'name' : null, 'page' => $n > 1 ? $n : null]))) === '' ? '' : "?{$q}"));
        $body .= "  </div>\n</main>";
        return $this->page('Players', 'Every character in Groddle Afternoon multiplayer.', $body);
    }

    #[Route(Method::Get, '/players/{slug:[a-z0-9][a-z0-9.-]*}', policy: new Policy(Access::Public))]
    public function bareProfile(Request $request, string $slug): Response
    {
        if ($this->find($slug) === null) {
            throw new RouteMiss();
        }
        return Response::redirect("/players/{$slug}/" . self::query($request), 301);
    }

    /** One character's profile. */
    #[Route(Method::Get, '/players/{slug:[a-z0-9][a-z0-9.-]*}/', policy: new Policy(Access::Public))]
    public function profile(Request $request, string $slug): Response
    {
        return $this->tab($request, $slug, '');
    }

    #[Route(Method::Get, '/players/{slug:[a-z0-9][a-z0-9.-]*}/{tab:snaps|skills|achievements|buddies}', policy: new Policy(Access::Public))]
    public function bareTab(Request $request, string $slug, string $tab): Response
    {
        if ($this->find($slug) === null) {
            throw new RouteMiss();
        }
        return Response::redirect("/players/{$slug}/{$tab}/" . self::query($request), 301);
    }

    /** A profile's page of snaps, skills, badges or buddies. */
    #[Route(Method::Get, '/players/{slug:[a-z0-9][a-z0-9.-]*}/{tab:snaps|skills|achievements|buddies}/', policy: new Policy(Access::Public))]
    public function subpage(Request $request, string $slug, string $tab): Response
    {
        return $this->tab($request, $slug, $tab);
    }

    /** The profile ('') or one of its pages, under the same rule: below the listed level, the owner's alone. */
    private function tab(Request $request, string $slug, string $tab): Response
    {
        if (!isset(self::TABS[$tab])) {
            throw new RouteMiss();
        }
        $found = $this->find($slug);
        if ($found === null) {
            throw new RouteMiss();
        }
        [$id, $canonical] = $found;
        $here = "/players/{$canonical}/" . ($tab === '' ? '' : "{$tab}/");
        if ($canonical !== $slug) {
            return Response::redirect($here, 301);
        }
        $who = $this->who($request, $id, $canonical, $tab);
        ['name' => $name, 'facts' => $facts] = $who;
        $private = $facts['level'] < Profiles::LISTED_LEVEL
            ? '    <p class="profile-private">Only you can see this page for now. It goes on the Players page when you reach level ' . Profiles::LISTED_LEVEL . ".</p>\n"
            : '';
        $page = max(1, (int) ($request->query('page') ?? '1'));
        [$title, $description, $main] = match ($tab) {
            '' => [$name, "{$name}, a player in Groddle Afternoon.", $this->overview($who)],
            'snaps' => ["{$name}: Snaps", "The snaps {$name} has shared from Groddle Afternoon.", self::snapsSection($who, null)],
            'skills' => ["{$name}: Skills", "The skills {$name} has learned in Groddle Afternoon.", self::skillsSection($who, null)],
            'achievements' => ["{$name}: Badges", "The badges {$name} has earned in Groddle Afternoon.", self::badgesSection($who, null)],
            'buddies' => ["{$name}: Buddies", "{$name}'s buddies in Groddle Afternoon.", $this->buddiesSection($who, $page)],
        };
        $body = self::masthead($who, $tab)
            . '<main class="band profile' . ($tab === '' ? '' : " profile-page-{$tab}") . "\">\n  <div class=\"wrap\">\n"
            . $private . $main
            . "  </div>\n</main>";
        $scripts = $who['buddy'] === '' ? '' : '<script src="' . self::BUDDY_JS . '?v=' . self::BUDDY_JS_KEY . "\" defer></script>\n";
        // A home street to pan along (HomeLayout version 2): its camera.
        if (str_contains($main, 'hd-pan')) {
            $scripts .= '<script type="module" src="' . self::HOME_PAN_JS . '?v=' . self::HOME_PAN_JS_KEY . "\"></script>\n";
        }
        return $this->page($title, $description, $body, $scripts);
    }

    /**
     * Everything a profile page shows of a character, under the profile's
     * rule: below the listed level the owner's alone, a suspended player's
     * page gone for everyone (RouteMiss).
     *
     * @return array<string, mixed>
     */
    private function who(Request $request, int $id, string $canonical, string $tab): array
    {
        $db = $this->minn->db;
        $character = $db->row('SELECT name, created_at FROM ' . $db->table(Characters::TABLE) . ' WHERE user_id = ?', [$id]);
        $profile = $db->row('SELECT about, avatar_at, home_at, home_w, home_h, home_small IS NOT NULL AS home_has_small, home_layout FROM ' . $db->table(Profiles::TABLE) . ' WHERE user_id = ?', [$id]) ?? [];
        $facts = Profiles::fromSave($db, $id);
        // Below the listed level the page is the owner's alone.
        $own = $this->minn->reader->userId === $id;
        if ($facts['level'] < Profiles::LISTED_LEVEL && !$own) {
            throw new RouteMiss();
        }
        // A suspended player's page is gone for everyone (the owner can't sign in to play either).
        if (AdminConsole::banned($db, $id) !== null) {
            throw new RouteMiss();
        }
        $name = (string) ($character['name'] ?? '');
        $who = [
            'id' => $id,
            'name' => $name,
            'base' => "/players/{$canonical}/",
            'own' => $own,
            'facts' => $facts,
            'profile' => $profile,
            'joined' => strtotime(((string) ($character['created_at'] ?? '')) . ' UTC'),
            'badges' => $this->badges($facts['badges']),
            'skills' => self::skillRows($facts['skills'], $this->skillCatalog(), $this->root->dir),
            'buddyCount' => $this->buddies($id, 0, 0)['total'],
            // G1240: the Snaps they made public, newest first (none for a reader either of them blocks).
            'snaps' => Snaps::publicOf($db, $id, $this->minn->reader->userId),
            'streets' => [],
            // ?home=picture shows the uploaded picture even when there is a layout (comparing the two).
            'homePicture' => $request->query('home') === 'picture',
            // G1213: for 30 days after a player's own rename, the old name, so friends still find them.
            'formerly' => CharacterName::formerly($db, [$id], time())[$id] ?? null,
        ];
        if ($who['snaps'] !== []) {
            $who['streets'] = $this->streetIndex();
        }
        $who['buddy'] = $this->buddyButton($id, $name, $who['base'], $tab, (string) ($request->query('buddy') ?? ''));
        return $who;
    }

    /** The game's street index (tsid => row), or empty: a Snap's street name. */
    private function streetIndex(): array
    {
        $all = Json::file($this->root->dir . '/assets/streets/index.json', or: []);
        return is_array($all['streets'] ?? null) ? $all['streets'] : [];
    }

    /**
     * The form a no-JavaScript buddy button posts (players/buddy.js calls
     * groddle/v1/buddies instead): add or remove the player whose page it is,
     * then back to that page with what happened (?buddy=). Signed in, with
     * the page's REST nonce; the caller is the reader, never the form. Who
     * added the caller stays marked new for the game (Buddies keepNew).
     */
    #[Route(Method::Post, '/players/{slug:[a-z0-9][a-z0-9.-]*}/buddy/', policy: new Policy(Access::Public))]
    public function buddy(Request $request, string $slug): Response
    {
        [$id, $canonical] = $this->find($slug) ?? throw new RouteMiss();
        $form = $request->json();
        $tab = is_string($form['tab'] ?? null) && isset(self::TABS[$form['tab']]) ? $form['tab'] : '';
        $back = "/players/{$canonical}/" . ($tab === '' ? '' : "{$tab}/");
        $reader = $this->minn->reader;
        if ($reader->userId <= 0) {
            return Response::redirect($back, 303);
        }
        $nonce = is_string($form['_wpnonce'] ?? null) ? $form['_wpnonce'] : '';
        $said = 'failed';
        if ($nonce !== '' && $reader->sessionToken !== '' && Nonce::verify($nonce, $reader->userId, $reader->sessionToken)
            && $this->isPlayer($reader->userId) && Profiles::fromSave($this->minn->db, $id)['level'] >= Profiles::LISTED_LEVEL) {
            $remove = ($form['do'] ?? '') === 'remove';
            $buddies = new Buddies($this->minn);
            try {
                $remove ? $buddies->removeBuddy($id, true) : $buddies->addBuddy($id, true);
                $said = $remove ? 'removed' : 'added';
            } catch (RestError $e) {
                $said = match ($e->errorCode) {
                    'groddle_buddy_exists' => 'added',
                    'groddle_buddy_missing' => 'removed',
                    'groddle_buddy_limit' => 'limit',
                    default => 'failed',
                };
            }
        }
        return Response::redirect("{$back}?buddy={$said}", 303);
    }

    /**
     * The buddy button for the reader on this player's page, or '' (signed
     * out, not a player, their own page, or either blocks the other).
     */
    private function buddyButton(int $id, string $name, string $base, string $tab, string $said): string
    {
        $reader = $this->minn->reader;
        if ($reader->userId <= 0 || $reader->userId === $id || $reader->sessionToken === '' || !$this->isPlayer($reader->userId)) {
            return '';
        }
        $between = (new Buddies($this->minn))->between($id);
        return self::buddyControl([
            'viewer' => $reader->userId,
            'id' => $id,
            'name' => $name,
            'base' => $base,
            'tab' => $tab,
            'holds' => $between['holds'],
            'blocked' => $between['blocked'],
            'nonce' => Nonce::create($reader->userId, $reader->sessionToken),
            'said' => $said,
        ]);
    }

    /** Whether a user has a character (only players have buddies). */
    private function isPlayer(int $userId): bool
    {
        return $this->minn->db->value('SELECT 1 FROM ' . $this->minn->db->table(Characters::TABLE) . ' WHERE user_id = ?', [$userId]) !== null;
    }

    /**
     * The buddy button (pure, for tests): a form that posts to buddy() and
     * that players/buddy.js takes over, with a line for what happened.
     * '' when there is nothing to offer: no viewer, their own page, blocked
     * either way, no nonce.
     *
     * @param array{viewer: int, id: int, name: string, base: string, tab: string, holds: bool, blocked: bool, nonce: string, said: string} $s
     */
    public static function buddyControl(array $s): string
    {
        if ($s['viewer'] <= 0 || $s['viewer'] === $s['id'] || $s['blocked'] || $s['nonce'] === '') {
            return '';
        }
        $do = $s['holds'] ? 'remove' : 'add';
        $said = isset(self::BUDDY_SAID[$s['said']]) ? sprintf(self::BUDDY_SAID[$s['said']], $s['name']) : '';
        return '      <form class="profile-buddy" method="post" action="' . Html::attr($s['base'] . 'buddy/') . '" data-buddy-form="" data-buddy-id="' . $s['id'] . '" data-buddy-name="' . Html::attr($s['name']) . '"'
            . ' data-label-add="' . Html::attr(self::BUDDY_LABELS['add']) . '" data-label-remove="' . Html::attr(self::BUDDY_LABELS['remove']) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . Html::attr($s['nonce']) . '" />'
            . '<input type="hidden" name="tab" value="' . Html::attr($s['tab']) . '" />'
            . '<input type="hidden" name="do" value="' . $do . '" />'
            . '<button type="submit" class="profile-buddy-btn" data-buddy-do="' . $do . '">' . self::BUDDY_LABELS[$do] . '</button>'
            . '<a class="profile-im-btn" href="' . Html::attr(self::imLink($s['id'], $s['name'])) . '" data-im-link="' . $s['id'] . '">' . self::IM_LABEL . '</a>'
            . '<span class="profile-buddy-note" role="status" aria-live="polite"' . ($s['said'] === 'failed' || $s['said'] === 'limit' ? ' data-tone="bad"' : '') . '>' . Html::esc($said) . '</span>'
            . "</form>\n";
    }

    /**
     * The top of every profile page: on the profile the avatar large, the
     * stats and the description they wrote; on its pages the tile and the
     * page's name. Then the tabs.
     *
     * @param array<string, mixed> $who
     */
    private static function masthead(array $who, string $tab): string
    {
        ['id' => $id, 'name' => $name, 'base' => $base, 'facts' => $facts, 'profile' => $profile] = $who;
        $nameHtml = Html::esc($name);
        $formerly = is_string($who['formerly'] ?? null) && $who['formerly'] !== '' ? '      <p class="profile-formerly">formerly ' . Html::esc($who['formerly']) . "</p>\n" : '';
        $avatarAt = is_string($profile['avatar_at'] ?? null) && $profile['avatar_at'] !== '' ? $profile['avatar_at'] : null;
        $counts = ['snaps' => count($who['snaps']), 'skills' => count($who['skills']), 'achievements' => count($who['badges']), 'buddies' => $who['buddyCount']];
        $tabs = '';
        foreach (self::TABS as $path => $label) {
            $tabs .= '<a href="' . Html::attr($base . ($path === '' ? '' : "{$path}/")) . '"' . ($path === $tab ? ' aria-current="page"' : '') . '>' . $label
                . ($path === '' ? '' : ' <span class="profile-tab-n">' . number_format($counts[$path]) . '</span>') . '</a>';
        }
        $tabs = '      <nav class="profile-tabs" aria-label="' . Html::attr("{$name}'s pages") . "\">{$tabs}</nav>\n";
        if ($tab !== '') {
            $face = $avatarAt === null
                ? self::ghost('profile-mini-art')
                : '<img class="profile-mini-art" src="' . Html::attr(Profiles::imageUrl($id, 'tile', $avatarAt)) . '" width="100" height="144" alt="" />';
            return '<header class="masthead note-head profile-head profile-head-mini">' . "\n  <div class=\"wrap profile-top\">\n"
                . "    <figure class=\"profile-mini\">{$face}</figure>\n"
                . "    <div class=\"profile-who\">\n"
                . '      <p class="kicker"><a href="/players/">Players</a> <a href="' . Html::attr($base) . "\">{$nameHtml}</a></p>\n"
                . "      <h1>{$nameHtml}: " . self::TABS[$tab] . "</h1>\n"
                . $formerly
                . $who['buddy']
                . $tabs
                . "    </div>\n  </div>\n</header>\n";
        }
        $about = trim((string) ($profile['about'] ?? ''));
        $joined = $who['joined'];
        $avatar = $avatarAt === null
            ? self::ghost('profile-avatar-art')
            : '<img class="profile-avatar-art" src="' . Html::attr(Profiles::imageUrl($id, 'avatar', $avatarAt)) . '" width="172" height="248" alt="' . Html::attr("{$name}'s avatar") . '" />';
        $count = $counts['achievements'];
        return '<header class="masthead note-head profile-head">' . "\n  <div class=\"wrap profile-top\">\n"
            . "    <figure class=\"profile-avatar\">{$avatar}</figure>\n"
            . "    <div class=\"profile-who\">\n"
            . "      <p class=\"kicker\"><a href=\"/players/\">Players</a></p>\n"
            . "      <h1>{$nameHtml}</h1>\n"
            . $formerly
            . '      <ul class="profile-stats">'
            . '<li><strong>' . $facts['level'] . '</strong><span>Level</span></li>'
            . '<li><strong>' . number_format($counts['skills']) . '</strong><span>' . ($counts['skills'] === 1 ? 'Skill' : 'Skills') . '</span></li>'
            . '<li><strong>' . number_format($count) . '</strong><span>' . ($count === 1 ? 'Badge' : 'Badges') . '</span></li>'
            . ($joined ? '<li><strong>' . gmdate('M j, Y', $joined) . '</strong><span>Joined</span></li>' : '')
            . "</ul>\n"
            . ($about === ''
                ? "      <p class=\"profile-bio profile-empty\">{$nameHtml} hasn't written anything about themselves yet.</p>\n"
                : '      <p class="profile-bio">' . nl2br(Html::esc($about), false) . "</p>\n")
            . $who['buddy']
            . $tabs
            . "    </div>\n  </div>\n</header>\n";
    }

    /**
     * The home street's <img>: its size when known (no jump as it loads)
     * and, when the site made a phone copy (HomePicture::SMALL_W), a srcset
     * so a phone fetches that and a wide screen the full picture. A tall
     * one (an upright phone's view, before the game took every picture in
     * the layout's frame) is cut to that frame's shape (style.css).
     */
    private static function homeImg(int $id, string $name, string $at, array $profile): string
    {
        $full = Profiles::imageUrl($id, 'home', $at);
        $w = (int) ($profile['home_w'] ?? 0);
        $h = (int) ($profile['home_h'] ?? 0);
        $attrs = ' src="' . Html::attr($full) . '"';
        if ($w > HomePicture::SMALL_W && !empty($profile['home_has_small'])) {
            $srcset = Profiles::imageUrl($id, 'home-small', $at) . ' ' . HomePicture::SMALL_W . 'w, ' . $full . " {$w}w";
            $attrs .= ' srcset="' . Html::attr($srcset) . '" sizes="' . Html::attr(self::HOME_SIZES) . '"';
        }
        if ($w > 0 && $h > 0) {
            $attrs .= " width=\"{$w}\" height=\"{$h}\"";
            // HOMESHOT: a picture taken on an upright phone (a tall slice) shows in the landscape frame, its foot kept.
            if ($w < $h * HomeLayout::MIN_ASPECT) {
                $attrs .= ' class="profile-home-tall"';
            }
        }
        return '<img' . $attrs . ' alt="' . Html::attr("{$name}'s home street") . '" loading="lazy" decoding="async" />';
    }

    /**
     * The home street as a diorama (HomeLayout) when the game has sent its
     * layout, else the picture it uploaded (players whose game has not sent a
     * layout yet), else a note. null when there is no usable layout.
     */
    private function homeDiorama(array $who): ?string
    {
        $stored = $who['profile']['home_layout'] ?? null;
        if (!is_string($stored) || $stored === '' || $who['homePicture']) {
            return null;
        }
        $layout = Json::decode($stored, or: null);
        $v = is_array($layout) ? ($layout['v'] ?? null) : null;
        $whole = $v === HomeLayout::VERSION ? isset($layout['draw']) : ($v === HomeLayout::STREET_VERSION && isset($layout['layers'], $layout['sw'], $layout['cx']));
        if (!$whole || !isset($layout['w'], $layout['h'], $layout['art'], $layout['spots'])) {
            return null;
        }
        $avatarAt = $who['profile']['avatar_at'] ?? null;
        return HomeLayout::render($layout, [
            'owner' => $who['name'],
            'root' => $this->root->dir,
            'avatar' => is_string($avatarAt) && $avatarAt !== '' ? Profiles::imageUrl($who['id'], 'avatar', $avatarAt) : null,
        ]);
    }

    /** The profile: the home street, then the newest badges and skills and some buddies, each with a way to the rest. */
    private function overview(array $who): string
    {
        ['id' => $id, 'name' => $name, 'profile' => $profile] = $who;
        $nameHtml = Html::esc($name);
        $homeAt = is_string($profile['home_at'] ?? null) && $profile['home_at'] !== '' ? $profile['home_at'] : null;
        $diorama = $this->homeDiorama($who);
        return "    <section class=\"profile-section\" aria-labelledby=\"profile-home\">\n      <h2 id=\"profile-home\">{$nameHtml}'s home street</h2>\n"
            . ($diorama !== null
                ? "    {$diorama}\n"
                : ($homeAt === null
                ? "      <p class=\"profile-empty\">No picture of {$nameHtml}'s home yet. It appears after they visit their home street in multiplayer.</p>\n"
                : '      <figure class="profile-home">' . self::homeImg($id, $name, $homeAt, $profile) . "</figure>\n"))
            . "    </section>\n"
            . self::snapsSection($who, self::SNAPS_SHOWN)
            . self::badgesSection($who, self::LATEST)
            . self::skillsSection($who, self::LATEST)
            . $this->buddiesSection($who, 0);
    }

    /**
     * The Snaps they made public (G1240), newest first: all of them, or the
     * newest $limit with a link to the rest. Each card keeps its picture's
     * own shape and links its page. Its owner is told how one gets here.
     */
    private static function snapsSection(array $who, ?int $limit): string
    {
        $all = $who['snaps'];
        $shown = $limit === null ? $all : array_slice($all, 0, $limit);
        $out = "    <section class=\"profile-section\" aria-labelledby=\"profile-snaps\">\n"
            . self::sectionHead('profile-snaps', 'Snaps', count($all), $limit, $who['base'] . 'snaps/', 'snap', 'snaps');
        $note = $who['own'] ? '      <p class="profile-snaps-note">Only snaps you make public show here. Open Snaps in the game\'s Menu and choose Make public on one.</p>' . "\n" : '';
        if ($all === []) {
            if ($limit !== null && !$who['own']) {
                return '';
            }
            return $out . $note . "      <p class=\"profile-empty\">No snaps shared yet.</p>\n    </section>\n";
        }
        $out .= $note . '      <ul class="profile-snaps">';
        foreach ($shown as $row) {
            $out .= "\n        <li>" . self::snapCard($who, $row) . '</li>';
        }
        return $out . "\n      </ul>\n    </section>\n";
    }

    /** One public Snap's card: the picture in its own shape, the caption, the street and the date, linking its page. */
    private static function snapCard(array $who, array $row): string
    {
        $id = (int) $row['id'];
        $w = (int) $row['width'];
        $h = (int) $row['height'];
        $caption = trim((string) $row['caption']);
        $where = BuddiesOnline::where((string) $row['street'], $who['streets'])['street'];
        $at = strtotime(((string) $row['created_at']) . ' UTC');
        $size = $w > 0 && $h > 0 ? " width=\"{$w}\" height=\"{$h}\"" : '';
        $small = Snaps::url($who['id'], $id, 'small');
        // The cards' copy (SnapImage::SMALL_W) on most screens, the picture itself where a card is wider than that.
        $srcset = $w > SnapImage::SMALL_W ? ' srcset="' . Html::attr($small . ' ' . SnapImage::SMALL_W . 'w, ' . Snaps::url($who['id'], $id) . " {$w}w") . '" sizes="' . Html::attr(self::SNAP_SIZES) . '"' : '';
        return '<a class="profile-snap" href="' . Html::attr($who['base'] . "snaps/{$id}/") . '">'
            . '<img src="' . Html::attr($small) . '"' . $size . $srcset . ' alt="' . Html::attr($caption !== '' ? $caption : 'A snap') . '" loading="lazy" decoding="async" />'
            . ($caption !== '' ? '<span class="profile-snap-caption">' . Html::esc($caption) . '</span>' : '')
            . '<span class="profile-snap-meta">' . Html::esc(implode(' · ', array_filter([$where, $at ? gmdate('M j, Y', $at) : '']))) . '</span>'
            . '</a>';
    }

    #[Route(Method::Get, '/players/{slug:[a-z0-9][a-z0-9.-]*}/snaps/{snap:\d+}', policy: new Policy(Access::Public))]
    public function bareSnapPage(Request $request, string $slug, string $snap): Response
    {
        [, $canonical] = $this->find($slug) ?? throw new RouteMiss();
        return Response::redirect("/players/{$canonical}/snaps/{$snap}/", 301);
    }

    /**
     * One Snap's page (G1240; Glitch's saved dialog linked the photo's own
     * page): the picture full size, the caption, the street and the date, a
     * download. Anyone may see a public one (Snaps::viewable); a private one
     * is its owner's alone and answers everyone else as a page that is not
     * there, the same as a Snap that never was.
     */
    #[Route(Method::Get, '/players/{slug:[a-z0-9][a-z0-9.-]*}/snaps/{snap:\d+}/', policy: new Policy(Access::Public))]
    public function snapPage(Request $request, string $slug, string $snap): Response
    {
        [$id, $canonical] = $this->find($slug) ?? throw new RouteMiss();
        $db = $this->minn->db;
        $row = Snaps::find($db, $id, (int) $snap);
        if ($row === null || !Snaps::viewable($db, $this->minn->reader->userId, $row)) {
            throw new RouteMiss();
        }
        if ($canonical !== $slug) {
            return Response::redirect("/players/{$canonical}/snaps/{$snap}/", 301);
        }
        $who = $this->who($request, $id, $canonical, 'snaps');
        $who['streets'] = $this->streetIndex();
        $name = $who['name'];
        $sid = (int) $row['id'];
        $w = (int) $row['width'];
        $h = (int) $row['height'];
        $caption = trim((string) $row['caption']);
        $place = BuddiesOnline::where((string) $row['street'], $who['streets']);
        $where = implode(', ', array_filter([$place['street'], $place['hub']]));
        $at = strtotime(((string) $row['created_at']) . ' UTC');
        $public = (int) $row['public'] === 1;
        $picture = Snaps::url($id, $sid);
        $size = $w > 0 && $h > 0 ? " width=\"{$w}\" height=\"{$h}\"" : '';
        $alt = $caption !== '' ? $caption : "A snap by {$name}";
        $main = ($public ? '' : "    <p class=\"profile-private\">Only you can see this snap. Make it public in the game's Snaps to share this page.</p>\n")
            . "    <figure class=\"snap-page\">\n"
            . '      <a class="snap-page-picture" href="' . Html::attr($picture) . '"><img src="' . Html::attr($picture) . '"' . $size . ' alt="' . Html::attr($alt) . "\" decoding=\"async\" /></a>\n"
            . "      <figcaption>\n"
            . ($caption !== '' ? '        <p class="snap-page-caption">' . Html::esc($caption) . "</p>\n" : '')
            . '        <p class="snap-page-meta">' . Html::esc(implode(' · ', array_filter([$where, $at ? gmdate('F j, Y', $at) : '', "by {$name}"]))) . "</p>\n"
            . '        <p class="snap-page-links"><a href="' . Html::attr(Snaps::url($id, $sid, 'download')) . '" download="">Download</a> <a href="' . Html::attr($who['base'] . 'snaps/') . '">All of ' . Html::esc($name) . "'s snaps</a></p>\n"
            . "      </figcaption>\n    </figure>\n";
        $body = self::masthead($who, 'snaps')
            . "<main class=\"band profile profile-page-snap\">\n  <div class=\"wrap\">\n" . $main . "  </div>\n</main>";
        $title = $caption !== '' ? "{$caption}: a snap by {$name}" : "A snap by {$name}";
        $description = trim(($where !== '' ? "Taken on {$where}" : 'Taken') . ($at ? ' on ' . gmdate('F j, Y', $at) : '') . " by {$name} in Groddle Afternoon.");
        // The link preview is the Snap itself (SocialTags leaves a page that has its own og:image alone).
        $origin = rtrim((string) ($this->minn->site->option('home') ?? ''), '/');
        $head = '';
        if ($public) {
            $tags = [
                ['property', 'og:type', 'article'], ['property', 'og:site_name', 'Groddle Afternoon'], ['property', 'og:title', $title],
                ['property', 'og:description', $description], ['property', 'og:url', $origin . $who['base'] . "snaps/{$sid}/"],
                ['property', 'og:image', $origin . $picture], ['property', 'og:image:width', (string) $w], ['property', 'og:image:height', (string) $h],
                ['property', 'og:image:alt', $alt], ['name', 'twitter:card', 'summary_large_image'], ['name', 'twitter:image', $origin . $picture],
            ];
            foreach ($tags as [$kind, $key, $value]) {
                $head .= "<meta {$kind}=\"{$key}\" content=\"" . Html::attr($value) . "\" />\n";
            }
        } else {
            $head = "<meta name=\"robots\" content=\"noindex\" />\n";
        }
        return $this->page($title, $description, $body, '', $head);
    }

    /**
     * A Snap's picture (G1240): 'picture' the kept JPEG, 'small' the cards'
     * copy, 'download' the kept JPEG as a file named for its street and date.
     * Snaps::viewable decides: the owner always, anyone else only a public
     * Snap of a public profile with no block between them; everything else
     * is a 404, the same as no Snap. A public one is never kept without
     * asking again (SNAP_KEEP 0, with an ETag), so making it private stops
     * it at once; a private one is never stored at all. The file's own
     * address is never sent anywhere (Snaps).
     */
    #[Route(Method::Get, '/players/{id:\d+}/snaps/{snap:\d+}/{kind:picture|small|download}', policy: new Policy(Access::Public))]
    public function snapPicture(Request $request, string $id, string $snap, string $kind): Response
    {
        $db = $this->minn->db;
        $row = Snaps::find($db, (int) $id, (int) $snap);
        if ($row === null || !Snaps::viewable($db, $this->minn->reader->userId, $row)) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'], 'Not found');
        }
        $bytes = Snaps::bytes($row, $kind);
        if ($bytes === null) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'], 'Not found');
        }
        $public = (int) $row['public'] === 1;
        $etag = '"snap-' . substr(hash('sha256', $kind . $row['file'] . ($public ? '1' : '0')), 0, 20) . '"';
        $headers = [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => $public ? 'public, max-age=' . self::SNAP_KEEP . ', must-revalidate' : 'private, no-store',
            'ETag' => $etag,
        ];
        if ($kind === 'download') {
            $file = Snaps::fileName($row, $this->streetIndex());
            $headers['Content-Disposition'] = 'attachment; filename="' . $file . '"; filename*=UTF-8\'\'' . rawurlencode($file);
        }
        if ($public && trim((string) $request->header('if-none-match')) === $etag) {
            return new Response(304, $headers);
        }
        return new Response(200, $headers + ['Content-Length' => (string) strlen($bytes)], $bytes);
    }

    /**
     * Badges, newest first: all of them, or the newest $limit with a link to
     * the rest. The art is Tiny Speck's (assets/badges, CC0).
     */
    private static function badgesSection(array $who, ?int $limit): string
    {
        $all = $who['badges'];
        $shown = $limit === null ? $all : array_slice($all, 0, $limit);
        $out = "    <section class=\"profile-section\" aria-labelledby=\"profile-badges\">\n"
            . self::sectionHead('profile-badges', $limit === null ? 'Badges earned' : 'Badges', count($all), $limit, $who['base'] . 'achievements/', 'badge', 'badges');
        if ($all === []) {
            return $out . "      <p class=\"profile-empty\">No badges yet.</p>\n    </section>\n";
        }
        $out .= '      <ul class="profile-badges">';
        foreach ($shown as [$badge, $label, $desc]) {
            $out .= "\n        <li title=\"" . Html::attr($desc) . '"><img src="/assets/badges/' . Html::attr($badge) . '.webp" width="72" height="72" alt="" loading="lazy" decoding="async" /><span>' . Html::esc($label) . '</span>'
                . ($limit === null && $desc !== '' ? '<small class="profile-badge-desc">' . Html::esc($desc) . '</small>' : '') . '</li>';
        }
        return $out . "\n      </ul>\n    </section>\n";
    }

    /** Skills learned, newest first: all, or the newest $limit with a link to the rest. */
    private static function skillsSection(array $who, ?int $limit): string
    {
        $all = $who['skills'];
        $shown = $limit === null ? $all : array_slice($all, 0, $limit);
        $out = "    <section class=\"profile-section\" aria-labelledby=\"profile-skills\">\n"
            . self::sectionHead('profile-skills', $limit === null ? 'Skills learned' : 'Skills', count($all), $limit, $who['base'] . 'skills/', 'skill', 'skills');
        if ($all === []) {
            return $out . "      <p class=\"profile-empty\">No skills learned yet.</p>\n    </section>\n";
        }
        $out .= '      <ul class="profile-skills">';
        foreach ($shown as [, $title, $art]) {
            // The card art is 230x310 with the picture near its middle; .profile-skill-art shows that middle.
            $out .= "\n        <li><span class=\"profile-skill-art\"><img src=\"" . Html::attr($art) . '" width="128" height="172" alt="" loading="lazy" decoding="async" /></span><span>' . Html::esc($title) . '</span></li>';
        }
        return $out . "\n      </ul>\n    </section>\n";
    }

    /**
     * Buddies who are public (a character at the listed level or above),
     * newest added first: some on the profile ($page 0), a page of them on
     * /buddies/. Someone who ignores this player, or whom they ignore, is
     * left out, as Buddies leaves them out of every list.
     */
    private function buddiesSection(array $who, int $page): string
    {
        $total = $who['buddyCount'];
        $limit = $page === 0 ? self::BUDDIES_SHOWN : self::PER_PAGE;
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = $page === 0 ? 1 : min($page, $pages);
        $rows = $total === 0 ? [] : $this->buddies($who['id'], $limit, ($page - 1) * $limit)['rows'];
        $out = "    <section class=\"profile-section\" aria-labelledby=\"profile-buddies\">\n"
            . self::sectionHead('profile-buddies', 'Buddies', $total, $limit === self::PER_PAGE ? null : $limit, $who['base'] . 'buddies/', 'buddy', 'buddies');
        if ($rows === []) {
            return $out . '      <p class="profile-empty">No buddies on the Players page yet.' . "</p>\n    </section>\n";
        }
        $slugs = Profiles::slugs($this->minn->db);
        $tiles = '';
        foreach ($rows as $row) {
            $tiles .= self::tile((int) $row['buddy_id'], (string) $row['name'], $row['avatar_at'] ?? null, $slugs, '        ');
        }
        $out .= "      <ul class=\"players-grid\">{$tiles}\n      </ul>\n";
        if ($limit === self::PER_PAGE) {
            $base = $who['base'] . 'buddies/';
            $out .= self::pager($page, $pages, static fn (int $n): string => $base . ($n > 1 ? "?page={$n}" : ''));
        }
        return $out . "    </section>\n";
    }

    /** A section's heading, with how many there are and, when only some show, the way to all of them. */
    private static function sectionHead(string $id, string $title, int $total, ?int $limit, string $all, string $one, string $many): string
    {
        $more = $limit !== null && $total > $limit
            ? ' <a class="profile-more" href="' . Html::attr($all) . '">See all ' . number_format($total) . " {$many}</a>"
            : ($limit === null && $total > 0 ? ' <span class="profile-count">' . number_format($total) . ' ' . ($total === 1 ? $one : $many) . '</span>' : '');
        return "      <div class=\"profile-section-head\"><h2 id=\"{$id}\">{$title}</h2>{$more}</div>\n";
    }

    /**
     * A picture the game uploaded, as its stored type (the avatar's two PNGs; the home's WebP, JPEG
     * or PNG, and its phone copy home-small); versioned URLs are kept for IMAGE_KEEP (an hour, ours;
     * it was a year and immutable), so a suspended player's pictures leave caches soon after the ban:
     * from then on they answer 404, kept MISS_KEEP. The path has no
     * .png: Kinsta's nginx answers any missing .png file itself, so PHP never saw those (2026-10-29).
     */
    #[Route(Method::Get, '/players/{id:\d+}/{kind:avatar|tile|home|home-small}', policy: new Policy(Access::Public))]
    public function image(Request $request, string $id, string $kind): Response
    {
        [$column, $at, $typeColumn] = self::KINDS[$kind] ?? throw new RouteMiss();
        $db = $this->minn->db;
        $type = $typeColumn === null ? "'image/png'" : $typeColumn;
        $row = $db->row("SELECT {$column} AS bytes, {$at} AS at, {$type} AS type FROM " . $db->table(Profiles::TABLE) . ' WHERE user_id = ?', [(int) $id]);
        if (!is_string($row['bytes'] ?? null) || $row['bytes'] === '' || !is_string($row['at'] ?? null)) {
            throw new RouteMiss();
        }
        // Only a type HomePicture keeps is ever sent (nosniff below holds the browser to it).
        $contentType = in_array($row['type'] ?? null, HomePicture::TYPES, true) ? $row['type'] : 'image/png';
        // An unlisted character's pictures are its owner's alone, so a new account can't host images here.
        $listed = Profiles::fromSave($db, (int) $id)['level'] >= Profiles::LISTED_LEVEL;
        if (!$listed && $this->minn->reader->userId !== (int) $id) {
            throw new RouteMiss();
        }
        if (AdminConsole::banned($db, (int) $id) !== null) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=' . self::MISS_KEEP, 'X-Content-Type-Options' => 'nosniff'], 'Not found');
        }
        $stamp = Profiles::stamp($row['at']);
        $etag = "\"{$kind}-{$stamp}\"";
        $headers = [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => !$listed ? 'private, no-store' : ($request->query('v') === $stamp ? 'public, max-age=' . self::IMAGE_KEEP : 'public, max-age=60'),
            'ETag' => $etag,
        ];
        if (trim((string) $request->header('if-none-match')) === $etag) {
            return new Response(304, $headers);
        }
        return new Response(200, $headers + ['Content-Length' => (string) strlen($row['bytes'])], $row['bytes']);
    }

    /**
     * The character a path names, with the path that is theirs: [id, slug],
     * or null.
     *
     * @return array{0: int, 1: string}|null
     */
    private function find(string $slug): ?array
    {
        $slugs = Profiles::slugs($this->minn->db);
        $id = array_search($slug, $slugs, true);
        if ($id !== false) {
            return [(int) $id, $slug];
        }
        // bo-peep.57 for a character whose name is the first owner's: theirs only if the id is theirs.
        if (preg_match('/^(.+)\.(\d+)$/', $slug, $m) === 1 && isset($slugs[(int) $m[2]]) && str_starts_with($slugs[(int) $m[2]], $m[1])) {
            return [(int) $m[2], $slugs[(int) $m[2]]];
        }
        // A character an administrator renamed (AdminConsole::rename): its old address finds it, and the
        // caller answers a 301 to the new one. A path someone holds now (above) always wins.
        return self::renamed($this->minn->db, $slug, $slugs);
    }

    /**
     * The character a renamed path belonged to, with its path now: [id, slug], or null.
     *
     * @param array<int, string> $slugs
     * @return array{0: int, 1: string}|null
     */
    public static function renamed(\Minn\Db $db, string $slug, array $slugs): ?array
    {
        $id = $db->value('SELECT user_id FROM ' . $db->table(AdminConsole::RENAMES) . ' WHERE old_slug = ? ORDER BY id DESC LIMIT 1', [$slug]);
        return $id !== null && isset($slugs[(int) $id]) ? [(int) $id, $slugs[(int) $id]] : null;
    }

    /**
     * Badges by id as [id, name, description], the ones the game knows
     * (assets/content/achievements.json from the build), in the order given.
     *
     * @param list<string> $ids
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function badges(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $all = Json::file($this->root->dir . '/assets/content/achievements.json', or: []);
        $out = [];
        foreach ($ids as $id) {
            $def = is_array($all) ? ($all[$id] ?? null) : null;
            if (is_array($def) && is_string($def['name'] ?? null)) {
                $out[] = [$id, $def['name'], is_string($def['desc'] ?? null) ? $def['desc'] : ''];
            }
        }
        return $out;
    }

    /**
     * The character's public buddies, newest added first: how many, and a
     * page of them as user id, name and when their pictures were saved.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    private function buddies(int $id, int $limit, int $offset): array
    {
        $db = $this->minn->db;
        $buddies = $db->table(Buddies::TABLE);
        $ignores = $db->table(Buddies::IGNORES);
        $from = "FROM {$buddies} b JOIN " . $db->table(Characters::TABLE) . ' c ON c.user_id = b.buddy_id JOIN ' . $db->table('users') . ' u ON u.ID = b.buddy_id'
            . ' JOIN ' . $db->table(MultiplayerSave::TABLE) . ' s ON s.user_id = b.buddy_id AND ' . Profiles::levelSql('s') . ' >= ' . Profiles::LISTED_LEVEL;
        $where = "WHERE b.user_id = ? AND b.buddy_id <> b.user_id AND NOT EXISTS (SELECT 1 FROM {$ignores} i WHERE (i.user_id = b.buddy_id AND i.ignored_id = b.user_id) OR (i.user_id = b.user_id AND i.ignored_id = b.buddy_id))";
        $total = (int) $db->value("SELECT COUNT(*) {$from} {$where}", [$id]);
        if ($limit < 1 || $total === 0) {
            return ['total' => $total, 'rows' => []];
        }
        $rows = $db->rows("SELECT b.buddy_id, c.name, p.avatar_at {$from} LEFT JOIN " . $db->table(Profiles::TABLE) . " p ON p.user_id = b.buddy_id {$where} ORDER BY b.added_at DESC, b.buddy_id DESC LIMIT ? OFFSET ?", [$id, $limit, $offset]);
        return ['total' => $total, 'rows' => $rows];
    }

    /** The game's skills (assets/content/skills.json from the build), by class id. */
    private function skillCatalog(): array
    {
        $all = Json::file($this->root->dir . '/assets/content/skills.json', or: []);
        return is_array($all) ? $all : [];
    }

    /**
     * Learned skills as [id, title, picture], in the order given, the ones
     * the game knows. The title is the original client's (js/skill-tree.js
     * skillTitle): the name, and the rank in Roman numerals when the skill
     * has ranks. The picture is the one the game shows for a skill
     * (js/familiar.js skillArtFor): Tiny Speck's imagination card art for
     * its family (CC0, assets/imagination/cards), else the brain.
     *
     * @param list<string> $ids
     * @param array<string, mixed> $catalog
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function skillRows(array $ids, array $catalog, string $dir): array
    {
        $ranks = [];
        foreach ($catalog as $def) {
            if (is_array($def) && is_string($def['name'] ?? null)) {
                $ranks[$def['name']] = ($ranks[$def['name']] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($ids as $id) {
            $def = $catalog[$id] ?? null;
            // Better Learning I-V is the game's own (js/better-learning.js BETTER_LEARNING_SKILLS), not in skills.json.
            if (!is_array($def) && preg_match('/^betterlearning_([1-5])$/', $id, $m) === 1) {
                $def = ['name' => 'Better Learning', 'level' => (int) $m[1]];
                $ranks['Better Learning'] = 5;
            }
            if (!is_array($def) || !is_string($def['name'] ?? null) || $def['name'] === '') {
                continue;
            }
            $level = (int) ($def['level'] ?? 0);
            $title = ($ranks[$def['name']] ?? 1) > 1 && $level > 0 ? $def['name'] . ' ' . self::roman($level) : $def['name'];
            $family = str_replace('_', '', (string) preg_replace('/_\d+$/', '', $id));
            $art = is_file("{$dir}/assets/imagination/cards/art_skill_{$family}.svg") ? "art_skill_{$family}" : 'art_brain';
            $out[] = [$id, $title, "/assets/imagination/cards/{$art}.svg"];
        }
        return $out;
    }

    /** js/skill-tree.js romanRank. */
    private static function roman(int $n): string
    {
        return ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'][$n] ?? (string) $n;
    }

    /** One character in a grid: their tile picture (or the silhouette) and name, linking their profile. */
    private static function tile(int $id, string $name, mixed $at, array $slugs, string $indent): string
    {
        if (!isset($slugs[$id])) {
            return '';
        }
        $at = is_string($at) && $at !== '' ? $at : null;
        $face = $at === null
            ? self::ghost('player-tile-art')
            : '<img class="player-tile-art" src="' . Html::attr(Profiles::imageUrl($id, 'tile', $at)) . '" width="100" height="144" alt="" loading="lazy" decoding="async" />';
        return "\n{$indent}<li><a class=\"player-tile\" href=\"/players/" . Html::attr($slugs[$id]) . "/\">{$face}<span class=\"player-tile-name\">" . Html::esc($name) . '</span></a></li>';
    }

    /** Previous and next under a grid, when there is more than one page. */
    private static function pager(int $page, int $pages, \Closure $link): string
    {
        if ($pages < 2) {
            return '';
        }
        return '    <nav class="players-pager" aria-label="Pages">'
            . ($page > 1 ? '<a href="' . Html::attr($link($page - 1)) . '" rel="prev">Previous</a>' : '<span></span>')
            . "<span>Page {$page} of {$pages}</span>"
            . ($page < $pages ? '<a href="' . Html::attr($link($page + 1)) . '" rel="next">Next</a>' : '<span></span>')
            . "</nav>\n";
    }

    /** Our own plain silhouette for a character with no picture yet (not Tiny Speck art). */
    private static function ghost(string $class): string
    {
        return '<svg class="' . $class . ' player-ghost" viewBox="0 0 100 144" width="100" height="144" role="img" aria-label="No picture yet">'
            . '<g fill="currentColor"><ellipse cx="50" cy="46" rx="29" ry="31"/><path d="M29 84c13-7 29-7 42 0l5 34H24z"/>'
            . '<rect x="33" y="114" width="12" height="24" rx="6"/><rect x="55" y="114" width="12" height="24" rx="6"/></g></svg>';
    }

    private static function query(Request $request): string
    {
        return $request->query === [] ? '' : '?' . http_build_query($request->query);
    }

    /** A whole page in the site's look: the head the themed pages carry, the header menu, the footer. */
    private function page(string $title, string $description, string $body, string $scripts = '', string $head = ''): Response
    {
        $path = $this->minn->request->path;
        $footer = (string) preg_replace('/<!--.*?-->\s*/s', '', (string) @file_get_contents(dirname(__DIR__) . '/parts/footer.html'));
        $html = "<!DOCTYPE html>\n<html lang=\"en-US\">\n<head>\n<meta charset=\"UTF-8\" />\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />\n"
            . '<title>' . Html::esc($title) . " &#8211; Groddle Afternoon</title>\n"
            . '<meta name="description" content="' . Html::attr($description) . "\" />\n"
            . Extension::head()
            . $head
            . "</head>\n<body class=\"notes players-page\">\n"
            . SiteNav::nav(SiteNav::links($this->minn, 0), $path, SiteNav::badges($this->minn)) . "\n"
            . $body . "\n"
            . trim($footer) . "\n" . $scripts . "</body>\n</html>\n";
        $html = SocialTags::insert($html, (string) ($this->minn->site->option('home') ?? ''), $path);
        // A signed-in reader's page can carry their nonce (the buddy button): theirs alone.
        return Response::html($this->minn->frontBar($html))->withHeader('Cache-Control', $this->minn->reader->userId > 0 ? 'private, no-cache' : 'no-cache');
    }
}
