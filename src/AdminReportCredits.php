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
 * The console's report credits (ReportCredits; the Credit box on a report
 * and the Credits tab, console/console.js), manage_options only like the
 * console's other routes. A score is one line in groddle_admin_actions
 * (kind credit: the ticket, subject, points, kind and who scored it).
 *
 *   GET admin/tickets/{id}/credit   {"ticket":{id,subject,status,player},"credit":Credit|null,"kinds":{kind:points},"cap":8}
 *   PUT admin/tickets/{id}/credit   {"kind"?,"points"?,"reason","refs"?:[…],"by"?:"agent"|"admin"}
 *                                   the same, saved (scoring again replaces it), + {"text"}
 *   GET admin/credits               {"credits":[Credit + {subject,status,player}],"unscored":[{id,subject,status,createdAt,player}],
 *                                    "board":[{player,points,reports,first}],"kinds":{…},"cap":8}
 *                                   every scored report, newest first; closed and answered reports nobody has scored yet;
 *                                   the Contribute page's board as it stands (ReportCredits::rank)
 *   Credit: ReportCredits::out: {"ticket","points","kind","reason","refs":[…],"by","at"}
 *
 * groddle-afternoon tools/reports/credits.mjs reads these (and the ticket
 * routes) and, after Austin's OK, PUTs the scoring agent's proposals with
 * "by": "agent".
 */
final readonly class AdminReportCredits
{
    private const ADMIN = 'manage_options';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/admin/tickets/{id:\d+}/credit', policy: new Policy(Access::Cap, self::ADMIN))]
    public function show(Request $request, string $id): Response
    {
        return $this->answer($this->ticket((int) $id));
    }

    #[Route(Method::Put, '/groddle/v1/admin/tickets/{id:\d+}/credit', policy: new Policy(Access::Cap, self::ADMIN))]
    public function save(Request $request, string $id): Response
    {
        $admin = $this->minn->user();
        if ($admin === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        $row = $this->ticket((int) $id);
        $credit = ReportCredits::input($request->json());
        $now = time();
        $player = (int) $row['player_user_id'];
        (new ReportCredits($this->minn->db))->put((int) $row['id'], $player, $credit, $now);
        AdminConsole::recordAction($this->minn->db, $admin->id, $player, 'credit', [
            'ticket' => (int) $row['id'], 'subject' => (string) $row['subject'], 'points' => $credit['points'], 'kind' => $credit['kind'], 'by' => $credit['by'],
        ], 'stored', $now);
        $text = $credit['points'] === 0
            ? 'Scored: no points.'
            : ($credit['points'] === 1 ? 'Scored: 1 point.' : "Scored: {$credit['points']} points.");
        return $this->answer($row, ['text' => $text]);
    }

    #[Route(Method::Get, '/groddle/v1/admin/credits', policy: new Policy(Access::Cap, self::ADMIN))]
    public function index(Request $request): Response
    {
        $db = $this->minn->db;
        $store = new ReportCredits($db);
        $all = $store->all();
        $unscored = $store->unscored();
        $board = ReportCredits::rank($store->totals());
        $names = (new Characters($db))->namesOf(array_merge(
            array_map(static fn (array $r): int => (int) $r['player_user_id'], $all),
            array_map(static fn (array $r): int => (int) $r['player_user_id'], $unscored),
            array_map(static fn (array $r): int => $r['id'], $board),
        ));
        $who = static fn (int $id): array => ['id' => $id, 'name' => (string) ($names[$id] ?? "#{$id}")];
        return Response::json([
            'credits' => array_map(static fn (array $r): array => ReportCredits::out($r) + [
                'subject' => (string) $r['subject'], 'status' => (string) $r['status'], 'player' => $who((int) $r['player_user_id']),
            ], $all),
            'unscored' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'subject' => (string) $r['subject'], 'status' => (string) $r['status'],
                'createdAt' => SaveShape::time((string) $r['created_at']), 'player' => $who((int) $r['player_user_id']),
            ], $unscored),
            'board' => array_map(static fn (array $t): array => [
                'player' => $who($t['id']), 'points' => $t['points'], 'reports' => $t['reports'], 'first' => SaveShape::time($t['first']),
            ], $board),
            'kinds' => ReportCredits::KINDS,
            'cap' => ReportCredits::CAP,
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function answer(array $row, array $extra = []): Response
    {
        $player = (int) $row['player_user_id'];
        $found = (new ReportCredits($this->minn->db))->find((int) $row['id']);
        $names = (new Characters($this->minn->db))->namesOf([$player]);
        return Response::json($extra + [
            'ticket' => ['id' => (int) $row['id'], 'subject' => (string) $row['subject'], 'status' => (string) $row['status'], 'player' => ['id' => $player, 'name' => (string) ($names[$player] ?? "#{$player}")]],
            'credit' => $found === null ? null : ReportCredits::out($found),
            'kinds' => ReportCredits::KINDS,
            'cap' => ReportCredits::CAP,
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function ticket(int $id): array
    {
        $row = (new TicketStore($this->minn->db))->find($id);
        if ($row === null) {
            throw new RestError('groddle_ticket_missing', 'No report has that id.', 404);
        }
        return $row;
    }
}
