<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;
use Minn\Html;

/**
 * The restoration report's data sections (blocks/restoration-*), for the
 * /restoration/ page in Minn. Each draws the markup the built page's
 * restoration.js fills, from public/restoration/data.json, which
 * build-restoration.mjs computes from the repo: nothing in them is typed in.
 * What can be drawn simply is drawn here too (the score with how many systems
 * work between real players in multiplayer, recent changes, the lost list, the
 * tag legend, the stamp, the seasons), so the editor's previews and a reader
 * without scripts see it; the rest shows a one-line summary until the script
 * runs. The script itself loads once, after the page, when a section is on it.
 */
final class Restoration
{
    private const ORDER = ['source', 'rebuilt', 'reimagined', 'solo', 'progress', 'missing', 'lost'];

    private static bool $used = false;
    private static ?array $data = null;

    public static function register(Seams $minn, StaticRoot $root): void
    {
        $file = $root->dir . '/restoration/data.json';
        $data = static function () use ($file): array {
            if (self::$data === null) {
                $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
                self::$data = is_array($decoded) ? $decoded : [];
            }
            return self::$data;
        };
        $section = static function (string $name, \Closure $draw) use ($minn, $data): void {
            $minn->block("blocks/restoration-{$name}", static function (array $a) use ($draw, $data): string {
                self::$used = true;
                $d = $data();
                return $d === [] ? '<p class="report-pending">The restoration figures appear once the game is built (public/restoration/data.json).</p>' : $draw($a, $d);
            });
        };
        $section('score', static fn (array $a, array $d): string => self::score($d));
        $section('street', static fn (array $a, array $d): string => self::street((string) $a['prompt']));
        $section('systems', static fn (array $a, array $d): string => self::systems($d));
        $section('numbers', static fn (array $a, array $d): string => self::numbers((string) $a['questsHeading'], $d));
        $section('rooms', static fn (array $a, array $d): string => self::rooms($d));
        $section('designers', static fn (array $a, array $d): string => '<div id="designer-groups">' . self::pending(sprintf('%d levels from %d designers, with their recordings.', count(array_filter($d['designers']['levels'] ?? [], static fn ($l) => is_array($l))), count($d['designers']['designers'] ?? []))) . '</div>');
        $section('levels', static fn (array $a, array $d): string => '<div class="levels" id="text-levels">' . self::pending(sprintf('%d levels rebuilt from words alone.', count($d['designers']['text'] ?? []))) . '</div>');
        $section('recent', static fn (array $a, array $d): string => self::recent($d));
        $section('lost', static fn (array $a, array $d): string => self::lost($d));
        // The holidays, with which is on today (Seasons works that out per request, in the site's time zone).
        $section('seasons', static fn (array $a, array $d): string => is_array($d['seasons'] ?? null) ? Seasons::draw($d['seasons'], Seasons::zone((string) ($minn->site->option('timezone_string') ?? ''), $minn->site->option('gmt_offset') ?? 0)) : self::pending('The seasons appear once the restoration data is rebuilt.'));
        $section('legend', static fn (array $a, array $d): string => self::legend($d));
        $section('stamp', static fn (array $a, array $d): string => '<p id="foot-meta">' . Html::esc(self::stamp($d)) . '</p>');

        $script = $root->dir . '/restoration/restoration.js';
        $minn->footer(static fn (): string => self::$used ? '<script src="/restoration/restoration.js?v=' . (is_file($script) ? filemtime($script) : 0) . '" type="module"></script>' . "\n" : '');
    }

    private static function pending(string $line): string
    {
        return '<p class="report-pending">' . Html::esc($line) . '</p>';
    }

    private static function tone(array $d, string $status): string
    {
        return 'var(--' . preg_replace('/[^a-z]/', '', (string) ($d['status'][$status]['tone'] ?? 'stone')) . ')';
    }

    private static function fmt(mixed $n): string
    {
        return number_format((float) $n);
    }

    private static function score(array $d): string
    {
        $total = max(1, count($d['items'] ?? []));
        $bar = '';
        $legend = '';
        foreach (self::ORDER as $status) {
            $n = (int) ($d['tally'][$status] ?? 0);
            if ($n === 0) {
                continue;
            }
            $label = (string) ($d['status'][$status]['label'] ?? $status);
            $bar .= '<span style="width:' . round($n / $total * 100, 4) . '%; background:' . self::tone($d, $status) . '" title="' . Html::attr("{$label}: {$n}") . '"></span>';
            $legend .= '<li><span class="dot" style="background:' . self::tone($d, $status) . '"></span><b>' . $n . '</b>' . Html::esc($label) . '</li>';
        }
        return '<div class="scoreboard" id="scoreboard" aria-live="polite">'
            . '<div class="score-main"><span class="score-num" data-bind="back">' . self::fmt($d['back'] ?? 0) . '</span>'
            . '<span class="score-of">of <span data-bind="possible">' . self::fmt($d['possible'] ?? 0) . '</span> systems are playable again</span></div>'
            . '<div class="score-bar" id="score-bar" role="img" aria-label="Systems by restoration status">' . $bar . '</div>'
            . '<ul class="score-legend" id="score-legend">' . $legend . '</ul>'
            . '<p class="score-mp" id="score-mp">' . self::multiplayer($d) . '</p></div>';
    }

    /** How many systems work between real players (each item's multiplayer line, Track M). */
    private static function multiplayer(array $d): string
    {
        $n = (int) ($d['multiplayer'] ?? 0);
        return $n > 0 ? '<b>' . $n . '</b> of them work between real players in multiplayer.' : '';
    }

    /** The diorama: the game's own sprites, laid out as the built page lays them out. */
    private static function street(string $prompt): string
    {
        $prompt = Inline::html($prompt);
        return <<<HTML
        <div class="street-layout">
          <div class="diorama-frame" id="diorama-frame">
            <div class="diorama" id="diorama" aria-label="Street diorama">
              <div class="d-sky"></div>
              <img class="d-forest" src="/assets/decos/groddle/wallpaper_tree_short_1.webp" alt="" style="left:-120px" />
              <img class="d-forest" src="/assets/decos/groddle/wallpaper_tree_short_1.webp" alt="" style="left:560px; transform:scaleX(-1)" />
              <img class="d-pine" src="/assets/decos/groddle/tree_coniferous_fg_1.webp" alt="" style="left:196px" />
              <img class="d-pine" src="/assets/decos/groddle/tree_coniferous_fg_2.webp" alt="" style="left:446px; width:170px" />
              <img class="d-pine" src="/assets/decos/groddle/tree_coniferous_fg_1.webp" alt="" style="left:744px; width:140px" />
              <img class="d-pine" src="/assets/decos/groddle/tree_coniferous_fg_2.webp" alt="" style="left:1060px; width:160px" />
              <img class="d-deco" src="/assets/decos/groddle/bush_3.webp" alt="" style="left:340px; bottom:92px; width:250px" />
              <img class="d-deco" src="/assets/decos/groddle/bush_3.webp" alt="" style="left:690px; bottom:92px; width:230px; transform:scaleX(-1)" />
              <div class="d-ground"></div>
              <img class="d-deco" src="/assets/decos/groddle/groddle_bush1.webp" alt="" style="left:-20px; bottom:88px; width:170px" />
              <img class="d-deco" src="/assets/decos/groddle/groddle_bush1.webp" alt="" style="left:1080px; bottom:88px; width:150px; transform:scaleX(-1)" />
              <img class="d-deco d-front" src="/assets/decos/groddle/groddle_grass_2.webp" alt="" style="left:410px; bottom:70px; width:70px" />
              <img class="d-deco d-front" src="/assets/decos/groddle/groddle_grass_2.webp" alt="" style="left:960px; bottom:70px; width:64px" />
              <button class="spot" data-spot="clock" style="left:470px; top:18px" aria-label="Dametime and the day cycle">
                <span class="d-nameplate">Hechey Track · Groddle Forest · <b id="d-clock">5:33 PM</b></span>
              </button>
              <button class="spot" data-spot="signpost" style="left:76px; bottom:98px" aria-label="Original streets">
                <span class="d-sign"><span class="d-sign-arm">Grand Mile</span><span class="d-sign-arm d-sign-arm-r">Evanfell Craine</span><span class="d-sign-post"></span></span>
              </button>
              <button class="spot" data-spot="vendor" style="left:186px; bottom:98px" aria-label="Street spirit vendors">
                <img src="/assets/decos/groddle/vendor.webp" alt="" style="width:104px" />
              </button>
              <button class="spot" data-spot="tree" style="left:322px; bottom:96px" aria-label="Trants and Wood Trees">
                <img src="/assets/sprites/trants/frames/trant_fruit_m10.png" alt="" style="width:214px" />
              </button>
              <button class="spot" data-spot="butterfly" style="left:420px; bottom:258px" aria-label="Butterflies">
                <span class="sheet sheet-butterfly"></span>
              </button>
              <button class="spot" data-spot="quoin" style="left:560px; bottom:214px" aria-label="Quoins">
                <span class="sheet sheet-quoin"></span>
              </button>
              <button class="spot" data-spot="quoin" style="left:612px; bottom:240px" aria-label="Quoins" tabindex="-1">
                <span class="sheet sheet-quoin sheet-quoin-qurazy"></span>
              </button>
              <button class="spot spot-walker" data-spot="player" style="left:486px; bottom:96px" aria-label="Your Glitch and the physics of Ur">
                <span class="sheet sheet-glitch"></span>
              </button>
              <button class="spot" data-spot="piggy" style="left:582px; bottom:94px" aria-label="Piggies">
                <span class="piggy-walk"><span class="sheet sheet-piggy"></span></span>
              </button>
              <button class="spot" data-spot="garden" style="left:770px; bottom:88px" aria-label="Gardens and herbs">
                <span class="d-garden"><img src="/assets/sprites/garden/garden_new__x1_1_png_1354833270.png" alt="" /><img src="/assets/sprites/garden/garden_new__x1_1_png_1354833270.png" alt="" /></span>
              </button>
              <button class="spot" data-spot="rock" style="left:978px; bottom:96px" aria-label="Mining and metal">
                <span class="sheet sheet-rock"></span>
              </button>
              <button class="spot" data-spot="chicken" style="left:878px; bottom:94px" aria-label="Chickens">
                <span class="sheet sheet-chicken"></span>
              </button>
              <button class="spot" data-spot="shrine" style="left:1078px; bottom:96px" aria-label="Giants and shrines">
                <span class="sheet sheet-shrine"></span>
              </button>
              <button class="spot" data-spot="mailbox" style="left:1166px; bottom:96px" aria-label="Your house and yard">
                <img src="/assets/sprites/street-furniture/mailbox-left-idle.png" alt="" style="width:62px" />
              </button>
              <button class="spot" data-spot="rock-magic" style="left:84px; bottom:300px" aria-label="Story and skill quests">
                <img class="d-float" src="/assets/sprites/icons/npc_cubimal_magicrock@3x.png" alt="" style="width:64px" />
              </button>
              <p class="d-hint" aria-hidden="true">Click anything</p>
            </div>
          </div>
          <aside class="spot-card" id="spot-card" aria-live="polite">
            <p class="spot-card-empty">{$prompt}</p>
          </aside>
        </div>
        <template id="detail-tpl">
          <div class="detail">
            <p class="detail-kicker"></p>
            <h3 class="detail-title"></h3>
            <span class="pill"></span>
            <a class="play-link detail-play" target="_blank" rel="noopener" hidden>Play it</a>
            <p class="detail-blurb"></p>
            <img class="detail-shot" alt="" loading="lazy" />
            <details class="detail-evidence"><summary>Where it lives in the code</summary><ul></ul></details>
          </div>
        </template>
        HTML;
    }

    /** The groups with how much of each is back; the items and filters come with the script. */
    private static function systems(array $d): string
    {
        $groups = '';
        foreach ($d['groups'] ?? [] as $group) {
            $items = array_values(array_filter($d['items'] ?? [], static fn ($it) => ($it['group'] ?? '') === ($group['id'] ?? null)));
            $back = count(array_filter($items, static fn ($it) => in_array($it['status'] ?? '', ['source', 'rebuilt', 'reimagined', 'solo'], true)));
            $mp = count(array_filter($items, static fn ($it) => is_string($it['multiplayer'] ?? null) && $it['multiplayer'] !== ''));
            $meter = '';
            foreach (self::ORDER as $status) {
                $n = count(array_filter($items, static fn ($it) => ($it['status'] ?? '') === $status));
                if ($n > 0) {
                    $meter .= '<span style="width:' . round($n / count($items) * 100, 4) . '%; background:' . self::tone($d, $status) . '"></span>';
                }
            }
            $groups .= '<section class="group" aria-label="' . Html::attr((string) ($group['name'] ?? '')) . '"><h3>' . Html::esc((string) ($group['name'] ?? '')) . '</h3>'
                . '<p class="group-blurb">' . Html::esc(($group['blurb'] ?? '') . " {$back} of " . count($items) . ' back' . ($mp > 0 ? ", {$mp} in multiplayer" : '') . '.') . '</p>'
                . '<div class="group-meter" aria-hidden="true">' . $meter . '</div></section>';
        }
        return '<div class="filters" id="filters" role="toolbar" aria-label="Filter by status"></div><div class="groups" id="groups">' . $groups . '</div>';
    }

    private static function numbers(string $heading, array $d): string
    {
        $q = $d['quests'] ?? [];
        $heading = str_replace('{total}', self::fmt($q['total'] ?? 0), $heading);
        $tiles = self::pending(sprintf('%s of %s quests playable, %s of %s quest rooms rebuilt, %s original streets, and more, counted from the repository.', self::fmt($q['playable'] ?? 0), self::fmt($q['story'] ?? 0), self::fmt($d['rooms']['built'] ?? 0), self::fmt($d['rooms']['total'] ?? 0), self::fmt($d['streets']['imported'] ?? 0)));
        return '<div class="tiles" id="tiles">' . $tiles . '</div>'
            . '<div class="quest-split"><h3>' . Inline::html($heading) . '</h3><div class="split-bar" id="quest-bar" role="img"></div><ul class="split-legend" id="quest-legend"></ul></div>';
    }

    /** The room cards and the dialog each opens; the cards come with the script. */
    private static function rooms(array $d): string
    {
        $grid = self::pending(sprintf('%d of %d quest rooms rebuilt, each with its story.', (int) ($d['rooms']['built'] ?? 0), (int) ($d['rooms']['total'] ?? 0)));
        return '<div class="rooms" id="room-grid">' . $grid . '</div>' . <<<'HTML'
        <dialog class="room-modal" id="room-modal" aria-labelledby="room-modal-title">
          <div class="rm-media">
            <img class="rm-shot" alt="" />
            <button class="rm-close" type="button" aria-label="Close">×</button>
          </div>
          <div class="rm-body">
            <div class="rm-head">
              <div>
                <p class="rm-kicker"></p>
                <h3 class="rm-title" id="room-modal-title"></h3>
                <p class="rm-tagline"></p>
              </div>
              <div class="rm-actions"><span class="pill rm-pill"></span><a class="play-link rm-playlink" target="_blank" rel="noopener" hidden>Play this room</a></div>
            </div>
            <blockquote class="rm-offer"><p></p><cite>The quest's opening line in Glitch</cite></blockquote>
            <div class="rm-grid">
              <section><h4>In Glitch</h4><p class="rm-about"></p></section>
              <section><h4>How it plays here</h4><p class="rm-play"></p></section>
              <section><h4>Straight from the original</h4><p class="rm-source"></p></section>
              <section><h4>Rebuilt</h4><p class="rm-rebuilt"></p></section>
            </div>
            <div class="rm-footage"><h4>Rebuilt with help from</h4><ul></ul></div>
            <nav class="rm-nav" aria-label="Other quest rooms">
              <button type="button" class="rm-prev">← <span></span></button>
              <button type="button" class="rm-next"><span></span> →</button>
            </nav>
          </div>
        </dialog>
        HTML;
    }

    private static function recent(array $d): string
    {
        $kinds = ['new' => 'New', 'improve' => 'Better', 'fix' => 'Fixed'];
        $out = '';
        foreach ($d['git']['recent'] ?? [] as $r) {
            $kind = (string) ($r['kind'] ?? '');
            $out .= '<li><time datetime="' . Html::attr((string) ($r['date'] ?? '')) . '">' . Html::esc((string) ($r['date'] ?? '')) . '</time>'
                . '<span class="kind kind-' . Html::attr($kind) . '">' . Html::esc($kinds[$kind] ?? '') . '</span><span>' . Html::esc((string) ($r['text'] ?? '')) . '</span></li>';
        }
        return '<ol class="recent" id="recent-list">' . $out . '</ol>';
    }

    private static function lost(array $d): string
    {
        $out = '';
        foreach ($d['items'] ?? [] as $it) {
            if (($it['status'] ?? '') === 'lost') {
                $out .= '<li><b>' . Html::esc((string) ($it['name'] ?? '')) . '</b>: ' . Html::esc((string) ($it['blurb'] ?? '')) . '</li>';
            }
        }
        return '<ul class="honest-list" id="lost-list">' . $out . '</ul>';
    }

    private static function legend(array $d): string
    {
        $out = '';
        foreach (self::ORDER as $status) {
            $st = $d['status'][$status] ?? null;
            if (is_array($st)) {
                $out .= '<div><dt><span class="pill" style="--tone:' . self::tone($d, $status) . '">' . Html::esc((string) ($st['label'] ?? '')) . '</span></dt><dd>' . Html::esc((string) ($st['desc'] ?? '')) . '</dd></div>';
            }
        }
        return '<dl class="legend-list" id="legend-list">' . $out . '</dl>';
    }

    private static function stamp(array $d): string
    {
        return sprintf('Generated from the repository on %s: %s commits since %s, %s test files.', $d['generatedAt'] ?? 'build', self::fmt($d['git']['commits'] ?? 0), $d['git']['since'] ?? '', self::fmt($d['tests'] ?? 0));
    }
}
