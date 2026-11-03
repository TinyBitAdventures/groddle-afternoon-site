<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;
use Minn\Html;

/**
 * The Contribute page's data blocks (blocks/contribute-shrine,
 * blocks/contribute-ledger), drawn in full from public/contribute/data.json,
 * which groddle-afternoon tools/contribute/build-data.mjs writes from its
 * hand-kept ledger.json.
 *
 * The shrine is a small street of Tiny Speck's own art (CC0) with a Giant's
 * shrine on it. Drawn here it is a plain link to GitHub Sponsors, so it works
 * without scripts; contribute.js, loaded once when the block is on the page,
 * turns it into the game's verb menu (the shrine's name, what the Giant is,
 * "1 Donate" and a footer line; no icon, as the game shows none for a shrine), whose Donate opens Sponsors in a new tab.
 *
 * The ledger is worked out per request from the data and today's date in the
 * site's time zone (totals), so it stays current without a build: a monthly
 * cost is charged on the start date's day of every month, a yearly one on the
 * start date and each anniversary (a day past a short month's end is its last
 * day). The game's build-data.mjs ledgerTotals is the same arithmetic, and the
 * game's tests hold the two to each other.
 */
final class Contribute
{
    private static bool $used = false;
    private static ?array $data = null;

    /** The street's art in design pixels (a 960 x 500 stage, scaled by the .ct-* styles in style.css): file, left, bottom, width, and a class. */
    private const DECOR = [
        ['/assets/decos/groddle/wallpaper_tree_short_1.webp', -140, 70, 820, 'ct-forest'],
        ['/assets/decos/groddle/wallpaper_tree_short_1.webp', 460, 70, 820, 'ct-forest ct-flip'],
        ['/assets/decos/groddle/tree_coniferous_fg_1.webp', 40, 82, 150, 'ct-pine'],
        ['/assets/decos/groddle/tree_coniferous_fg_2.webp', 300, 82, 170, 'ct-pine'],
        ['/assets/decos/groddle/tree_coniferous_fg_1.webp', 640, 82, 140, 'ct-pine'],
        ['/assets/decos/groddle/tree_coniferous_fg_2.webp', 840, 82, 160, 'ct-pine'],
        ['/assets/decos/groddle/bush_3.webp', 330, 90, 300, ''],
        ['/assets/decos/groddle/bush_3.webp', 700, 90, 240, 'ct-flip'],
    ];

    /** The art in front of the ground line. */
    private const FRONT = [
        ['/assets/sprites/trants/frames/trant_fruit_m10.png', 120, 94, 230, ''],
        ['/assets/decos/groddle/groddle_bush1.webp', -30, 86, 170, ''],
        ['/assets/sprites/street-furniture/mailbox-left-idle.png', 760, 96, 62, ''],
        ['/assets/decos/groddle/groddle_bush1.webp', 830, 86, 160, 'ct-flip'],
        ['/assets/decos/groddle/groddle_grass_2.webp', 380, 70, 70, 'ct-front'],
        ['/assets/decos/groddle/groddle_grass_2.webp', 590, 70, 64, 'ct-front ct-flip'],
    ];

    public static function register(Seams $minn, StaticRoot $root): void
    {
        $file = $root->dir . '/contribute/data.json';
        $data = static function () use ($file): array {
            if (self::$data === null) {
                $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
                self::$data = is_array($decoded) ? $decoded : [];
            }
            return self::$data;
        };
        $pending = static fn (string $what): string => '<p class="report-pending">' . Html::esc("The {$what} appears once the game is built (public/contribute/data.json).") . '</p>';
        $minn->block('blocks/contribute-shrine', static function (array $a) use ($data, $pending): string {
            $d = $data();
            if ($d === []) {
                return $pending('shrine');
            }
            self::$used = true;
            return self::shrine($d, (string) $a['hint'], (string) $a['footer']);
        });
        $minn->block('blocks/contribute-ledger', static function (array $a) use ($minn, $data, $pending): string {
            $d = $data();
            if ($d === []) {
                return $pending('ledger');
            }
            $zone = Seasons::zone((string) ($minn->site->option('timezone_string') ?? ''), $minn->site->option('gmt_offset') ?? 0);
            return self::ledger($d, (new \DateTimeImmutable('now', $zone))->format('Y-m-d'), (string) $a['note']);
        });
        $script = $root->dir . '/contribute/contribute.js';
        $minn->footer(static fn (): string => self::$used ? '<script src="/contribute/contribute.js?v=' . (is_file($script) ? filemtime($script) : 0) . '" type="module"></script>' . "\n" : '');
    }

    /** A ledger date (YYYY-MM-DD) as [year, month, day], or null when it is no real date. */
    public static function date(string $iso): ?array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    private static function daysIn(int $y, int $m): int
    {
        return (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');
    }

    /** How many times a cost charged every month or year from $start has been charged by $today. */
    public static function charges(array $start, string $every, array $today): int
    {
        [$y0, $m0, $d0] = $start;
        [$y, $m, $d] = $today;
        if ([$y, $m, $d] < [$y0, $m0, $d0]) {
            return 0;
        }
        if ($every === 'month') {
            return ($y - $y0) * 12 + ($m - $m0) + ($d >= min($d0, self::daysIn($y, $m)) ? 1 : 0);
        }
        $day = min($d0, self::daysIn($y, $m0));
        return ($y - $y0) + ($m > $m0 || ($m === $m0 && $d >= $day) ? 1 : 0);
    }

    /**
     * The ledger on a day (YYYY-MM-DD): each cost's times charged and total,
     * money out and in (cents), the gifts counted, the difference and the months.
     */
    public static function totals(array $d, string $iso): array
    {
        $start = self::date((string) ($d['start'] ?? ''));
        $today = self::date($iso);
        if ($start === null || $today === null) {
            throw new \InvalidArgumentException('not a date: ' . ($start === null ? (string) ($d['start'] ?? '') : $iso));
        }
        $costs = [];
        $out = 0;
        foreach ($d['costs'] ?? [] as $c) {
            $times = self::charges($start, (string) $c['every'], $today);
            $costs[] = ['id' => (string) $c['id'], 'times' => $times, 'cents' => $times * (int) $c['cents']];
            $out += $times * (int) $c['cents'];
        }
        $in = 0;
        $gifts = 0;
        foreach ($d['in'] ?? [] as $e) {
            $on = self::date((string) $e['date']);
            if ($on !== null && $on <= $today) {
                $in += (int) $e['cents'];
                $gifts++;
            }
        }
        return ['months' => self::charges($start, 'month', $today), 'costs' => $costs, 'out' => $out, 'in' => $in, 'gifts' => $gifts, 'difference' => $in - $out];
    }

    /** Cents as dollars: $86, $5.50, and a minus sign below zero. */
    public static function money(int $cents): string
    {
        $abs = abs($cents);
        return ($cents < 0 ? "\u{2212}" : '') . '$' . number_format($abs / 100, $abs % 100 === 0 ? 0 : 2);
    }

    private static function day(string $iso): string
    {
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
        return $at === false ? $iso : $at->format('j F Y');
    }

    private static function ordinal(int $n): string
    {
        $tens = $n % 100;
        return $n . (($tens >= 11 && $tens <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th'));
    }

    /** One picture on the street: its place and width in design pixels (the --x, --b, --w the styles scale). */
    private static function art(array $a): string
    {
        [$src, $x, $b, $w, $cls] = $a;
        return '<img class="ct-art' . ($cls === '' ? '' : ' ' . $cls) . '" src="' . Html::attr($src) . '" alt="" loading="lazy" style="--x:' . $x . ';--b:' . $b . ';--w:' . $w . '" />';
    }

    /** The street with the shrine, and the verb menu its script opens over it. */
    private static function shrine(array $d, string $hint, string $footer): string
    {
        $s = $d['shrine'] ?? [];
        $url = Html::attr((string) ($d['sponsors'] ?? ''));
        $name = Html::esc('Shrine to ' . (string) ($s['name'] ?? ''));
        $sheet = Html::attr("url('" . (string) ($s['sheet3x'] ?? $s['sheet'] ?? '') . "')");
        $back = implode('', array_map(self::art(...), self::DECOR));
        $front = implode('', array_map(self::art(...), self::FRONT));
        $hint = $hint === '' ? '' : '<p class="ct-hint" aria-hidden="true">' . Inline::html($hint) . '</p>';
        $about = Html::esc((string) ($s['description'] ?? ''));
        $footer = Inline::html($footer);
        return <<<HTML
        <div class="ct-street" data-ct-street>
          <div class="ct-frame">
            <div class="ct-stage">
              <div class="ct-sky"></div>
              {$back}
              <div class="ct-ground"></div>
              {$front}
              <span class="ct-butterfly" aria-hidden="true"></span>
              <a class="ct-shrine" id="ct-shrine" href="{$url}" target="_blank" rel="noopener" data-ct-shrine>
                <span class="ct-shrine-art" style="--sheet:{$sheet}"></span>
                <span class="ct-nameplate">{$name}</span><span class="ct-sr" data-ct-sr>: help with the costs on GitHub Sponsors (opens in a new tab)</span>
              </a>
            </div>
            {$hint}
          </div>
          <div class="ct-menu" id="ct-shrine-menu" role="menu" aria-labelledby="ct-shrine-menu-title" hidden>
            <div class="ct-menu-head">
              <div class="ct-menu-titles"><p class="ct-menu-title" id="ct-shrine-menu-title">{$name}</p><p class="ct-menu-sub">{$about}</p></div>
            </div>
            <a class="ct-menu-row" role="menuitem" href="{$url}" target="_blank" rel="noopener" aria-keyshortcuts="1"><span class="ct-key" aria-hidden="true">1</span><span class="ct-label">Donate</span><span class="ct-ext" aria-hidden="true">↗</span><span class="ct-sr"> (GitHub Sponsors, opens in a new tab)</span></a>
            <p class="ct-menu-foot">{$footer}</p>
          </div>
        </div>
        HTML;
    }

    /** The open ledger on a day: the totals as tiles, the recurring costs, then what came in. */
    public static function ledger(array $d, string $iso, string $note): string
    {
        $t = self::totals($d, $iso);
        $start = self::date((string) $d['start']) ?? [0, 0, 0];
        $since = 'since ' . self::day((string) $d['start']);
        $diff = $t['difference'];
        $tile = static fn (string $label, string $value, string $line, string $cls = ''): string => '<div class="tile' . ($cls === '' ? '' : " {$cls}") . '"><p class="tile-label">' . Html::esc($label) . '</p><div class="tile-num">' . Html::esc($value) . '</div><p class="tile-note">' . Html::esc($line) . '</p></div>';
        $tiles = $tile('Money in, all time', self::money($t['in']), $t['gifts'] === 0 ? 'Nothing yet' : ($t['gifts'] === 1 ? 'From 1 gift' : "From {$t['gifts']} gifts"), 'ct-in')
            . $tile('Money out, all time', self::money($t['out']), $since, 'ct-out')
            . $tile('Difference', self::money($diff), $diff < 0 ? 'More out than in' : ($diff > 0 ? 'More in than out' : 'Even'), 'ct-diff')
            . $tile('Months counted', (string) $t['months'], $since);
        $rows = '';
        $monthly = 0;
        $yearly = 0;
        foreach ($d['costs'] as $i => $c) {
            $every = (string) $c['every'];
            $cents = (int) $c['cents'];
            if ($every === 'month') {
                $monthly += $cents;
                $when = 'Every month, on the ' . self::ordinal($start[2]);
            } else {
                $yearly += $cents;
                $when = 'Every year, on ' . (\DateTimeImmutable::createFromFormat('!Y-m-d', (string) $d['start']) ?: new \DateTimeImmutable())->format('j F');
            }
            $times = $t['costs'][$i]['times'];
            $rows .= '<tr><th scope="row">' . Html::esc((string) $c['name']) . '<span class="ct-every">' . Html::esc($when) . '</span></th><td class="ct-when">' . Html::esc($when) . '</td><td class="ct-num">' . self::money($cents) . '</td><td class="ct-num ct-times">' . Html::esc((string) $times) . '</td><td class="ct-num">' . self::money($t['costs'][$i]['cents']) . '</td></tr>';
        }
        $each = implode(' and ', array_filter([$monthly > 0 ? self::money($monthly) . ' a month' : '', $yearly > 0 ? self::money($yearly) . ' a year' : '']));
        $costs = '<h3 class="ct-sub-h" id="ct-costs-h">Recurring costs</h3><div class="ct-table-wrap"><table class="ct-table" aria-labelledby="ct-costs-h">'
            . '<thead><tr><th scope="col">Cost</th><th scope="col" class="ct-when">Charged</th><th scope="col" class="ct-num">Each time</th><th scope="col" class="ct-num ct-times">Times</th><th scope="col" class="ct-num">All time</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '<tfoot><tr><th scope="row">Money out<span class="ct-every">' . Html::esc($each) . '</span></th><td class="ct-when">' . Html::esc($each) . '</td><td></td><td class="ct-times"></td><td class="ct-num">' . self::money($t['out']) . '</td></tr></tfoot></table></div>';
        $gifts = '';
        foreach ($d['in'] ?? [] as $e) {
            $on = self::date((string) $e['date']);
            if ($on !== null && $on <= self::date($iso)) {
                $gifts .= '<tr><td><time datetime="' . Html::attr((string) $e['date']) . '">' . Html::esc(self::day((string) $e['date'])) . '</time></td><td>' . Html::esc((string) $e['source']) . '</td><td class="ct-num">' . self::money((int) $e['cents']) . '</td></tr>';
            }
        }
        $in = $gifts === ''
            ? '<p class="ct-none">Nothing has come in yet.</p>'
            : '<div class="ct-table-wrap"><table class="ct-table ct-gifts" aria-labelledby="ct-in-h"><thead><tr><th scope="col">Date</th><th scope="col">From</th><th scope="col" class="ct-num">Amount</th></tr></thead><tbody>' . $gifts . '</tbody>'
                . '<tfoot><tr><th scope="row" colspan="2">Money in</th><td class="ct-num">' . self::money($t['in']) . '</td></tr></tfoot></table></div>';
        $asOf = 'As of ' . self::day($iso) . '.' . ($note === '' ? '' : ' ');
        return '<div class="ct-ledger" data-ct-ledger="' . Html::attr($iso) . '">'
            . '<div class="tiles ct-tiles">' . $tiles . '</div>'
            . $costs
            . '<h3 class="ct-sub-h" id="ct-in-h">Money in</h3>' . $in
            . '<p class="ct-ledger-note">' . Html::esc($asOf) . Inline::html($note) . '</p>'
            . '</div>';
    }
}
