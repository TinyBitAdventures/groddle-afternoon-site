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
 * The Quarter's house lots (register G1186, Austin 2026-11-23): a shared block street whose lots
 * players claim, each lot showing its owner's house and leading to their home street (the game's
 * js/quarter.js and features/quarter-feature.js). In Glitch a quarter's houses were bought through
 * the familiar (GameServerJS locations/inc_pols.js pols_canBuy 15-63: not one with an owner,
 * "owned"; not a second, "has_other"; players/inc_houses.js houses_familiar_no_owner 318-361).
 * Ours: a lot is free (G208 chose the free home street over papers and currants), one per player,
 * and giving it up puts it back on sale.
 *
 * The realtime server (groddle-afternoon-server quarter.go) decides who asked first and tells the
 * block; this keeps the claims (table groddle_quarter_lots: its primary key is the street and the
 * lot, so a lot has one owner, and user_id is unique, so a player has one lot across every block,
 * however claims race; lot numbers are "<x><y>##", so no two blocks share one either):
 *
 *   POST groddle/v1/ledger/quarter/lots     (the realtime server, signed: ServiceCall) {}
 *        200 {"lots":[Row]}: every claimed lot, for a server that has just started
 *   POST groddle/v1/ledger/quarter/claim    (signed) {"op":"quarter-<16 hex>","id":7,"street":"GQR…","lot":1105}
 *        200 {"status":"settled","lot":1105,"lots":[Row]} | 409 {"status":"refused","why":"lot|taken|have|no_home|newxp","text":"…","lots":[Row]}
 *   POST groddle/v1/ledger/quarter/release  (signed) {"op":"quarter-…","id":7}
 *        200 {"status":"settled","street":"GQR…","lot":1105,"lots":[Row]} | 409 {"status":"refused","why":"none","lots":[Row]}
 *
 *   Row: {"street","lot","owner":7,"name":"Ada"}. A claim of the lot you already hold answers
 *   settled again (the server may ask twice after a lost answer).
 *
 *   GET  groddle/v1/quarter/{street}        (signed in) the block's claimed lots with each owner's
 *        house as they keep it on their home street (G796, Neighbours::homeHouse) and its style,
 *        and the lot the caller holds on any block (null for none), so the game can say "one lot
 *        each" before it asks:
 *        {"street","lots":[{"lot","owner":{"id","name"},"house","style"}],"mine":{"street","lot"}|null}
 *
 * A block's lots are the game's own street JSON (assets/streets/<tsid>.json `quarter.lots`): every
 * block (1100, 1200, ...) is one street there, and nothing here names one, so a new block needs no
 * change on the site. HomeStreet sends the owner's lot (held) for the home street's quarter arm.
 */
final readonly class Quarter
{
    public const TABLE = 'groddle_quarter_lots';

    public const LOTS = '/groddle/v1/ledger/quarter/lots';
    public const CLAIM = '/groddle/v1/ledger/quarter/claim';
    public const RELEASE = '/groddle/v1/ledger/quarter/release';

    /** Rows one answer carries at most (two blocks of ten today). */
    public const ROWS_MAX = 2000;

    public const TEXT_TAKEN = 'Someone else has just claimed that lot.'; // ours
    // inc_houses.js:344, "sell" and "buy" as "give up" and "claim": a lot is free here.
    public const TEXT_HAVE = "This location is for sale. You already own a location, which you'll need to give up before you can claim a new one.";
    public const TEXT_LOT = 'There is no lot like that here.'; // ours
    public const TEXT_NONE = "You don't have a lot in the Quarter."; // ours

    public function __construct(private Seams $minn)
    {
    }

    // —— The realtime server ——

    #[Route(Method::Post, self::LOTS, policy: new Policy(Access::Public))]
    public function lotsRoute(Request $request): Response
    {
        $this->signed($request, self::LOTS);
        return Response::json(['lots' => $this->table()]);
    }

    #[Route(Method::Post, self::CLAIM, policy: new Policy(Access::Public))]
    public function claimRoute(Request $request): Response
    {
        $this->signed($request, self::CLAIM);
        $call = self::call(json_decode($request->body, true), true);
        if ($call === null) {
            throw new RestError('groddle_ledger_invalid', 'Not a claim.', 400);
        }
        $all = $this->blockLots($call['street']);
        $state = Neighbours::state($this->minn->db, $call['id']);
        $out = Ledger::atomically($this->minn->db, fn () => $this->claim($call, $all, Neighbours::hasHome($state), Neighbours::inTutorial($state)));
        $out['lots'] = $this->table();
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    #[Route(Method::Post, self::RELEASE, policy: new Policy(Access::Public))]
    public function releaseRoute(Request $request): Response
    {
        $this->signed($request, self::RELEASE);
        $call = self::call(json_decode($request->body, true), false);
        if ($call === null) {
            throw new RestError('groddle_ledger_invalid', 'Not a release.', 400);
        }
        $out = Ledger::atomically($this->minn->db, fn () => $this->release($call['id']));
        $out['lots'] = $this->table();
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    // —— The game ——

    /** A block's claimed lots with each owner's house, for drawing the fronts. */
    #[Route(Method::Get, '/groddle/v1/quarter/{street:[GL][A-Z0-9]{12,14}}', policy: new Policy(Access::SignedIn))]
    public function block(Request $request, string $street): Response
    {
        $street = 'G' . substr($street, 1);
        if ($this->blockLots($street) === []) {
            throw new RestError('groddle_quarter_street', 'That street is not in a Quarter.', 404);
        }
        $db = $this->minn->db;
        $rows = $db->rows('SELECT lot, user_id FROM ' . $db->table(self::TABLE) . ' WHERE street = ? ORDER BY lot', [$street]);
        $names = (new Characters($db))->namesOf(array_map(static fn (array $r): int => (int) $r['user_id'], $rows));
        $lots = [];
        foreach ($rows as $r) {
            $id = (int) $r['user_id'];
            if (!isset($names[$id])) {
                continue;
            }
            $state = Neighbours::state($db, $id);
            $lots[] = self::front((int) $r['lot'], $id, $names[$id], Neighbours::homeHouse($state), Neighbours::homeStyle($state));
        }
        return Response::json(['street' => $street, 'lots' => $lots, 'mine' => self::held($db, (int) $this->minn->reader->userId)]);
    }

    /**
     * The lot a player holds on any block, or null (the home street's quarter arm, HomeStreet; and
     * the game's "one lot each" before it asks, block).
     *
     * @return array{street: string, lot: int}|null
     */
    public static function held(Db $db, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $db->row('SELECT street, lot FROM ' . $db->table(self::TABLE) . ' WHERE user_id = ?', [$id]);
        return $row === null ? null : ['street' => (string) $row['street'], 'lot' => (int) $row['lot']];
    }

    // —— Rules (pure: tests run them through php -r) ——

    /**
     * A claim or a release as the realtime server sends it, checked: null when it is not one.
     *
     * @return array{op: string, id: int, street: string, lot: int}|null
     */
    public static function call(mixed $body, bool $claim): ?array
    {
        if (!is_array($body)) {
            return null;
        }
        $op = $body['op'] ?? null;
        $id = $body['id'] ?? null;
        if (!is_string($op) || preg_match('/^quarter-[0-9a-f]{16}$/', $op) !== 1 || !is_int($id) || $id <= 0) {
            return null;
        }
        if (!$claim) {
            return ['op' => $op, 'id' => $id, 'street' => '', 'lot' => 0];
        }
        $street = $body['street'] ?? null;
        $lot = $body['lot'] ?? null;
        if (!is_string($street) || preg_match('/^G[A-Z0-9]{12,14}$/', $street) !== 1 || !is_int($lot) || $lot < 100 || $lot > 9999) {
            return null;
        }
        return ['op' => $op, 'id' => $id, 'street' => $street, 'lot' => $lot];
    }

    /**
     * pols_canBuy for one lot: null when $me may claim $lot, else why ('lot' not on the block,
     * 'taken' owned by another, 'have' a lot already, 'newxp' in the tutorial, 'no_home' no home
     * street for the front to lead to). $owner is who holds $lot (0: nobody); $mine the lot $me
     * holds anywhere (0: none). The game (js/quarter.js judgeClaim) and the server check the same.
     *
     * @param list<int> $all the block's lots
     */
    public static function judgeClaim(array $all, int $lot, int $owner, int $me, int $mine, bool $hasHome, bool $tutorial): ?string
    {
        if (!in_array($lot, $all, true)) {
            return 'lot';
        }
        if ($owner !== 0 && $owner !== $me) {
            return 'taken';
        }
        if ($owner === $me) {
            return null;
        }
        if ($mine !== 0) {
            return 'have';
        }
        if ($tutorial) {
            return 'newxp';
        }
        if (!$hasHome) {
            return 'no_home';
        }
        return null;
    }

    public static function text(string $why): string
    {
        return match ($why) {
            'taken' => self::TEXT_TAKEN,
            'have' => self::TEXT_HAVE,
            'none' => self::TEXT_NONE,
            'newxp' => Neighbours::TEXT_NEWXP_US,
            'no_home' => Neighbours::TEXT_NO_HOME,
            default => self::TEXT_LOT,
        };
    }

    /**
     * A block's lot numbers from its street JSON (`quarter.lots[].lot`), or none.
     *
     * @return list<int>
     */
    public static function lotsOf(mixed $street): array
    {
        $rows = is_array($street) && is_array($street['quarter']['lots'] ?? null) ? $street['quarter']['lots'] : [];
        $out = [];
        foreach ($rows as $row) {
            $lot = is_array($row) ? ($row['lot'] ?? null) : null;
            if (is_int($lot) && $lot >= 100 && $lot <= 9999 && !in_array($lot, $out, true)) {
                $out[] = $lot;
            }
        }
        return $out;
    }

    /** One front as the game draws it. @return array<string, mixed> */
    public static function front(int $lot, int $id, string $name, ?array $house, string $style): array
    {
        return ['lot' => $lot, 'owner' => ['id' => $id, 'name' => $name], 'house' => $house, 'style' => $style];
    }

    // —— Storage ——

    /** @return array{status: string, why?: string, text?: string, lot?: int} */
    private function claim(array $call, array $all, bool $hasHome, bool $tutorial): array
    {
        $db = $this->minn->db;
        $t = $db->table(self::TABLE);
        $mine = $db->row("SELECT street, lot FROM {$t} WHERE user_id = ? FOR UPDATE", [$call['id']]);
        $held = $db->row("SELECT user_id FROM {$t} WHERE street = ? AND lot = ? FOR UPDATE", [$call['street'], $call['lot']]);
        $owner = $held === null ? 0 : (int) $held['user_id'];
        // The lot the caller holds elsewhere (this one counts as $owner).
        $other = $mine !== null && !((string) $mine['street'] === $call['street'] && (int) $mine['lot'] === $call['lot']) ? (int) $mine['lot'] : 0;
        $why = self::judgeClaim($all, $call['lot'], $owner, $call['id'], $other, $hasHome, $tutorial);
        if ($why !== null) {
            return ['status' => 'refused', 'why' => $why, 'text' => self::text($why)];
        }
        if ($owner === $call['id']) {
            return ['status' => 'settled', 'lot' => $call['lot']];
        }
        // The keys hold the rules if two claims race past the reads above: one lot, one owner.
        $made = $db->execute("INSERT IGNORE INTO {$t} (street, lot, user_id, claimed_at) VALUES (?, ?, ?, ?)", [$call['street'], $call['lot'], $call['id'], gmdate('Y-m-d H:i:s')]);
        if ($made === 0) {
            return ['status' => 'refused', 'why' => 'taken', 'text' => self::TEXT_TAKEN];
        }
        return ['status' => 'settled', 'lot' => $call['lot']];
    }

    /** @return array{status: string, why?: string, text?: string, street?: string, lot?: int} */
    private function release(int $id): array
    {
        $db = $this->minn->db;
        $t = $db->table(self::TABLE);
        $mine = $db->row("SELECT street, lot FROM {$t} WHERE user_id = ? FOR UPDATE", [$id]);
        if ($mine === null) {
            return ['status' => 'refused', 'why' => 'none', 'text' => self::TEXT_NONE];
        }
        $db->execute("DELETE FROM {$t} WHERE user_id = ?", [$id]);
        return ['status' => 'settled', 'street' => (string) $mine['street'], 'lot' => (int) $mine['lot']];
    }

    /** Every claimed lot with its owner's name. @return list<array{street: string, lot: int, owner: int, name: string}> */
    private function table(): array
    {
        $db = $this->minn->db;
        $rows = $db->rows('SELECT street, lot, user_id FROM ' . $db->table(self::TABLE) . ' ORDER BY street, lot LIMIT ' . self::ROWS_MAX);
        $names = (new Characters($db))->namesOf(array_map(static fn (array $r): int => (int) $r['user_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['user_id'];
            $out[] = ['street' => (string) $r['street'], 'lot' => (int) $r['lot'], 'owner' => $id, 'name' => $names[$id] ?? ''];
        }
        return $out;
    }

    /** @return list<int> */
    private function blockLots(string $street): array
    {
        $file = StaticRoot::fromSite($this->minn)->dir . '/assets/streets/' . $street . '.json';
        if (preg_match('/^G[A-Z0-9]{12,14}$/', $street) !== 1 || !is_file($file)) {
            return [];
        }
        return self::lotsOf(json_decode((string) file_get_contents($file), true));
    }

    private function signed(Request $request, string $route): void
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', $route, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
    }
}
