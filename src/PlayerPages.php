<?php

declare(strict_types=1);

namespace Groddle\Minn;

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
 * The data is Profiles', the save's and Buddies'; the pictures are the ones
 * the game uploaded, served here. The save keeps no times for skills or
 * badges, so "newest" is the order they came in, with no dates.
 *
 * Drawn whole on the server in the site's look (the header menu, the
 * restoration report's tokens, light and dark) so they read without
 * JavaScript. Everything a player wrote is escaped. Only characters show:
 * accounts with a row in Characters' table, never an email or a username.
 */
final readonly class PlayerPages
{
    /** Tiles on one page of the grid. */
    public const PER_PAGE = 60;

    private const KINDS = ['avatar' => 'avatar', 'tile' => 'tile', 'home' => 'home'];

    /** A profile's pages: path under /players/<name>/ => tab label (the path keeps the original site's word). */
    private const TABS = ['' => 'Profile', 'skills' => 'Skills', 'achievements' => 'Badges', 'buddies' => 'Buddies'];

    /** Badges and skills the profile shows before "See all". */
    public const LATEST = 8;

    /** Buddies the profile shows before "See all". */
    public const BUDDIES_SHOWN = 12;

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

    #[Route(Method::Get, '/players/{slug:[a-z0-9][a-z0-9.-]*}/{tab:skills|achievements|buddies}', policy: new Policy(Access::Public))]
    public function bareTab(Request $request, string $slug, string $tab): Response
    {
        if ($this->find($slug) === null) {
            throw new RouteMiss();
        }
        return Response::redirect("/players/{$slug}/{$tab}/" . self::query($request), 301);
    }

    /** A profile's page of skills, badges or buddies. */
    #[Route(Method::Get, '/players/{slug:[a-z0-9][a-z0-9.-]*}/{tab:skills|achievements|buddies}/', policy: new Policy(Access::Public))]
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
        $db = $this->minn->db;
        $character = $db->row('SELECT name, created_at FROM ' . $db->table(Characters::TABLE) . ' WHERE user_id = ?', [$id]);
        $profile = $db->row('SELECT about, avatar_at, home_at FROM ' . $db->table(Profiles::TABLE) . ' WHERE user_id = ?', [$id]) ?? [];
        $facts = Profiles::fromSave($db, $id);
        // Below the listed level the page is the owner's alone.
        $own = $this->minn->reader->userId === $id;
        if ($facts['level'] < Profiles::LISTED_LEVEL && !$own) {
            throw new RouteMiss();
        }
        $name = (string) ($character['name'] ?? '');
        $who = [
            'id' => $id,
            'name' => $name,
            'base' => "/players/{$canonical}/",
            'facts' => $facts,
            'profile' => $profile,
            'joined' => strtotime(((string) ($character['created_at'] ?? '')) . ' UTC'),
            'badges' => $this->badges($facts['badges']),
            'skills' => self::skillRows($facts['skills'], $this->skillCatalog(), $this->root->dir),
            'buddyCount' => $this->buddies($id, 0, 0)['total'],
        ];
        $private = $facts['level'] < Profiles::LISTED_LEVEL
            ? '    <p class="profile-private">Only you can see this page for now. It goes on the Players page when you reach level ' . Profiles::LISTED_LEVEL . ".</p>\n"
            : '';
        $page = max(1, (int) ($request->query('page') ?? '1'));
        [$title, $description, $main] = match ($tab) {
            '' => [$name, "{$name}, a player in Groddle Afternoon.", $this->overview($who)],
            'skills' => ["{$name}: Skills", "The skills {$name} has learned in Groddle Afternoon.", self::skillsSection($who, null)],
            'achievements' => ["{$name}: Badges", "The badges {$name} has earned in Groddle Afternoon.", self::badgesSection($who, null)],
            'buddies' => ["{$name}: Buddies", "{$name}'s buddies in Groddle Afternoon.", $this->buddiesSection($who, $page)],
        };
        $body = self::masthead($who, $tab)
            . '<main class="band profile' . ($tab === '' ? '' : " profile-page-{$tab}") . "\">\n  <div class=\"wrap\">\n"
            . $private . $main
            . "  </div>\n</main>";
        return $this->page($title, $description, $body);
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
        $avatarAt = is_string($profile['avatar_at'] ?? null) && $profile['avatar_at'] !== '' ? $profile['avatar_at'] : null;
        $counts = ['skills' => count($who['skills']), 'achievements' => count($who['badges']), 'buddies' => $who['buddyCount']];
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
                . $tabs
                . "    </div>\n  </div>\n</header>\n";
        }
        $about = trim((string) ($profile['about'] ?? ''));
        $joined = $who['joined'];
        $avatar = $avatarAt === null
            ? self::ghost('profile-avatar-art')
            : '<img class="profile-avatar-art" src="' . Html::attr(Profiles::imageUrl($id, 'avatar', $avatarAt)) . '" width="172" height="248" alt="' . Html::attr("{$name}'s Glitch") . '" />';
        $count = $counts['achievements'];
        return '<header class="masthead note-head profile-head">' . "\n  <div class=\"wrap profile-top\">\n"
            . "    <figure class=\"profile-avatar\">{$avatar}</figure>\n"
            . "    <div class=\"profile-who\">\n"
            . "      <p class=\"kicker\"><a href=\"/players/\">Players</a></p>\n"
            . "      <h1>{$nameHtml}</h1>\n"
            . '      <ul class="profile-stats">'
            . '<li><strong>' . $facts['level'] . '</strong><span>Level</span></li>'
            . '<li><strong>' . number_format($counts['skills']) . '</strong><span>' . ($counts['skills'] === 1 ? 'Skill' : 'Skills') . '</span></li>'
            . '<li><strong>' . number_format($count) . '</strong><span>' . ($count === 1 ? 'Badge' : 'Badges') . '</span></li>'
            . ($joined ? '<li><strong>' . gmdate('M j, Y', $joined) . '</strong><span>Joined</span></li>' : '')
            . "</ul>\n"
            . ($about === ''
                ? "      <p class=\"profile-bio profile-empty\">{$nameHtml} hasn't written anything about themselves yet.</p>\n"
                : '      <p class="profile-bio">' . nl2br(Html::esc($about), false) . "</p>\n")
            . $tabs
            . "    </div>\n  </div>\n</header>\n";
    }

    /** The profile: the home street, then the newest badges and skills and some buddies, each with a way to the rest. */
    private function overview(array $who): string
    {
        ['id' => $id, 'name' => $name, 'profile' => $profile] = $who;
        $nameHtml = Html::esc($name);
        $homeAt = is_string($profile['home_at'] ?? null) && $profile['home_at'] !== '' ? $profile['home_at'] : null;
        return "    <section class=\"profile-section\" aria-labelledby=\"profile-home\">\n      <h2 id=\"profile-home\">{$nameHtml}'s home street</h2>\n"
            . ($homeAt === null
                ? "      <p class=\"profile-empty\">No picture of {$nameHtml}'s home yet. It appears after they visit their home street in multiplayer.</p>\n"
                : '      <figure class="profile-home"><img src="' . Html::attr(Profiles::imageUrl($id, 'home', $homeAt)) . '" alt="' . Html::attr("{$name}'s home street") . "\" loading=\"lazy\" decoding=\"async\" /></figure>\n")
            . "    </section>\n"
            . self::badgesSection($who, self::LATEST)
            . self::skillsSection($who, self::LATEST)
            . $this->buddiesSection($who, 0);
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
            $out .= "\n        <li title=\"" . Html::attr($desc) . '"><img src="/assets/badges/' . Html::attr($badge) . '.png" width="72" height="72" alt="" loading="lazy" decoding="async" /><span>' . Html::esc($label) . '</span>'
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
     * A picture the game uploaded, as image/png; versioned URLs are kept for a year. The path has no
     * .png: Kinsta's nginx answers any missing .png file itself, so PHP never saw those (2026-10-29).
     */
    #[Route(Method::Get, '/players/{id:\d+}/{kind:avatar|tile|home}', policy: new Policy(Access::Public))]
    public function image(Request $request, string $id, string $kind): Response
    {
        $kind = self::KINDS[$kind] ?? throw new RouteMiss();
        $at = $kind === 'home' ? 'home_at' : 'avatar_at';
        $db = $this->minn->db;
        $row = $db->row("SELECT {$kind} AS png, {$at} AS at FROM " . $db->table(Profiles::TABLE) . ' WHERE user_id = ?', [(int) $id]);
        if (!is_string($row['png'] ?? null) || $row['png'] === '' || !is_string($row['at'] ?? null)) {
            throw new RouteMiss();
        }
        // An unlisted character's pictures are its owner's alone, so a new account can't host images here.
        $listed = Profiles::fromSave($db, (int) $id)['level'] >= Profiles::LISTED_LEVEL;
        if (!$listed && $this->minn->reader->userId !== (int) $id) {
            throw new RouteMiss();
        }
        $stamp = Profiles::stamp($row['at']);
        $etag = "\"{$kind}-{$stamp}\"";
        $headers = [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => !$listed ? 'private, no-store' : ($request->query('v') === $stamp ? 'public, max-age=31536000, immutable' : 'public, max-age=60'),
            'ETag' => $etag,
        ];
        if (trim((string) $request->header('if-none-match')) === $etag) {
            return new Response(304, $headers);
        }
        return new Response(200, $headers + ['Content-Length' => (string) strlen($row['png'])], $row['png']);
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
        return null;
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
    private function page(string $title, string $description, string $body): Response
    {
        $path = $this->minn->request->path;
        $footer = (string) preg_replace('/<!--.*?-->\s*/s', '', (string) @file_get_contents(dirname(__DIR__) . '/parts/footer.html'));
        $html = "<!DOCTYPE html>\n<html lang=\"en-US\">\n<head>\n<meta charset=\"UTF-8\" />\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />\n"
            . '<title>' . Html::esc($title) . " &#8211; Groddle Afternoon</title>\n"
            . '<meta name="description" content="' . Html::attr($description) . "\" />\n"
            . Extension::head()
            . "</head>\n<body class=\"notes players-page\">\n"
            . SiteNav::nav(SiteNav::links($this->minn, 0), $path) . "\n"
            . $body . "\n"
            . trim($footer) . "\n</body>\n</html>\n";
        return Response::html($this->minn->frontBar($html))->withHeader('Cache-Control', 'no-cache');
    }
}
