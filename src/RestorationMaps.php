<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;

/**
 * The Maps page's data block (blocks/restoration-maps, /restoration/maps/;
 * Austin 2026-11-14), drawn in full from public/restoration/maps.json, which
 * groddle-afternoon tools/restoration-maps/build-data.mjs writes from the
 * provenance records: nothing here is typed in, and a region restored later
 * shows up with no page edit.
 *
 * Each map is a card: the picture before beside the picture the game has now
 * (the World map's charts: Tiny Speck's original and the 2x copy; a region:
 * Tiny Speck's own 2012 Encyclopedia map of it, with the game's roads and
 * names on it, and the painting in the game now; Austin 2026-11-14: "use the
 * originals we have from screenshots, not our before AI generations"; a
 * region with no 2012 map: its thumbnail), then its name, state, words,
 * facts, its World map card and its Encyclopedia page. The page reads without
 * JavaScript; restoration/maps.js (this theme), loaded once when the block is
 * on the page, stacks each pair in one box to wipe or flip between them and
 * opens a larger preview with zoom (the compare page's tools).
 *
 * Each pair opens in the mode maps.json's "view" names (Wipe; Austin
 * 2026-11-14: "I want the 'wipe' option to be the default"): drawn here as
 * the pair's data-mode, which the script starts from.
 *
 * Parts (the block's "part"), so the page's own words can sit between them:
 * summary (the counts), world, restored, painted (the regions painted from a
 * thumbnail or a street layout), or all of them.
 *
 * Plain PHP with no engine classes in draw(), so tests run it with php -r.
 */
final class RestorationMaps
{
    public const SCRIPT = '/wp-content/themes/groddle/restoration/maps.js';

    /** The compare modes the script knows, the first the default. */
    public const MODES = ['wipe', 'flip', 'side'];

    private static bool $used = false;

    public static function register(Seams $minn, StaticRoot $root): void
    {
        $file = $root->dir . '/restoration/maps.json';
        $minn->block('blocks/restoration-maps', static function (array $a) use ($file, $root): string {
            self::$used = true;
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            return is_array($decoded)
                ? DataKeys::html(self::draw($decoded, (string) ($a['part'] ?? 'all')), DataKeys::map($root->dir))
                : '<p class="report-pending">The maps appear once the game is built (public/restoration/maps.json).</p>';
        });
        $minn->footer(static fn (): string => self::$used ? '<script src="' . Extension::keyed(self::SCRIPT) . '" defer></script>' . "\n" : '');
    }

    /** Whether this request drew a part of the page (its section menu, ReportMenu, goes on it as on the report). */
    public static function drawn(): bool
    {
        return self::$used;
    }

    /** One part of the page, or all of it. */
    public static function draw(array $d, string $part = 'all'): string
    {
        return match ($part) {
            'summary' => self::summary($d),
            'world' => self::world($d),
            'restored' => self::regions($d, ['restored']),
            'painted' => self::regions($d, ['painted', 'layout']),
            default => self::summary($d) . self::world($d) . self::regions($d, ['restored']) . self::regions($d, ['painted', 'layout']),
        };
    }

    private static function esc(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** An address on this site for a picture (the game's art or the kept replaced paintings), or null. */
    private static function src(mixed $src): ?string
    {
        $src = (string) $src;
        return preg_match('#^/(?:assets|restoration/maps)/[A-Za-z0-9_@./-]+\.(?:webp|png|jpe?g|svg)$#', $src) === 1 && !str_contains($src, '..') ? $src : null;
    }

    /** A page on this site (an Encyclopedia place), or null. */
    private static function href(mixed $href): ?string
    {
        $href = (string) $href;
        return preg_match('#^/[a-z0-9/-]+/$#', $href) === 1 && !str_contains($href, '//') ? $href : null;
    }

    private static function anchor(mixed $s, string $fallback): string
    {
        return preg_replace('/[^a-z0-9-]/', '', (string) $s) ?: $fallback;
    }

    /** A state's tone as a restoration.css token (letters only, so nothing else reaches the style attribute). */
    private static function tone(array $d, string $state): string
    {
        return 'var(--' . preg_replace('/[^a-z]/', '', (string) ($d['states'][$state]['tone'] ?? 'stone')) . ')';
    }

    private static function label(array $d, string $state): string
    {
        return (string) ($d['states'][$state]['label'] ?? $state);
    }

    /** How many maps there are, by state. */
    private static function summary(array $d): string
    {
        $c = $d['counts'] ?? [];
        $chips = '';
        foreach (['restored', 'painted', 'layout'] as $state) {
            $n = (int) ($c[$state] ?? 0);
            if ($n > 0) {
                $chips .= '<li class="gaps-count" style="--tone:' . self::tone($d, $state) . '"><b>' . $n . '</b> ' . self::esc(self::label($d, $state)) . '</li>';
            }
        }
        $charts = count($d['world']['charts'] ?? []);
        $total = (int) ($c['regions'] ?? 0);
        return '<div class="gaps-summary maps-summary">'
            . '<p class="gaps-total"><b>' . $total . '</b> region ' . ($total === 1 ? 'map' : 'maps') . ($charts > 0 ? ' and the World map' : '') . '</p>'
            . '<ul class="gaps-counts">' . $chips . '</ul></div>';
    }

    /** The mode each pair opens in: maps.json's "view" when it is one the script knows, else Wipe. */
    private static function view(array $d): string
    {
        $view = (string) ($d['view'] ?? '');
        return in_array($view, self::MODES, true) ? $view : self::MODES[0];
    }

    /** The World map's charts, each Tiny Speck's original beside its 2x copy. */
    private static function world(array $d): string
    {
        $w = $d['world'] ?? null;
        if (!is_array($w)) {
            return '';
        }
        $out = '';
        foreach ($w['charts'] ?? [] as $chart) {
            if (!is_array($chart)) {
                continue;
            }
            $facts = [['label' => 'Made', 'text' => trim(($w['made'] ?? '') . (empty($w['model']) ? '' : ', with ' . $w['model']), ', ')]];
            $out .= self::card($chart, 'world', '', $facts, null, null, self::view($d));
        }
        return $out === '' ? '' : '<div class="maps-list maps-list-wide" data-maps-list>' . $out . '</div>';
    }

    /** The regions in these states, in maps.json's order. */
    private static function regions(array $d, array $states): string
    {
        $out = '';
        foreach ($d['regions'] ?? [] as $r) {
            if (!is_array($r) || !in_array($r['state'] ?? '', $states, true)) {
                continue;
            }
            $state = (string) $r['state'];
            $chip = '<p class="maps-state gaps-state" style="--tone:' . self::tone($d, $state) . '">' . self::esc(self::label($d, $state)) . '</p>';
            $out .= self::card($r, $state, $chip, is_array($r['facts'] ?? null) ? $r['facts'] : [], is_array($r['card'] ?? null) ? $r['card'] : null, self::href($r['place'] ?? ''), self::view($d));
        }
        if ($out === '') {
            return '';
        }
        $wide = in_array('restored', $states, true);
        return '<div class="maps-list ' . ($wide ? 'maps-list-wide' : 'maps-list-grid') . '" data-maps-list>' . $out . '</div>';
    }

    /**
     * One map: the pair (before, then the picture in the game now), then the words: the name, its
     * chip, caption and differences, and beside them (under them on a phone) the facts, the World
     * map's card and the Encyclopedia link. Austin 2026-11-15: in the restored list the pair spans
     * the card's whole width and the words sit under it (style.css .maps-list-wide).
     *
     * @param list<array{label?: string, text?: string}> $facts
     */
    private static function card(array $m, string $state, string $chip, array $facts, ?array $hover, ?string $place, string $view): string
    {
        $anchor = self::anchor($m['anchor'] ?? '', 'map');
        $name = (string) ($m['name'] ?? '');
        // A before much smaller than the picture now (a 150 x 91 thumbnail) is shown at its own size side by side.
        $small = is_array($m['before'] ?? null) && is_array($m['after'] ?? null) && 2 * (int) ($m['before']['w'] ?? 0) <= (int) ($m['after']['w'] ?? 0);
        $before = is_array($m['before'] ?? null) ? self::figure($m['before'], 'before', $small) : '';
        $after = is_array($m['after'] ?? null) ? self::figure($m['after'], 'after', false) : '';
        $list = '';
        foreach ($facts as $f) {
            if (is_array($f) && ($f['text'] ?? '') !== '') {
                $list .= '<div><dt>' . self::esc($f['label'] ?? '') . '</dt><dd>' . self::esc($f['text']) . '</dd></div>';
            }
        }
        $card = '';
        if ($hover !== null && ($src = self::src($hover['src'] ?? '')) !== null) {
            $card = '<figure class="maps-hover"><img src="' . self::esc($src) . '" alt="' . self::esc('The World map’s card for ' . $name) . '" width="' . (int) ($hover['w'] ?? 0) . '" height="' . (int) ($hover['h'] ?? 0) . '" loading="lazy" decoding="async" />'
                . '<figcaption>' . self::esc($hover['label'] ?? '') . '</figcaption></figure>';
        }
        $link = $place === null ? '' : '<p class="maps-place"><a href="' . self::esc($place) . '">' . self::esc($name) . ' in the Encyclopedia</a></p>';
        return '<article class="maps-card maps-' . self::anchor($state, 'world') . '" id="' . $anchor . '" aria-labelledby="' . $anchor . '-h" data-maps-card>'
            . '<div class="maps-pair' . ($before === '' ? ' maps-pair-one' : '') . '"' . ($before === '' ? '' : ' data-maps-pair data-mode="' . self::esc($view) . '"') . '>' . $before . $after . '</div>'
            . '<div class="maps-words">'
            . '<div class="maps-words-main">'
            . '<h3 id="' . $anchor . '-h">' . self::esc($name) . '</h3>'
            . $chip
            . (($m['caption'] ?? '') === '' ? '' : '<p class="maps-caption">' . self::esc($m['caption']) . '</p>')
            . (($m['differences'] ?? '') === '' ? '' : '<p class="maps-diff">' . self::esc($m['differences']) . '</p>')
            . '</div>'
            . ($list . $card . $link === '' ? '' : '<div class="maps-words-side">'
                . ($list === '' ? '' : '<dl class="maps-facts">' . $list . '</dl>')
                . ($card . $link === '' ? '' : '<div class="maps-more">' . $card . $link . '</div>')
                . '</div>')
            . '</div></article>';
    }

    /** One picture of a pair, at the shape of the frame it fills ($small: a thumbnail shown at its own size). */
    private static function figure(array $img, string $role, bool $small): string
    {
        $src = self::src($img['src'] ?? '');
        $w = (int) ($img['w'] ?? 0);
        $h = (int) ($img['h'] ?? 0);
        if ($src === null || $w < 1 || $h < 1) {
            return '';
        }
        $src2 = self::src($img['src2'] ?? '');
        $label = (string) ($img['label'] ?? '');
        $alt = (string) (($img['alt'] ?? '') !== '' ? $img['alt'] : $label);
        return '<figure class="maps-fig maps-fig-' . $role . ($small ? ' maps-fig-small' : '') . '" data-pic="' . $role . '" style="--w:' . $w . ';--h:' . $h . '">'
            . '<span class="maps-frame"><img src="' . self::esc($src) . '"'
            . ($src2 === null ? '' : ' srcset="' . self::esc($src) . ' 1x, ' . self::esc($src2) . ' 2x"')
            . ' alt="' . self::esc($alt) . '" width="' . $w . '" height="' . $h . '" loading="lazy" decoding="async" /></span>'
            . '<figcaption>' . self::esc($label) . '</figcaption></figure>';
    }
}
