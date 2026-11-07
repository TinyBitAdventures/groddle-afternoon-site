<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;
use Minn\Json;
use Minn\RestError;

/**
 * Credit for bug reports that led to a change (Austin 2026-11-03: "rank
 * Glitchen who have submitted reports which are tied to a bug fix or
 * improvement"). One row per ticket in groddle_report_credits (minn.json):
 * how many points it earned, the kind of change it led to, a short reason
 * and the commits or releases that carry the change (refs, JSON), and who
 * scored it: 'agent' (groddle-afternoon tools/reports/credits.mjs, the
 * scoring agent's proposals once Austin has looked at them) or 'admin' (the
 * console's Credit box). Scoring again replaces the row.
 *
 * The rubric: a fix that kept saves, accounts or security safe 5, a bug
 * fixed 3, an idea that shipped 2, a clue or a partial fix 1, nothing 0
 * (working as Glitch intended, the same player's repeat, withdrawn). A report with several
 * changes in it scores its best one plus 1 for each other, at most CAP.
 *
 * The Contribute page's board (ContributeCredits) ranks players by their
 * total: more points first, then more reports that led to a change, then
 * whoever's first such report came earliest (the ticket's created_at), then
 * the lower id. Banned players, the accounts in EXCLUDED (Austin's own: 1
 * and 54) and players with no points are left off it. The rules are static
 * and pure so the game's tests run them without a database
 * (tests/fixtures/minn-accounts/credits.php).
 */
final readonly class ReportCredits
{
    public const TABLE = 'groddle_report_credits';

    /** Each kind of change and the points it is worth. */
    public const KINDS = [
        'safety_fix' => 5,
        'bug_fixed' => 3,
        'improvement_shipped' => 2,
        'partial_or_clue' => 1,
        'none' => 0,
    ];

    /** The kinds in the console's words. */
    public const KIND_WORDS = [
        'safety_fix' => 'Safety fix',
        'bug_fixed' => 'Bug fixed',
        'improvement_shipped' => 'Improvement shipped',
        'partial_or_clue' => 'Partial fix or clue',
        'none' => 'No change',
    ];

    /** The most one report can earn (its best change, plus 1 for each other). */
    public const CAP = 8;

    public const REASON_MAX = 200;
    public const REFS_MAX = 12;
    public const REF_MAX = 64;

    public const AGENT = 'agent';
    public const ADMIN = 'admin';

    /** Players on the board. */
    public const BOARD = 10;

    /** Accounts never on the board: Austin's (anchorhost, and the review account). */
    public const EXCLUDED = [1, 54];

    /** The ticket statuses a report can be scored in: done with, or answered. */
    public const SCORABLE = [TicketStore::CLOSED, TicketStore::ANSWERED];

    public function __construct(private Db $db)
    {
    }

    // —— The rules ——

    /**
     * A credit as given to PUT admin/tickets/{id}/credit: {"kind"?, "points"?,
     * "reason", "refs"?, "by"?}. The kind's own points when no points are
     * given; points alone name their kind when they are one kind's points.
     * Points run 0 to CAP, and no points is exactly kind none.
     *
     * @return array{kind: string, points: int, reason: string, refs: list<string>, by: string}
     */
    public static function input(array $body): array
    {
        $kind = $body['kind'] ?? null;
        $points = $body['points'] ?? null;
        if ($kind !== null && (!is_string($kind) || !array_key_exists($kind, self::KINDS))) {
            throw new RestError('groddle_credit_kind', 'That is not a kind of change: safety_fix, bug_fixed, improvement_shipped, partial_or_clue or none.', 400);
        }
        if ($points !== null && (!is_int($points) || $points < 0 || $points > self::CAP)) {
            throw new RestError('groddle_credit_points', 'Points are a whole number from 0 to ' . self::CAP . '.', 400);
        }
        if ($kind === null && $points === null) {
            throw new RestError('groddle_credit_kind', 'Say what kind of change the report led to.', 400);
        }
        if ($kind === null) {
            $kind = array_search($points, self::KINDS, true);
            if ($kind === false) {
                throw new RestError('groddle_credit_kind', "Say what kind of change {$points} points is for.", 400);
            }
        }
        $points ??= self::KINDS[$kind];
        if (($points === 0) !== ($kind === 'none')) {
            throw new RestError('groddle_credit_points', $kind === 'none' ? 'A report that changed nothing has no points.' : 'A report that led to a change has at least 1 point.', 400);
        }
        $reason = $body['reason'] ?? '';
        // One plain line, refused rather than cut when it is too long.
        $reason = is_string($reason) ? trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', ' ', mb_scrub($reason, 'UTF-8')))) : '';
        if ($reason === '') {
            throw new RestError('groddle_credit_reason', 'Write a short reason.', 400);
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new RestError('groddle_credit_reason', 'A reason is at most ' . self::REASON_MAX . ' characters.', 400);
        }
        $by = $body['by'] ?? self::ADMIN;
        if ($by !== self::ADMIN && $by !== self::AGENT) {
            throw new RestError('groddle_credit_by', 'Scored by is agent or admin.', 400);
        }
        return ['kind' => $kind, 'points' => $points, 'reason' => $reason, 'refs' => self::refs($body['refs'] ?? []), 'by' => $by];
    }

    /**
     * Refs as kept: plain one-line strings (a commit id, a release tag,
     * "game v0.12.0"), each at most REF_MAX characters, at most REFS_MAX of
     * them, blanks and repeats dropped. A list, or one string split on commas.
     *
     * @return list<string>
     */
    public static function refs(mixed $refs): array
    {
        if (is_string($refs)) {
            $refs = explode(',', $refs);
        }
        if (!is_array($refs) || !array_is_list($refs)) {
            throw new RestError('groddle_credit_refs', 'Refs are a list of commit ids or release tags.', 400);
        }
        $out = [];
        foreach ($refs as $ref) {
            if (!is_string($ref)) {
                throw new RestError('groddle_credit_refs', 'Each ref is text: a commit id or a release tag.', 400);
            }
            $ref = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', ' ', mb_scrub($ref, 'UTF-8'))));
            if ($ref === '') {
                continue;
            }
            if (mb_strlen($ref) > self::REF_MAX) {
                throw new RestError('groddle_credit_refs', 'A ref is at most ' . self::REF_MAX . ' characters.', 400);
            }
            if (!in_array($ref, $out, true)) {
                $out[] = $ref;
            }
        }
        if (count($out) > self::REFS_MAX) {
            throw new RestError('groddle_credit_refs', 'At most ' . self::REFS_MAX . ' refs.', 400);
        }
        return $out;
    }

    /** A credit row as the console reads it. */
    public static function out(array $row): array
    {
        $refs = Json::decode((string) ($row['refs'] ?? '[]'), or: []);
        return [
            'ticket' => (int) $row['ticket_id'],
            'points' => (int) $row['points'],
            'kind' => (string) $row['kind'],
            'reason' => (string) $row['reason'],
            'refs' => is_array($refs) ? array_values(array_filter($refs, 'is_string')) : [],
            'by' => (string) $row['scored_by'],
            'at' => SaveShape::time((string) $row['scored_at']),
        ];
    }

    /**
     * The board from each player's totals ({id, points, reports, first,
     * banned}; first is when their first report that led to a change was
     * sent): players with points, not banned and not EXCLUDED, most points
     * first, then more reports, then the earliest first report, then the lower
     * id; at most $limit.
     *
     * @param list<array{id: int, points: int, reports: int, first: ?string, banned?: bool}> $totals
     * @return list<array{id: int, points: int, reports: int, first: ?string, banned?: bool}>
     */
    public static function rank(array $totals, int $limit = self::BOARD): array
    {
        $rows = array_values(array_filter($totals, static fn (array $t): bool => (int) $t['points'] > 0
            && empty($t['banned'])
            && !in_array((int) $t['id'], self::EXCLUDED, true)));
        usort($rows, static fn (array $a, array $b): int => [(int) $b['points'], (int) $b['reports'], (string) ($a['first'] ?? '9999'), (int) $a['id']]
            <=> [(int) $a['points'], (int) $a['reports'], (string) ($b['first'] ?? '9999'), (int) $b['id']]);
        return array_slice($rows, 0, max(0, $limit));
    }

    // —— The table ——

    public function find(int $ticket): ?array
    {
        return $this->db->row('SELECT * FROM ' . $this->db->table(self::TABLE) . ' WHERE ticket_id = ?', [$ticket]);
    }

    /** Scores a ticket, replacing any earlier score. $credit is input()'s. */
    public function put(int $ticket, int $player, array $credit, int $now): void
    {
        $this->db->execute('REPLACE INTO ' . $this->db->table(self::TABLE) . ' (ticket_id, player_user_id, points, kind, reason, refs, scored_by, scored_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $ticket, $player, $credit['points'], $credit['kind'], $credit['reason'], json_encode($credit['refs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $credit['by'], gmdate('Y-m-d H:i:s', $now),
        ]);
    }

    /** Every credit, newest scored first, with its ticket's subject, status and player. */
    public function all(): array
    {
        $t = $this->db->table(TicketStore::TABLE);
        return $this->db->rows('SELECT c.*, t.subject, t.status, t.created_at AS ticket_created_at FROM ' . $this->db->table(self::TABLE) . " c JOIN {$t} t ON t.id = c.ticket_id ORDER BY c.scored_at DESC, c.ticket_id DESC");
    }

    /** Closed and answered tickets nobody has scored yet, oldest first (not Austin's own, EXCLUDED: they are never on the board). */
    public function unscored(): array
    {
        $t = $this->db->table(TicketStore::TABLE);
        return $this->db->rows("SELECT t.id, t.player_user_id, t.status, t.subject, t.created_at FROM {$t} t LEFT JOIN " . $this->db->table(self::TABLE) . ' c ON c.ticket_id = t.id WHERE c.ticket_id IS NULL AND t.status IN (?) AND t.player_user_id NOT IN (?) ORDER BY t.id ASC', [self::SCORABLE, self::EXCLUDED]);
    }

    /**
     * Each player's totals for rank(): points, reports that led to a change,
     * when the first of those was sent, and whether they are banned. Players
     * whose account is gone are left out.
     *
     * @return list<array{id: int, points: int, reports: int, first: ?string, banned: bool}>
     */
    public function totals(): array
    {
        $db = $this->db;
        $rows = $db->rows('SELECT c.player_user_id AS id, SUM(c.points) AS points, SUM(CASE WHEN c.points > 0 THEN 1 ELSE 0 END) AS reports,'
            . ' MIN(CASE WHEN c.points > 0 THEN t.created_at END) AS first, MAX(b.user_id) AS banned'
            . ' FROM ' . $db->table(self::TABLE) . ' c'
            . ' JOIN ' . $db->table(TicketStore::TABLE) . ' t ON t.id = c.ticket_id'
            . ' JOIN ' . $db->table('users') . ' u ON u.ID = c.player_user_id'
            . ' LEFT JOIN ' . $db->table(AdminConsole::BANS) . ' b ON b.user_id = c.player_user_id'
            . ' GROUP BY c.player_user_id');
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'points' => (int) $r['points'],
            'reports' => (int) $r['reports'],
            'first' => $r['first'] === null ? null : (string) $r['first'],
            'banned' => $r['banned'] !== null,
        ], $rows);
    }
}
