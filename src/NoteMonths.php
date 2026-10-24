<?php

declare(strict_types=1);

namespace Groddle\Minn;

/**
 * The Notes list grouped by month, as the static blog drew it: a query loop
 * renders one list of cards, and this splits it where the month changes,
 * each run under its month's heading. A card's month is the datetime its
 * post-date already carries.
 */
final class NoteMonths
{
    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /** The post-template list, split into one section per month; anything else comes back unchanged. */
    public static function group(string $html): string
    {
        if (preg_match('/^(\s*<ul\b[^>]*>)(.*)(<\/ul>\s*)$/s', $html, $list) !== 1) {
            return $html;
        }
        $items = preg_split('/(?=<li\b)/', $list[2], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $runs = [];
        foreach ($items as $item) {
            if (preg_match('/datetime="(\d{4})-(\d{2})/', $item, $date) !== 1) {
                return $html;
            }
            $key = $date[1] . '-' . $date[2];
            $runs[$key][] = $item;
        }
        $out = '';
        foreach ($runs as $key => $run) {
            [$year, $month] = explode('-', (string) $key);
            $name = self::MONTHS[(int) $month - 1] . ' ' . $year;
            $out .= '<section class="note-month" aria-labelledby="m-' . $key . '"><h2 id="m-' . $key . '">' . $name . '</h2>'
                . $list[1] . implode('', $run) . '</ul></section>';
        }
        return $out;
    }
}
