<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Html;

/**
 * The Players page's ways to look (Austin 2026-11-25, register G1298): sorts beside Newest
 * (Level, Age, Recently played, Name), filters (Has public snaps, Has a Tower, Has a home),
 * a Cards / Neighbourhood view and "Visit a neighbour". Everything is a
 * query parameter (?sort=level&snaps=1&view=street&page=2, ?visit=1) read on the server, so the
 * page works without JavaScript; players/players.js only swaps the list in place.
 *
 * What a card shows is what a profile already shows in public (the level, the join date, the
 * public Snaps, the home street picture, the Tower page), plus two things from play: when they last
 * played, in days (PlayTime's last time; Austin approved this sort on 2026-11-25, the total stays
 * administrators' alone), and a green dot while they are connected (the realtime server's
 * "online" list). Those two are for signed-in players only (an account with a character;
 * Austin 2026-11-25: "I don't want non players to see that"): a signed-out page is drawn with
 * no dot, no "Played ..." and no Recently played sort, and asks nothing about either (no data
 * attribute, no JSON). There is no Online now filter. A player with "Appear offline"
 * (PresenceSetting) is never online here and sorts as never seen: their last time is never
 * read out of the database.
 *
 * Glitch's profiles showed the join date ("Joined ..."; research/wayback/raw/profiles/) and a
 * player's home street; the neighbourhood view lays every listed home street picture side by
 * side, as one long street.
 *
 * Pure helpers (no database): PlayerPages::index runs the queries.
 */
final class PlayerList
{
    /** The sorts, query value => tab label; the first is the default. */
    public const SORTS = ['newest' => 'Newest', 'level' => 'Level', 'age' => 'Age', 'played' => 'Recently played', 'name' => 'Name'];

    /** The filters, query key => label (each ?key=1). */
    public const FILTERS = ['snaps' => 'Has public snaps', 'tower' => 'Has a Tower', 'home' => 'Has a home'];

    /** The sorts only signed-in players are offered (the rest of the world sees no play times). */
    public const PLAYER_SORTS = ['played'];

    /** Filters' earlier names, still read: ?street=1 was Has a home street. */
    public const FILTER_ALIASES = ['street' => 'home'];

    /** The views, query value => label; the first is the default. */
    public const VIEWS = ['cards' => 'Cards', 'street' => 'Neighbourhood'];

    /** The realtime server's connected players as the site keeps them: {"checked": unix s, "ids": [..]}. */
    public const ONLINE_OPTION = 'groddle_players_online';

    /** When the site last asked (unix s), answered or not. */
    public const ONLINE_TRIED = 'groddle_players_online_tried';

    /** Seconds the kept list is used before the next page view asks again. */
    public const ONLINE_REFRESH = 30;

    /** Seconds between asks while the server does not answer. */
    public const ONLINE_RETRY = 30;

    /** The oldest list shown; older, nobody shows online (no stale green dots). */
    public const ONLINE_KEEP = 120;

    /** Seconds the page waits for the realtime server (a public page must not hang on it). */
    public const ONLINE_TIMEOUT = 1.0;

    /** How wide a neighbourhood lot shows (the phone copy is HomePicture::SMALL_W wide). */
    public const LOT_W = 360;

    /**
     * The page's options from its query: an unknown sort or view is the default (so is a
     * player-only sort for anyone else), a filter is on only as "1", the page at least 1.
     *
     * @param array<string, mixed> $query
     * @return array{sort: string, filters: array<string, bool>, view: string, page: int}
     */
    public static function options(array $query, bool $player = false): array
    {
        $sort = is_string($query['sort'] ?? null) && isset(self::sorts($player)[$query['sort']]) ? $query['sort'] : array_key_first(self::SORTS);
        $view = is_string($query['view'] ?? null) && isset(self::VIEWS[$query['view']]) ? $query['view'] : array_key_first(self::VIEWS);
        $filters = [];
        foreach (self::FILTERS as $key => $label) {
            $filters[$key] = ($query[$key] ?? null) === '1';
        }
        foreach (self::FILTER_ALIASES as $old => $key) {
            $filters[$key] = $filters[$key] || ($query[$old] ?? null) === '1';
        }
        $page = is_string($query['page'] ?? null) && ctype_digit($query['page']) ? max(1, (int) $query['page']) : 1;
        return ['sort' => $sort, 'filters' => $filters, 'view' => $view, 'page' => $page];
    }

    /** The sorts offered: every one to a signed-in player, the others to everyone else. @return array<string, string> */
    public static function sorts(bool $player): array
    {
        return $player ? self::SORTS : array_diff_key(self::SORTS, array_flip(self::PLAYER_SORTS));
    }

    /**
     * The page's address with some options changed (the page goes back to 1 unless given):
     * defaults are left out, so the plain page is /players/.
     *
     * @param array{sort: string, filters: array<string, bool>, view: string, page: int} $o
     * @param array<string, mixed> $change
     */
    public static function href(array $o, array $change = []): string
    {
        $o = array_replace($o, ['page' => 1], $change);
        $q = [];
        if ($o['sort'] !== array_key_first(self::SORTS)) {
            $q['sort'] = $o['sort'];
        }
        foreach (self::FILTERS as $key => $label) {
            if (!empty($o['filters'][$key])) {
                $q[$key] = '1';
            }
        }
        if ($o['view'] !== array_key_first(self::VIEWS)) {
            $q['view'] = $o['view'];
        }
        if ($o['page'] > 1) {
            $q['page'] = (string) $o['page'];
        }
        return '/players/' . ($q === [] ? '' : '?' . http_build_query($q));
    }

    /**
     * ORDER BY for a sort, given the SQL for the level and the last-played time (NULL for a
     * player never seen or hidden). Ties go by name, then id, so a page never shuffles.
     */
    public static function orderSql(string $sort, string $levelSql, string $playedSql): string
    {
        return match ($sort) {
            'level' => "{$levelSql} DESC, c.name_key ASC, c.user_id ASC",
            'age' => 'c.created_at ASC, c.user_id ASC',
            'played' => "({$playedSql}) IS NULL ASC, ({$playedSql}) DESC, c.name_key ASC, c.user_id ASC",
            'name' => 'c.name_key ASC, c.user_id ASC',
            default => 'c.created_at DESC, c.user_id DESC',
        };
    }

    /**
     * How long ago, in whole days at the finest ("today", "yesterday", "3 days ago", "2 weeks
     * ago", "5 months ago", "2 years ago"): a last-played time is never told closer than a day.
     */
    public static function ago(int $then, int $now): string
    {
        $days = intdiv(max(0, $now - $then), 86400);
        $plural = static fn (int $n, string $one): string => $n === 1 ? "1 {$one} ago" : "{$n} {$one}s ago";
        return match (true) {
            $days === 0 => 'today',
            $days === 1 => 'yesterday',
            $days < 14 => $plural($days, 'day'),
            $days < 60 => $plural(intdiv($days, 7), 'week'),
            $days < 730 => $plural(intdiv($days, 30), 'month'),
            default => $plural(intdiv($days, 365), 'year'),
        };
    }

    /**
     * The realtime server's "online" answer (admin.go adminOnline: [{id, name, street, since,
     * off}]) as the site keeps it: the ids of the connected players who do not appear offline,
     * each once, sorted. Null when it is not an answer.
     *
     * @return array{checked: int, ids: list<int>}|null
     */
    public static function onlineSnapshot(mixed $answer, int $now): ?array
    {
        if (!is_array($answer) || !is_array($answer['players'] ?? null) || ($answer['http'] ?? 200) !== 200) {
            return null;
        }
        $ids = [];
        foreach ($answer['players'] as $p) {
            if (is_array($p) && is_int($p['id'] ?? null) && $p['id'] > 0 && empty($p['off'])) {
                $ids[$p['id']] = $p['id'];
            }
        }
        sort($ids);
        return ['checked' => $now, 'ids' => array_values($ids)];
    }

    /** The kept list back from its option, or null. @return array{checked: int, ids: list<int>}|null */
    public static function readOnline(mixed $json): ?array
    {
        $data = json_decode(is_string($json) ? $json : '', true);
        if (!is_array($data) || !is_int($data['checked'] ?? null) || !is_array($data['ids'] ?? null)) {
            return null;
        }
        return ['checked' => $data['checked'], 'ids' => array_values(array_filter($data['ids'], static fn (mixed $id): bool => is_int($id) && $id > 0))];
    }

    /** Whether to ask the realtime server now: the kept list is missing or past REFRESH, and no ask in the last RETRY. */
    public static function onlineDue(?array $snap, int $tried, int $now): bool
    {
        return ($snap === null || $snap['checked'] <= $now - self::ONLINE_REFRESH) && $tried <= $now - self::ONLINE_RETRY;
    }

    /** The ids shown online from the kept list: none when it is older than KEEP. @return list<int> */
    public static function onlineIds(?array $snap, int $now): array
    {
        return $snap === null || $snap['checked'] < $now - self::ONLINE_KEEP || $snap['checked'] > $now + 60 ? [] : $snap['ids'];
    }

    /**
     * One character's card: the tile picture with a green dot while online, the name, the
     * sort's fact (the level, when they joined, when they last played) and the public Snaps'
     * count on a camera chip.
     *
     * @param array{id: int, name: string, slug: string, avatar_at: ?string, level: int, joined: int|false, played: ?int, snaps: int, online: bool} $c
     */
    public static function card(array $c, string $sort, int $now, string $indent): string
    {
        $face = $c['avatar_at'] === null
            ? PlayerPages::ghost('player-tile-art')
            : '<img class="player-tile-art" src="' . Html::attr(Profiles::imageUrl($c['id'], 'tile', $c['avatar_at'])) . '" width="100" height="144" alt="" loading="lazy" decoding="async" />';
        $dot = $c['online'] ? '<span class="player-online" title="Online now"><span class="players-sr">Online now</span></span>' : '';
        $fact = match ($sort) {
            'level' => 'Level ' . $c['level'],
            'age' => $c['joined'] ? 'Joined <time datetime="' . gmdate('Y-m-d', $c['joined']) . '" title="' . gmdate('M j, Y', $c['joined']) . '">' . self::ago($c['joined'], $now) . '</time>' : '',
            'played' => $c['online'] ? 'Online now' : ($c['played'] !== null ? 'Played ' . self::ago($c['played'], $now) : ''),
            default => '',
        };
        $chip = $c['snaps'] > 0
            ? '<span class="player-snaps" title="' . ($c['snaps'] === 1 ? '1 public snap' : $c['snaps'] . ' public snaps') . '">' . self::camera() . $c['snaps']
                . '<span class="players-sr">' . ($c['snaps'] === 1 ? ' public snap' : ' public snaps') . '</span></span>'
            : '';
        return "\n{$indent}<li><a class=\"player-tile\" href=\"/players/" . Html::attr($c['slug']) . '/">'
            . "<span class=\"player-tile-pic\">{$face}{$dot}</span>"
            . '<span class="player-tile-name">' . Html::esc($c['name']) . '</span>'
            . ($fact === '' ? '' : "<span class=\"player-tile-fact\">{$fact}</span>")
            . $chip . '</a></li>';
    }

    /**
     * One lot of the neighbourhood: the home street picture (its phone copy when there is one),
     * lazy, the name under it, opening their profile (which draws the home street); a player
     * with no picture yet gets a plain empty lot (CSS only).
     *
     * @param array{id: int, name: string, slug: string, home_at: ?string, home_w: int, home_h: int, home_small: bool, online: bool} $c
     */
    public static function lot(array $c, string $indent): string
    {
        $name = Html::esc($c['name']);
        $base = '/players/' . Html::attr($c['slug']) . '/';
        $dot = $c['online'] ? '<span class="player-online" title="Online now"><span class="players-sr">Online now</span></span>' : '';
        if ($c['home_at'] === null) {
            return "\n{$indent}<li class=\"hood-lot hood-lot-empty\"><a href=\"{$base}\"><span class=\"hood-house hood-empty\"><span>No picture yet</span></span>"
                . "<span class=\"hood-name\">{$name}{$dot}</span></a></li>";
        }
        $w = $c['home_w'];
        $h = $c['home_h'];
        $small = $c['home_small'] && $w > HomePicture::SMALL_W;
        $src = Profiles::imageUrl($c['id'], $small ? 'home-small' : 'home', $c['home_at']);
        $size = '';
        if ($w > 0 && $h > 0) {
            $sw = $small ? HomePicture::SMALL_W : $w;
            $size = " width=\"{$sw}\" height=\"" . max(1, (int) round($h * $sw / $w)) . '"';
        }
        return "\n{$indent}<li class=\"hood-lot\"><a href=\"{$base}\">"
            . '<img class="hood-house" src="' . Html::attr($src) . "\"{$size} alt=\"" . Html::attr("{$c['name']}'s home street") . '" loading="lazy" decoding="async" />'
            . "<span class=\"hood-name\">{$name}{$dot}</span></a></li>";
    }

    /** A small camera for the Snaps chip (a plain drawing, not Tiny Speck art). */
    private static function camera(): string
    {
        return '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false"><path fill="currentColor" d="M5.5 2.5h5l1 1.5H14a1 1 0 0 1 1 1V13a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h2.5zM8 6a3 3 0 1 0 0 6 3 3 0 0 0 0-6m0 1.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3"/></svg>';
    }
}
