<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;

/**
 * Report #246: before the fix, a paying level-up or a repeatable imagination
 * card's purchase past the day's count (LedgerMath LEVELS_DAILY, CARD_DAILY)
 * was put off to a later day, and meanwhile what it paid went through the
 * budget and, past it, was taken back out of the pack ("Your pack was
 * corrected"). The later day credited the ledger, but the save no longer
 * held it, so the player lost it for good. This finds those losses in the
 * ledger log and, when asked, mails them back once (bin/ledger-refund.php).
 *
 * What counts, per character:
 *   - a level: a credit row whose detail has "later" (the old deferral; the
 *     fixed code logs "deferred" instead) for a level the economy table pays,
 *     once per level, with the currants a clamp row of the same write took
 *     (its own detail, or "last" when it was folded into an earlier row,
 *     Ledger::CLAMP_LOG_EVERY), at most what the level pays;
 *   - a card: a clamp row's items of a class a repeatable card hands out,
 *     at most LedgerMath::CARD_WAIT a class a UTC day (what the fixed code
 *     keeps waiting). The log cannot tell those from a forged pile, so the
 *     dry run lists them for a person to read before anything is sent.
 *
 * Applying (one transaction per character): the letters go as GardenPayout's
 * do (LedgerMail's letters from nobody, id 0, delivered at once; collecting
 * one is the ordinary ledger-safe collect), the character is marked
 * (groddle_ledger_credited kind KIND, ref REF) so it is never paid twice, and
 * the ledger log gets a "refund" row.
 */
final class LedgerRefund
{
    public const KIND = 'refund';
    public const REF = 'defer246';

    /** The letter (Claude's draft, Austin to approve). Plain, at most LedgerMail::TEXT_MAX characters, no em-dashes. */
    public const TEXT = 'Sorry! When you leveled up or bought a card, our save checker took back what it gave you and said your pack was corrected. That was our mistake, now fixed. Here it is back.';
    public const SUBJECT = 'Something we took back by mistake';

    /** The log rows the scan reads: credit rows of the old deferral, and clamp rows ($suffix: see run). */
    public static function logRows(Db $db, ?array $users = null, string $suffix = ''): array
    {
        $t = $db->table(Ledger::LOG . $suffix);
        $where = "(kind = 'credit' AND detail LIKE '%\"later\"%') OR kind = 'clamp'";
        $args = [];
        if ($users !== null) {
            if ($users === []) {
                return [];
            }
            $where = '(' . $where . ') AND user_id IN (' . implode(', ', array_fill(0, count($users), '?')) . ')';
            $args = array_map('intval', $users);
        }
        return $db->rows("SELECT id, user_id, at, kind, detail FROM {$t} WHERE {$where} ORDER BY user_id, at, id", $args);
    }

    /**
     * What each character lost, from log rows ({id, user_id, at, kind, detail}).
     * Pure, so a test can run it on fixture rows.
     *
     * @return list<array{user: int, levels: list<array{level: int, at: string, pays: int, clamped: int}>, cards: list<array{class: string, day: string, n: int}>, currants: int, items: array<string,int>}>
     */
    public static function owed(array $rows, Economy $economy): array
    {
        $levels = (array) ($economy->credits('levels') ?? []);
        $cardItems = [];
        foreach ((array) ($economy->credits('cards') ?? []) as $card) {
            if (is_array($card) && !empty($card['k']) && is_string($card['c'] ?? null)) {
                $cardItems[$card['c']] = true;
            }
        }
        $by = [];
        foreach ($rows as $r) {
            $detail = json_decode((string) $r['detail'], true);
            $by[(int) $r['user_id']][] = ['at' => strtotime((string) $r['at'] . ' UTC'), 'atText' => (string) $r['at'], 'kind' => (string) $r['kind'], 'detail' => is_array($detail) ? $detail : []];
        }
        $out = [];
        foreach ($by as $user => $list) {
            $one = ['user' => $user, 'levels' => [], 'cards' => [], 'currants' => 0, 'items' => []];
            $seen = [];
            foreach ($list as $row) {
                $level = (int) ($row['detail']['later']['level'] ?? 0);
                if ($row['kind'] !== 'credit' || $level <= 0 || isset($seen[$level])) {
                    continue;
                }
                $pays = (int) ($levels[(string) $level] ?? 0);
                if ($pays <= 0) {
                    continue;
                }
                $taken = 0;
                foreach ($list as $c) {
                    if ($c['kind'] === 'clamp' && $c['at'] <= $row['at'] && $c['at'] >= $row['at'] - Ledger::CLAMP_LOG_EVERY) {
                        $taken = max($taken, $c['at'] === $row['at'] ? self::clampedCurrants($c['detail']) : self::clampedCurrants($c['detail']['last'] ?? []));
                    }
                }
                $seen[$level] = true;
                if ($taken > 0) {
                    $n = min($pays, $taken);
                    $one['levels'][] = ['level' => $level, 'at' => $row['atText'], 'pays' => $pays, 'clamped' => $n];
                    $one['currants'] += $n;
                }
            }
            $perDay = [];
            foreach ($list as $c) {
                if ($c['kind'] !== 'clamp') {
                    continue;
                }
                $day = gmdate('Y-m-d', $c['at']);
                // A folded row's own detail is its first write; "last" is the latest one folded in.
                foreach ([$c['detail'], $c['detail']['last'] ?? []] as $i => $d) {
                    foreach ((array) ($d['clamped']['items'] ?? []) as $class => $n) {
                        if (isset($cardItems[$class]) && (int) $n > 0 && ($i === 0 || (int) ($c['detail']['repeats'] ?? 0) > 0)) {
                            $perDay[$day][$class] = min(LedgerMath::CARD_WAIT, ($perDay[$day][$class] ?? 0) + (int) $n);
                        }
                    }
                }
            }
            ksort($perDay);
            foreach ($perDay as $day => $classes) {
                ksort($classes);
                foreach ($classes as $class => $n) {
                    $one['cards'][] = ['class' => (string) $class, 'day' => (string) $day, 'n' => $n];
                    $one['items'][(string) $class] = ($one['items'][(string) $class] ?? 0) + $n;
                }
            }
            ksort($one['items']);
            if ($one['currants'] > 0 || $one['items'] !== []) {
                $out[] = $one;
            }
        }
        return $out;
    }

    private static function clampedCurrants(mixed $detail): int
    {
        return is_array($detail) ? LedgerMath::count($detail['clamped']['currants'] ?? 0) : 0;
    }

    /**
     * The letters one character's refund takes: currants in letters of at most
     * LedgerMail::CURRANTS_MAX, each class in letters of at most COUNT_MAX.
     *
     * @return list<array{class: string, count: int, currants: int}>
     */
    public static function letters(int $currants, array $items): array
    {
        $out = [];
        for ($left = $currants; $left > 0; $left -= LedgerMail::CURRANTS_MAX) {
            $out[] = ['class' => '', 'count' => 0, 'currants' => min($left, LedgerMail::CURRANTS_MAX)];
        }
        foreach ($items as $class => $n) {
            for ($left = (int) $n; $left > 0; $left -= LedgerMail::COUNT_MAX) {
                $out[] = ['class' => (string) $class, 'count' => min($left, LedgerMail::COUNT_MAX), 'currants' => 0];
            }
        }
        return $out;
    }

    /**
     * Lists (and with $apply, pays) every character's loss. Read-only unless
     * $apply. Each entry says whether it was refunded before. $suffix names
     * copies of the three tables (ledger log, credited, mail) to rehearse on,
     * e.g. "_rehearsal" for groddle_ledger_log_rehearsal; '' is the real ones.
     *
     * @return array{apply: bool, players: list<array>, letters: int}
     */
    public static function run(Db $db, Economy $economy, bool $apply, ?array $users = null, ?int $now = null, string $suffix = ''): array
    {
        if (preg_match('/^(_[a-z0-9]{1,16})?$/', $suffix) !== 1) {
            throw new \InvalidArgumentException('A table suffix is _ and up to 16 lowercase letters or digits.');
        }
        $now ??= time();
        $owed = self::owed(self::logRows($db, $users, $suffix), $economy);
        $names = (new Characters($db))->namesOf(array_column($owed, 'user'));
        $c = $db->table(Ledger::CREDITED . $suffix);
        $t = $db->table(LedgerMail::TABLE . $suffix);
        $log = $db->table(Ledger::LOG . $suffix);
        $players = [];
        $letters = 0;
        foreach ($owed as $one) {
            $user = $one['user'];
            $sent = Ledger::atomically($db, static function () use ($db, $c, $t, $log, $one, $user, $apply, $now): array {
                $before = $db->value("SELECT n FROM {$c} WHERE user_id = ? AND kind = ? AND ref = ?" . ($apply ? ' FOR UPDATE' : ''), [$user, self::KIND, self::REF]) !== null;
                $list = self::letters($one['currants'], $one['items']);
                if (!$apply || $before) {
                    return ['before' => $before, 'letters' => 0, 'planned' => count($list)];
                }
                $stamp = gmdate('Y-m-d H:i:s', $now);
                foreach ($list as $l) {
                    $db->execute("INSERT INTO {$t} (id, from_id, to_id, deliver_to, text, class_tsid, count, currants, postage, status, created_at, deliver_at) VALUES (?, 0, ?, ?, ?, ?, ?, ?, 0, 'sent', ?, ?)",
                        [bin2hex(random_bytes(8)), $user, $user, self::TEXT, $l['class'], $l['count'], $l['currants'], $stamp, $stamp]);
                }
                $db->execute("INSERT INTO {$c} (user_id, kind, ref, n, at) VALUES (?, ?, ?, ?, ?)", [$user, self::KIND, self::REF, $one['currants'] + array_sum($one['items']), $stamp]);
                $db->execute("INSERT INTO {$log} (user_id, at, kind, detail) VALUES (?, ?, ?, ?)", [$user, $stamp, self::KIND, json_encode(['ref' => self::REF, 'currants' => $one['currants'], 'items' => (object) $one['items'], 'letters' => count($list)], JSON_UNESCAPED_SLASHES)]);
                return ['before' => false, 'letters' => count($list), 'planned' => count($list)];
            });
            $letters += $sent['letters'];
            $players[] = ['user' => $user, 'name' => $names[$user] ?? '', 'currants' => $one['currants'], 'items' => (object) $one['items'], 'levels' => $one['levels'], 'cards' => $one['cards'], 'letters' => $sent['planned'], 'before' => $sent['before'], 'paid' => $apply && !$sent['before']];
        }
        return ['apply' => $apply, 'players' => $players, 'letters' => $letters];
    }
}
