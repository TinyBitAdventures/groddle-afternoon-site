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
 * groddle/v1/ledger: the ledger's routes (Ledger). Two are for the realtime
 * server only, signed with the realtime secret (ServiceCall), never with a
 * player's cookie:
 *
 *   POST groddle/v1/ledger/trade       {"trade":"…","ids":[7,8],"gave":[Goods,Goods]}
 *        200 {"status":"settled"} | 409 {"status":"refused","short":{"id":7,"c":"apple"}|{"id":7,"currants":true}}
 *        | 200 {"status":"void"} (voided before it came)
 *   POST groddle/v1/ledger/trade/void  {"trade":"…","ids":[7,8]}
 *        200 {"status":"void"} | {"status":"settled","ids":[7,8],"gave":[Goods,Goods]}
 *
 * Goods are the realtime server's ({"items":[{"c","n","r"?}],"currants"}).
 * Both are idempotent per trade id: a trade is settled or void exactly once
 * (trade.go settles, and voids when it cannot tell). A missing or bad
 * signature is 401 and says nothing more.
 *
 * And one for the player: GET groddle/v1/ledger, your own ledger (the
 * currants and tradeable things the site holds you to, and your budget).
 */
final readonly class LedgerService
{
    public const TRADE = '/groddle/v1/ledger/trade';
    public const VOID = '/groddle/v1/ledger/trade/void';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/ledger', policy: new Policy(Access::SignedIn))]
    public function mine(Request $request): Response
    {
        $ledger = Ledger::fromSeams($this->minn)->peek($this->minn->reader->userId);
        return Response::json($ledger ?? ['currants' => 0, 'items' => (object) [], 'budget' => 0, 'seeded' => null]);
    }

    #[Route(Method::Post, self::TRADE, policy: new Policy(Access::Public))]
    public function trade(Request $request): Response
    {
        $this->signed($request, self::TRADE);
        $body = json_decode($request->body, true);
        $trade = is_array($body) ? ($body['trade'] ?? null) : null;
        $ids = is_array($body['ids'] ?? null) ? array_values($body['ids']) : [];
        $gave = is_array($body['gave'] ?? null) ? array_values($body['gave']) : [];
        if (!self::tradeId($trade) || count($ids) !== 2 || !is_int($ids[0]) || !is_int($ids[1]) || $ids[0] <= 0 || $ids[1] <= 0 || $ids[0] === $ids[1] || count($gave) !== 2 || !is_array($gave[0]) || !is_array($gave[1])) {
            throw new RestError('groddle_ledger_invalid', 'Not a trade.', 400);
        }
        $ledger = Ledger::fromSeams($this->minn);
        $out = Ledger::atomically($this->minn->db, fn () => $ledger->settle($trade, [$ids[0], $ids[1]], [$gave[0], $gave[1]], time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    #[Route(Method::Post, self::VOID, policy: new Policy(Access::Public))]
    public function void(Request $request): Response
    {
        $this->signed($request, self::VOID);
        $body = json_decode($request->body, true);
        $trade = is_array($body) ? ($body['trade'] ?? null) : null;
        if (!self::tradeId($trade)) {
            throw new RestError('groddle_ledger_invalid', 'Not a trade.', 400);
        }
        $ids = is_array($body['ids'] ?? null) ? array_values(array_filter($body['ids'], 'is_int')) : [];
        $ledger = Ledger::fromSeams($this->minn);
        return Response::json(Ledger::atomically($this->minn->db, fn () => $ledger->void($trade, count($ids) === 2 ? [$ids[0], $ids[1]] : [0, 0], time())));
    }

    private function signed(Request $request, string $route): void
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', $route, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
    }

    /** trade.go tradeIDOK: lowercase letters, digits and dashes, at most 40. */
    private static function tradeId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[a-z0-9-]{1,40}$/', $id) === 1;
    }
}
