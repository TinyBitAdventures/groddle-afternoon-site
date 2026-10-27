<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Html;

/**
 * The restoration report's Seasons section (blocks/restoration-seasons,
 * registered by Restoration): a card per holiday from the `seasons` block of
 * public/restoration/data.json, which groddle-afternoon
 * tools/restoration/seasons.mjs writes from the game's js/seasons.js. Which
 * season is on is worked out here on every request, from the windows the data
 * names, so the "On now" mark moves without a rebuild: the real-world dates in
 * the site's time zone (as the game uses the player's own date), Pi Day in UTC
 * as the original did, and Zilloween on Ur's own Dametime calendar.
 */
final class Seasons
{
    private const DAY_MS = 86_400_000;
    private const MONTHS = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /** The site's time zone (timezone_string, else gmt_offset), UTC when it names none. */
    public static function zone(?string $name, mixed $offset): \DateTimeZone
    {
        if ($name !== null && $name !== '') {
            try {
                return new \DateTimeZone($name);
            } catch (\Exception) {
                // An unknown name falls through to the offset.
            }
        }
        $hours = (float) $offset;
        if ($hours === 0.0) {
            return new \DateTimeZone('UTC');
        }
        $minutes = (int) round(abs($hours) * 60);
        return new \DateTimeZone(sprintf('%s%02d:%02d', $hours < 0 ? '-' : '+', intdiv($minutes, 60), $minutes % 60));
    }

    /** Easter Sunday of a year as [month, day] (the anonymous Gregorian algorithm, as seasons.js easterSunday). */
    public static function easter(int $year): array
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return [$month, $day];
    }

    private static function dayIndex(int $y, int $m, int $d): int
    {
        return intdiv(gmmktime(0, 0, 0, $m, $d, $y), 86_400);
    }

    /** The Egg Hunt's first and last day in a year, as day indexes. */
    private static function eggHunt(array $when, int $year): array
    {
        [$em, $ed] = self::easter($year);
        $easter = self::dayIndex($year, $em, $ed);
        return [$easter - (int) ($when['before'] ?? 7), $easter + (int) ($when['after'] ?? 1)];
    }

    /** Whether a season's real-world window holds the moment $ms (Unix milliseconds). */
    public static function onNow(array $when, int $ms, \DateTimeZone $tz): bool
    {
        $at = (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone($tz);
        [$y, $m, $d] = array_map('intval', explode('-', $at->format('Y-n-j')));
        switch ($when['kind'] ?? '') {
            case 'dates':
                $v = $m * 100 + $d;
                $from = (int) $when['from'][0] * 100 + (int) $when['from'][1];
                $to = (int) $when['to'][0] * 100 + (int) $when['to'][1];
                return $from <= $to ? $v >= $from && $v <= $to : $v >= $from || $v <= $to;
            case 'easter':
                [$first, $last] = self::eggHunt($when, $y);
                $today = self::dayIndex($y, $m, $d);
                return $today >= $first && $today <= $last;
            case 'utc-day':
                // isPiDay: the day in UTC, or twelve hours either side of it.
                $year = (int) gmdate('Y', intdiv($ms, 1000));
                $start = (gmmktime(0, 0, 0, (int) $when['month'], (int) $when['day'], $year) - (int) $when['slackHours'] * 3600) * 1000;
                $end = $start + (24 + 2 * (int) $when['slackHours']) * 3_600_000;
                return $ms >= $start && $ms < $end;
        }
        return false;
    }

    /** Where Ur's calendar is in its Dametime Zilloween: ['on' => bool, 'ms' => until it ends, or until it next starts]. */
    public static function dametime(array $dt, int $ms): array
    {
        $year = (int) $dt['yearMs'];
        $t = (($ms - (int) $dt['epochMs']) % $year + $year) % $year;
        $from = (int) $dt['fromMs'];
        $to = (int) $dt['toMs'];
        if ($t >= $from && $t < $to) {
            return ['on' => true, 'ms' => $to - $t];
        }
        return ['on' => false, 'ms' => $t < $from ? $from - $t : $year - $t + $from];
    }

    /** "about 3 days" or "about 20 hours". */
    private static function span(int $ms): string
    {
        $hours = (int) round($ms / 3_600_000);
        if ($hours < 36) {
            $hours = max(1, $hours);
            return 'about ' . $hours . ' hour' . ($hours === 1 ? '' : 's');
        }
        $days = (int) round($ms / self::DAY_MS);
        return "about {$days} days";
    }

    private static function date(int $day): string
    {
        $ts = $day * 86_400;
        return self::MONTHS[(int) gmdate('n', $ts)] . ' ' . gmdate('j', $ts);
    }

    /** The section: a card per season, $ms the moment to draw it for (now unless a test names one). */
    public static function draw(array $s, \DateTimeZone $tz, ?int $ms = null): string
    {
        $ms ??= (int) floor(microtime(true) * 1000);
        $cards = '';
        foreach ($s['seasons'] ?? [] as $season) {
            if (is_array($season)) {
                $cards .= self::card($season, $s, $tz, $ms);
            }
        }
        return '<ul class="season-grid" id="season-grid">' . $cards . '</ul>';
    }

    private static function icon(array $item, int $size, string $class): string
    {
        return '<img class="' . $class . '" src="' . Html::attr((string) $item['icon']) . '"'
            . (empty($item['icon3x']) ? '' : ' srcset="' . Html::attr((string) $item['icon3x']) . ' 3x"')
            . ' alt="' . Html::attr((string) ($item['name'] ?? '')) . '" title="' . Html::attr((string) ($item['name'] ?? '')) . '" width="' . $size . '" height="' . $size . '" loading="lazy" />';
    }

    private static function card(array $season, array $s, \DateTimeZone $tz, int $ms): string
    {
        $id = (string) $season['id'];
        $status = $s['status'][$season['status']] ?? ['label' => (string) $season['status'], 'tone' => 'stone', 'note' => ''];
        $on = self::onNow((array) ($season['when'] ?? []), $ms, $tz);
        $ur = !empty($season['dametime']) && isset($s['dametime']) ? self::dametime($s['dametime'], $ms) : null;

        $when = [Html::esc(ucfirst((string) $season['window']) . '.')];
        if (($season['when']['kind'] ?? '') === 'easter') {
            $year = (int) (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone($tz)->format('Y');
            $today = intdiv(intdiv($ms, 1000) + $tz->getOffset(new \DateTimeImmutable('@' . intdiv($ms, 1000))), 86_400);
            [$first, $last] = self::eggHunt($season['when'], $year);
            if ($today > $last) {
                [$first, $last] = self::eggHunt($season['when'], ++$year);
            }
            $when[] = Html::esc("In {$year}: " . self::date($first) . ' to ' . self::date($last) . '.');
        }
        if ($ur !== null) {
            $dt = $s['dametime'];
            $line = $ur['on']
                ? sprintf('It is %s %d to %d in Ur right now, for %s more.', $dt['month'], $dt['fromDay'], $dt['toDay'], self::span($ur['ms']))
                : sprintf('Ur’s calendar next reaches %s %d in %s.', $dt['month'], $dt['fromDay'], self::span($ur['ms']));
            $when[] = Html::esc($line);
        }

        $mark = '';
        if ($on || ($ur['on'] ?? false)) {
            $mark = '<span class="season-now">' . Html::esc($on ? 'On now' : 'On now in Ur') . '</span>';
        }

        $facts = '';
        foreach ($season['facts'] ?? [] as $fact) {
            $icons = implode('', array_map(static fn (array $it): string => '<li>' . self::icon($it, 40, 'season-item') . '</li>', (array) ($fact['items'] ?? [])));
            $facts .= '<div class="season-fact"><dt>' . Html::esc((string) $fact['label']) . '</dt><dd><p>' . Html::esc((string) $fact['text']) . '</p>'
                . ($icons === '' ? '' : '<ul class="season-items">' . $icons . '</ul>') . '</dd></div>';
        }

        $badges = '';
        foreach ($season['badges'] ?? [] as $badge) {
            $art = empty($badge['icon']) ? '' : '<img src="' . Html::attr((string) $badge['icon']) . '" alt="" width="36" height="36" loading="lazy" />';
            $badges .= '<li title="' . Html::attr((string) ($badge['desc'] ?? '')) . '">' . $art . '<span>' . Html::esc((string) $badge['name']) . '</span></li>';
        }
        $secret = (int) ($season['secret'] ?? 0);
        $secretLine = $secret === 0 ? '' : ($secret === 1 ? 'And one secret badge, which shows itself once you earn it.' : "And {$secret} secret badges, which show themselves once you earn them.");
        $badgeBlock = $badges === '' && $secretLine === '' ? '' : '<div class="season-badges"><h4>Badges</h4>'
            . ($badges === '' ? '' : '<ul>' . $badges . '</ul>')
            . ($secretLine === '' ? '' : '<p>' . Html::esc($secretLine) . '</p>') . '</div>';

        return '<li class="season-card' . ($mark === '' ? '' : ' is-on') . '" id="season-' . Html::attr($id) . '" style="--season-tone: var(--' . Html::attr(preg_replace('/[^a-z]/', '', (string) ($season['tone'] ?? 'stone'))) . ')">'
            . '<div class="season-head">' . self::icon((array) $season['icon'], 56, 'season-icon')
            . '<div class="season-title"><h3>' . Html::esc((string) $season['label']) . '</h3>'
            . '<div class="season-tags"><span class="pill" style="--tone: var(--' . Html::attr(preg_replace('/[^a-z]/', '', (string) $status['tone'])) . ')" title="' . Html::attr((string) ($status['note'] ?? '')) . '">' . Html::esc((string) $status['label']) . '</span>' . $mark . '</div></div></div>'
            . '<p class="season-when">' . implode(' ', $when) . '</p>'
            . '<p class="season-blurb">' . Html::esc((string) $season['blurb']) . '</p>'
            . ($facts === '' ? '' : '<dl class="season-facts">' . $facts . '</dl>')
            . $badgeBlock
            . (empty($status['note']) ? '' : '<p class="season-source">' . Html::esc((string) $status['note']) . '</p>')
            . (empty($season['play']) ? '' : '<p class="season-play"><a class="play-link" href="' . Html::attr((string) $season['play']) . '" target="_blank" rel="noopener">Try it in solo</a></p>')
            . '</li>';
    }
}
