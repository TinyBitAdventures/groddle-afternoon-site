<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;
use Minn\Extension\Seams;
use Minn\Html;

/**
 * The Contribute page's "Thank you, testers" board (blocks/contribute-credits):
 * the top ReportCredits::BOARD players by the points their bug reports earned
 * (ReportCredits::rank), drawn in full on the server from the database each
 * time the page is read, no script. Every other tester with points is behind
 * a "View N more testers" button, in a pop-up over the page (Austin
 * 2026-11-10): the browser's own popover, so it opens, closes on Esc or a
 * click outside and returns focus without a script.
 *
 * Each row: the rank, the player's Glitch (their avatar, the Players page's
 * /players/<id>/avatar picture), their character's name, their points and how
 * many of their reports led to a change. The picture and a link to their page
 * (/players/<slug>/) show only for a character with a public page, the
 * Players page's rule (Profiles::LISTED_LEVEL, level 5, and a slug): below it
 * the page and the picture are the owner's alone, so the row shows the name
 * as text and the silhouette. Never the username: a player with no character
 * name shows as "A tester".
 *
 * The line under it ("how") is the block's attribute, words edited in Minn
 * Admin; before anyone is credited the board says so.
 */
final class ContributeCredits
{
    public const NAMELESS = 'A tester';

    public static function register(Seams $minn): void
    {
        $minn->block('blocks/contribute-credits', static fn (array $a): string => self::draw(self::board($minn->db), (string) ($a['how'] ?? ''), (string) ($a['empty'] ?? '')));
    }

    /**
     * The board as it stands, everyone with points in rank order (draw shows
     * the first ReportCredits::BOARD and keeps the rest for the pop-up):
     * ReportCredits::rank's rows with what a row draws: name, slug (null
     * without a public page) and the avatar's time.
     *
     * @return list<array{id: int, points: int, reports: int, name: string, slug: ?string, avatarAt: ?string}>
     */
    public static function board(Db $db): array
    {
        $top = ReportCredits::rank((new ReportCredits($db))->totals(), PHP_INT_MAX);
        if ($top === []) {
            return [];
        }
        $ids = array_map(static fn (array $t): int => $t['id'], $top);
        $names = [];
        foreach ($db->rows('SELECT user_id, name FROM ' . $db->table(Characters::TABLE) . ' WHERE user_id IN (?)', [$ids]) as $r) {
            $names[(int) $r['user_id']] = (string) $r['name'];
        }
        $levels = [];
        foreach ($db->rows('SELECT s.user_id, ' . Profiles::levelSql('s') . ' AS level FROM ' . $db->table(MultiplayerSave::TABLE) . ' s WHERE s.user_id IN (?)', [$ids]) as $r) {
            $levels[(int) $r['user_id']] = (int) $r['level'];
        }
        $avatars = [];
        foreach ($db->rows('SELECT user_id, avatar_at FROM ' . $db->table(Profiles::TABLE) . ' WHERE user_id IN (?)', [$ids]) as $r) {
            $avatars[(int) $r['user_id']] = is_string($r['avatar_at'] ?? null) && $r['avatar_at'] !== '' ? (string) $r['avatar_at'] : null;
        }
        $slugs = Profiles::slugs($db);
        return array_map(static fn (array $t): array => self::entry($t, $names[$t['id']] ?? '', $levels[$t['id']] ?? 0, $slugs[$t['id']] ?? null, $avatars[$t['id']] ?? null), $top);
    }

    /**
     * One row's facts: a slug and an avatar only for a public page (level
     * LISTED_LEVEL or above, with a slug), the same rule as /players/.
     */
    public static function entry(array $t, string $name, int $level, ?string $slug, ?string $avatarAt): array
    {
        $public = $level >= Profiles::LISTED_LEVEL && $slug !== null && $slug !== '';
        return [
            'id' => (int) $t['id'],
            'points' => (int) $t['points'],
            'reports' => (int) $t['reports'],
            'name' => $name !== '' ? $name : self::NAMELESS,
            'slug' => $public ? $slug : null,
            'avatarAt' => $public ? $avatarAt : null,
        ];
    }

    /** The board, or the empty state, and the line on how points work. */
    public static function draw(array $board, string $how, string $empty = ''): string
    {
        $how = $how === '' ? '' : '<p class="cc-how">' . Inline::html($how) . '</p>';
        if ($board === []) {
            $empty = $empty !== '' ? $empty : 'Nobody is on the board yet. The first reports that lead to a change will put their testers here.';
            return '<div class="cc-board cc-board-empty"><p class="cc-empty">' . Inline::html($empty) . '</p>' . $how . '</div>';
        }
        $rows = '';
        $rest = '';
        foreach (array_values($board) as $i => $e) {
            if ($i < ReportCredits::BOARD) {
                $rows .= self::row($i + 1, $e);
            } else {
                $rest .= self::row($i + 1, $e);
            }
        }
        return '<div class="cc-board"><ol class="cc-list" role="list" aria-label="Testers by points">' . $rows . '</ol>'
            . ($rest === '' ? '' : self::more($rest, count($board) - ReportCredits::BOARD))
            . $how . '</div>';
    }

    /**
     * The button under the board and the pop-up it opens: the testers below
     * the first ReportCredits::BOARD, their ranks carrying on. The pop-up is a
     * popover (no script); its pictures are lazy, so they load when it opens.
     */
    private static function more(string $rows, int $count): string
    {
        $label = $count === 1 ? 'View 1 more tester' : 'View ' . $count . ' more testers';
        return '<p class="cc-more"><button type="button" class="cc-more-open" popovertarget="cc-more">' . $label . '</button></p>'
            . '<div class="cc-pop" id="cc-more" popover role="dialog" aria-labelledby="cc-more-title">'
            . '<header class="cc-pop-head"><h3 class="cc-pop-title" id="cc-more-title">More testers</h3>'
            . '<button type="button" class="cc-pop-close" popovertarget="cc-more" popovertargetaction="hide" aria-label="Close">×</button></header>'
            . '<ol class="cc-list cc-list-more" role="list" start="' . (ReportCredits::BOARD + 1) . '" aria-label="More testers by points">' . $rows . '</ol>'
            . '</div>';
    }

    private static function row(int $rank, array $e): string
    {
        $name = Html::esc($e['name']);
        $face = $e['avatarAt'] === null
            ? '<svg class="cc-art player-ghost" viewBox="0 0 100 144" width="100" height="144" aria-hidden="true" focusable="false"><g fill="currentColor"><ellipse cx="50" cy="46" rx="29" ry="31"/><path d="M29 84c13-7 29-7 42 0l5 34H24z"/><rect x="33" y="114" width="12" height="24" rx="6"/><rect x="55" y="114" width="12" height="24" rx="6"/></g></svg>'
            : '<img class="cc-art" src="' . Html::attr(Profiles::imageUrl($e['id'], 'avatar', $e['avatarAt'])) . '" width="172" height="248" alt="" loading="lazy" decoding="async" />';
        // The name is the row's one link (its ::after covers the row), so a reader hears it once.
        $who = $e['slug'] === null
            ? '<span class="cc-name">' . $name . '</span>'
            : '<a class="cc-name" href="/players/' . Html::attr($e['slug']) . '/">' . $name . '</a>';
        $reports = $e['reports'] === 1 ? '1 report that led to a change' : $e['reports'] . ' reports that led to a change';
        return '<li class="cc-row' . ($rank <= 3 ? ' cc-top' : '') . ($e['slug'] === null ? '' : ' cc-linked') . '">'
            . '<span class="cc-rank" aria-hidden="true">' . $rank . '</span>'
            . '<span class="cc-face">' . $face . '</span>'
            . '<span class="cc-who">' . $who . '<span class="cc-reports">' . Html::esc($reports) . '</span></span>'
            . '<span class="cc-points"><b>' . $e['points'] . '</b> <span>' . ($e['points'] === 1 ? 'point' : 'points') . '</span></span>'
            . "</li>";
    }
}
