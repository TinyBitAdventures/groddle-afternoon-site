<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * The console's Boards tab (Austin 2026-11-04): the notes players pinned on
 * the shared Notice Boards (LedgerBoard), reported ones first, and Remove.
 * Administrators only (manage_options).
 *
 *   GET  admin/board_notes?show=pinned|reported|all
 *        {"notes":[Row],"counts":{"pinned":n,"reported":n}}
 *   POST admin/board_remove {"id":"board-…"}
 *        {"id","street":{tsid,label},"delivery":"live"|"none"|"down","text"}
 *        409 groddle_admin_board_gone when it is no longer on its board
 *
 *   Row: {"id","street":{tsid,label},"title","body","author":{id,name}|null,"remake",
 *         "poster":{id,name}|null,"status":"pinned"|"taken"|"removed","reports","at"(ms)}
 *
 * Removing marks the note removed at the site (its row stays, for the record)
 * and asks the realtime server to take it off the board for everyone there
 * (groddle-afternoon-server boards.go board_remove): live, none (the server
 * did not have it: nobody saw it, or it has not loaded), down (not
 * answering: it leaves the board the next time someone tries to take it, or
 * when the server starts). Nothing goes back to anyone: the Note left the
 * poster's ledger when it was pinned and no ledger gets it. One
 * groddle_admin_actions line (kind board_remove, the note's writer or poster).
 */
final readonly class AdminBoards
{
    /** Rows one list shows. */
    public const LIST_MAX = 200;

    public const SHOWS = ['pinned', 'reported', 'all'];

    private const ADMIN = 'manage_options';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/admin/board_notes', policy: new Policy(Access::Cap, self::ADMIN))]
    public function index(Request $request): Response
    {
        $show = $request->query('show');
        $show = in_array($show, self::SHOWS, true) ? $show : 'pinned';
        $db = $this->minn->db;
        $t = $db->table(LedgerBoard::TABLE);
        $where = match ($show) {
            'reported' => 'reports > 0',
            'all' => '1 = 1',
            default => "status = 'pinned'",
        };
        $rows = $db->rows("SELECT id, street, board, poster_id, author_id, remake, title, body, status, reports, created_at FROM {$t} WHERE {$where} ORDER BY (status = 'pinned' AND reports > 0) DESC, created_at DESC, id DESC LIMIT " . self::LIST_MAX);
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['author_id'];
            $ids[] = (int) $r['poster_id'];
        }
        $ids = array_values(array_unique(array_filter($ids)));
        $names = $ids === [] ? [] : (new Characters($db))->namesOf($ids);
        $streets = (new LedgerBoard($this->minn))->streetIndex();
        $out = array_map(static fn (array $r): array => self::row($r, $names, $streets), $rows);
        $counts = [
            'pinned' => (int) $db->value("SELECT COUNT(*) FROM {$t} WHERE status = 'pinned'"),
            'reported' => (int) $db->value("SELECT COUNT(*) FROM {$t} WHERE status = 'pinned' AND reports > 0"),
        ];
        return Response::json(['notes' => $out, 'counts' => $counts])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * One note as the console lists it.
     *
     * @param array<string, mixed> $r
     * @param array<int, string> $names
     * @param array<string, array<string, mixed>> $streets
     * @return array<string, mixed>
     */
    public static function row(array $r, array $names, array $streets): array
    {
        $note = LedgerBoard::noteOut($r, $names);
        $poster = (int) $r['poster_id'];
        $tsid = (string) $r['street'];
        return [
            'id' => $note['id'],
            'street' => ['tsid' => $tsid, 'label' => (string) ($streets[$tsid]['label'] ?? $tsid)],
            'title' => $note['title'],
            'body' => $note['body'],
            'author' => $note['author'],
            'remake' => $note['remake'],
            'poster' => $poster > 0 ? ['id' => $poster, 'name' => $names[$poster] ?? "#{$poster}"] : null,
            'status' => in_array($r['status'], ['pinned', 'taken', 'removed'], true) ? $r['status'] : 'pinned',
            'reports' => max(0, (int) $r['reports']),
            'at' => $note['at'],
        ];
    }

    #[Route(Method::Post, '/groddle/v1/admin/board_remove', policy: new Policy(Access::Cap, self::ADMIN))]
    public function remove(Request $request): Response
    {
        $admin = $this->minn->user();
        if ($admin === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        $id = $request->json()['id'] ?? null;
        if (!LedgerBoard::noteId($id)) {
            throw new RestError('groddle_admin_no_note', 'Choose a note first.', 400);
        }
        $db = $this->minn->db;
        $t = $db->table(LedgerBoard::TABLE);
        $now = time();
        $row = Ledger::atomically($db, function () use ($db, $t, $id, $admin, $now): ?array {
            $row = $db->row("SELECT id, street, board, poster_id, author_id, remake, title, body, status, reports, created_at FROM {$t} WHERE id = ? FOR UPDATE", [$id]);
            if ($row === null || $row['status'] !== 'pinned') {
                return null;
            }
            $db->execute("UPDATE {$t} SET status = 'removed', removed_by = ?, removed_at = ? WHERE id = ?", [$admin->id, gmdate('Y-m-d H:i:s', $now), $id]);
            return $row;
        });
        if ($row === null) {
            throw new RestError('groddle_admin_board_gone', 'That note is no longer on its board.', 409);
        }
        $answer = (new RealtimeCall($this->minn))->post('board_remove', ['id' => $id]);
        $delivery = match ($answer['status'] ?? null) {
            'removed' => 'live',
            'none' => 'none',
            default => 'down',
        };
        $names = (new Characters($db))->namesOf(array_values(array_unique(array_filter([(int) $row['author_id'], (int) $row['poster_id']]))));
        $out = self::row($row, $names, (new LedgerBoard($this->minn))->streetIndex());
        $writer = $out['author']['id'] ?? ($out['poster']['id'] ?? 0);
        $detail = ['note' => $id, 'street' => $out['street'], 'title' => mb_substr($out['title'], 0, 80), 'by' => $out['remake'] ? 'the remake' : ($out['author']['name'] ?? $out['poster']['name'] ?? '')];
        AdminConsole::recordAction($db, $admin->id, $writer, 'board_remove', $detail, $delivery, $now);
        return Response::json(['id' => $id, 'street' => $out['street'], 'delivery' => $delivery, 'text' => self::removeText($out['street']['label'], $delivery)]);
    }

    /** What the console says after a removal. */
    public static function removeText(string $label, string $delivery): string
    {
        return match ($delivery) {
            'live' => "The note is off the board in {$label}. Everyone there saw it go, and nobody gets it back.",
            'none' => "The note is off the board in {$label}. Nobody gets it back.",
            default => "The note is removed, but the realtime server is not answering: players in {$label} see it until someone tries to take it or the server restarts.",
        };
    }
}
