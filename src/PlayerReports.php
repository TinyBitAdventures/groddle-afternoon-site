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
 * A player's report of another player (register G872, report #417, Austin 2026-11-16: "public chat
 * only, never IMs"): Report on the player menu in the game (under Block), as
 * Glitch's Report Abuse (glitch-client TSFrontController.as startReportAbuseDialog 2645-2680,
 * reportAbuse 2602-2630; InteractionMenuController.as 869-875).
 *
 *   POST groddle/v1/players/{id}/report  {"note"?}   (signed in)
 *        201 {"status":"reported","ticket":id,"lines":n}
 *        400 groddle_report_self, 404 groddle_report_no_player, 429 groddle_report_again (that player,
 *        within PER_TARGET_SECONDS), groddle_report_slow (PER_DAY in a day, or the bug reports' limits)
 *
 * A report is a ticket like a bug report or a note report (TicketStore: the reporter's Reports sheet,
 * where the administrators can answer; the console's Reports tab, AdminTickets), with the reporter's
 * note in its first message, and a row of TABLE beside it: who was reported and the public chat lines
 * the reporter could see in the last half hour, which the realtime server answers from its chat log
 * (groddle-afternoon-server reports.go, the signed report_lines call: street chat where the reporter
 * stood, the Global, Live Help and Trade channels they are in, party lines sent to them). The chat log
 * never holds an IM, and lines() keeps only those three kinds, so no IM's words reach a report, an
 * administrator or Grok Bot (Austin 2026-11-09, register G607). The lines are kept apart from the
 * message so the reporter's edit of their words (Tickets edit) never touches them, and only the
 * administrators see them (AdminTickets thread "playerReport"); the reporter's own Reports sheet shows
 * their words. Names only: no email, IP or login reaches what is shown.
 *
 * Limits are per login (Logins: a player's characters count together): PER_DAY reports a day and one
 * report of the same player each PER_TARGET_SECONDS, on top of the bug reports' own (a report is a ticket).
 */
final readonly class PlayerReports
{
    public const TABLE = 'groddle_player_reports';

    public const NOTE_MAX = 500;
    /** Player reports from one login in a day. */
    public const PER_DAY = 5;
    /** One report of the same player from one login this often. */
    public const PER_TARGET_SECONDS = 3600;
    /** Lines kept with a report (reports.go reportMaxLines). */
    public const LINES_MAX = 50;
    /** The chat kinds a report may carry: never an IM. */
    public const KINDS = ['local', 'channel', 'party'];
    /** The channels it may name (reports.go reportChannels): no group chat. */
    public const CHANNELS = ['global', 'help', 'newbie', 'trade'];
    /** The window the realtime server reads, in seconds (reports.go reportWindow). */
    public const WINDOW = 1800;

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Post, '/groddle/v1/players/{id:[1-9][0-9]{0,18}}/report', policy: new Policy(Access::SignedIn))]
    public function report(Request $request, string $id): Response
    {
        $user = $this->minn->user();
        if ($user === null) {
            throw new RestError('groddle_feedback_signed_out', 'Sign in to send us feedback.', 401);
        }
        $db = $this->minn->db;
        $reported = (int) $id;
        $logins = new Logins($db);
        if ($reported === $user->id || $logins->sameLogin($reported, $user->id)) {
            throw new RestError('groddle_report_self', 'You can\'t report yourself.', 400);
        }
        $name = (new Characters($db))->namesOf([$reported])[$reported] ?? null;
        if ($name === null) {
            throw new RestError('groddle_report_no_player', 'That player could not be found.', 404);
        }
        $now = time();
        $login = $logins->loginOf($user->id);
        $t = $db->table(self::TABLE);
        $today = (int) $db->value("SELECT COUNT(*) FROM {$t} WHERE reporter_login = ? AND created_at >= ?", [$login, gmdate('Y-m-d H:i:s', $now - 86400)]);
        $again = (int) $db->value("SELECT COUNT(*) FROM {$t} WHERE reporter_login = ? AND reported_id = ? AND created_at >= ?", [$login, $reported, gmdate('Y-m-d H:i:s', $now - self::PER_TARGET_SECONDS)]);
        $store = new TicketStore($db);
        $tickets = $store->openedSince($user->id, BugReports::WINDOW, $now) >= BugReports::PER_WINDOW || $store->openedSince($user->id, 86400, $now) >= BugReports::PER_DAY;
        $refused = self::refusal($today, $again, $tickets);
        if ($refused !== null) {
            throw new RestError($refused, self::refusalText($refused, $name), 429);
        }
        $body = $request->json();
        $note = BugReports::clean(is_string($body['note'] ?? null) ? $body['note'] : '', self::NOTE_MAX, true);
        $answer = (new RealtimeCall($this->minn))->post('report_lines', ['reporter' => $user->id, 'reported' => $reported, 'name' => $name]);
        $got = self::lines($answer);
        $ticket = $store->open($user->id, self::message($name, $note), BugReports::context(['page' => "player-report:{$reported}"]), $now);
        if ($ticket <= 0) {
            throw new RestError('groddle_feedback_failed', 'That didn\'t send. Try again in a moment.', 500);
        }
        $db->execute("INSERT INTO {$t} (ticket_id, reporter_id, reporter_login, reported_id, lines_status, chat_lines, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)", [
            $ticket, $user->id, $login, $reported, $got['status'],
            json_encode($got['lines'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), gmdate('Y-m-d H:i:s', $now),
        ]);
        return Response::json(['status' => 'reported', 'ticket' => $ticket, 'lines' => count($got['lines'])], 201);
    }

    /** PURE: why a report is refused (a RestError code), or null: the day's count, the same player within the hour, the tickets' limits. */
    public static function refusal(int $today, int $sameTarget, bool $ticketsFull): ?string
    {
        if ($sameTarget > 0) {
            return 'groddle_report_again';
        }
        if ($today >= self::PER_DAY || $ticketsFull) {
            return 'groddle_report_slow';
        }
        return null;
    }

    public static function refusalText(string $code, string $name): string
    {
        return $code === 'groddle_report_again'
            ? "You reported {$name} a little while ago. The administrators have it; you can add to it from your Reports."
            : 'You have sent a few reports already today. Try again tomorrow, or add to one from your Reports.';
    }

    /** PURE: the report's first message (its first line is the ticket's subject): who, and the reporter's words. */
    public static function message(string $name, string $note): string
    {
        return implode("\n", [
            "Reporting a player: {$name}",
            '',
            $note === '' ? '(No note.)' : $note,
            '',
            'The public chat you could see goes with it (street chat, Global, Live Help, Trade and party lines from the last 30 minutes). IMs never do.',
        ]);
    }

    /**
     * PURE: the realtime server's report_lines answer as kept: status ok (lines), off (no chat log) or down
     * (no answer), and at most LINES_MAX lines of the three public kinds (anything else is dropped: an IM
     * can never be kept), each cut to what a chat line can hold, the newest kept.
     *
     * @return array{status: string, lines: list<array<string, mixed>>}
     */
    public static function lines(mixed $answer): array
    {
        $status = is_array($answer) ? ($answer['status'] ?? null) : null;
        if ($status === 'off') {
            return ['status' => 'off', 'lines' => []];
        }
        if ($status !== 'ok' || !is_array($answer['lines'] ?? null)) {
            return ['status' => 'down', 'lines' => []];
        }
        $str = static fn (mixed $v, int $max): string => is_string($v) ? BugReports::clean($v, $max, false) : '';
        $out = [];
        foreach ($answer['lines'] as $l) {
            if (!is_array($l)) {
                continue;
            }
            $kind = $l['kind'] ?? null;
            $where = $l['where'] ?? null;
            $id = $l['id'] ?? null;
            if (!in_array($kind, self::KINDS, true) || !is_string($where) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $where) !== 1 || !is_int($id) || $id <= 0) {
                continue;
            }
            if ($kind === 'channel' && !in_array($where, self::CHANNELS, true)) {
                continue;
            }
            $at = is_string($l['at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?Z$/', $l['at']) === 1 ? $l['at'] : '';
            $out[] = [
                'at' => $at,
                'kind' => $kind,
                'where' => $where,
                'id' => $id,
                'name' => $str($l['name'] ?? null, 64),
                'text' => $str($l['text'] ?? null, ChatHistory::TEXT_MAX),
                'shown' => ($l['filtered'] ?? false) === true ? $str($l['shown'] ?? null, ChatHistory::TEXT_MAX) : '',
                'deleted' => ($l['deleted'] ?? false) === true,
                'reported' => ($l['reported'] ?? false) === true,
            ];
        }
        return ['status' => 'ok', 'lines' => array_slice($out, -self::LINES_MAX)];
    }

    // For the administrators (AdminTickets)

    /**
     * The reported player of each of these tickets that is a player report: ticket id => {id, name}.
     *
     * @param list<int> $tickets
     * @return array<int, array{id: int, name: string}>
     */
    public static function reportedOf(Db $db, array $tickets): array
    {
        $tickets = array_values(array_unique(array_filter($tickets, static fn (int $id): bool => $id > 0)));
        if ($tickets === []) {
            return [];
        }
        $rows = $db->rows('SELECT ticket_id, reported_id FROM ' . $db->table(self::TABLE) . ' WHERE ticket_id IN (?)', [$tickets]);
        $names = (new Characters($db))->namesOf(array_map(static fn (array $r): int => (int) $r['reported_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['reported_id'];
            $out[(int) $r['ticket_id']] = ['id' => $id, 'name' => (string) ($names[$id] ?? "#{$id}")];
        }
        return $out;
    }

    /**
     * A ticket's player report for the console's thread, or null when it is not one: who was reported
     * (as the game names them now), whether the lines came (linesStatus ok, off, down) and the lines,
     * oldest first, each with where it was said in words ($streets: the game's street index).
     *
     * @param array<string, mixed> $streets
     * @return array<string, mixed>|null
     */
    public static function forTicket(Db $db, int $ticket, array $streets): ?array
    {
        $row = $db->row('SELECT * FROM ' . $db->table(self::TABLE) . ' WHERE ticket_id = ?', [$ticket]);
        if ($row === null) {
            return null;
        }
        $reported = (int) $row['reported_id'];
        $names = (new Characters($db))->namesOf([$reported]);
        $lines = json_decode((string) $row['chat_lines'], true);
        return [
            'reported' => ['id' => $reported, 'name' => (string) ($names[$reported] ?? "#{$reported}")],
            'linesStatus' => (string) $row['lines_status'],
            'windowMinutes' => intdiv(self::WINDOW, 60),
            'lines' => self::linesOut(is_array($lines) ? $lines : [], $streets),
        ];
    }

    /**
     * PURE: kept lines as the console shows them: where in words (AdminConsole::chatWhere), only the kept fields.
     *
     * @param array<string, mixed> $streets
     * @return list<array<string, mixed>>
     */
    public static function linesOut(array $lines, array $streets): array
    {
        $out = [];
        foreach ($lines as $l) {
            if (!is_array($l) || !in_array($l['kind'] ?? null, self::KINDS, true)) {
                continue;
            }
            $kind = (string) $l['kind'];
            $where = (string) ($l['where'] ?? '');
            $out[] = [
                'at' => (string) ($l['at'] ?? ''),
                'kind' => $kind,
                'place' => AdminConsole::chatWhere($kind, $where, $streets, []),
                'id' => (int) ($l['id'] ?? 0),
                'name' => (string) ($l['name'] ?? ''),
                'text' => (string) ($l['text'] ?? ''),
                'shown' => (string) ($l['shown'] ?? ''),
                'deleted' => ($l['deleted'] ?? false) === true,
                'reported' => ($l['reported'] ?? false) === true,
            ];
        }
        return $out;
    }
}
