<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;

/**
 * The moderation queue (Austin 2026-11-09: "Put Grok Bot in charge of keeping the game safe"), table
 * groddle_mod_reports: what the game's signals raise, for the moderators (Moderation: Grok Bot's token
 * and the administrators) to look at and resolve.
 *
 * Players do not report IMs (Austin 2026-11-09: "maybe scrap looking at IMs. I don't want to deal with
 * privacy changes"): the privacy policy promises only an IM's two players read it, administrators
 * included, so no IM's words reach this queue, a moderator or Grok Bot, reported or not. IM spam is
 * handled from the counts below, the players' own blocks, and a purge (Moderation::purge empties the
 * player's side of their IMs by player id, without anyone reading them).
 *
 * Signals (kind "flag", modsignals.go in the realtime server, through ChatHistory's ingest; and
 * "blocked", from Buddies when BLOCK_FLAG players block one player within BLOCK_WINDOW): no words,
 * only the signal, its count and window, with how new the account is (accountDays; under
 * NEW_ACCOUNT_DAYS counts as new). One open row a player a signal: a repeat counts up (times).
 */
final readonly class ModReports
{
    public const TABLE = 'groddle_mod_reports';

    public const NOTE_MAX = 500;

    /** "blocked": this many different players blocking one player within BLOCK_WINDOW seconds. */
    public const BLOCK_FLAG = 3;
    public const BLOCK_WINDOW = 86400;
    /** An account younger than this (days) is new: its flags say so. */
    public const NEW_ACCOUNT_DAYS = 7;

    /** The signals a flag may carry (modsignals.go, and "blocked" from here). */
    public const SIGNALS = [
        'im_fanout' => 'IMs to many players in a short time',
        'im_refused' => 'Many IMs refused (unread pile-up or too fast)',
        'im_filtered' => 'Many IMs the word filter changed',
        'chat_refused' => 'Many chat lines refused (too fast or silenced)',
        'blocked' => 'Blocked by several players in a day',
    ];

    /** A moderator's note on a resolved row: one line, at most NOTE_MAX. */
    public static function note(mixed $text): string
    {
        $text = is_string($text) ? mb_scrub($text, 'UTF-8') : '';
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', ' ', $text)));
        return trim(mb_substr($text, 0, self::NOTE_MAX));
    }

    // —— Signals ——

    /** A signal's flag into the queue (one open row a player a signal; a repeat counts up). 1 when stored. */
    public static function flag(Db $db, int $player, string $signal, int $n, int $window): int
    {
        if ($player <= 0 || !isset(self::SIGNALS[$signal])) {
            return 0;
        }
        $t = $db->table(self::TABLE);
        $stamp = gmdate('Y-m-d H:i:s');
        $open = $db->value("SELECT id FROM {$t} WHERE kind = 'flag' AND status = 'open' AND reported_id = ? AND signal_name = ? ORDER BY id DESC LIMIT 1", [$player, $signal]);
        if ($open !== null) {
            return $db->execute("UPDATE {$t} SET times = times + 1, signal_n = GREATEST(signal_n, ?), signal_window = ?, updated_at = ? WHERE id = ?", [max(0, $n), max(0, $window), $stamp, (int) $open]);
        }
        return $db->execute("INSERT INTO {$t} (kind, status, reported_id, signal_name, signal_n, signal_window, created_at, updated_at) VALUES ('flag', 'open', ?, ?, ?, ?, ?, ?)", [$player, $signal, max(0, $n), max(0, $window), $stamp, $stamp]);
    }

    /** Buddies, after a block: a flag when BLOCK_FLAG different players blocked $player within BLOCK_WINDOW. */
    public static function afterBlock(Db $db, int $player): void
    {
        $n = (int) $db->value('SELECT COUNT(*) FROM ' . $db->table(Buddies::IGNORES) . ' WHERE ignored_id = ? AND added_at >= ?', [$player, gmdate('Y-m-d H:i:s', time() - self::BLOCK_WINDOW)]);
        if ($n >= self::BLOCK_FLAG) {
            self::flag($db, $player, 'blocked', $n, self::BLOCK_WINDOW);
        }
    }

    // —— For the moderators (Moderation) ——

    /**
     * Queue rows as the feed gives them: names as the game names players now, account age, the signal.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function out(Db $db, array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['reported_id'];
        }
        $ids = array_values(array_unique(array_filter($ids)));
        $names = $ids === [] ? [] : (new Characters($db))->namesOf($ids);
        $age = [];
        if ($ids !== []) {
            foreach ($db->rows('SELECT ID, user_registered FROM ' . $db->table('users') . ' WHERE ID IN (?)', [$ids]) as $u) {
                $age[(int) $u['ID']] = (int) floor((time() - (int) strtotime((string) $u['user_registered'] . ' UTC')) / 86400);
            }
        }
        $who = static fn (int $id): ?array => $id > 0 ? ['id' => $id, 'name' => (string) ($names[$id] ?? "#{$id}")] : null;
        $out = [];
        foreach ($rows as $r) {
            $reported = (int) $r['reported_id'];
            $row = [
                'id' => (int) $r['id'],
                'kind' => (string) $r['kind'],
                'status' => (string) $r['status'],
                'player' => $who($reported) + ['accountDays' => $age[$reported] ?? null, 'newAccount' => isset($age[$reported]) && $age[$reported] < self::NEW_ACCOUNT_DAYS],
                'createdAt' => SaveShape::time((string) $r['created_at']),
                'updatedAt' => SaveShape::time((string) $r['updated_at']),
                'outcome' => (string) $r['outcome'] === '' ? null : ['outcome' => (string) $r['outcome'], 'note' => (string) $r['outcome_note'], 'by' => (string) $r['resolved_by'], 'at' => $r['resolved_at'] === null ? null : SaveShape::time((string) $r['resolved_at'])],
            ];
            $row += ['signal' => (string) $r['signal_name'], 'about' => self::SIGNALS[(string) $r['signal_name']] ?? '', 'n' => (int) $r['signal_n'], 'windowSeconds' => (int) $r['signal_window'], 'times' => (int) $r['times']];
            $out[] = $row;
        }
        return $out;
    }
}
