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
 * G895 (#432, ref REF_G895): before the fix, gains the save's own records
 * earned (a shrine's emblems, a donation's Gameshow Ticket, a cracked rock's
 * beads and pieces) and capped drops the budget was short for (Musicblocks)
 * were paid from the budget alone, and a drained budget took them back. What
 * counts, per character: a clamp row's items (its own detail and, when
 * writes were folded into it, "last") from G895_FROM on (the v0.43.0 deploy;
 * since= moves it) of those kinds only, within the fixed rules' own counts a
 * UTC day (G895_EMBLEM_DAY emblems, TICKET_DAILY tickets, a capped class its
 * cap). Ordinary gains (crops, currants) are not in it: the fix does not
 * credit those either.
 *
 * G905 (#436, #438, #439, ref REF_G905): before the fix, timed production
 * (machine output, garden crops), an Elemental Shake's elements, a new
 * player's tutorial gifts and rare finds the budget was short for (uncapped
 * Musicblocks, gems) were taken back. What counts, per character: a clamp
 * row's items (its own detail and "last" when writes were folded in) from
 * G905_FROM on, of those kinds only, within fixed counts: a tutorial class
 * its sets' counts once (LedgerMath::TUTORIAL_SETS; the island's Gameshow Ticket counts as a ticket), an element SHAKE_DAILY
 * shakes' worth a UTC day, timed output G905_TIMED_DAY of value a UTC day, a
 * rare find G905_DROP_DAY of value a UTC day, a Gameshow Ticket a drop table
 * gave (#438) its dcap a UTC day. Rows G895's refunds already
 * paid are never paid again: for a character marked refund/g895, the G895
 * kinds (emblems, Gameshow Tickets, capped classes) up to that mark's time;
 * for one marked refund/g895-hand (Austin's hand refund: potatoes, Dullite,
 * Earth and currants), those classes up to its time. Planks, apples, Plain
 * Metal and currants are not in it: no G905 rule credits them.
 *
 * Applying (one transaction per character): the letters go as GardenPayout's
 * do (LedgerMail's letters from nobody, id 0, delivered at once; collecting
 * one is the ordinary ledger-safe collect), the character is marked
 * (groddle_ledger_credited kind KIND, ref REF or REF_G895) so it is never
 * paid twice, and the ledger log gets a "refund" row.
 */
final class LedgerRefund
{
    public const KIND = 'refund';
    public const REF = 'defer246';

    /** The letter (Claude's draft, Austin to approve). Plain, at most LedgerMail::TEXT_MAX characters, no em-dashes. */
    public const TEXT = 'Sorry! When you leveled up or bought a card, our save checker took back what it gave you and said your pack was corrected. That was our mistake, now fixed. Here it is back.';
    public const SUBJECT = 'Something we took back by mistake';

    /** G895 (#432): the ref, the letter (plain, no em-dashes; the same subject) and where the scan starts (the v0.43.0 deploy, UTC). */
    public const REF_G895 = 'g895';
    public const TEXT_G895 = 'Sorry! Our save checker took back something you had earned and said your pack was corrected. That was our mistake, now fixed. Here it is back.';
    public const G895_FROM = '2026-11-16 15:50:00';

    /**
     * The G895 fix's emblem count a UTC day for all Giants (LedgerMath EMBLEM_DAILY until G1299 took
     * the count away, 2026-11-25): what owedG895 pays within, so the refund it already paid stays the
     * same refund.
     */
    public const G895_EMBLEM_DAY = 4;

    /** G905: the ref, the letter (plain, no em-dashes; the same subject) and where the scan starts (the v0.43.0 deploy, UTC). */
    public const REF_G905 = 'g905';
    public const TEXT_G905 = 'Sorry! Our save checker took back things you had made, grown, shaken or found and said your pack was corrected. That was our mistake, now fixed. Here they are back.';
    public const G905_FROM = '2026-11-16 15:50:00';

    /** G905: Austin's hand refund after G895 (credited refund/g895-hand) and the classes it paid back (with 21 currants). */
    public const REF_G895_HAND = 'g895-hand';
    public const G895_HAND_CLASSES = ['dullite', 'earth', 'potato'];

    /**
     * G905, ours: the most value of timed output (a machine's, a garden's) and of rare finds refunded a
     * UTC day per character. A day of machine room jobs and harvests is a few thousand; the cap only
     * keeps a forged pile from being cashed in.
     */
    public const G905_TIMED_DAY = 20000;
    public const G905_DROP_DAY = 12000;

    /** The refunds run knows: ref => [the letter, the scan]. */
    public const REFS = [self::REF => self::TEXT, self::REF_G895 => self::TEXT_G895, self::REF_G905 => self::TEXT_G905];

    /** The log rows the scan reads: credit rows of the old deferral, and clamp rows ($suffix: see run); for G895 clamp rows from $from on. */
    public static function logRows(Db $db, ?array $users = null, string $suffix = '', string $ref = self::REF, string $from = self::G895_FROM): array
    {
        $t = $db->table(Ledger::LOG . $suffix);
        $where = "(kind = 'credit' AND detail LIKE '%\"later\"%') OR kind = 'clamp'";
        $args = [];
        if ($ref === self::REF_G895 || $ref === self::REF_G905) {
            $where = "kind = 'clamp' AND at >= ?";
            $args = [$from];
        }
        if ($users !== null) {
            if ($users === []) {
                return [];
            }
            $where = '(' . $where . ') AND user_id IN (' . implode(', ', array_fill(0, count($users), '?')) . ')';
            $args = array_merge($args, array_map('intval', $users));
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

    /**
     * G895: what each character lost of the kinds the fix now credits or lets wait, from clamp rows
     * ({id, user_id, at, kind, detail}) from $from on, within each rule's count a UTC day: a shrine's
     * emblems (G895_EMBLEM_DAY for all Giants), Gameshow Tickets (TICKET_DAILY), a capped class (beads,
     * artifact pieces, Musicblocks ...) its cap. Pure, so a test can run it on fixture rows. Each
     * entry has the shape owed() gives (levels and cards empty, currants 0) plus days: what each UTC
     * day's rows took, after the counts.
     *
     * @return list<array{user: int, levels: list<array>, cards: list<array>, currants: int, items: array<string,int>, days: list<array{day: string, class: string, n: int}>}>
     */
    public static function owedG895(array $rows, Economy $economy, string $from = self::G895_FROM): array
    {
        $since = strtotime($from . ' UTC');
        $rule = static function (string $c) use ($economy): ?array {
            if (str_starts_with($c, 'emblem_') && $economy->tradeable($c)) {
                return ['emblem', self::G895_EMBLEM_DAY];
            }
            if ($c === LedgerMath::TICKET_CLASS) {
                return ['ticket', LedgerMath::TICKET_DAILY];
            }
            return $economy->cap($c) > 0 ? ["cap:{$c}", $economy->cap($c)] : null;
        };
        $by = [];
        foreach ($rows as $r) {
            $at = strtotime((string) $r['at'] . ' UTC');
            if ((string) $r['kind'] !== 'clamp' || $at === false || $at < $since) {
                continue;
            }
            $detail = json_decode((string) $r['detail'], true);
            $by[(int) $r['user_id']][] = ['at' => $at, 'detail' => is_array($detail) ? $detail : []];
        }
        ksort($by);
        $out = [];
        foreach ($by as $user => $list) {
            usort($list, static fn ($a, $b) => $a['at'] <=> $b['at']);
            $used = [];
            $took = [];
            foreach ($list as $c) {
                $day = gmdate('Y-m-d', $c['at']);
                // A folded row's own detail is its first write; "last" is the latest one folded in.
                foreach ([$c['detail'], $c['detail']['last'] ?? []] as $i => $d) {
                    if ($i === 1 && (int) ($c['detail']['repeats'] ?? 0) <= 0) {
                        continue;
                    }
                    $items = is_array($d) ? (array) ($d['clamped']['items'] ?? []) : [];
                    ksort($items);
                    foreach ($items as $class => $n) {
                        $class = (string) $class;
                        $kind = $rule($class);
                        $n = LedgerMath::count($n);
                        if ($kind === null || $n <= 0) {
                            continue;
                        }
                        $k = min($n, max(0, $kind[1] - ($used[$day][$kind[0]] ?? 0)));
                        if ($k > 0) {
                            $used[$day][$kind[0]] = ($used[$day][$kind[0]] ?? 0) + $k;
                            $took[$day][$class] = ($took[$day][$class] ?? 0) + $k;
                        }
                    }
                }
            }
            $one = ['user' => $user, 'levels' => [], 'cards' => [], 'currants' => 0, 'items' => [], 'days' => []];
            ksort($took);
            foreach ($took as $day => $classes) {
                ksort($classes);
                foreach ($classes as $class => $n) {
                    $one['days'][] = ['day' => (string) $day, 'class' => (string) $class, 'n' => $n];
                    $one['items'][(string) $class] = ($one['items'][(string) $class] ?? 0) + $n;
                }
            }
            ksort($one['items']);
            if ($one['items'] !== []) {
                $out[] = $one;
            }
        }
        return $out;
    }

    /** G938: tutorial sets added after G905's refund window, left out of what it owes. */
    public const G905_LATER_SETS = ['startblock', 'musicblockgift'];

    /**
     * G905: what each character lost of the kinds G905 credits or lets wait, from clamp rows
     * ({id, user_id, at, kind, detail}) from $from on, within the fixed counts (see the class comment),
     * never the rows G895's refunds paid ($paid: user -> ref -> the time that refund was applied, UTC
     * "Y-m-d H:i:s"). Pure, so a test can run it on fixture rows; the entries have owedG895's shape.
     *
     * @param array<int, array<string, string>> $paid
     * @return list<array{user: int, levels: list<array>, cards: list<array>, currants: int, items: array<string,int>, days: list<array{day: string, class: string, n: int}>}>
     */
    public static function owedG905(array $rows, Economy $economy, string $from = self::G905_FROM, array $paid = []): array
    {
        $since = strtotime($from . ' UTC');
        $tutorial = [];
        foreach (LedgerMath::TUTORIAL_SETS as $set => $def) {
            // G938's sets came after the window this refund covers (their gains did not exist then).
            if (in_array($set, self::G905_LATER_SETS, true)) {
                continue;
            }
            foreach ($def['items'] as $c => $n) {
                // The island's Musicblock is a rare find below; a Gameshow Ticket is G895's (its donations rule).
                if ($c !== 'musicblock' && $c !== LedgerMath::TICKET_CLASS) {
                    $tutorial[(string) $c] = ($tutorial[(string) $c] ?? 0) + (int) $n;
                }
            }
        }
        $shake = LedgerMath::SHAKE_DAILY * (int) round(LedgerMath::ELEMENT_STACK * max(LedgerMath::SHAKE_CARDS));
        // [the count's key, its size, true when it counts value (else pieces), true when it is once ever (else a UTC day)]
        $rule = static function (string $c) use ($economy, $tutorial, $shake): ?array {
            if (isset($tutorial[$c])) {
                return ["tut:{$c}", $tutorial[$c], false, true];
            }
            if (in_array($c, LedgerMath::SHAKE_ELEMENTS, true)) {
                return ["shake:{$c}", $shake, false, false];
            }
            if ($economy->timedOutput($c)) {
                return ['timed', self::G905_TIMED_DAY, true, false];
            }
            // #438: a Gameshow Ticket a drop table gave (mining, a harvest), within its day (dcap).
            if ($c === LedgerMath::TICKET_CLASS && $economy->dayCap($c) > 0) {
                return ['ticket', $economy->dayCap($c), false, false];
            }
            if ($economy->drop($c) && !str_starts_with($c, 'emblem_')) {
                return ['drop', self::G905_DROP_DAY, true, false];
            }
            return null;
        };
        // What G895's refunds paid: its kinds up to refund/g895, the hand refund's classes up to refund/g895-hand.
        $paidBy = static function (int $user, string $c, int $at) use ($paid, $economy): bool {
            $g895 = isset($paid[$user][self::REF_G895]) ? strtotime($paid[$user][self::REF_G895] . ' UTC') : false;
            if ($g895 !== false && $at <= $g895 && (str_starts_with($c, 'emblem_') || $c === LedgerMath::TICKET_CLASS || $economy->cap($c) > 0)) {
                return true;
            }
            $hand = isset($paid[$user][self::REF_G895_HAND]) ? strtotime($paid[$user][self::REF_G895_HAND] . ' UTC') : false;
            return $hand !== false && $at <= $hand && in_array($c, self::G895_HAND_CLASSES, true);
        };
        $by = [];
        foreach ($rows as $r) {
            $at = strtotime((string) $r['at'] . ' UTC');
            if ((string) $r['kind'] !== 'clamp' || $at === false || $at < $since) {
                continue;
            }
            $detail = json_decode((string) $r['detail'], true);
            $by[(int) $r['user_id']][] = ['at' => $at, 'id' => (int) ($r['id'] ?? 0), 'detail' => is_array($detail) ? $detail : []];
        }
        ksort($by);
        $out = [];
        foreach ($by as $user => $list) {
            usort($list, static fn ($a, $b) => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);
            $used = [];
            $took = [];
            foreach ($list as $c) {
                $day = gmdate('Y-m-d', $c['at']);
                foreach ([$c['detail'], $c['detail']['last'] ?? []] as $i => $d) {
                    if ($i === 1 && (int) ($c['detail']['repeats'] ?? 0) <= 0) {
                        continue;
                    }
                    $items = is_array($d) ? (array) ($d['clamped']['items'] ?? []) : [];
                    ksort($items);
                    foreach ($items as $class => $n) {
                        $class = (string) $class;
                        $kind = $rule($class);
                        $n = LedgerMath::count($n);
                        if ($kind === null || $n <= 0 || $paidBy($user, $class, $c['at'])) {
                            continue;
                        }
                        [$key, $size, $byValue, $once] = $kind;
                        $slot = $once ? 'once' : $day;
                        $v = $byValue ? $economy->value($class) : 1.0;
                        $room = $size - ($used[$slot][$key] ?? 0);
                        $k = (int) min($n, floor(($room + 1e-9) / $v));
                        if ($k > 0) {
                            $used[$slot][$key] = ($used[$slot][$key] ?? 0) + $k * $v;
                            $took[$day][$class] = ($took[$day][$class] ?? 0) + $k;
                        }
                    }
                }
            }
            $one = ['user' => $user, 'levels' => [], 'cards' => [], 'currants' => 0, 'items' => [], 'days' => []];
            ksort($took);
            foreach ($took as $day => $classes) {
                ksort($classes);
                foreach ($classes as $class => $n) {
                    $one['days'][] = ['day' => (string) $day, 'class' => (string) $class, 'n' => $n];
                    $one['items'][(string) $class] = ($one['items'][(string) $class] ?? 0) + $n;
                }
            }
            ksort($one['items']);
            if ($one['items'] !== []) {
                $out[] = $one;
            }
        }
        return $out;
    }

    /**
     * G905: when each character's G895 refunds were applied (credited refund/g895 and refund/g895-hand),
     * user -> ref -> "Y-m-d H:i:s" (UTC).
     *
     * @return array<int, array<string, string>>
     */
    public static function paidG895(Db $db, string $suffix = ''): array
    {
        $out = [];
        foreach ($db->rows('SELECT user_id, ref, at FROM ' . $db->table(Ledger::CREDITED . $suffix) . ' WHERE kind = ? AND ref IN (?, ?)', [self::KIND, self::REF_G895, self::REF_G895_HAND]) as $r) {
            $out[(int) $r['user_id']][(string) $r['ref']] = (string) $r['at'];
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
     * $ref picks the refund (REF, #246; REF_G895 or REF_G905 from $from on, UTC).
     *
     * @return array{apply: bool, ref: string, players: list<array>, letters: int}
     */
    public static function run(Db $db, Economy $economy, bool $apply, ?array $users = null, ?int $now = null, string $suffix = '', string $ref = self::REF, ?string $from = null): array
    {
        if (preg_match('/^(_[a-z0-9]{1,16})?$/', $suffix) !== 1) {
            throw new \InvalidArgumentException('A table suffix is _ and up to 16 lowercase letters or digits.');
        }
        if (!isset(self::REFS[$ref])) {
            throw new \InvalidArgumentException('Not a refund this knows: ' . implode(', ', array_keys(self::REFS)) . '.');
        }
        $from ??= $ref === self::REF_G905 ? self::G905_FROM : self::G895_FROM;
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $from) !== 1) {
            throw new \InvalidArgumentException('since= is a UTC time, YYYY-MM-DD HH:MM:SS.');
        }
        $text = self::REFS[$ref];
        $now ??= time();
        $rows = self::logRows($db, $users, $suffix, $ref, $from);
        $owed = match ($ref) {
            self::REF_G895 => self::owedG895($rows, $economy, $from),
            self::REF_G905 => self::owedG905($rows, $economy, $from, self::paidG895($db, $suffix)),
            default => self::owed($rows, $economy),
        };
        $names = (new Characters($db))->namesOf(array_column($owed, 'user'));
        $c = $db->table(Ledger::CREDITED . $suffix);
        $t = $db->table(LedgerMail::TABLE . $suffix);
        $log = $db->table(Ledger::LOG . $suffix);
        $players = [];
        $letters = 0;
        foreach ($owed as $one) {
            $user = $one['user'];
            $sent = Ledger::atomically($db, static function () use ($db, $c, $t, $log, $one, $user, $apply, $now, $ref, $text): array {
                $before = $db->value("SELECT n FROM {$c} WHERE user_id = ? AND kind = ? AND ref = ?" . ($apply ? ' FOR UPDATE' : ''), [$user, self::KIND, $ref]) !== null;
                $list = self::letters($one['currants'], $one['items']);
                if (!$apply || $before) {
                    return ['before' => $before, 'letters' => 0, 'planned' => count($list)];
                }
                $stamp = gmdate('Y-m-d H:i:s', $now);
                foreach ($list as $l) {
                    $db->execute("INSERT INTO {$t} (id, from_id, to_id, deliver_to, text, class_tsid, count, currants, postage, status, created_at, deliver_at) VALUES (?, 0, ?, ?, ?, ?, ?, ?, 0, 'sent', ?, ?)",
                        [bin2hex(random_bytes(8)), $user, $user, $text, $l['class'], $l['count'], $l['currants'], $stamp, $stamp]);
                }
                $db->execute("INSERT INTO {$c} (user_id, kind, ref, n, at) VALUES (?, ?, ?, ?, ?)", [$user, self::KIND, $ref, $one['currants'] + array_sum($one['items']), $stamp]);
                $db->execute("INSERT INTO {$log} (user_id, at, kind, detail) VALUES (?, ?, ?, ?)", [$user, $stamp, self::KIND, json_encode(['ref' => $ref, 'currants' => $one['currants'], 'items' => (object) $one['items'], 'letters' => count($list)], JSON_UNESCAPED_SLASHES)]);
                return ['before' => false, 'letters' => count($list), 'planned' => count($list)];
            });
            $letters += $sent['letters'];
            $players[] = ['user' => $user, 'name' => $names[$user] ?? '', 'currants' => $one['currants'], 'items' => (object) $one['items'], 'levels' => $one['levels'], 'cards' => $one['cards']] + (isset($one['days']) ? ['days' => $one['days']] : []) + ['letters' => $sent['planned'], 'before' => $sent['before'], 'paid' => $apply && !$sent['before']];
        }
        return ['apply' => $apply, 'ref' => $ref, 'players' => $players, 'letters' => $letters];
    }
}
