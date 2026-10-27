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
 * groddle/v1/ledger/spend: the realtime server spends from one character's
 * ledger (Ledger) for something it runs (G334, groddle-afternoon-server
 * party_spaces.go): a Party Pack that opens a party's Party Space, or the
 * currants the Party ATM takes to extend one. For the realtime server only,
 * signed with the realtime secret (ServiceCall), never with a player's
 * cookie:
 *
 *   POST groddle/v1/ledger/spend       {"op":"party-<16 hex>","id":7,"gave":Goods,"why":"party_pack"|"party_atm"}
 *        200 {"status":"settled"} | 409 {"status":"refused","short":{"id":7,"c":"party_pack_toxic_moon"}|{"id":7,"currants":true}}
 *        | 200 {"status":"void"} (given back, or voided before it came)
 *   POST groddle/v1/ledger/spend/void  {"op":"party-…","id":7}
 *        200 {"status":"void"} | 200 {"status":"settled"} (the save already applied it)
 *
 * A spend is recorded as a settled trade with nobody (groddle_trades, b_id
 * 0, b's side applied), so the ledger's own bookkeeping carries it: until the
 * player's save lists the spend id as applied (the trade slice's applied
 * list, which the game writes when the realtime server says the space
 * opened or the ATM took the currants) a save write is compared as if the
 * pack were still there, and a load applies it to the stored save
 * (Ledger::catchUp). Idempotent per op: asking again answers as recorded.
 * The void gives a spend back while no save has applied it (a space that
 * could not open after all), and makes sure a late spend never settles.
 */
final readonly class LedgerSpend
{
    public const SPEND = '/groddle/v1/ledger/spend';
    public const VOID = '/groddle/v1/ledger/spend/void';

    /** What the realtime server may spend on, and what each takes. */
    private const WHY = ['party_pack', 'party_atm'];

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Post, self::SPEND, policy: new Policy(Access::Public))]
    public function spend(Request $request): Response
    {
        $this->signed($request, self::SPEND);
        $body = json_decode($request->body, true);
        $op = is_array($body) ? ($body['op'] ?? null) : null;
        $id = is_array($body) ? ($body['id'] ?? null) : null;
        $why = is_array($body) ? ($body['why'] ?? null) : null;
        $gave = is_array($body) && is_array($body['gave'] ?? null) ? LedgerMath::goods($body['gave']) : null;
        if (!self::opId($op) || !is_int($id) || $id <= 0 || !in_array($why, self::WHY, true) || $gave === null || !self::fits($why, $gave)) {
            throw new RestError('groddle_ledger_invalid', 'Not a spend.', 400);
        }
        $out = Ledger::atomically($this->minn->db, fn () => $this->settle($op, $id, $why, $gave, time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    #[Route(Method::Post, self::VOID, policy: new Policy(Access::Public))]
    public function void(Request $request): Response
    {
        $this->signed($request, self::VOID);
        $body = json_decode($request->body, true);
        $op = is_array($body) ? ($body['op'] ?? null) : null;
        $id = is_array($body) ? ($body['id'] ?? null) : null;
        if (!self::opId($op) || !is_int($id) || $id <= 0) {
            throw new RestError('groddle_ledger_invalid', 'Not a spend.', 400);
        }
        return Response::json(Ledger::atomically($this->minn->db, fn () => $this->giveBack($op, $id, time())));
    }

    /** A pack is one Party Pack and nothing else; the ATM takes currants and nothing else. */
    private static function fits(string $why, array $gave): bool
    {
        if ($why === 'party_atm') {
            return $gave['items'] === [] && $gave['currants'] > 0;
        }
        if ($gave['currants'] !== 0 || count($gave['items']) !== 1) {
            return false;
        }
        $class = (string) array_key_first($gave['items']);
        return $gave['items'][$class] === 1 && preg_match('/^party_pack_[a-z_]{1,48}$/', $class) === 1 && Economy::shared()->tradeable($class);
    }

    /**
     * @param array{items: array<string,int>, currants: int} $gave
     * @return array{status: string, short?: array}
     */
    private function settle(string $op, int $id, string $why, array $gave, int $now): array
    {
        $db = $this->minn->db;
        $t = $db->table(Ledger::TRADES);
        $held = $db->row("SELECT status FROM {$t} WHERE trade_id = ? FOR UPDATE", [$op]);
        if ($held !== null) {
            return ['status' => (string) $held['status']];
        }
        $ledger = Ledger::fromSeams($this->minn);
        $state = $ledger->lock($id, $now);
        $short = null;
        foreach ($gave['items'] as $c => $n) {
            if (($state['items'][$c] ?? 0) < $n) {
                $short ??= ['id' => $id, 'c' => (string) $c];
            }
        }
        if ($short === null && $state['currants'] < $gave['currants']) {
            $short = ['id' => $id, 'currants' => true];
        }
        $row = [$op, $id, self::json($gave), '{"items":[],"currants":0}', gmdate('Y-m-d H:i:s', $now)];
        if ($short !== null) {
            $db->execute("INSERT INTO {$t} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'void', ?, 0, ?, ?, NULL, ?)", $row);
            $ledger->log($id, 'refuse', ['spend' => $op, 'why' => $why, 'short' => $short], $now);
            return ['status' => 'refused', 'short' => $short];
        }
        $this->move($id, $state, $gave, -1, $now);
        $db->execute("INSERT INTO {$t} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$op, $id, self::json($gave), '{"items":[],"currants":0}', gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now)]);
        $ledger->log($id, 'spend', ['spend' => $op, 'why' => $why, 'gave' => ['items' => (object) $gave['items'], 'currants' => $gave['currants']]], $now);
        return ['status' => 'settled'];
    }

    /** @return array{status: string} */
    private function giveBack(string $op, int $id, int $now): array
    {
        $db = $this->minn->db;
        $t = $db->table(Ledger::TRADES);
        $row = $db->row("SELECT status, a_id, b_id, a_gave, a_applied_at FROM {$t} WHERE trade_id = ? FOR UPDATE", [$op]);
        if ($row === null) {
            $db->execute("INSERT INTO {$t} (trade_id, status, a_id, b_id, a_gave, b_gave, created_at) VALUES (?, 'void', ?, 0, '{}', '{}', ?)", [$op, $id, gmdate('Y-m-d H:i:s', $now)]);
            return ['status' => 'void'];
        }
        if ($row['status'] !== 'settled' || (int) $row['a_id'] !== $id || (int) $row['b_id'] !== 0) {
            return ['status' => (string) $row['status']];
        }
        if ($row['a_applied_at'] !== null) {
            return ['status' => 'settled'];
        }
        $ledger = Ledger::fromSeams($this->minn);
        $state = $ledger->lock($id, $now);
        $gave = LedgerMath::goods(json_decode((string) $row['a_gave'], true));
        $this->move($id, $state, $gave, 1, $now);
        $db->execute("UPDATE {$t} SET status = 'void' WHERE trade_id = ?", [$op]);
        $ledger->log($id, 'unspend', ['spend' => $op], $now);
        return ['status' => 'void'];
    }

    /** Takes the goods out of the ledger ($sign -1) or puts them back (1). */
    private function move(int $id, array $state, array $gave, int $sign, int $now): void
    {
        $db = $this->minn->db;
        $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET currants = ?, updated_at = ? WHERE user_id = ?', [max(0, $state['currants'] + $sign * $gave['currants']), gmdate('Y-m-d H:i:s', $now), $id]);
        $items = $db->table(Ledger::ITEMS);
        foreach ($gave['items'] as $c => $n) {
            $count = ($state['items'][$c] ?? 0) + $sign * $n;
            if ($count <= 0) {
                $db->execute("DELETE FROM {$items} WHERE user_id = ? AND class_tsid = ?", [$id, (string) $c]);
            } else {
                $db->execute("INSERT INTO {$items} (user_id, class_tsid, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)", [$id, (string) $c, $count]);
            }
        }
    }

    private static function json(array $gave): string
    {
        $items = [];
        foreach ($gave['items'] as $c => $n) {
            $items[] = ['c' => (string) $c, 'n' => $n];
        }
        return (string) json_encode(['items' => $items, 'currants' => $gave['currants']], JSON_UNESCAPED_SLASHES);
    }

    private function signed(Request $request, string $route): void
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', $route, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
    }

    /** party_spaces.go newSpend: party- and 16 hex. */
    private static function opId(mixed $op): bool
    {
        return is_string($op) && preg_match('/^party-[0-9a-f]{16}$/', $op) === 1;
    }
}
