<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Html;

/**
 * The Code page's "Lines by area" quilt (blocks/codebase, src/Codebase.php):
 * a squarified treemap of one repo's areas, each a stitched square sized by its
 * lines, laid out here in PHP so the page reads without scripts. Every square
 * carries a number that matches the key below it, and the key carries the exact
 * lines, files and path of every area, so nothing depends on colour or on a
 * square being big enough to hold its words.
 */
final class CodebaseQuilt
{
    /** The quilt's shape (3:2), in the units the layout works in; drawn in percentages of it. */
    private const W = 300.0;
    private const H = 200.0;

    /** A square under this share of the quilt is drawn at this share, so it stays visible; its key row keeps the exact number. */
    private const FLOOR = 0.015;

    /**
     * Each area's fabric, by area id, so an area keeps its fabric when the sizes
     * change order, and tests are the same fabric in every repo. Tones come from
     * the page's tokens (restoration.css), which have light and dark values.
     */
    private const FABRIC = [
        'core' => 'moss', 'tests' => 'sky', 'features' => 'sun', 'tools' => 'sand', 'rooms' => 'plum', 'pages' => 'dusk',
        'modules' => 'sun', 'deploy' => 'stone',
        'ledger' => 'moss', 'services' => 'sun', 'templates' => 'sand', 'styles' => 'plum',
    ];

    /** @param list<array{id:string,label:string,path:string,files:int,lines:int}> $areas
     * @param callable(mixed):string $n a number with thousands separators
     * @param callable(int,string,string):string $plural a count with its noun
     * @param callable(float):string $pct a share as a percentage
     */
    public static function draw(array $areas, callable $n, callable $plural, callable $pct): string
    {
        $areas = array_values(array_filter($areas, static fn (array $a): bool => (int) ($a['lines'] ?? 0) > 0));
        if ($areas === []) {
            return '';
        }
        usort($areas, static fn (array $a, array $b): int => (int) $b['lines'] <=> (int) $a['lines']);
        $total = array_sum(array_map(static fn (array $a): int => (int) $a['lines'], $areas));
        $floored = false;
        $weights = [];
        foreach ($areas as $a) {
            $share = (int) $a['lines'] / $total;
            if ($share < self::FLOOR) {
                $floored = true;
            }
            $weights[] = max($share, self::FLOOR);
        }
        $rects = self::squarify($weights, 0.0, 0.0, self::W, self::H);

        $squares = '';
        $key = '';
        $describe = [];
        foreach ($areas as $i => $a) {
            [$x, $y, $w, $h] = $rects[$i];
            $tag = (string) ($i + 1);
            $lines = (int) $a['lines'];
            $files = (int) $a['files'];
            $fabric = self::FABRIC[(string) $a['id']] ?? 'stone';
            $share = $pct($lines / $total);
            $title = $a['label'] . ': ' . $n($lines) . ' lines (' . $share . '), ' . $plural($files, 'file', 'files');
            // How much fits: a big square holds its number, name and lines; a middling one its number and lines; a small one its number.
            $fit = ($w / self::W >= 0.3 && $h / self::H >= 0.3) ? 'big' : (($w / self::W >= 0.15 && $h / self::H >= 0.17) ? 'mid' : 'small');
            $style = 'left: ' . self::p($x / self::W) . '; top: ' . self::p($y / self::H) . '; width: ' . self::p($w / self::W) . '; height: ' . self::p($h / self::H);
            $squares .= '<li class="cb-q-sq cb-q-' . $fit . ' cb-fabric-' . Html::attr($fabric) . '" style="' . Html::attr($style) . '" title="' . Html::attr($title) . '">'
                . '<span class="cb-q-tag">' . Html::esc($tag) . '</span>'
                . '<span class="cb-q-name">' . Html::esc((string) $a['label']) . '</span>'
                . '<span class="cb-q-num">' . Html::esc($n($lines)) . '</span></li>';
            $key .= '<li><div class="cb-bar-head"><span class="cb-q-key"><span class="cb-q-tag cb-fabric-' . Html::attr($fabric) . '">' . Html::esc($tag) . '</span>'
                . '<span class="cb-bar-label">' . Html::esc((string) $a['label']) . '</span></span>'
                . '<span class="cb-bar-value">' . Html::esc($n($lines)) . '</span></div>'
                . '<span class="cb-bar-note">' . Html::esc($share . ' · ' . $plural($files, 'file', 'files') . ' · ' . $a['path']) . '</span></li>';
            $describe[] = $a['label'] . ' ' . $n($lines) . ' lines';
        }
        return '<figure class="cb-fig cb-quilt-fig"><h3 class="cb-fig-h">Lines by area</h3>'
            . '<div class="cb-quilt-frame"><ol class="cb-quilt" role="img" aria-label="' . Html::attr('A quilt of the repo\'s areas, each square sized by its lines of code: ' . implode(', ', $describe)) . '">' . $squares . '</ol></div>'
            . '<ol class="cb-bars cb-q-keys">' . $key . '</ol>'
            . ($floored ? '<p class="cb-q-note">' . Html::esc('Areas under ' . $pct(self::FLOOR) . ' of the lines are drawn at ' . $pct(self::FLOOR) . ' so their squares stay visible.') . '</p>' : '')
            . '</figure>';
    }

    /**
     * Squarified treemap (Bruls, Huizing and van Wijk): lay the weights, largest
     * first, in rows along the shorter side of what is left, adding to a row while
     * that makes its squares less long and thin.
     *
     * @param list<float> $weights
     * @return list<array{0:float,1:float,2:float,3:float}> x, y, width, height for each weight, in order
     */
    private static function squarify(array $weights, float $x, float $y, float $w, float $h): array
    {
        $sum = array_sum($weights);
        $areas = array_map(static fn (float $v): float => $v / $sum * $w * $h, $weights);
        $out = [];
        $i = 0;
        $count = count($areas);
        while ($i < $count) {
            $side = min($w, $h);
            $row = [$areas[$i]];
            $j = $i + 1;
            while ($j < $count && self::worst([...$row, $areas[$j]], $side) <= self::worst($row, $side)) {
                $row[] = $areas[$j];
                $j++;
            }
            $rowSum = array_sum($row);
            if ($w >= $h) {
                // A column down the left of what is left.
                $colW = $rowSum / $h;
                $cy = $y;
                foreach ($row as $k => $a) {
                    $ah = ($k === count($row) - 1) ? $y + $h - $cy : $a / $colW;
                    $out[] = [$x, $cy, $colW, $ah];
                    $cy += $ah;
                }
                $x += $colW;
                $w -= $colW;
            } else {
                // A row across the top of what is left.
                $rowH = $rowSum / $w;
                $cx = $x;
                foreach ($row as $k => $a) {
                    $aw = ($k === count($row) - 1) ? $x + $w - $cx : $a / $rowH;
                    $out[] = [$cx, $y, $aw, $rowH];
                    $cx += $aw;
                }
                $y += $rowH;
                $h -= $rowH;
            }
            $i = $j;
        }
        return $out;
    }

    /** @param list<float> $row */
    private static function worst(array $row, float $side): float
    {
        $s = array_sum($row);
        $s2 = $s * $s;
        $side2 = $side * $side;
        return max($side2 * max($row) / $s2, $s2 / ($side2 * min($row)));
    }

    private static function p(float $fraction): string
    {
        return rtrim(rtrim(number_format(100 * $fraction, 3, '.', ''), '0'), '.') . '%';
    }
}
