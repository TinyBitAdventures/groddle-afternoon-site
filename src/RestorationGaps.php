<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;

/**
 * The Known gaps page's data block (blocks/restoration-gaps, /restoration/gaps/),
 * drawn in full from public/restoration/gaps.json, which groddle-afternoon
 * tools/gaps/build-data.mjs writes from its curated public list and the
 * coverage register's states: nothing here is typed in. The page reads
 * without JavaScript; restoration/gaps.js (this theme), loaded once when the
 * block is on the page, only adds a row of chips to show one state at a time.
 *
 * Parts (the block's "part"), so the page's own words can sit between them:
 * summary (the counts), list (the gaps by area, each with its state), purpose
 * (differences from Glitch we chose), ideas (what Tiny Speck never built), or
 * all of them with headings of their own.
 */
final class RestorationGaps
{
    public const SCRIPT = '/wp-content/themes/groddle/restoration/gaps.js';

    /** The states listed under the areas, in the order the counts show them. */
    private const LISTED = ['fixing', 'known', 'unreleased', 'fixed', 'lost'];

    private static bool $used = false;

    public static function register(Seams $minn, StaticRoot $root): void
    {
        $file = $root->dir . '/restoration/gaps.json';
        $minn->block('blocks/restoration-gaps', static function (array $a) use ($file): string {
            self::$used = true;
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            return is_array($decoded)
                ? self::draw($decoded, (string) ($a['part'] ?? 'all'))
                : '<p class="report-pending">The known gaps appear once the game is built (public/restoration/gaps.json).</p>';
        });
        $minn->footer(static fn (): string => self::$used ? '<script src="' . Extension::keyed(self::SCRIPT) . '" defer></script>' . "\n" : '');
    }

    /** One part of the page, or all of it. */
    public static function draw(array $d, string $part = 'all'): string
    {
        return match ($part) {
            'summary' => self::summary($d),
            'list' => self::board($d),
            'purpose' => self::plain($d, 'purpose'),
            'ideas' => self::plain($d, 'ideas'),
            default => self::summary($d) . self::board($d)
                . '<h2 class="gaps-h">' . self::esc(self::label($d, 'purpose')) . '</h2>' . self::plain($d, 'purpose')
                . '<h2 class="gaps-h">' . self::esc(self::label($d, 'idea')) . '</h2>' . self::plain($d, 'ideas'),
        };
    }

    private static function esc(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function label(array $d, string $state): string
    {
        return (string) ($d['states'][$state]['label'] ?? $state);
    }

    /** A state's tone as a restoration.css token (letters only, so nothing else reaches the style attribute). */
    private static function tone(array $d, string $state): string
    {
        return 'var(--' . preg_replace('/[^a-z]/', '', (string) ($d['states'][$state]['tone'] ?? 'stone')) . ')';
    }

    /** How many are listed, by state, and the two lists beside them. */
    private static function summary(array $d): string
    {
        $c = $d['counts'] ?? [];
        $total = 0;
        $chips = '';
        foreach (self::LISTED as $state) {
            $n = (int) ($c[$state] ?? 0);
            $total += $n;
            if ($n > 0) {
                $chips .= '<li class="gaps-count" style="--tone:' . self::tone($d, $state) . '"><b>' . $n . '</b> ' . self::esc(self::label($d, $state)) . '</li>';
            }
        }
        $release = (string) ($d['release'] ?? '');
        $also = sprintf(
            'Below them: %d %s on purpose, and %d %s Glitch never built.',
            (int) ($c['purpose'] ?? 0),
            (int) ($c['purpose'] ?? 0) === 1 ? 'difference' : 'differences',
            (int) ($c['idea'] ?? 0),
            (int) ($c['idea'] ?? 0) === 1 ? 'thing' : 'things',
        );
        return '<div class="gaps-summary">'
            . '<p class="gaps-total"><b>' . $total . '</b> ' . ($total === 1 ? 'gap' : 'gaps') . ' listed' . ($release === '' ? '' : ', as of ' . self::esc($release)) . '</p>'
            . '<ul class="gaps-counts">' . $chips . '</ul>'
            . '<p class="gaps-also">' . self::esc($also) . '</p></div>';
    }

    /** The gaps by area, each with its state; the filter chips come with the script. */
    private static function board(array $d): string
    {
        $areas = '';
        foreach ($d['areas'] ?? [] as $i => $area) {
            $id = 'gaps-area-' . ($i + 1);
            $items = '';
            foreach ($area['items'] ?? [] as $it) {
                $state = (string) ($it['state'] ?? 'known');
                $label = $state === 'fixed' && !empty($it['version']) ? 'Fixed in ' . $it['version'] : self::label($d, $state);
                $report = isset($it['report']) ? '<span class="gaps-report">Report #' . (int) $it['report'] . '</span>' : '';
                $items .= '<li class="gaps-item" data-state="' . self::esc($state) . '" style="--tone:' . self::tone($d, $state) . '">'
                    . '<span class="gaps-state">' . self::esc($label) . '</span>' . $report
                    . '<p class="gaps-text">' . self::esc($it['text'] ?? '') . '</p></li>';
            }
            $areas .= '<section class="gaps-area" aria-labelledby="' . $id . '"><h3 id="' . $id . '">' . self::esc($area['name'] ?? '') . '</h3>'
                . '<ul class="gaps-list">' . $items . '</ul></section>';
        }
        return '<div class="gaps-board" data-gaps-board>'
            . '<div class="filters gaps-filters" role="toolbar" aria-label="Show gaps by state" hidden></div>'
            . '<div class="gaps-areas">' . $areas . '</div></div>';
    }

    /** The differences on purpose, or the things never built, each under its area's name. */
    private static function plain(array $d, string $key): string
    {
        $out = '';
        foreach ($d[$key] ?? [] as $it) {
            $out .= '<li><b>' . self::esc($it['area'] ?? '') . '</b> ' . self::esc($it['text'] ?? '') . '</li>';
        }
        $state = $key === 'ideas' ? 'idea' : 'purpose';
        return '<ul class="gaps-plain gaps-plain-' . $state . '" style="--tone:' . self::tone($d, $state) . '">' . $out . '</ul>';
    }
}
