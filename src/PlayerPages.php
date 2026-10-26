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
 * wrote, a picture of their home street and their badges. Glitch's website
 * had both (a profile per player at /profiles/<tsid>/: the avatar's _172
 * still, level, latest badge and count, the profile-bio, "View <name>'s Home
 * Street", and buddy grids of _100 avatars; Wayback capture in
 * research/wayback/raw/profiles/). The data is Profiles' and the save's;
 * the pictures are the ones the game uploaded, served here.
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
        $total = (int) $db->value("SELECT COUNT(*) FROM {$characters} c JOIN {$users} u ON u.ID = c.user_id");
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $order = $byName ? 'c.name_key ASC, c.user_id ASC' : 'c.created_at DESC, c.user_id DESC';
        $rows = $db->rows("SELECT c.user_id, c.name, p.avatar_at FROM {$characters} c JOIN {$users} u ON u.ID = c.user_id LEFT JOIN {$profiles} p ON p.user_id = c.user_id ORDER BY {$order} LIMIT ? OFFSET ?", [self::PER_PAGE, ($page - 1) * self::PER_PAGE]);
        $slugs = Profiles::slugs($db);

        $tiles = '';
        foreach ($rows as $row) {
            $id = (int) $row['user_id'];
            $name = (string) $row['name'];
            if (!isset($slugs[$id])) {
                continue;
            }
            $at = is_string($row['avatar_at'] ?? null) && $row['avatar_at'] !== '' ? $row['avatar_at'] : null;
            $face = $at === null
                ? self::ghost('player-tile-art')
                : '<img class="player-tile-art" src="' . Html::attr(Profiles::imageUrl($id, 'tile', $at)) . '" width="100" height="144" alt="" loading="lazy" decoding="async" />';
            $tiles .= "\n      <li><a class=\"player-tile\" href=\"/players/" . Html::attr($slugs[$id]) . "/\">{$face}<span class=\"player-tile-name\">" . Html::esc($name) . '</span></a></li>';
        }
        $sort = static fn (bool $name, string $label) => $name === $byName
            ? '<span aria-current="true">' . $label . '</span>'
            : '<a href="/players/' . ($name ? '?sort=name' : '') . '">' . $label . '</a>';
        $count = $total === 1 ? '1 character' : number_format($total) . ' characters';
        $body = '<header class="masthead note-head">' . "\n  <div class=\"wrap\">\n"
            . "    <p class=\"kicker\">Multiplayer</p>\n"
            . "    <h1>Players</h1>\n"
            . "    <p class=\"lead\">Every character in Groddle Afternoon's multiplayer, each in their own look. Pick one to see their home and badges.</p>\n"
            . "  </div>\n</header>\n"
            . "<main class=\"band players\">\n  <div class=\"wrap\">\n"
            . '    <p class="players-bar"><span>' . $count . '</span><span class="players-sort">' . $sort(false, 'Newest') . ' · ' . $sort(true, 'By name') . "</span></p>\n";
        $body .= $tiles === ''
            ? "    <p class=\"players-empty\">No characters yet. Make one from Play, Multiplayer.</p>\n"
            : "    <ul class=\"players-grid\">{$tiles}\n    </ul>\n";
        if ($pages > 1) {
            $link = static fn (int $n): string => '/players/?' . http_build_query(array_filter(['sort' => $byName ? 'name' : null, 'page' => $n > 1 ? $n : null]));
            $body .= '    <nav class="players-pager" aria-label="Pages">'
                . ($page > 1 ? '<a href="' . Html::attr($link($page - 1)) . '" rel="prev">Previous</a>' : '<span></span>')
                . "<span>Page {$page} of {$pages}</span>"
                . ($page < $pages ? '<a href="' . Html::attr($link($page + 1)) . '" rel="next">Next</a>' : '<span></span>')
                . "</nav>\n";
        }
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
        $found = $this->find($slug);
        if ($found === null) {
            throw new RouteMiss();
        }
        [$id, $canonical] = $found;
        if ($canonical !== $slug) {
            return Response::redirect("/players/{$canonical}/", 301);
        }
        $db = $this->minn->db;
        $character = $db->row('SELECT name, created_at FROM ' . $db->table(Characters::TABLE) . ' WHERE user_id = ?', [$id]);
        $profile = $db->row('SELECT about, avatar_at, home_at FROM ' . $db->table(Profiles::TABLE) . ' WHERE user_id = ?', [$id]) ?? [];
        $facts = Profiles::fromSave($db, $id);
        $name = (string) ($character['name'] ?? '');
        $nameHtml = Html::esc($name);
        $about = trim((string) ($profile['about'] ?? ''));
        $avatarAt = is_string($profile['avatar_at'] ?? null) && $profile['avatar_at'] !== '' ? $profile['avatar_at'] : null;
        $homeAt = is_string($profile['home_at'] ?? null) && $profile['home_at'] !== '' ? $profile['home_at'] : null;
        $joined = strtotime(((string) ($character['created_at'] ?? '')) . ' UTC');
        $badges = $this->badges($facts['badges']);

        $avatar = $avatarAt === null
            ? self::ghost('profile-avatar-art')
            : '<img class="profile-avatar-art" src="' . Html::attr(Profiles::imageUrl($id, 'avatar', $avatarAt)) . '" width="172" height="248" alt="' . Html::attr("{$name}'s Glitch") . '" />';
        $count = count($badges);
        $body = '<header class="masthead note-head profile-head">' . "\n  <div class=\"wrap profile-top\">\n"
            . "    <figure class=\"profile-avatar\">{$avatar}</figure>\n"
            . "    <div class=\"profile-who\">\n"
            . "      <p class=\"kicker\"><a href=\"/players/\">Players</a></p>\n"
            . "      <h1>{$nameHtml}</h1>\n"
            . '      <ul class="profile-stats">'
            . '<li><strong>' . $facts['level'] . '</strong><span>Level</span></li>'
            . '<li><strong>' . number_format($count) . '</strong><span>' . ($count === 1 ? 'Badge' : 'Badges') . '</span></li>'
            . ($joined ? '<li><strong>' . gmdate('M j, Y', $joined) . '</strong><span>Joined</span></li>' : '')
            . "</ul>\n"
            . ($about === ''
                ? "      <p class=\"profile-bio profile-empty\">{$nameHtml} hasn't written anything about themselves yet.</p>\n"
                : '      <p class="profile-bio">' . nl2br(Html::esc($about), false) . "</p>\n")
            . "    </div>\n  </div>\n</header>\n"
            . "<main class=\"band profile\">\n  <div class=\"wrap\">\n"
            . "    <section class=\"profile-section\" aria-labelledby=\"profile-home\">\n      <h2 id=\"profile-home\">{$nameHtml}'s home street</h2>\n"
            . ($homeAt === null
                ? "      <p class=\"profile-empty\">No picture of {$nameHtml}'s home yet. It appears after they visit their home street in multiplayer.</p>\n"
                : '      <figure class="profile-home"><img src="' . Html::attr(Profiles::imageUrl($id, 'home', $homeAt)) . '" alt="' . Html::attr("{$name}'s home street") . "\" loading=\"lazy\" decoding=\"async\" /></figure>\n")
            . "    </section>\n"
            . "    <section class=\"profile-section\" aria-labelledby=\"profile-badges\">\n      <h2 id=\"profile-badges\">Badges</h2>\n";
        if ($badges === []) {
            $body .= "      <p class=\"profile-empty\">No badges yet.</p>\n";
        } else {
            $body .= "      <ul class=\"profile-badges\">";
            foreach ($badges as [$badge, $label, $desc]) {
                $body .= "\n        <li title=\"" . Html::attr($desc) . '"><img src="/assets/badges/' . Html::attr($badge) . '.png" width="72" height="72" alt="" loading="lazy" decoding="async" /><span>' . Html::esc($label) . '</span></li>';
            }
            $body .= "\n      </ul>\n";
        }
        $body .= "    </section>\n  </div>\n</main>";
        return $this->page($name, "{$name}, a player in Groddle Afternoon.", $body);
    }

    /** A picture the game uploaded, as image/png; versioned URLs are kept for a year. */
    #[Route(Method::Get, '/players/{id:\d+}/{kind:avatar|tile|home}.png', policy: new Policy(Access::Public))]
    public function image(Request $request, string $id, string $kind): Response
    {
        $kind = self::KINDS[$kind] ?? throw new RouteMiss();
        $at = $kind === 'home' ? 'home_at' : 'avatar_at';
        $db = $this->minn->db;
        $row = $db->row("SELECT {$kind} AS png, {$at} AS at FROM " . $db->table(Profiles::TABLE) . ' WHERE user_id = ?', [(int) $id]);
        if (!is_string($row['png'] ?? null) || $row['png'] === '' || !is_string($row['at'] ?? null)) {
            throw new RouteMiss();
        }
        $stamp = Profiles::stamp($row['at']);
        $etag = "\"{$kind}-{$stamp}\"";
        $headers = [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => $request->query('v') === $stamp ? 'public, max-age=31536000, immutable' : 'public, max-age=60',
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
