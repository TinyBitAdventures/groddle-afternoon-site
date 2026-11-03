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

    /** The kinds of spot (the game's HOME_LAYOUT_KINDS). */
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
        $json = json_encode($value);
        if (!is_string($json) || strlen($json) > self::MAX_BYTES) {
            throw new RestError('groddle_home_layout_too_large', 'That home street is larger than a profile keeps.', 413, ['max' => self::MAX_BYTES]);
        }
        self::keys($value, ['v', 'w', 'h', 'art', 'draw', 'spots'], ['v', 'w', 'h', 'art', 'draw', 'spots']);
        if ($value['v'] !== self::VERSION) {
            throw self::bad('That layout version is not one this site knows.');
        }
        $w = self::int($value['w'], self::MIN_W, self::MAX_W, 'w');
        $h = self::int($value['h'], self::MIN_H, self::MAX_H, 'h');

        // Art: each a file under the static root, its size read from the file.
        $art = self::list($value['art'], self::MAX_ART, 'art');
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
        $spotsIn = self::list($value['spots'], self::MAX_SPOTS, 'spots');
        $names = self::itemNames($root);
        $spots = [];
        $keep = [];
        foreach ($spotsIn as $n => $spot) {
            $clean = self::spot($spot, $names);
            if ($clean !== null) {
                $keep[$n] = count($spots);
                $spots[] = $clean;
            }
        }

        $draw = [];
        $avatar = false;
        foreach (self::list($value['draw'], self::MAX_DRAW, 'draw') as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw self::bad('Each draw entry is an object.');
            }
            if (array_key_exists('a', $entry)) {
                self::keys($entry, ['a', 'x', 'y', 'w', 'h'], ['a', 'x', 'y', 'w', 'h', 'f', 'r', 'o', 't', 'c', 's']);
                $a = self::int($entry['a'], 0, count($files) - 1, 'a');
                $out = ['a' => $a] + self::box($entry);
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
                $out = ['col' => self::hex($entry['col'])] + self::box($entry);
                if (isset($entry['o'])) {
                    $out['o'] = round(self::num($entry['o'], 0, 1, 'o'), 2);
                }
            } elseif (array_key_exists('sky', $entry)) {
                self::keys($entry, ['sky', 'x', 'y', 'w', 'h'], ['sky', 'x', 'y', 'w', 'h']);
                $stops = self::list($entry['sky'], 6, 'sky');
                if ($stops === []) {
                    throw self::bad('A sky has colours.');
                }
                $out = ['sky' => array_map(self::hex(...), $stops)] + self::box($entry);
            } elseif (array_key_exists('txt', $entry)) {
                self::keys($entry, ['txt', 'x', 'y', 'w', 'h'], ['txt', 'x', 'y', 'w', 'h', 'r', 's']);
                if (!is_string($entry['txt']) || !isset(self::TEXTS[$entry['txt']])) {
                    throw self::bad('That text is not one the page writes.');
                }
                $out = ['txt' => $entry['txt']] + self::box($entry);
                if (isset($entry['r'])) {
                    $out['r'] = round(self::num($entry['r'], -360, 360, 'r'), 1);
                }
            } elseif (array_key_exists('avatar', $entry)) {
                self::keys($entry, ['avatar', 'x', 'y', 'w', 'h'], ['avatar', 'x', 'y', 'w', 'h', 'f', 'o', 's']);
                if ($entry['avatar'] !== 1 || $avatar) {
                    throw self::bad('A layout has one avatar.');
                }
                $avatar = true;
                $out = ['avatar' => 1] + self::box($entry);
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
                $s = self::int($entry['s'], 0, count($spotsIn) - 1, 's');
                if (isset($keep[$s])) {
                    $out['s'] = $keep[$s];
                }
            }
            $draw[] = $out;
        }

        return ['v' => self::VERSION, 'w' => $w, 'h' => $h, 'art' => $files, 'draw' => $draw, 'spots' => $spots];
    }

    /**
     * A spot checked, or null when it names a class the encyclopedia does
     * not know (its art stays, as scenery). Malformed spots throw.
     *
     * @param array<string, string> $names
     * @return array<string, mixed>|null
     */
    private static function spot(mixed $spot, array $names): ?array
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
            self::int($b[0], -self::REACH, self::REACH, 'b'),
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
        $W = (int) $layout['w'];
        $H = (int) $layout['h'];
        $names = self::itemNames($ctx['root']);
        $pct = static fn (float $v, int $of): string => rtrim(rtrim(number_format($v / $of * 100, 3, '.', ''), '0'), '.') . '%';
        $spots = [];
        foreach ($layout['spots'] as $i => $spot) {
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
        $filters = [];
        $outer = '';
        foreach ($layout['draw'] as $z => $d) {
            $spotIndex = isset($d['s'], $spots[$d['s']]) ? $d['s'] : null;
            // Inside a spot, boxes are in % of the spot's box; elsewhere of the stage.
            [$ox, $oy, $ow, $oh] = $spotIndex === null ? [0, 0, $W, $H] : $spots[$spotIndex]['box'];
            $place = 'left:' . $pct($d['x'] - $ox, $ow) . ';top:' . $pct($d['y'] - $oy, $oh) . ';width:' . $pct($d['w'], $ow) . ';height:' . $pct($d['h'], $oh) . ';z-index:' . ($z + 1);
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
            if (isset($d['sky'])) {
                $html = '<span class="hd-fill" style="' . Html::attr($place . ';background:linear-gradient(' . implode(',', $d['sky']) . ')') . '"></span>';
            } elseif (isset($d['col'])) {
                $html = '<span class="hd-fill" style="' . Html::attr($place . ';background:' . $d['col']) . '"></span>';
            } elseif (isset($d['txt'])) {
                // The words sit on the sign's art; their size follows the stage (cqw: the stage is the container).
                $size = round($d['h'] / $W * 100 * 0.92, 3);
                $html = '<span class="hd-sign" aria-hidden="true" style="' . Html::attr($place . ";font-size:{$size}cqw") . '">' . Html::esc(self::TEXTS[$d['txt']]) . '</span>';
            } elseif (isset($d['avatar'])) {
                if ($ctx['avatar'] === null) {
                    continue;
                }
                $html = '<img class="hd-art" src="' . Html::attr($ctx['avatar']) . '" alt="" loading="lazy" decoding="async" style="' . Html::attr($place) . '" />';
            } else {
                $file = $layout['art'][$d['a']];
                $src = '/' . $file['p'];
                $srcset = $file['x2'] === null ? '' : ' srcset="' . Html::attr("{$src} 1x, /{$file['x2'][0]} {$file['x2'][1]}") . '"';
                $tint = '';
                if (isset($d['t'])) {
                    $id = 'hd-tint-' . substr($d['t'], 1);
                    $filters[$id] = $d['t'];
                    $tint = ";--hd-tint:url(#{$id})";
                }
                if (isset($d['c'])) {
                    [$cx, $cy, $cw, $ch] = $d['c'];
                    $inner = 'left:' . $pct(-$cx, $cw) . ';top:' . $pct(-$cy, $ch) . ';width:' . $pct($file['w'], $cw) . ';height:' . $pct($file['h'], $ch);
                    $html = '<span class="hd-crop" style="' . Html::attr($place . $tint) . '"><img src="' . Html::attr($src) . '"' . $srcset . ' alt="" loading="lazy" decoding="async" style="' . Html::attr($inner) . '" /></span>';
                } else {
                    $html = '<img class="hd-art" src="' . Html::attr($src) . '"' . $srcset . ' alt="" loading="lazy" decoding="async" style="' . Html::attr($place . $tint) . '" />';
                }
            }
            if ($spotIndex === null) {
                $outer .= "\n        " . $html;
            } else {
                $spots[$spotIndex]['inner'] .= $html;
            }
        }
        // Spots left to right, so the keyboard walks the street as it reads.
        uasort($spots, static fn (array $a, array $b): int => $a['box'][0] <=> $b['box'][0]);
        $spotHtml = '';
        $caption = [];
        $order = count($layout['draw']) + 10;
        foreach ($spots as $s) {
            [$x, $y, $w, $h] = $s['box'];
            $style = 'left:' . $pct($x, $W) . ';top:' . $pct($y, $H) . ';width:' . $pct($w, $W) . ';height:' . $pct($h, $H);
            // A spot with no art of its own (a door painted on the house) sits over everything.
            if ($s['inner'] === '') {
                $style .= ';z-index:' . $order++;
            }
            $tip = $y < $H * 0.16 ? ' hd-tip-below' : '';
            $mid = ($x + $w / 2) / $W;
            $tip .= $mid < 0.12 ? ' hd-tip-start' : ($mid > 0.88 ? ' hd-tip-end' : '');
            // A spot reaching past the stage's edge keeps its name on the stage.
            if ($x < 0) {
                $style .= ';--hd-tip-in:' . $pct(-$x, $w);
            } elseif ($x + $w > $W) {
                $style .= ';--hd-tip-in:' . $pct($x + $w - $W, $w);
            }
            $attrs = ' class="hd-spot' . $tip . '" style="' . Html::attr($style) . '" data-kind="' . Html::attr($s['spot']['k']) . '" data-label="' . Html::attr($s['label']) . '" aria-label="' . Html::attr($s['label']) . '"';
            $item = $s['spot']['i'] ?? null;
            $page = $item !== null && is_file($ctx['root'] . '/encyclopedia/items/' . $item . '/index.html') ? '/encyclopedia/items/' . $item . '/' : null;
            $spotHtml .= "\n        " . ($page !== null
                ? '<a href="' . Html::attr($page) . '"' . $attrs . '>' . $s['inner'] . '</a>'
                : '<span tabindex="0" role="img"' . $attrs . '>' . $s['inner'] . '</span>');
            if ($s['spot']['k'] !== 'avatar') {
                $caption[$s['label']] = true;
            }
        }
        $svg = '';
        if ($filters !== []) {
            $svg = "\n        " . '<svg class="hd-filters" width="0" height="0" aria-hidden="true" focusable="false">';
            foreach ($filters as $id => $hex) {
                [$r, $g, $b] = array_map(static fn (string $c): string => (string) round(hexdec($c) / 255, 4), str_split(substr($hex, 1), 2));
                $svg .= '<filter id="' . $id . '" color-interpolation-filters="sRGB"><feColorMatrix type="matrix" values="' . "{$r} 0 0 0 0 0 {$g} 0 0 0 0 0 {$b} 0 0 0 0 0 1 0" . '"/></filter>';
            }
            $svg .= '</svg>';
        }
        $owner = Html::esc($ctx['owner']);
        $things = $caption === [] ? '' : ' On it: ' . Html::esc(implode(', ', array_keys($caption))) . '.';
        return '<figure class="profile-home home-diorama">'
            . "\n      " . '<div class="hd-stage" role="group" aria-label="' . Html::attr($ctx['owner'] . "'s home street") . '" style="aspect-ratio:' . $W . ' / ' . $H . '">'
            . $svg . $outer . $spotHtml
            . "\n      </div>"
            . "\n      <figcaption class=\"hd-caption\">{$owner}'s home street, drawn from the game's own art. Point at anything to see what it is.{$things}</figcaption>"
            . "\n    </figure>";
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
    private static function box(array $entry): array
    {
        return [
            'x' => self::int($entry['x'], -self::REACH, self::REACH, 'x'),
            'y' => self::int($entry['y'], -self::REACH, self::REACH, 'y'),
            'w' => self::int($entry['w'], 1, self::REACH, 'w'),
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
