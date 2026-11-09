<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Html;
use Minn\Json;
use Minn\RestError;

/**
 * A player's home street as a layout (Austin 2026-11-02): the manifest the
 * game reads off its own stage (the game's js/home-layout.js has the schema)
 * and sends to PUT groddle/v1/profile/home-layout (Profiles::homeLayout), and
 * the diorama the profile draws from it (PlayerPages) with the game's own art:
 * every thing on the street placed where the game drew it, named on hover and
 * focus, reading without JavaScript.
 *
 * The manifest is untrusted. accept() takes only the fields version 1 has,
 * each in range, and throws on anything else; what it returns is the
 * normalized copy that is stored. Art paths must be files under the built
 * game's assets (Tiny Speck's decos and sprites, the home pieces, the
 * signpost; never new gz_ art or made animals), checked on disk inside the
 * static root; their size is read from the file, so a frame cut from a sheet
 * must lie inside it. Spots name a kind from KINDS, and class ids must be items
 * the encyclopedia knows. Every word the page shows is written here from those
 * ids (item names from the encyclopedia's data, the kinds' own words, the
 * texts in TEXTS); the one piece of player text, a Tower's name, goes through
 * WordFilter and is escaped like everything else.
 */
final class HomeLayout
{
    public const VERSION = 1;

    /** The manifest's JSON at most (the game's HOME_LAYOUT_LIMITS, held together by a test). */
    public const MAX_BYTES = 48 * 1024;
    public const MIN_W = 240;
    public const MAX_W = 2400;
    public const MIN_H = 80;
    public const MAX_H = 1400;
    public const MAX_ART = 160;
    public const MAX_DRAW = 400;
    public const MAX_SPOTS = 80;
    /** How far past the stage's edges a box may reach. */
    public const REACH = 4000;
    public const NAME_MAX = 40;

    /**
     * Version 2: the whole street to pan along (the game's HOME_STREET_VERSION and
     * HOME_STREET_LIMITS): a home street is at most 40 yard segments of 150 px and the
     * 720 px lot (6720 px); layers each with their parallax factor.
     */
    public const STREET_VERSION = 2;
    public const STREET_MAX_BYTES = 160 * 1024;
    public const MAX_SW = 8000;
    public const STREET_MAX_ART = 240;
    public const STREET_MAX_DRAW = 1600;
    public const STREET_MAX_SPOTS = 160;
    public const MAX_LAYERS = 24;

    /** The versions accept() takes (the profile answer names them, so the game sends the newest). */
    public const VERSIONS = [1, 2];

    /** The kinds of spot (the game's HOME_LAYOUT_KINDS). */
    /**
     * Kinds only the street's owner sees in the game, so a player's page leaves them out: the Mail
     * Dispatcher (the game draws it on your own home street only, mail-courier-feature.js; Austin
     * 2026-11-09: hide it from the home street previews). Layouts the game already sent keep the spot.
     */
    public const OWNER_ONLY = ['dispatcher'];

    public const KINDS = ['avatar', 'house', 'door', 'mailbox', 'signpost', 'dispatcher', 'tower', 'scaffolding', 'cultivation', 'proto', 'note_pole', 'item'];

    /** Fixed words the game paints on the street, by id (the game's HOME_LAYOUT_TEXTS). */
    public const TEXTS = ['way_out' => 'Back to Ur'];

    /** House styles (the game's homes-feature HOUSE_STYLE_KEYS) and their names. */
    public const HOUSE_STYLES = ['meadow' => 'Meadow', 'firebog' => 'Firebog', 'uralia' => 'Uralia'];

    /** Where art may come from under the static root (the game's HOME_LAYOUT_ART_ROOTS). */
    public const ART_ROOTS = ['assets/decos/', 'assets/sprites/', 'assets/homes/', 'assets/ui/signpost/'];

    /** The smallest box a spot is given on the page, so a flat garden bed can still be pointed at. */
    private const MIN_SPOT = 28;

    private const ART_PATH = '#^assets/[A-Za-z0-9_\-./@]+\.(png|webp|svg|jpg)$#';
    private const CLASS_ID = '/^[a-z0-9_]{1,64}$/';
    private const HEX = '/^#[0-9a-f]{6}$/';

    /** @var array<string, array<string, string>> encyclopedia item names by static root */
    private static array $names = [];

    /** @var array<string, array<string, array>> split 3x sheets by static root (splitSheets) */
    private static array $splits = [];

    /** @var array<string, ?array{0: int, 1: int}> a 3x part's size in 1x units, by file */
    private static array $partSizes = [];

    /**
     * The manifest checked and normalized, or a RestError. $root is the
     * built game (StaticRoot::$dir).
     *
     * @return array<string, mixed>
     */
    public static function accept(mixed $value, string $root): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::bad('Send the home street as a layout object.');
        }
        $street = ($value['v'] ?? null) === self::STREET_VERSION;
        $max = $street ? self::STREET_MAX_BYTES : self::MAX_BYTES;
        $json = json_encode($value);
        if (!is_string($json) || strlen($json) > $max) {
            throw new RestError('groddle_home_layout_too_large', 'That home street is larger than a profile keeps.', 413, ['max' => $max]);
        }
        $fields = $street ? ['v', 'w', 'h', 'sw', 'cx', 'art', 'layers', 'spots'] : ['v', 'w', 'h', 'art', 'draw', 'spots'];
        self::keys($value, $fields, $fields);
        if ($value['v'] !== self::VERSION && !$street) {
            throw self::bad('That layout version is not one this site knows.');
        }
        $w = self::int($value['w'], self::MIN_W, self::MAX_W, 'w');
        $h = self::int($value['h'], self::MIN_H, self::MAX_H, 'h');
        // Version 2: the whole street, at least the view wide, and where the camera starts on it.
        $sw = $street ? self::int($value['sw'], $w, self::MAX_SW, 'sw') : $w;
        $xMax = self::REACH + ($sw - $w);

        // Art: each a file under the static root, its size read from the file.
        $art = self::list($value['art'], $street ? self::STREET_MAX_ART : self::MAX_ART, 'art');
        $files = [];
        $seen = [];
        foreach ($art as $path) {
            $file = self::artFile($path, $root);
            if (isset($seen[$file['p']])) {
                throw self::bad('Each art path is listed once.');
            }
            $seen[$file['p']] = true;
            $files[] = $file;
        }

        // Spots first (draw entries point at them).
        $spotsIn = self::list($value['spots'], $street ? self::STREET_MAX_SPOTS : self::MAX_SPOTS, 'spots');
        $names = self::itemNames($root);
        $spots = [];
        $keep = [];
        foreach ($spotsIn as $n => $spot) {
            $clean = self::spot($spot, $names, $xMax);
            if ($clean !== null) {
                $keep[$n] = count($spots);
                $spots[] = $clean;
            }
        }

        $avatar = false;
        if (!$street) {
            $draw = [];
            foreach (self::list($value['draw'], self::MAX_DRAW, 'draw') as $entry) {
                $draw[] = self::entry($entry, $files, count($spotsIn), $keep, $avatar, $xMax);
            }
            return ['v' => self::VERSION, 'w' => $w, 'h' => $h, 'art' => $files, 'draw' => $draw, 'spots' => $spots];
        }

        // Layers, back to front, each scrolled by its parallax factor (0 the sky, 1 the street).
        $layers = [];
        $total = 0;
        foreach (self::list($value['layers'], self::MAX_LAYERS, 'layers') as $layer) {
            if (!is_array($layer) || array_is_list($layer)) {
                throw self::bad('Each layer is an object.');
            }
            self::keys($layer, ['f', 'draw'], ['f', 'draw']);
            $f = round(self::num($layer['f'], 0, 1, 'f'), 3);
            $draw = [];
            foreach (self::list($layer['draw'], self::STREET_MAX_DRAW, 'draw') as $entry) {
                if (++$total > self::STREET_MAX_DRAW) {
                    throw self::bad('A layout has at most ' . self::STREET_MAX_DRAW . ' draw entries.');
                }
                $out = self::entry($entry, $files, count($spotsIn), $keep, $avatar, $xMax);
                // Only the street itself (f 1) carries things to point at.
                if ($f !== 1.0) {
                    unset($out['s']);
                }
                $draw[] = $out;
            }
            $layers[] = ['f' => $f, 'draw' => $draw];
        }
        $cx = self::int($value['cx'], 0, $sw - $w, 'cx');
        return ['v' => self::STREET_VERSION, 'w' => $w, 'h' => $h, 'sw' => $sw, 'cx' => $cx, 'art' => $files, 'layers' => $layers, 'spots' => $spots];
    }

    /**
     * One draw entry checked: an image (a), a band (col), the sky, a sign's
     * words (txt) or the avatar, each with only its own fields, in range.
     *
     * @param list<array{p: string, w: int, h: int, x2: ?array}> $files
     * @param array<int, int> $keep spot index in the manifest => kept index
     * @return array<string, mixed>
     */
    private static function entry(mixed $entry, array $files, int $spotCount, array $keep, bool &$avatar, int $xMax): array
    {
        if (!is_array($entry) || array_is_list($entry)) {
            throw self::bad('Each draw entry is an object.');
        }
        if (array_key_exists('a', $entry)) {
            self::keys($entry, ['a', 'x', 'y', 'w', 'h'], ['a', 'x', 'y', 'w', 'h', 'f', 'r', 'o', 't', 'c', 's']);
            $a = self::int($entry['a'], 0, count($files) - 1, 'a');
            $out = ['a' => $a] + self::box($entry, $xMax);
            if (isset($entry['f'])) {
                $out['f'] = self::int($entry['f'], 1, 1, 'f');
            }
            if (isset($entry['r'])) {
                $out['r'] = round(self::num($entry['r'], -360, 360, 'r'), 1);
            }
            if (isset($entry['o'])) {
                $out['o'] = round(self::num($entry['o'], 0, 1, 'o'), 2);
            }
            if (isset($entry['t'])) {
                $out['t'] = self::hex($entry['t']);
            }
            if (isset($entry['c'])) {
                $c = self::list($entry['c'], 4, 'c');
                if (count($c) !== 4 || $files[$a]['w'] === 0) {
                    throw self::bad('A frame is [x, y, w, h] on a sheet whose size is known.');
                }
                $cx = self::int($c[0], 0, $files[$a]['w'] - 1, 'c');
                $cy = self::int($c[1], 0, $files[$a]['h'] - 1, 'c');
                $cw = self::int($c[2], 1, $files[$a]['w'] - $cx, 'c');
                $ch = self::int($c[3], 1, $files[$a]['h'] - $cy, 'c');
                $out['c'] = [$cx, $cy, $cw, $ch];
            }
        } elseif (array_key_exists('col', $entry)) {
            self::keys($entry, ['col', 'x', 'y', 'w', 'h'], ['col', 'x', 'y', 'w', 'h', 'o']);
            $out = ['col' => self::hex($entry['col'])] + self::box($entry, $xMax);
            if (isset($entry['o'])) {
                $out['o'] = round(self::num($entry['o'], 0, 1, 'o'), 2);
            }
        } elseif (array_key_exists('sky', $entry)) {
            self::keys($entry, ['sky', 'x', 'y', 'w', 'h'], ['sky', 'x', 'y', 'w', 'h']);
            $stops = self::list($entry['sky'], 6, 'sky');
            if ($stops === []) {
                throw self::bad('A sky has colours.');
            }
            $out = ['sky' => array_map(self::hex(...), $stops)] + self::box($entry, $xMax);
        } elseif (array_key_exists('txt', $entry)) {
            self::keys($entry, ['txt', 'x', 'y', 'w', 'h'], ['txt', 'x', 'y', 'w', 'h', 'r', 's']);
            if (!is_string($entry['txt']) || !isset(self::TEXTS[$entry['txt']])) {
                throw self::bad('That text is not one the page writes.');
            }
            $out = ['txt' => $entry['txt']] + self::box($entry, $xMax);
            if (isset($entry['r'])) {
                $out['r'] = round(self::num($entry['r'], -360, 360, 'r'), 1);
            }
        } elseif (array_key_exists('avatar', $entry)) {
            self::keys($entry, ['avatar', 'x', 'y', 'w', 'h'], ['avatar', 'x', 'y', 'w', 'h', 'f', 'o', 's']);
            if ($entry['avatar'] !== 1 || $avatar) {
                throw self::bad('A layout has one avatar.');
            }
            $avatar = true;
            $out = ['avatar' => 1] + self::box($entry, $xMax);
            if (isset($entry['f'])) {
                $out['f'] = self::int($entry['f'], 1, 1, 'f');
            }
            if (isset($entry['o'])) {
                $out['o'] = round(self::num($entry['o'], 0, 1, 'o'), 2);
            }
        } else {
            throw self::bad('That draw entry is not one this site knows.');
        }
        if (isset($entry['s'])) {
            $s = self::int($entry['s'], 0, $spotCount - 1, 's');
            if (isset($keep[$s])) {
                $out['s'] = $keep[$s];
            }
        }
        return $out;
    }

    /**
     * A spot checked, or null when it names a class the encyclopedia does
     * not know (its art stays, as scenery). Malformed spots throw.
     *
     * @param array<string, string> $names
     * @return array<string, mixed>|null
     */
    private static function spot(mixed $spot, array $names, int $xMax = self::REACH): ?array
    {
        if (!is_array($spot) || array_is_list($spot)) {
            throw self::bad('Each spot is an object.');
        }
        self::keys($spot, ['k', 'b'], ['k', 'b', 'i', 'n', 's', 'p', 'f', 'name']);
        if (!is_string($spot['k']) || !in_array($spot['k'], self::KINDS, true)) {
            throw self::bad('That kind of thing is not one this site knows.');
        }
        $b = self::list($spot['b'], 4, 'b');
        if (count($b) !== 4) {
            throw self::bad('A spot\'s box is [x, y, w, h].');
        }
        $out = ['k' => $spot['k'], 'b' => [
            self::int($b[0], -self::REACH, $xMax, 'b'),
            self::int($b[1], -self::REACH, self::REACH, 'b'),
            self::int($b[2], 1, self::REACH, 'b'),
            self::int($b[3], 1, self::REACH, 'b'),
        ]];
        if (isset($spot['i'])) {
            if (!is_string($spot['i']) || preg_match(self::CLASS_ID, $spot['i']) !== 1) {
                throw self::bad('A class id is lower case letters, digits and _.');
            }
            if (!isset($names[$spot['i']])) {
                return null;
            }
            $out['i'] = $spot['i'];
        }
        if (in_array($out['k'], ['cultivation', 'proto', 'item'], true) && !isset($out['i'])) {
            return null;
        }
        if (isset($spot['n'])) {
            $out['n'] = self::int($spot['n'], 0, 99, 'n');
        }
        if (isset($spot['s'])) {
            if (!is_string($spot['s']) || !isset(self::HOUSE_STYLES[$spot['s']])) {
                throw self::bad('That house style is not one this site knows.');
            }
            $out['s'] = $spot['s'];
        }
        if (isset($spot['p'])) {
            $out['p'] = self::int($spot['p'], 0, 100, 'p');
        }
        if (isset($spot['f'])) {
            $out['f'] = self::int($spot['f'], 1, 1, 'f');
        }
        if (isset($spot['name'])) {
            if ($out['k'] !== 'tower' || !is_string($spot['name']) || !mb_check_encoding($spot['name'], 'UTF-8')) {
                throw self::bad('Only a Tower carries a name.');
            }
            $name = trim((string) preg_replace('/[\p{C}]/u', '', $spot['name']));
            if (mb_strlen($name) > self::NAME_MAX) {
                throw self::bad('A Tower\'s name is at most ' . self::NAME_MAX . ' characters.');
            }
            if ($name !== '') {
                $out['name'] = WordFilter::text($name);
            }
        }
        return $out;
    }

    /**
     * An art path checked against the roots and the disk: { p, w, h, x2 } with
     * the file's own size (0 for an SVG) and its sharper twin, if one ships
     * beside it (the game's js/sprite-hires.js, icon-hires.js, deco-hires.js:
     * `<name>@3x.webp`, `<name>@3x.png`, `<name>@1.5x.webp`), as [path, density].
     *
     * @return array{p: string, w: int, h: int, x2: ?array{0: string, 1: string}}
     */
    private static function artFile(mixed $path, string $root): array
    {
        if (!is_string($path) || strlen($path) > 200 || preg_match(self::ART_PATH, $path) !== 1
            || str_contains($path, '..') || str_contains($path, '//') || preg_match('/@\d/', $path) === 1) {
            throw self::bad('Art is a path under assets/.');
        }
        $roots = array_filter(self::ART_ROOTS, static fn (string $r): bool => str_starts_with($path, $r));
        if ($roots === [] || preg_match('#(^|/)gz_#', $path) === 1 || str_starts_with($path, 'assets/sprites/made/')) {
            throw self::bad('Only Tiny Speck\'s art goes on a profile.');
        }
        $base = realpath($root . '/assets');
        $real = realpath($root . '/' . $path);
        if ($base === false || $real === false || !str_starts_with($real, $base . '/') || !is_file($real)) {
            throw self::bad('That art is not one of the game\'s files.');
        }
        $w = $h = 0;
        if (!str_ends_with($path, '.svg')) {
            $size = @getimagesize($real);
            if (!is_array($size) || $size[0] < 1 || $size[1] < 1) {
                throw self::bad('That art could not be read.');
            }
            [$w, $h] = [(int) $size[0], (int) $size[1]];
        }
        $twin = null;
        $stem = (string) preg_replace('/\.[a-z]+$/', '', $path);
        foreach ([['@3x.webp', '3x'], ['@3x.png', '3x'], ['@1.5x.webp', '1.5x']] as [$suffix, $density]) {
            $candidate = $stem . $suffix;
            $file = realpath($root . '/' . $candidate);
            if ($file !== false && str_starts_with($file, $base . '/') && is_file($file)) {
                $twin = [$candidate, $density];
                break;
            }
        }
        return ['p' => $path, 'w' => $w, 'h' => $h, 'x2' => $twin];
    }

    /**
     * Item names by class id, from the encyclopedia the game builds
     * (encyclopedia/items/data.json under the static root).
     *
     * @return array<string, string>
     */
    public static function itemNames(string $root): array
    {
        if (isset(self::$names[$root])) {
            return self::$names[$root];
        }
        $data = Json::file($root . '/encyclopedia/items/data.json', or: []);
        $out = [];
        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
            if (is_array($item) && is_string($item['id'] ?? null) && is_string($item['name'] ?? null) && $item['name'] !== '') {
                $out[$item['id']] = $item['name'];
            }
        }
        return self::$names[$root] = $out;
    }

    /**
     * What a spot is called on the page, from its kind and ids only.
     *
     * @param array<string, mixed> $spot
     * @param array<string, string> $names
     */
    public static function label(array $spot, array $names, string $owner): string
    {
        $item = isset($spot['i']) ? (string) ($names[$spot['i']] ?? '') : '';
        // Garden protos carry their size ("S. Herb Garden"): the page names the garden.
        $item = (string) preg_replace('/^[SML]\. /', '', $item);
        $n = (int) ($spot['n'] ?? 0);
        return match ($spot['k']) {
            'avatar' => $owner,
            'house' => 'House' . (isset($spot['s']) ? ' · ' . self::HOUSE_STYLES[$spot['s']] : ''),
            'door' => 'Door',
            'mailbox' => 'Mailbox',
            'signpost' => 'Signpost' . ($n > 0 ? ' · ' . $n . ($n === 1 ? ' friend' : ' friends') : ''),
            'dispatcher' => 'Mail Dispatcher',
            'tower' => (isset($spot['name']) ? $spot['name'] . ' · ' : '') . 'Tower' . ($n > 0 ? ' · ' . $n . ($n === 1 ? ' floor' : ' floors') : ''),
            'scaffolding' => 'Tower · ' . (($spot['p'] ?? 0) > 0 ? $spot['p'] . '% built' : 'being built'),
            'proto' => ($item !== '' ? $item : 'Cultivation') . ' · worn out',
            'note_pole' => 'Note Pole' . ($n > 0 ? ' · a note posted' : ''),
            default => $item,
        };
    }

    /**
     * The diorama: the stage scaled to the column with CSS (every box in % of
     * the stage), the art lazy with its sharper twin in srcset, each spot a
     * link to its encyclopedia page or a focusable span, named on hover and
     * focus, and a caption naming everything for anyone who cannot point.
     *
     * @param array<string, mixed> $layout as accept() returned it
     * @param array{owner: string, root: string, avatar: ?string} $ctx avatar: the owner's still's URL
     */
    public static function render(array $layout, array $ctx): string
    {
        if (($layout['v'] ?? null) === self::STREET_VERSION) {
            return self::renderStreet($layout, $ctx);
        }
        $W = (int) $layout['w'];
        $H = (int) $layout['h'];
        $spots = self::prepareSpots($layout, $ctx);
        $filters = [];
        $outer = '';
        foreach ($layout['draw'] as $z => $d) {
            if (self::ownerOnly($layout, $d)) {
                continue;
            }
            $spotIndex = isset($d['s'], $spots[$d['s']]) ? $d['s'] : null;
            // Inside a spot, boxes are in % of the spot's box; elsewhere of the stage.
            $html = self::drawHtml($d, $spotIndex === null ? [0, 0, $W, $H] : $spots[$spotIndex]['box'], $z + 1, $layout, $ctx, $filters, false);
            if ($html === null) {
                continue;
            }
            if ($spotIndex === null) {
                $outer .= "\n        " . $html;
            } else {
                $spots[$spotIndex]['inner'] .= $html;
            }
        }
        $spotHtml = self::spotsHtml($spots, $W, $H, 0, count($layout['draw']) + 10, $ctx);
        $owner = Html::esc($ctx['owner']);
        $things = self::things($spots);
        return '<figure class="profile-home home-diorama">'
            . "\n      " . '<div class="hd-stage" role="group" aria-label="' . Html::attr($ctx['owner'] . "'s home street") . '" style="aspect-ratio:' . $W . ' / ' . $H . '">'
            . self::filtersSvg($filters) . $outer . $spotHtml
            . "\n      </div>"
            . "\n      <figcaption class=\"hd-caption\">{$owner}'s home street, drawn from the game's own art. Point at anything to see what it is.{$things}</figcaption>"
            . "\n    </figure>";
    }

    /**
     * Version 2: the whole street, its layers each a stage-wide box moved by
     * the camera times its parallax factor (style.css .hd-pan .hd-layer, from
     * the stage's --cam), starting where the game's camera stood. Without
     * JavaScript that is the view; players/home-pan.js pans it (arrow keys,
     * Home and End, drag, the wheel) and loads art as it comes near. Art more
     * than half a view off the starting frame carries data-src, so the page
     * fetches only what is near the view.
     */
    private static function renderStreet(array $layout, array $ctx): string
    {
        $W = (int) $layout['w'];
        $H = (int) $layout['h'];
        $SW = (int) $layout['sw'];
        $CX = (int) $layout['cx'];
        $spots = self::prepareSpots($layout, $ctx);
        $filters = [];
        $outers = [];
        $home = [];
        $lastStreet = null;
        foreach ($layout['layers'] as $li => $layer) {
            $f = (float) $layer['f'];
            $street = $f === 1.0;
            if ($street) {
                $lastStreet = $li;
            }
            $outers[$li] = '';
            foreach ($layer['draw'] as $z => $d) {
                if (self::ownerOnly($layout, $d)) {
                    continue;
                }
                $spotIndex = $street && isset($d['s'], $spots[$d['s']]) ? $d['s'] : null;
                if ($spotIndex !== null && !isset($home[$spotIndex])) {
                    $home[$spotIndex] = $li;
                }
                // Near the starting frame (half a view either side), or loaded when the camera comes near.
                $left = $d['x'] - $CX * $f;
                $lazy = $left + $d['w'] < -$W / 2 || $left > $W * 1.5;
                $html = self::drawHtml($d, $spotIndex === null ? [0, 0, $W, $H] : $spots[$spotIndex]['box'], $z + 1, $layout, $ctx, $filters, $lazy);
                if ($html === null) {
                    continue;
                }
                if ($spotIndex === null) {
                    $outers[$li] .= "\n          " . $html;
                } else {
                    $spots[$spotIndex]['inner'] .= $html;
                }
            }
        }
        $body = '';
        foreach ($layout['layers'] as $li => $layer) {
            $mine = array_filter($spots, static fn (array $s, int $i): bool => ($home[$i] ?? $lastStreet) === $li, ARRAY_FILTER_USE_BOTH);
            $f = rtrim(rtrim(number_format((float) $layer['f'], 3, '.', ''), '0'), '.');
            $body .= "\n        " . '<div class="hd-layer" style="--f:' . ($f === '' ? '0' : $f) . ';z-index:' . ($li + 1) . '">'
                . $outers[$li]
                . self::spotsHtml($mine, $W, $H, $CX, count($layer['draw']) + 10, $ctx)
                . "\n        </div>";
        }
        $owner = Html::esc($ctx['owner']);
        $things = self::things($spots);
        return '<figure class="profile-home home-diorama hd-pan">'
            . "\n      " . '<div class="hd-stage" tabindex="0" role="group" aria-label="' . Html::attr($ctx['owner'] . "'s home street") . '" aria-describedby="hd-hint"'
            . ' data-w="' . $W . '" data-sw="' . $SW . '" data-cx="' . $CX . '"'
            . ' style="aspect-ratio:' . $W . ' / ' . $H . ';--w:' . $W . ';--cam:' . $CX . '">'
            . self::filtersSvg($filters) . $body
            . "\n      </div>"
            . "\n      <figcaption class=\"hd-caption\"><span class=\"hd-hint\" id=\"hd-hint\" hidden>Use the arrows, your arrow keys or drag to look around. </span>{$owner}'s home street, drawn from the game's own art. Point at anything to see what it is.{$things}</figcaption>"
            . "\n    </figure>";
    }

    /**
     * The spots the page names, by manifest index: label, the box to point at
     * (a flat thing grown to MIN_SPOT) and room for their art.
     *
     * @return array<int, array{spot: array, label: string, box: array{0: int, 1: int, 2: int, 3: int}, inner: string}>
     */
    private static function prepareSpots(array $layout, array $ctx): array
    {
        $names = self::itemNames($ctx['root']);
        $spots = [];
        foreach ($layout['spots'] as $i => $spot) {
            if (in_array($spot['k'], self::OWNER_ONLY, true)) {
                continue;
            }
            if ($spot['k'] === 'avatar' && $ctx['avatar'] === null) {
                continue;
            }
            $label = self::label($spot, $names, $ctx['owner']);
            if ($label === '') {
                continue;
            }
            [$x, $y, $w, $h] = $spot['b'];
            // A flat thing still gets a box to point at: grow it upwards and out from its middle.
            if ($h < self::MIN_SPOT) {
                $y -= self::MIN_SPOT - $h;
                $h = self::MIN_SPOT;
            }
            if ($w < self::MIN_SPOT) {
                $x -= intdiv(self::MIN_SPOT - $w, 2);
                $w = self::MIN_SPOT;
            }
            $spots[$i] = ['spot' => $spot, 'label' => $label, 'box' => [$x, $y, $w, $h], 'inner' => ''];
        }
        return $spots;
    }

    /** A draw entry of a thing only the street's owner sees in the game (OWNER_ONLY): left off the page. */
    private static function ownerOnly(array $layout, array $d): bool
    {
        $k = isset($d['s']) ? ($layout['spots'][$d['s']]['k'] ?? null) : null;
        return is_string($k) && in_array($k, self::OWNER_ONLY, true);
    }

    /** "On it: ..." for the caption: every named thing once, not the avatar. */
    private static function things(array $spots): string
    {
        $caption = [];
        foreach ($spots as $s) {
            if ($s['spot']['k'] !== 'avatar') {
                $caption[$s['label']] = true;
            }
        }
        return $caption === [] ? '' : ' On it: ' . Html::esc(implode(', ', array_keys($caption))) . '.';
    }

    /** One draw entry as HTML inside a box [x, y, w, h] (the stage, or its spot's box); null for none. */
    private static function drawHtml(array $d, array $box, int $z, array $layout, array $ctx, array &$filters, bool $lazy): ?string
    {
        $W = (int) $layout['w'];
        [$ox, $oy, $ow, $oh] = $box;
        $pct = self::pct(...);
        $place = 'left:' . $pct($d['x'] - $ox, $ow) . ';top:' . $pct($d['y'] - $oy, $oh) . ';width:' . $pct($d['w'], $ow) . ';height:' . $pct($d['h'], $oh) . ';z-index:' . $z;
        $transform = [];
        if (isset($d['r'])) {
            $transform[] = 'rotate(' . $d['r'] . 'deg)';
        }
        if (isset($d['f'])) {
            $transform[] = 'scaleX(-1)';
        }
        if ($transform !== []) {
            $place .= ';transform:' . implode(' ', $transform);
        }
        if (isset($d['o'])) {
            $place .= ';opacity:' . $d['o'];
        }
        // Lazy art waits in data-src for home-pan.js; the browser's own lazy loading does the rest.
        $source = static function (string $src, string $srcset) use ($lazy): string {
            $set = $srcset === '' ? '' : ($lazy ? ' data-srcset="' : ' srcset="') . Html::attr($srcset) . '"';
            return ($lazy ? ' data-src="' : ' src="') . Html::attr($src) . '"' . $set;
        };
        if (isset($d['sky'])) {
            return '<span class="hd-fill" style="' . Html::attr($place . ';background:linear-gradient(' . implode(',', $d['sky']) . ')') . '"></span>';
        }
        if (isset($d['col'])) {
            return '<span class="hd-fill" style="' . Html::attr($place . ';background:' . $d['col']) . '"></span>';
        }
        if (isset($d['txt'])) {
            // The words sit on the sign's art; their size follows the stage (cqw: the stage is the container).
            $size = round($d['h'] / $W * 100 * 0.92, 3);
            return '<span class="hd-sign" aria-hidden="true" style="' . Html::attr($place . ";font-size:{$size}cqw") . '">' . Html::esc(self::TEXTS[$d['txt']]) . '</span>';
        }
        if (isset($d['avatar'])) {
            if ($ctx['avatar'] === null) {
                return null;
            }
            return '<img class="hd-art"' . $source($ctx['avatar'], '') . ' alt="" loading="lazy" decoding="async" style="' . Html::attr($place) . '" />';
        }
        $file = self::shippedArt($ctx['root'], $layout['art'][$d['a']]);
        $src = '/' . $file['p'];
        $srcset = $file['x2'] === null ? '' : "{$src} 1x, /{$file['x2'][0]} {$file['x2'][1]}";
        $tint = '';
        if (isset($d['t'])) {
            $id = 'hd-tint-' . substr($d['t'], 1);
            $filters[$id] = $d['t'];
            $tint = ";--hd-tint:url(#{$id})";
        }
        if (isset($d['c'])) {
            [$cx, $cy, $cw, $ch] = $d['c'];
            $inner = 'left:' . $pct(-$cx, $cw) . ';top:' . $pct(-$cy, $ch) . ';width:' . $pct($file['w'], $cw) . ';height:' . $pct($file['h'], $ch);
            $crop = static fn (string $class, string $img): string => '<span class="hd-crop' . $class . '" style="' . Html::attr($place . $tint) . '">' . $img . '</span>';
            // A sheet whose 3x ships split into parts (js/sprite-hires.js SPLIT_SPRITES, as the mail
            // dispatcher's): the frame sits elsewhere on its part, on a grid of its own, so no srcset can
            // swap the file under one crop. Two crops in the same box: the 1x sheet's for screens under
            // 1.5 device px (the game's own threshold, sprite-hires installHiresSprites) and the part's
            // above (style.css .hd-lo / .hd-hi); lazy images that are display: none are never fetched.
            $hi = $srcset === '' ? self::hiresFrame($ctx['root'], $file['p'], $d['c']) : null;
            if ($hi !== null) {
                $hiInner = 'left:' . $pct(-$hi['x'], $cw) . ';top:' . $pct(-$hi['y'], $ch) . ';width:' . $pct($hi['pw'], $cw) . ';height:' . $pct($hi['ph'], $ch);
                return $crop(' hd-lo', '<img' . $source($src, '') . ' alt="" loading="lazy" decoding="async" style="' . Html::attr($inner) . '" />')
                    . $crop(' hd-hi', '<img' . $source('/' . $hi['src'], '') . ' alt="" loading="lazy" decoding="async" style="' . Html::attr($hiInner) . '" />');
            }
            return $crop('', '<img' . $source($src, $srcset) . ' alt="" loading="lazy" decoding="async" style="' . Html::attr($inner) . '" />');
        }
        return '<img class="hd-art"' . $source($src, $srcset) . ' alt="" loading="lazy" decoding="async" style="' . Html::attr($place . $tint) . '" />';
    }

    /**
     * A kept art entry as the game ships it now: a layout saved before the game's
     * pictures moved to lossless WebP (2026-11-07) names `.png`/`.jpg` files that
     * are gone, so each path whose file is missing takes its `.webp` twin when
     * that exists. Paths that still exist stay as they are.
     *
     * @param array{p: string, w: int, h: int, x2: ?array{0: string, 1: string}} $file
     * @return array{p: string, w: int, h: int, x2: ?array{0: string, 1: string}}
     */
    private static function shippedArt(string $root, array $file): array
    {
        $now = static function (string $path) use ($root): string {
            if (preg_match('/\.(png|jpe?g)$/', $path) !== 1 || is_file($root . '/' . $path)) {
                return $path;
            }
            $webp = (string) preg_replace('/\.(png|jpe?g)$/', '.webp', $path);
            return is_file($root . '/' . $webp) ? $webp : $path;
        };
        $file['p'] = $now($file['p']);
        if ($file['x2'] !== null) {
            $file['x2'][0] = $now($file['x2'][0]);
        }
        return $file;
    }

    /**
     * Sheets whose 3x render ships split into parts, read from the built
     * game's js/sprite-hires.js (SPLIT_SPRITES, generated by the game's
     * art builders): 1x sheet => { fw, fh, cols, parts: [[file, first cell,
     * cells, columns], ...] }. Only lines in the generated shape are read,
     * every path under assets/; anything else is left out.
     *
     * @return array<string, array{fw: int, fh: int, cols: int, parts: list<array{0: string, 1: int, 2: int, 3: int}>}>
     */
    public static function splitSheets(string $root): array
    {
        if (isset(self::$splits[$root])) {
            return self::$splits[$root];
        }
        $out = [];
        $src = @file_get_contents($root . '/js/sprite-hires.js');
        if (is_string($src) && preg_match('/^export const SPLIT_SPRITES = new Map\(\[\n(.*?)\n\]\);$/ms', $src, $m) === 1) {
            $path = '(assets\/[A-Za-z0-9_\-.\/@]+\.(?:png|webp))';
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^\s*\["' . $path . '", \{ fw: (\d+), fh: (\d+), cols: (\d+), parts: \[(.*)\] \}\],?$/', $line, $l) !== 1 || str_contains($l[1], '..')) {
                    continue;
                }
                preg_match_all('/\["' . $path . '", (\d+), (\d+), (\d+)\]/', $l[5], $pm, PREG_SET_ORDER);
                $parts = [];
                foreach ($pm as $p) {
                    if (!str_contains($p[1], '..') && (int) $p[4] > 0) {
                        $parts[] = [$p[1], (int) $p[2], (int) $p[3], (int) $p[4]];
                    }
                }
                if ($parts !== [] && (int) $l[2] > 0 && (int) $l[3] > 0 && (int) $l[4] > 0) {
                    $out[$l[1]] = ['fw' => (int) $l[2], 'fh' => (int) $l[3], 'cols' => (int) $l[4], 'parts' => $parts];
                }
            }
        }
        return self::$splits[$root] = $out;
    }

    /**
     * A frame [x, y, w, h] of a split sheet on its 3x part, as the game
     * moves it (js/sprite-hires.js pointAtPart): the part's file, the frame's
     * place on it and the part's size, all in 1x units; null when the sheet
     * is not split, the frame spans cells or falls outside every part, or the
     * part file is missing.
     *
     * @param array{0: int, 1: int, 2: int, 3: int} $c
     * @return array{src: string, x: int, y: int, pw: int, ph: int}|null
     */
    public static function hiresFrame(string $root, string $sheet, array $c): ?array
    {
        $split = self::splitSheets($root)[$sheet] ?? null;
        if ($split === null) {
            return null;
        }
        [$x, $y, $w, $h] = $c;
        ['fw' => $fw, 'fh' => $fh] = $split;
        $col = intdiv($x, $fw);
        $row = intdiv($y, $fh);
        $dx = $x - $col * $fw;
        $dy = $y - $row * $fh;
        if ($col >= $split['cols'] || $dx + $w > $fw || $dy + $h > $fh) {
            return null;
        }
        $cell = $row * $split['cols'] + $col;
        foreach ($split['parts'] as [$file, $from, $count, $cols]) {
            if ($cell < $from || $cell >= $from + $count) {
                continue;
            }
            $size = self::partSize($root, $file);
            if ($size === null || $size[0] < $cols * $fw) {
                return null;
            }
            $j = $cell - $from;
            return ['src' => $file, 'x' => ($j % $cols) * $fw + $dx, 'y' => intdiv($j, $cols) * $fh + $dy, 'pw' => $size[0], 'ph' => $size[1]];
        }
        return null;
    }

    /** A 3x part's size in 1x units (its pixels over 3), or null when it is not one of the game's files. */
    private static function partSize(string $root, string $file): ?array
    {
        if (array_key_exists($file, self::$partSizes)) {
            return self::$partSizes[$file];
        }
        $base = realpath($root . '/assets');
        $real = realpath($root . '/' . $file);
        $size = $base !== false && $real !== false && str_starts_with($real, $base . '/') ? @getimagesize($real) : false;
        return self::$partSizes[$file] = is_array($size) && $size[0] >= 3 && $size[1] >= 3 ? [intdiv((int) $size[0], 3), intdiv((int) $size[1], 3)] : null;
    }

    /**
     * The spots as elements, left to right so the keyboard walks the street as
     * it reads: a link to the encyclopedia page, else a focusable span. $cam is
     * where the view starts on the street, for which way a name opens.
     */
    private static function spotsHtml(array $spots, int $W, int $H, int $cam, int $order, array $ctx): string
    {
        $pct = self::pct(...);
        uasort($spots, static fn (array $a, array $b): int => $a['box'][0] <=> $b['box'][0]);
        $out = '';
        foreach ($spots as $s) {
            [$x, $y, $w, $h] = $s['box'];
            $style = 'left:' . $pct($x, $W) . ';top:' . $pct($y, $H) . ';width:' . $pct($w, $W) . ';height:' . $pct($h, $H);
            // A spot with no art of its own (a door painted on the house) sits over everything.
            if ($s['inner'] === '') {
                $style .= ';z-index:' . $order++;
            }
            $sx = $x - $cam;
            $tip = $y < $H * 0.16 ? ' hd-tip-below' : '';
            $mid = ($sx + $w / 2) / $W;
            $tip .= $mid < 0.12 ? ' hd-tip-start' : ($mid > 0.88 ? ' hd-tip-end' : '');
            // A spot reaching past the stage's edge keeps its name on the stage (one off it has no name showing).
            if ($sx + $w <= 0 || $sx >= $W) {
                $tip = $y < $H * 0.16 ? ' hd-tip-below' : '';
            } elseif ($sx < 0) {
                $style .= ';--hd-tip-in:' . $pct(-$sx, $w);
            } elseif ($sx + $w > $W) {
                $style .= ';--hd-tip-in:' . $pct($sx + $w - $W, $w);
            }
            $attrs = ' class="hd-spot' . $tip . '" style="' . Html::attr($style) . '" data-kind="' . Html::attr($s['spot']['k']) . '" data-label="' . Html::attr($s['label']) . '" data-b="' . implode(',', $s['box']) . '" aria-label="' . Html::attr($s['label']) . '"';
            $item = $s['spot']['i'] ?? null;
            $page = $item !== null && is_file($ctx['root'] . '/encyclopedia/items/' . $item . '/index.html') ? '/encyclopedia/items/' . $item . '/' : null;
            $out .= "\n        " . ($page !== null
                ? '<a href="' . Html::attr($page) . '"' . $attrs . '>' . $s['inner'] . '</a>'
                : '<span tabindex="0" role="img"' . $attrs . '>' . $s['inner'] . '</span>');
        }
        return $out;
    }

    /** The tints' SVG filters (multiply by the colour), once each. */
    private static function filtersSvg(array $filters): string
    {
        if ($filters === []) {
            return '';
        }
        $svg = "\n        " . '<svg class="hd-filters" width="0" height="0" aria-hidden="true" focusable="false">';
        foreach ($filters as $id => $hex) {
            [$r, $g, $b] = array_map(static fn (string $c): string => (string) round(hexdec($c) / 255, 4), str_split(substr($hex, 1), 2));
            $svg .= '<filter id="' . $id . '" color-interpolation-filters="sRGB"><feColorMatrix type="matrix" values="' . "{$r} 0 0 0 0 0 {$g} 0 0 0 0 0 {$b} 0 0 0 0 0 1 0" . '"/></filter>';
        }
        return $svg . '</svg>';
    }

    /** v as a % of `of`, three decimals, no trailing zeros. */
    private static function pct(float $v, int $of): string
    {
        return rtrim(rtrim(number_format($v / $of * 100, 3, '.', ''), '0'), '.') . '%';
    }

    /** @param array<string, mixed> $value */
    private static function keys(array $value, array $required, array $allowed): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                throw self::bad("A layout entry needs {$key}.");
            }
        }
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw self::bad('A layout entry has a field this site does not know.');
            }
        }
    }

    /** @return list<mixed> */
    private static function list(mixed $value, int $max, string $what): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw self::bad("{$what} is a list.");
        }
        if (count($value) > $max) {
            throw self::bad("A layout has at most {$max} {$what}.");
        }
        return $value;
    }

    /** @return array{x: int, y: int, w: int, h: int} */
    private static function box(array $entry, int $xMax = self::REACH): array
    {
        return [
            'x' => self::int($entry['x'], -self::REACH, $xMax, 'x'),
            'y' => self::int($entry['y'], -self::REACH, self::REACH, 'y'),
            // A street-wide band (the field, the soil) is as wide as the street.
            'w' => self::int($entry['w'], 1, $xMax, 'w'),
            'h' => self::int($entry['h'], 1, self::REACH, 'h'),
        ];
    }

    private static function num(mixed $value, float $min, float $max, string $what): float
    {
        if (!(is_int($value) || is_float($value)) || !is_finite((float) $value) || $value < $min || $value > $max) {
            throw self::bad("{$what} is a number from {$min} to {$max}.");
        }
        return (float) $value;
    }

    private static function int(mixed $value, int $min, int $max, string $what): int
    {
        return (int) round(self::num($value, $min, $max, $what));
    }

    private static function hex(mixed $value): string
    {
        if (!is_string($value) || preg_match(self::HEX, $value) !== 1) {
            throw self::bad('Colours are #rrggbb.');
        }
        return $value;
    }

    private static function bad(string $why): RestError
    {
        return new RestError('groddle_home_layout', $why, 400);
    }
}
