<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * The moderation queue (Austin 2026-11-09: "Put Grok Bot in charge of keeping the game safe"), table
 * groddle_mod_reports: what players report and what the game's signals raise, for the moderators
 * (Moderation: Grok Bot's token and the administrators) to look at and resolve.
 *
 *   POST groddle/v1/mod/report/im {"with":9,"line":12,"at":ms,"note":"…","lines":[{id,from,text,at}…]}
 *        201 {"status":"reported","report":id} | 200 {"status":"already"}
 *        429 groddle_mod_report_slow | 403 groddle_mod_report_old (sent before reports reached moderation)
 *
 * Reporting an IM (kind "im"): a signed-in player reports a line the other player sent them. The
 * report keeps that line and the conversation around it as the reporter sees it (both sides, at most
 * EXCERPT_MAX lines: EXCERPT_BEFORE before it, EXCERPT_AFTER after), with the reporter's note, who and
 * when. The lines are the site's own kept copy (ChatHistory's groddle_im_lines, the two players' rows),
 * never the words the game sends; a line said a moment ago that has not reached the site yet (the
 * realtime server sends its lines every few seconds) is taken from the game's copy and marked
 * unverified. This is the only way an IM's words reach anyone but its two players (Austin: Grok Bot
 * and the administrators see an IM's words only when a recipient reports it):
 *
 *   - only lines at or after the option groddle_mod_im_reports_from (Unix ms, set the first time this
 *     runs: the day the privacy policy changed) are ever copied: an IM sent before stays private (the
 *     policy promised it), and a report of one is refused;
 *   - only the reporter's own conversation, only a line the other player wrote, never a purged one;
 *   - the reported player is not told, and nothing in the game changes for them;
 *   - rate: REPORT_BURST a REPORT_WINDOW and REPORT_DAY a day for one reporter, one report a line.
 *
 * Signals (kind "flag", modsignals.go in the realtime server, through ChatHistory's ingest; and
 * "blocked", from Buddies when BLOCK_FLAG players block one player within BLOCK_WINDOW): no words,
 * only the signal, its count and window, with how new the account is (accountDays; under
 * NEW_ACCOUNT_DAYS counts as new). One open row a player a signal: a repeat counts up (times).
 */
final readonly class ModReports
{
    public const TABLE = 'groddle_mod_reports';
    public const IM_FROM_OPTION = 'groddle_mod_im_reports_from';

    public const EXCERPT_BEFORE = 14;
    public const EXCERPT_AFTER = 5;
    public const EXCERPT_MAX = 20;
    public const NOTE_MAX = 500;
    /** A line the site has not got yet may be this recent (ms) for the game's copy to stand in. */
    public const UNVERIFIED_FRESH = 120000;

    /** Reports one player may make: REPORT_BURST in REPORT_WINDOW seconds, REPORT_DAY in a day. */
    public const REPORT_BURST = 5;
    public const REPORT_WINDOW = 600;
    public const REPORT_DAY = 20;

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

    public function __construct(private Seams $minn)
    {
    }

    // —— A player reports an IM ——

    #[Route(Method::Post, '/groddle/v1/mod/report/im', policy: new Policy(Access::SignedIn))]
    public function reportIm(Request $request): Response
    {
        $user = $this->minn->user() ?? throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        $me = $user->id;
        $db = $this->minn->db;
        $body = $request->json();
        $with = is_int($body['with'] ?? null) ? $body['with'] : 0;
        $line = is_int($body['line'] ?? null) ? $body['line'] : 0;
        $at = is_int($body['at'] ?? null) ? $body['at'] : 0;
        if ($with <= 0 || $with === $me || $line <= 0 || $at <= 0) {
            throw new RestError('groddle_mod_report_bad', 'That is not a line you can report.', 400);
        }
        $note = self::note($body['note'] ?? '');
        $from = self::imFrom($this->minn);
        if ($at < $from) {
            throw new RestError('groddle_mod_report_old', 'That IM was sent before reports reached our moderators, so it stays private. You can still block them.', 403);
        }
        $now = time();
        $t = $db->table(self::TABLE);
        $recent = (int) $db->value("SELECT COUNT(*) FROM {$t} WHERE reporter_id = ? AND created_at >= ?", [$me, gmdate('Y-m-d H:i:s', $now - self::REPORT_WINDOW)]);
        $day = (int) $db->value("SELECT COUNT(*) FROM {$t} WHERE reporter_id = ? AND created_at >= ?", [$me, gmdate('Y-m-d H:i:s', $now - 86400)]);
        if ($recent >= self::REPORT_BURST || $day >= self::REPORT_DAY) {
            throw new RestError('groddle_mod_report_slow', 'You have sent a lot of reports. Try again a little later.', 429);
        }
        [$a, $b] = $me < $with ? [$me, $with] : [$with, $me];
        $excerpt = self::excerpt($db, $a, $b, $me, $line, $at, $from);
        $verified = $excerpt !== null;
        if ($excerpt === null) {
            // Not on the site yet: the game's copy, if the line is fresh and the other player's.
            $excerpt = self::gameExcerpt(is_array($body['lines'] ?? null) ? $body['lines'] : [], $me, $with, $line, $at, $from, (int) (microtime(true) * 1000));
        }
        if ($excerpt === null) {
            throw new RestError('groddle_mod_report_bad', 'That is not a line you can report.', 400);
        }
        $row = $excerpt['row'];
        // One report a line (a line the site does not have yet is counted by the rate alone).
        if ($row > 0 && $db->value("SELECT 1 FROM {$t} WHERE reporter_id = ? AND kind = 'im' AND im_row = ? LIMIT 1", [$me, $row]) !== null) {
            return Response::json(['status' => 'already']);
        }
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $db->execute("INSERT INTO {$t} (kind, status, reporter_id, reported_id, im_row, note, excerpt, verified, created_at, updated_at) VALUES ('im', 'open', ?, ?, ?, ?, ?, ?, ?, ?)", [
            $me, $with, $row, $note, (string) json_encode(['a' => $a, 'b' => $b, 'line' => $line, 'at' => $at, 'lines' => $excerpt['lines']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $verified ? 1 : 0, $stamp, $stamp,
        ]);
        return Response::json(['status' => 'reported', 'report' => $db->insertId()], 201);
    }

    /** When IM reports began (Unix ms): the option, set to now the first time it is asked for. */
    public static function imFrom(Seams $minn): int
    {
        $v = $minn->site->option(self::IM_FROM_OPTION);
        if (is_numeric($v) && (int) $v > 0) {
            return (int) $v;
        }
        $now = (int) (microtime(true) * 1000);
        $minn->site->setOption(self::IM_FROM_OPTION, (string) $now);
        return $now;
    }

    public static function note(mixed $text): string
    {
        $text = is_string($text) ? mb_scrub($text, 'UTF-8') : '';
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', ' ', $text)));
        return trim(mb_substr($text, 0, self::NOTE_MAX));
    }

    /**
     * The reported line and the lines around it from the site's kept copy (the pair's rows only), or
     * null when the site does not have that line from the other player. Lines before $from never come.
     *
     * @return array{row: int, lines: list<array{id: int, from: int, text: string, at: int, parent?: int, reported?: bool}>}|null
     */
    public static function excerpt(Db $db, int $a, int $b, int $me, int $line, int $at, int $from): ?array
    {
        $t = $db->table(ChatHistory::IMS);
        $hit = $db->row("SELECT id, from_id, parent_id FROM {$t} WHERE a_id = ? AND b_id = ? AND line_id = ? AND at_ms = ? AND deleted_ms = 0", [$a, $b, $line, $at]);
        if ($hit === null || (int) $hit['from_id'] === $me) {
            return null;
        }
        $parent = (int) $hit['parent_id'];
        $before = $db->rows("SELECT id, line_id, from_id, text, at_ms, parent_id FROM {$t} WHERE a_id = ? AND b_id = ? AND parent_id = ? AND deleted_ms = 0 AND at_ms >= ? AND (at_ms < ? OR (at_ms = ? AND id < ?)) ORDER BY at_ms DESC, id DESC LIMIT " . self::EXCERPT_BEFORE, [$a, $b, $parent, $from, $at, $at, (int) $hit['id']]);
        $after = $db->rows("SELECT id, line_id, from_id, text, at_ms, parent_id FROM {$t} WHERE a_id = ? AND b_id = ? AND parent_id = ? AND deleted_ms = 0 AND (at_ms > ? OR (at_ms = ? AND id > ?)) ORDER BY at_ms ASC, id ASC LIMIT " . self::EXCERPT_AFTER, [$a, $b, $parent, $at, $at, (int) $hit['id']]);
        $self = $db->row("SELECT id, line_id, from_id, text, at_ms, parent_id FROM {$t} WHERE id = ?", [(int) $hit['id']]);
        $rows = [...array_reverse($before), $self, ...$after];
        $out = [];
        foreach (array_slice($rows, 0, self::EXCERPT_MAX) as $r) {
            $l = ['id' => (int) $r['line_id'], 'from' => (int) $r['from_id'], 'text' => (string) $r['text'], 'at' => (int) $r['at_ms']];
            if ((int) $r['parent_id'] > 0) {
                $l['parent'] = (int) $r['parent_id'];
            }
            if ((int) $r['id'] === (int) $hit['id']) {
                $l['reported'] = true;
            }
            $out[] = $l;
        }
        return ['row' => (int) $hit['id'], 'lines' => $out];
    }

    /**
     * The game's copy of the conversation, for a line the site has not got yet: taken only when the
     * reported line is the other player's, at most UNVERIFIED_FRESH old, and every line kept is from one
     * of the two, at or after $from, at most EXCERPT_MAX of them ending at the reported line's neighbours.
     *
     * @param array<mixed> $lines
     * @return array{row: int, lines: list<array<string, mixed>>}|null
     */
    public static function gameExcerpt(array $lines, int $me, int $with, int $line, int $at, int $from, int $nowMs): ?array
    {
        if ($nowMs - $at > self::UNVERIFIED_FRESH || $at > $nowMs + 60000) {
            return null;
        }
        $clean = [];
        foreach (array_slice($lines, -60) as $l) {
            if (!is_array($l) || !is_int($l['id'] ?? null) || !is_int($l['from'] ?? null) || !is_int($l['at'] ?? null) || !is_string($l['text'] ?? null)) {
                continue;
            }
            if (($l['from'] !== $me && $l['from'] !== $with) || $l['at'] < $from || $l['id'] <= 0) {
                continue;
            }
            $clean[] = ['id' => $l['id'], 'from' => $l['from'], 'text' => mb_substr(trim($l['text']), 0, ChatHistory::TEXT_MAX), 'at' => $l['at']];
        }
        $i = null;
        foreach ($clean as $k => $l) {
            if ($l['id'] === $line && $l['at'] === $at && $l['from'] === $with) {
                $i = $k;
            }
        }
        if ($i === null) {
            return null;
        }
        $clean[$i]['reported'] = true;
        $start = max(0, $i - self::EXCERPT_BEFORE);
        return ['row' => 0, 'lines' => array_slice($clean, $start, min(self::EXCERPT_MAX, $i - $start + 1 + self::EXCERPT_AFTER))];
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
        return $db->execute("INSERT INTO {$t} (kind, status, reporter_id, reported_id, signal_name, signal_n, signal_window, created_at, updated_at) VALUES ('flag', 'open', 0, ?, ?, ?, ?, ?, ?)", [$player, $signal, max(0, $n), max(0, $window), $stamp, $stamp]);
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
     * Queue rows as the feed gives them: names as the game names players now, account age, an IM
     * report's excerpt with each line's speaker.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function out(Db $db, array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['reporter_id'];
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
            if ($r['kind'] === 'im') {
                $ex = json_decode((string) $r['excerpt'], true);
                $lines = [];
                foreach (is_array($ex['lines'] ?? null) ? $ex['lines'] : [] as $l) {
                    $from = (int) ($l['from'] ?? 0);
                    $lines[] = ['from' => $who($from), 'text' => (string) ($l['text'] ?? ''), 'at' => (int) ($l['at'] ?? 0), 'reported' => ($l['reported'] ?? false) === true] + (isset($l['parent']) ? ['thread' => true] : []);
                }
                $row += ['reporter' => $who((int) $r['reporter_id']), 'note' => (string) $r['note'], 'verified' => (int) $r['verified'] === 1, 'lines' => $lines, 'lineDeletable' => (int) $r['im_row'] > 0];
            } else {
                $row += ['signal' => (string) $r['signal_name'], 'about' => self::SIGNALS[(string) $r['signal_name']] ?? '', 'n' => (int) $r['signal_n'], 'windowSeconds' => (int) $r['signal_window'], 'times' => (int) $r['times']];
            }
            $out[] = $row;
        }
        return $out;
    }
}
