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
 * groddle/v1/ledger/crowns: a Game of Crowns winner's currants into their
 * ledger (G334, groddle-afternoon-server crowns.go). The realtime server
 * decides who won from the positions players report (it holds the crown, the
 * tags, the locks and the clock), then pays the source's 50 currants
 * (GameServerJS players/inc_games.js games_end_it_game 671-674:
 * stats_add_currants(50)). For the realtime server only, signed with the
 * realtime secret (ServiceCall), never with a player's cookie:
 *
 *   POST groddle/v1/ledger/crowns  {"id":"crowns-<16 hex match>-<round>-<player>","user":7,"got":Goods}
 *        200 {"status":"settled"}; idempotent per id
 *
 * It only ever puts in, and never more than one win's currants and no items:
 * a forged or replayed call can pay one id once at most. The move is recorded
 * like a street project's reward (LedgerProject: groddle_trades, b_id 0), so
 * until the winner's save lists the id as applied (the trade slice's applied
 * list, features/crowns-feature.js through ledgerReceipt) a save write is
 * compared as if the win had not been paid, and a load applies it to the
 * stored save (Ledger::catchUp). The 200 iMG and the badges are the game's
 * to apply, like every other reward the save carries.
 */
final readonly class LedgerCrowns
{
    public const ROUTE = '/groddle/v1/ledger/crowns';

    /** games_end_it_game 673: the winner's currants. */
    public const WIN_CURRANTS = 50;

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Post, self::ROUTE, policy: new Policy(Access::Public))]
    public function win(Request $request): Response
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', self::ROUTE, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
        $body = json_decode($request->body, true);
        $id = is_array($body) ? ($body['id'] ?? null) : null;
        $user = is_array($body) ? ($body['user'] ?? null) : null;
        $got = is_array($body) && is_array($body['got'] ?? null) ? LedgerMath::goods($body['got']) : null;
        if (!self::id($id) || !is_int($user) || $user <= 0 || !str_ends_with($id, '-' . $user) || $got === null
            || $got['items'] !== [] || $got['currants'] <= 0 || $got['currants'] > self::WIN_CURRANTS) {
            throw new RestError('groddle_ledger_invalid', 'Not a Game of Crowns win.', 400);
        }
        $project = new LedgerProject($this->minn);
        $out = Ledger::atomically($this->minn->db, fn () => $project->settle($id, $user, ['items' => [], 'currants' => 0], $got, time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    /** crowns.go: crowns-<16 hex match>-<round>-<player>, at most 40 (groddle_trades.trade_id). */
    public static function id(mixed $id): bool
    {
        return is_string($id) && strlen($id) <= 40 && preg_match('/^crowns-[0-9a-f]{16}-[0-9]{1,3}-[0-9]{1,12}$/', $id) === 1;
    }
}
