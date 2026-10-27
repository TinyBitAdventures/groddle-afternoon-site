<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;
use Minn\Html;

/**
 * The Code page's figures (blocks/codebase), drawn in full from
 * public/codebase/data.json, which groddle-afternoon tools/codebase/build-data.mjs
 * measures with git at the tags the live site runs: for one repo its lines and
 * files by language and by area, tests, commits, largest files and what is not
 * counted as code; the three repos side by side; or the Minn Engine and Minn
 * Admin commits the live site is pinned to. Every chart carries its exact
 * numbers in text beside it, so the page reads the same without scripts.
 */
final class Codebase
{
    /** Languages with less than this share of a repo's lines join "Other" in its bar (the legend still names them). */
    private const FOLD_BELOW = 0.01;

    /** The fixed order of languages in a bar, so each keeps its place and colour on every chart. */
    private const ORDER = ['javascript', 'go', 'php', 'html', 'css', 'python', 'shell'];

    private static ?array $data = null;

    public static function register(Seams $minn, StaticRoot $root): void
    {
        $file = $root->dir . '/codebase/data.json';
        $minn->block('blocks/codebase', static function (array $a) use ($file): string {
            if (self::$data === null) {
                $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
                self::$data = is_array($decoded) ? $decoded : [];
            }
            $d = self::$data;
            if ($d === []) {
                return '<p class="report-pending">The code figures appear once the game is built (public/codebase/data.json).</p>';
            }
            $which = (string) ($a['repo'] ?? 'overview');
            if ($which === 'overview') {
                return self::overview($d);
            }
            if ($which === 'platform') {
                return self::platform($d);
            }
            foreach ($d['repos'] ?? [] as $repo) {
                if (($repo['id'] ?? '') === $which) {
                    return self::repo($repo);
                }
            }
            return '<p class="report-pending">' . Html::esc("There is no repo called {$which} in the code figures.") . '</p>';
        });
    }

    private static function n(mixed $value): string
    {
        return number_format((float) $value);
    }

    private static function pct(float $share): string
    {
        $p = $share * 100;
        // One decimal near the ends, so a small share is never 0% and a large one never a rounded 100%.
        return (($p >= 10 && $p < 99.5) || $p === 0.0 || $p === 100.0 ? number_format($p) : ($p < 0.1 ? '<0.1' : ($p > 99.9 ? '>99.9' : number_format($p, 1)))) . '%';
    }

    private static function size(int $bytes): string
    {
        if ($bytes >= 1_000_000_000) {
            return number_format($bytes / 1_000_000_000, 2) . ' GB';
        }
        if ($bytes >= 1_000_000) {
            return number_format($bytes / 1_000_000, 1) . ' MB';
        }
        return number_format(max(1, (int) round($bytes / 1000))) . ' KB';
    }

    private static function day(?string $date): string
    {
        $at = $date ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        return $at ? $at->format('j M Y') : '';
    }

    private static function plural(int $n, string $one, string $many): string
    {
        return self::n($n) . ' ' . ($n === 1 ? $one : $many);
    }

    /** The three repos side by side: lines of code as bars on one scale, the other counts beside them. */
    private static function overview(array $d): string
    {
        $repos = $d['repos'] ?? [];
        $max = max(1, ...array_map(static fn (array $r): int => (int) ($r['code']['lines'] ?? 0), $repos));
        $total = array_sum(array_map(static fn (array $r): int => (int) ($r['code']['lines'] ?? 0), $repos));
        $rows = '';
        foreach ($repos as $r) {
            $lines = (int) ($r['code']['lines'] ?? 0);
            $detail = implode(' · ', [
                self::plural((int) $r['code']['files'], 'file', 'files'),
                self::plural((int) $r['tests']['cases'], 'test', 'tests'),
                self::plural((int) $r['history']['commits'], 'commit', 'commits'),
            ]);
            $rows .= '<li><div class="cb-bar-head"><a class="cb-bar-label" href="#' . Html::attr((string) $r['id']) . '">' . Html::esc((string) $r['name']) . '</a>'
                . '<span class="cb-bar-value">' . Html::esc(self::n($lines) . ' lines') . '</span></div>'
                . '<span class="cb-track"><span class="cb-fill" style="width: ' . Html::attr(self::width($lines, $max)) . '" title="' . Html::attr(self::n($lines) . ' lines of code') . '"></span></span>'
                . '<span class="cb-bar-note">' . Html::esc($detail) . '</span></li>';
        }
        return '<div class="cb-overview"><p class="cb-total"><b>' . Html::esc(self::n($total)) . '</b> lines of code across the three repos, measured at ' . Html::esc(self::measured($d)) . '.</p>'
            . '<ol class="cb-bars">' . $rows . '</ol></div>';
    }

    private static function measured(array $d): string
    {
        $tags = array_map(static fn (array $r): string => (string) $r['ref'], $d['repos'] ?? []);
        $same = count(array_unique($tags)) === 1;
        $what = $same ? $tags[0] : implode(', ', $tags);
        return str_starts_with((string) ($d['measured'] ?? ''), 'the tags') ? "the live release ({$what}" . ($same ? ' in each' : '') . ')' : "each repo's {$what}";
    }

    private static function width(int $value, int $max): string
    {
        return rtrim(rtrim(number_format(max(0.4, 100 * $value / $max), 2, '.', ''), '0'), '.') . '%';
    }

    /** One repo: where it is, its headline counts, then the charts with their numbers. */
    private static function repo(array $r): string
    {
        $url = (string) $r['url'];
        $meta = '<p class="cb-meta"><a href="' . Html::attr($url) . '">' . Html::esc(preg_replace('#^https://#', '', $url)) . '</a>'
            . ' · ' . Html::esc((string) $r['licence']) . ' licence'
            . ' · measured at <a href="' . Html::attr($url . '/tree/' . $r['ref']) . '">' . Html::esc((string) $r['ref']) . '</a>'
            . ($r['ref'] === $r['commit'] ? '' : ' <span class="cb-sha">(' . Html::esc((string) $r['commit']) . ')</span>') . '</p>';
        $tests = $r['tests'] ?? [];
        $testNote = isset($tests['where'])
            ? Html::esc('in ' . self::plural((int) $tests['files'], 'file', 'files') . ' in ' . $tests['where'] . ' that read this repo')
            : Html::esc('in ' . self::plural((int) $tests['files'], 'file', 'files') . ', run with ') . '<code>' . Html::esc((string) $tests['runner']) . '</code>';
        $tiles = [
            ['Lines of code', self::n($r['code']['lines']), Html::esc('in ' . self::plural((int) $r['code']['files'], 'file', 'files')), ''],
            ['Tests', self::n($tests['cases'] ?? 0), $testNote, ''],
            ['Commits', self::n($r['history']['commits']), Html::esc('up to ' . $r['ref']), ''],
            ['First commit', self::day($r['history']['first'] ?? null), '', ' cb-tile-date'],
            ['Latest commit', self::day($r['history']['latest'] ?? null), '', ' cb-tile-date'],
        ];
        $tileHtml = '';
        foreach ($tiles as [$label, $value, $note, $class]) {
            $tileHtml .= '<li><span class="cb-tile-label">' . Html::esc($label) . '</span><span class="cb-tile-value' . $class . '">' . Html::esc($value) . '</span>'
                . ($note === '' ? '' : '<span class="cb-tile-note">' . $note . '</span>') . '</li>';
        }
        return '<div class="cb-repo" data-repo="' . Html::attr((string) $r['id']) . '">' . $meta
            . '<ul class="cb-tiles">' . $tileHtml . '</ul>'
            . '<div class="cb-grid"><div class="cb-col">' . self::languages($r) . self::largest($r) . '</div>'
            . '<div class="cb-col">' . self::areas($r) . self::notCode($r) . '</div></div>'
            . '</div>';
    }

    /** Lines by language: one stacked bar in the fixed language order, small ones folded into Other, every language named in the legend. */
    private static function languages(array $r): string
    {
        $total = max(1, (int) $r['code']['lines']);
        $by = [];
        foreach ($r['languages'] ?? [] as $l) {
            $by[(string) $l['id']] = $l;
        }
        $shown = [];
        $other = [];
        foreach (self::ORDER as $id) {
            if (!isset($by[$id])) {
                continue;
            }
            if ((int) $by[$id]['lines'] / $total < self::FOLD_BELOW) {
                $other[] = $by[$id];
            } else {
                $shown[] = $by[$id];
            }
        }
        // A lone small language stays itself: Other only gathers two or more.
        if (count($other) === 1) {
            $shown[] = $other[0];
            $other = [];
        }
        $segments = '';
        $legend = '';
        $describe = [];
        foreach ($shown as $l) {
            $lines = (int) $l['lines'];
            $title = $l['label'] . ': ' . self::n($lines) . ' lines (' . self::pct($lines / $total) . '), ' . self::plural((int) $l['files'], 'file', 'files');
            $segments .= '<span class="cb-seg cb-lang-' . Html::attr((string) $l['id']) . '" style="flex-grow: ' . $lines . '" title="' . Html::attr($title) . '"></span>';
            $legend .= '<li><span class="cb-dot cb-lang-' . Html::attr((string) $l['id']) . '"></span><b>' . Html::esc((string) $l['label']) . '</b> '
                . Html::esc(self::n($lines) . ' lines · ' . self::pct($lines / $total) . ' · ' . self::plural((int) $l['files'], 'file', 'files')) . '</li>';
            $describe[] = $l['label'] . ' ' . self::pct($lines / $total);
        }
        if ($other !== []) {
            $lines = array_sum(array_map(static fn (array $l): int => (int) $l['lines'], $other));
            $names = implode(', ', array_map(static fn (array $l): string => $l['label'] . ' ' . self::n($l['lines']) . ' lines in ' . self::plural((int) $l['files'], 'file', 'files'), $other));
            $segments .= '<span class="cb-seg cb-lang-other" style="flex-grow: ' . $lines . '" title="' . Html::attr('Other: ' . $names) . '"></span>';
            $legend .= '<li><span class="cb-dot cb-lang-other"></span><b>Other</b> ' . Html::esc(self::n($lines) . ' lines · ' . self::pct($lines / $total) . ' (' . $names . ')') . '</li>';
            $describe[] = 'other ' . self::pct($lines / $total);
        }
        return '<figure class="cb-fig"><h3 class="cb-fig-h">Lines by language</h3>'
            . '<div class="cb-stack" role="img" aria-label="' . Html::attr('Lines of code by language: ' . implode(', ', $describe)) . '">' . $segments . '</div>'
            . '<ul class="cb-legend">' . $legend . '</ul></figure>';
    }

    /** Lines by area of the repo, largest first, on one scale. */
    private static function areas(array $r): string
    {
        $areas = $r['areas'] ?? [];
        $max = max(1, ...array_map(static fn (array $a): int => (int) $a['lines'], $areas));
        $rows = '';
        foreach ($areas as $a) {
            $lines = (int) $a['lines'];
            $rows .= '<li><div class="cb-bar-head"><span class="cb-bar-label">' . Html::esc((string) $a['label']) . '</span>'
                . '<span class="cb-bar-value">' . Html::esc(self::n($lines)) . '</span></div>'
                . '<span class="cb-track"><span class="cb-fill" style="width: ' . Html::attr(self::width($lines, $max)) . '" title="' . Html::attr($a['label'] . ': ' . self::n($lines) . ' lines, ' . self::plural((int) $a['files'], 'file', 'files')) . '"></span></span>'
                . '<span class="cb-bar-note">' . Html::esc(self::plural((int) $a['files'], 'file', 'files') . ' · ' . $a['path']) . '</span></li>';
        }
        return '<figure class="cb-fig"><h3 class="cb-fig-h">Lines by area</h3><ol class="cb-bars">' . $rows . '</ol></figure>';
    }

    private static function largest(array $r): string
    {
        $rows = '';
        foreach ($r['largest'] ?? [] as $f) {
            $path = (string) $f['path'];
            $rows .= '<li><a class="cb-path" href="' . Html::attr($r['url'] . '/blob/' . $r['ref'] . '/' . implode('/', array_map('rawurlencode', explode('/', $path)))) . '">' . Html::esc($path) . '</a>'
                . '<span class="cb-bar-value">' . Html::esc(self::n($f['lines'])) . '</span></li>';
        }
        return '<div class="cb-fig"><h3 class="cb-fig-h">Largest files</h3><ol class="cb-files">' . $rows . '</ol></div>';
    }

    /** Everything in the repo that is not counted as code, with its size. */
    private static function notCode(array $r): string
    {
        $rows = '';
        foreach ($r['notCode'] ?? [] as $n) {
            $what = self::plural((int) $n['files'], 'file', 'files') . ' · ' . self::size((int) $n['bytes']) . (isset($n['lines']) ? ' · ' . self::n($n['lines']) . ' lines' : '');
            $rows .= '<li><span>' . Html::esc((string) $n['label']) . '</span><span class="cb-bar-value">' . Html::esc($what) . '</span></li>';
        }
        return '<div class="cb-fig"><h3 class="cb-fig-h">Not counted as code</h3><ul class="cb-files">' . $rows . '</ul></div>';
    }

    /** The Minn Engine and Minn Admin commits the live site is pinned to (tools/deploy/live.json). */
    private static function platform(array $d): string
    {
        $items = '';
        foreach (['engine', 'minnAdmin'] as $key) {
            $p = $d['platform'][$key] ?? null;
            if (!is_array($p)) {
                continue;
            }
            $commit = (string) ($p['commit'] ?? '');
            $items .= '<li><a href="' . Html::attr((string) $p['url']) . '"><b>' . Html::esc((string) $p['name']) . '</b></a> '
                . ($commit === '' ? '' : 'at commit <a class="cb-sha" href="' . Html::attr($p['url'] . '/commit/' . $commit) . '">' . Html::esc($commit) . '</a>') . '</li>';
        }
        $when = self::day($d['deployed'] ?? null);
        return '<div class="cb-platform"><p class="cb-meta">The live site runs' . ($when === '' ? '' : ' (deployed ' . Html::esc($when) . ')') . ':</p><ul class="cb-pins">' . $items . '</ul></div>';
    }
}
