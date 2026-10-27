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
 * Furniture put up for sale in a player's house, bought by a visitor, and
 * the Pile of Currants it leaves (register G332, lane HOMES3), the rules of
 * GameServerJS furniture_base (items/furniture_bed.js and every other
 * furniture item):
 *
 * - set_sale_price / edit_sale_price / dont_sell (92-191): the owner, in
 *   their own house, names a price of 1 to 999999 currants (input_max_chars
 *   6, digits only, onInputBoxResponse 489-509: 0 or nothing stops the
 *   sale); never a chassis or a door (canSell 400-405); a bag must be
 *   emptied first (145).
 * - buy (344-375) and sellItem (511-645): anyone but the owner; the price
 *   must be the one shown ("Oops, the price appears to have changed
 *   underneath you. Please try your purchase again."), the buyer must
 *   afford it ("Sorry, you can't afford that."); the buyer pays, the piece
 *   is theirs, the owner hears "<buyer> just bought your <label> for N
 *   currants." and a pile_of_currants holding the price waits where the
 *   piece stood, for the owner alone (location.js createAndReturnItem
 *   1897-1921, only_visible_to); items/pile_of_currants.js collect pays it
 *   out.
 *
 * The price is the site's (this table), never a client's. A sale moves
 * through the ledger (Ledger, G335) in one transaction, asked by the realtime
 * server for a buyer standing in the owner's house (homes3.go furn_buy),
 * signed (ServiceCall):
 *
 *   POST groddle/v1/ledger/furniture  {"op":"furn-<16 hex>","buyer":9,"owner":7,"price":1200}
 *        200 {"status":"settled","class":"furniture_bed","uid":4,"price":1200,"x":610}
 *        | 409 {"status":"refused","why":"gone|price|short|blocked|own","text":"…"}
 *
 *   The buyer's ledger pays the price and gains the piece (trade op: a the
 *   buyer, b nobody), the owner's loses the piece (trade op-o): each save
 *   applies its side as any settled trade (the trade slice's applied list;
 *   a load applies a missed one, Ledger::catchUp, the owner's through
 *   catchUp here, which takes that very piece out of the house). The price
 *   is in nobody's ledger, in the pile (this row), until the owner collects
 *   it: a settled credit op-in, as the Butler's take (ButlerParcels).
 *
 *   GET    groddle/v1/homes/sales                   mine: listed and piles
 *   PUT    groddle/v1/homes/sales                   {"uid":4,"price":1200}  (price 0 stops the sale)
 *   DELETE groddle/v1/homes/sales/{uid}             dont_sell
 *   POST   groddle/v1/homes/sales/{op}/collect      the pile: 200 {"status":"collected","credit":"<op>-in","currants":1200}
 *
 * A listing is checked against the owner's stored house (systems.features.
 * homes.decor.placed, the ground floor visitors walk in), and one that is no
 * longer placed there is left out of every answer. Table
 * groddle_furniture_sales.
 */
final readonly class LedgerFurniture
{
    public const TABLE = 'groddle_furniture_sales';

    public const ROUTE = '/groddle/v1/ledger/furniture';

    /** furniture_bed.js:154 input_max_chars 6, input_restrict 0-9. */
    public const PRICE_MAX = 999999;

    /** canSell 400-405. */
    public const UNSELLABLE = ['furniture_chassis', 'furniture_tower_chassis', 'furniture_door'];

    /** Pieces on sale in one house at once (ours). */
    public const LISTED_MAX = 60;

    // furniture_bed.js 514, 576, 591, 596, 145; pile_of_currants.js; ours where marked.
    public const TEXT_NOT_SELLING = 'That store is no longer selling.';
    public const TEXT_PRICE = 'Oops, the price appears to have changed underneath you. Please try your purchase again.';
    public const TEXT_AFFORD = "Sorry, you can't afford that.";
    public const TEXT_NO_OWNER = "I can't sell you that thing, since I can't find the owner of this location.";
    public const TEXT_EMPTY = 'This item must be emptied before it can be sold.';
    public const TEXT_CANT_SELL = "You can't sell that.";               // ours: the source shows no verb
    public const TEXT_NOT_HERE = "That isn't in your house.";            // ours
    public const TEXT_TOO_MANY = 'You have a lot on sale already.';     // ours
    public const TEXT_OWN = "That's yours already.";                    // ours: the source shows no verb
    public const TEXT_NO_PILE = 'There are no currants here for you.';  // ours

    public function __construct(private Seams $minn)
    {
    }

    // —— The owner ——

    #[Route(Method::Get, '/groddle/v1/homes/sales', policy: new Policy(Access::SignedIn))]
    public function mine(Request $request): Response
    {
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        return Response::json(['listed' => self::listed($db, $me), 'piles' => self::piles($db, $me)]);
    }

    /** set_sale_price / edit_sale_price; a price of 0 is dont_sell (onInputBoxResponse 498-500). */
    #[Route(Method::Put, '/groddle/v1/homes/sales', policy: new Policy(Access::SignedIn))]
    public function price(Request $request): Response
    {
        $body = $request->json();
        $uid = $body['uid'] ?? null;
        $price = $body['price'] ?? null;
        if (!is_int($uid) || $uid <= 0 || !is_int($price) || $price < 0 || $price > self::PRICE_MAX) {
            throw new RestError('groddle_sale_invalid', 'Name the piece and a price of 1 to 999999 currants.', 400);
        }
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        if ($price === 0) {
            $this->stop($me, $uid);
            return $this->ownerAnswer($me);
        }
        $row = self::placed(Neighbours::state($db, $me), $uid);
        if ($row === null) {
            throw new RestError('groddle_sale_here', self::TEXT_NOT_HERE, 404);
        }
        $class = (string) ($row->classTsid ?? '');
        if (in_array($class, self::UNSELLABLE, true) || !Economy::shared()->tradeable($class)) {
            throw new RestError('groddle_sale_cant', self::TEXT_CANT_SELL, 400);
        }
        if (is_object($row->display ?? null) && (int) ($row->display->count ?? 0) > 0) {
            throw new RestError('groddle_sale_empty', self::TEXT_EMPTY, 400);
        }
        Ledger::atomically($db, function () use ($db, $me, $uid, $class, $price): void {
            $t = $db->table(self::TABLE);
            $held = $db->row("SELECT id FROM {$t} WHERE owner_id = ? AND uid = ? AND status = 'listed' FOR UPDATE", [$me, $uid]);
            $now = gmdate('Y-m-d H:i:s');
            if ($held !== null) {
                $db->execute("UPDATE {$t} SET price = ?, class_tsid = ?, updated_at = ? WHERE id = ?", [$price, $class, $now, (string) $held['id']]);
                return;
            }
            if ((int) $db->value("SELECT COUNT(*) FROM {$t} WHERE owner_id = ? AND status = 'listed'", [$me]) >= self::LISTED_MAX) {
                throw new RestError('groddle_sale_many', self::TEXT_TOO_MANY, 400);
            }
            $db->execute("INSERT INTO {$t} (id, owner_id, uid, class_tsid, price, status, buyer_id, x, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'listed', 0, 0, ?, ?)", [self::newOp(), $me, $uid, $class, $price, $now, $now]);
        });
        return $this->ownerAnswer($me);
    }

    #[Route(Method::Delete, '/groddle/v1/homes/sales/{uid:\d+}', policy: new Policy(Access::SignedIn))]
    public function dontSell(Request $request, string $uid): Response
    {
        $me = $this->minn->reader->userId;
        $this->stop($me, (int) $uid);
        return $this->ownerAnswer($me);
    }

    /** pile_of_currants.js onConversation: the owner takes the pile's currants. */
    #[Route(Method::Post, '/groddle/v1/homes/sales/{op:furn-[0-9a-f]+}/collect', policy: new Policy(Access::SignedIn))]
    public function collect(Request $request, string $op): Response
    {
        if (!self::opId($op)) {
            throw new RestError('groddle_sale_pile', self::TEXT_NO_PILE, 404);
        }
        $me = $this->minn->reader->userId;
        $db = $this->minn->db;
        $out = Ledger::atomically($db, function () use ($db, $op, $me): ?array {
            $t = $db->table(self::TABLE);
            $row = $db->row("SELECT owner_id, buyer_id, class_tsid, price, status FROM {$t} WHERE id = ? FOR UPDATE", [$op]);
            if ($row === null || (int) $row['owner_id'] !== $me || !in_array($row['status'], ['sold', 'collected'], true)) {
                return null;
            }
            $credit = $op . '-in';
            $price = (int) $row['price'];
            if ($row['status'] === 'collected') {
                return ['status' => 'done', 'credit' => $credit, 'currants' => $price];
            }
            $now = time();
            $stamp = gmdate('Y-m-d H:i:s', $now);
            $ledger = Ledger::fromSeams($this->minn);
            $state = $ledger->lock($me, $now);
            self::move($db, $me, $state['currants'] + $price, $state['items'], [], $now);
            $db->execute('INSERT IGNORE INTO ' . $db->table(Ledger::TRADES) . " (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$credit, $me, self::goodsJson([], 0), self::goodsJson([], $price), $stamp, $stamp]);
            $ledger->log($me, 'furniture', ['sale' => $op, 'collected' => $price], $now);
            $db->execute("UPDATE {$t} SET status = 'collected', collected_at = ?, updated_at = ? WHERE id = ?", [$stamp, $stamp, $op]);
            return ['status' => 'collected', 'credit' => $credit, 'currants' => $price];
        });
        if ($out === null) {
            throw new RestError('groddle_sale_pile', self::TEXT_NO_PILE, 404);
        }
        return Response::json($out);
    }

    private function stop(int $me, int $uid): void
    {
        $db = $this->minn->db;
        $db->execute('DELETE FROM ' . $db->table(self::TABLE) . " WHERE owner_id = ? AND uid = ? AND status = 'listed'", [$me, $uid]);
    }

    private function ownerAnswer(int $me): Response
    {
        $db = $this->minn->db;
        $listed = self::listed($db, $me);
        return Response::json(['listed' => $listed, 'piles' => self::piles($db, $me)]);
    }

    // —— The realtime server ——

    #[Route(Method::Post, self::ROUTE, policy: new Policy(Access::Public))]
    public function buy(Request $request): Response
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', self::ROUTE, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
        $body = json_decode($request->body, true);
        $op = is_array($body) ? ($body['op'] ?? null) : null;
        $buyer = is_array($body) ? ($body['buyer'] ?? null) : null;
        $owner = is_array($body) ? ($body['owner'] ?? null) : null;
        $price = is_array($body) ? ($body['price'] ?? null) : null;
        if (!self::opId($op) || !is_int($buyer) || $buyer <= 0 || !is_int($owner) || $owner <= 0 || !is_int($price) || $price <= 0 || $price > self::PRICE_MAX) {
            throw new RestError('groddle_ledger_invalid', 'Not a purchase.', 400);
        }
        $out = Ledger::atomically($this->minn->db, fn () => $this->settle($op, $buyer, $owner, $price, time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    /** sellItem 511-645, in one transaction. */
    private function settle(string $op, int $buyer, int $owner, int $price, int $now): array
    {
        $db = $this->minn->db;
        $t = $db->table(self::TABLE);
        $ledger = Ledger::fromSeams($this->minn);
        $refuse = function (string $why, string $text) use ($ledger, $buyer, $op, $now): array {
            $ledger->log($buyer, 'refuse', ['furniture' => $op, 'why' => $why], $now);
            return ['status' => 'refused', 'why' => $why, 'text' => $text];
        };
        $row = $db->row("SELECT owner_id, uid, class_tsid, price, status, buyer_id, x FROM {$t} WHERE id = ? FOR UPDATE", [$op]);
        if ($row === null || (int) $row['owner_id'] !== $owner) {
            return $refuse('gone', self::TEXT_NOT_SELLING);
        }
        $class = (string) $row['class_tsid'];
        $uid = (int) $row['uid'];
        if ($row['status'] !== 'listed') {
            // Asked again: answer as it was settled.
            return (int) $row['buyer_id'] === $buyer
                ? ['status' => 'settled', 'class' => $class, 'uid' => $uid, 'price' => (int) $row['price'], 'x' => (int) $row['x']]
                : $refuse('gone', self::TEXT_NOT_SELLING);
        }
        if ($buyer === $owner) {
            return $refuse('own', self::TEXT_OWN);
        }
        if ((int) $row['price'] !== $price) {
            return $refuse('price', self::TEXT_PRICE);
        }
        $ignores = $db->table(Buddies::IGNORES);
        if ($db->value("SELECT 1 FROM {$ignores} WHERE user_id = ? AND ignored_id = ?", [$owner, $buyer]) !== null) {
            return $refuse('blocked', Neighbours::TEXT_BLOCKED);
        }
        // The owner's save row first (as a save write takes it), then both ledgers in id order.
        $db->value('SELECT version FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ? FOR UPDATE', [$owner]);
        $placed = self::placed(Neighbours::state($db, $owner), $uid);
        if ($placed === null || (string) ($placed->classTsid ?? '') !== $class) {
            $db->execute("DELETE FROM {$t} WHERE id = ?", [$op]);
            return $refuse('gone', self::TEXT_NOT_SELLING);
        }
        $states = [];
        foreach ($buyer < $owner ? [$buyer, $owner] : [$owner, $buyer] as $id) {
            $states[$id] = $ledger->lock($id, $now);
        }
        if (($states[$owner]['items'][$class] ?? 0) < 1) {
            return $refuse('gone', self::TEXT_NO_OWNER);
        }
        if ($states[$buyer]['currants'] < $price) {
            return $refuse('short', self::TEXT_AFFORD);
        }
        $bItems = $states[$buyer]['items'];
        $bItems[$class] = ($bItems[$class] ?? 0) + 1;
        self::move($db, $buyer, $states[$buyer]['currants'] - $price, $bItems, $states[$buyer]['items'], $now);
        $oItems = $states[$owner]['items'];
        $oItems[$class] -= 1;
        self::move($db, $owner, $states[$owner]['currants'], $oItems, $states[$owner]['items'], $now);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $trades = $db->table(Ledger::TRADES);
        $db->execute("INSERT INTO {$trades} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$op, $buyer, self::goodsJson([], $price), self::goodsJson([$class => 1], 0), $stamp, $stamp]);
        $db->execute("INSERT INTO {$trades} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$op . '-o', $owner, self::goodsJson([$class => 1], 0), self::goodsJson([], 0), $stamp, $stamp]);
        $x = (int) round((float) ($placed->x ?? 0));
        $db->execute("UPDATE {$t} SET status = 'sold', buyer_id = ?, x = ?, sold_at = ?, updated_at = ? WHERE id = ?", [$buyer, $x, $stamp, $stamp, $op]);
        $ledger->log($buyer, 'furniture', ['sale' => $op, 'from' => $owner, 'gave' => ['currants' => $price], 'got' => [$class => 1]], $now);
        $ledger->log($owner, 'furniture', ['sale' => $op, 'to' => $buyer, 'gave' => [$class => 1], 'pile' => $price], $now);
        return ['status' => 'settled', 'class' => $class, 'uid' => $uid, 'price' => $price, 'x' => $x];
    }

    // —— Reading ——

    /**
     * The owner's pieces on sale that their stored house still has placed.
     *
     * @return list<array{op: string, uid: int, class: string, price: int}>
     */
    public static function listed(Db $db, int $owner): array
    {
        $state = Neighbours::state($db, $owner);
        $out = [];
        foreach ($db->rows('SELECT id, uid, class_tsid, price FROM ' . $db->table(self::TABLE) . " WHERE owner_id = ? AND status = 'listed' ORDER BY uid LIMIT " . self::LISTED_MAX, [$owner]) as $r) {
            $row = self::placed($state, (int) $r['uid']);
            if ($row === null || (string) ($row->classTsid ?? '') !== (string) $r['class_tsid']) {
                continue;
            }
            $out[] = ['op' => (string) $r['id'], 'uid' => (int) $r['uid'], 'class' => (string) $r['class_tsid'], 'price' => (int) $r['price']];
        }
        return $out;
    }

    /**
     * The owner's piles: pieces sold whose currants wait to be collected.
     *
     * @return list<array{op: string, uid: int, class: string, price: int, x: int, buyer: array{id: int, name: string}, at: int}>
     */
    public static function piles(Db $db, int $owner): array
    {
        $rows = $db->rows('SELECT id, uid, class_tsid, price, x, buyer_id, sold_at FROM ' . $db->table(self::TABLE) . " WHERE owner_id = ? AND status = 'sold' ORDER BY sold_at, id LIMIT 100", [$owner]);
        $names = (new Characters($db))->namesOf(array_map(static fn (array $r): int => (int) $r['buyer_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $b = (int) $r['buyer_id'];
            $out[] = ['op' => (string) $r['id'], 'uid' => (int) $r['uid'], 'class' => (string) $r['class_tsid'], 'price' => (int) $r['price'], 'x' => (int) $r['x'],
                'buyer' => ['id' => $b, 'name' => $names[$b] ?? 'Someone'], 'at' => (int) (strtotime((string) $r['sold_at'] . ' UTC') ?: 0) * 1000];
        }
        return $out;
    }

    /** The placed piece with this id in the stored house's ground floor, or null. */
    public static function placed(?object $state, int $uid): ?object
    {
        $placed = $state?->systems?->features?->homes?->decor?->placed ?? null;
        foreach (is_array($placed) ? $placed : [] as $row) {
            if (is_object($row) && is_numeric($row->id ?? null) && (int) $row->id === $uid) {
                return $row;
            }
        }
        return null;
    }

    // —— A missed sale on load (Ledger::catchUp) ——

    /** The owner's side of a sale: furn-<16 hex>-o. */
    public static function isSale(string $id): bool
    {
        return preg_match('/^furn-[0-9a-f]{16}-o$/', $id) === 1;
    }

    /**
     * Applies the owner's side of a sale to a stored save: that very piece
     * leaves the house (what stood on it is set down), as the owner's game
     * does when it hears of the sale; the id joins the trade slice's applied
     * list. A piece no longer placed comes out of the pack instead
     * (LedgerMath::applyTrade).
     */
    public static function catchUp(Db $db, array $entries, int $user, array $p): array
    {
        $op = substr($p['trade'], 0, -2);
        $sale = $db->row('SELECT uid, class_tsid FROM ' . $db->table(self::TABLE) . ' WHERE id = ? AND owner_id = ?', [$op, $user]);
        $raw = $entries['playerState'] ?? null;
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, false) : null;
        if ($sale === null || !$state instanceof \stdClass || in_array($p['trade'], LedgerMath::applied($entries), true)) {
            return LedgerMath::applyTrade($entries, $p['trade'], $p['gave'], $p['got']);
        }
        $decor = $state->systems->features->homes->decor ?? null;
        $placed = $decor instanceof \stdClass && is_array($decor->placed ?? null) ? $decor->placed : null;
        $uid = (int) $sale['uid'];
        $at = null;
        foreach ($placed ?? [] as $i => $row) {
            if (is_object($row) && (int) ($row->id ?? 0) === $uid && (string) ($row->classTsid ?? '') === (string) $sale['class_tsid']) {
                $at = $i;
            }
        }
        if ($at === null) {
            return LedgerMath::applyTrade($entries, $p['trade'], $p['gave'], $p['got']);
        }
        array_splice($placed, $at, 1);
        foreach ($placed as $row) {
            if (is_object($row) && (int) ($row->on ?? 0) === $uid) {
                $row->on = null;
            }
        }
        $decor->placed = array_values($placed);
        $features = $state->systems->features;
        if (!($features->{LedgerMath::TRADE_SLICE} ?? null) instanceof \stdClass) {
            $features->{LedgerMath::TRADE_SLICE} = new \stdClass();
        }
        $slice = $features->{LedgerMath::TRADE_SLICE};
        $applied = is_array($slice->applied ?? null) ? $slice->applied : [];
        $applied[] = $p['trade'];
        $slice->applied = array_values(array_slice($applied, -LedgerMath::APPLIED_KEEP));
        $entries['playerState'] = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $entries;
    }

    // —— Helpers ——

    /** homes3.go and the listing: furn- and 16 hex. */
    public static function opId(mixed $op): bool
    {
        return is_string($op) && preg_match('/^furn-[0-9a-f]{16}$/', $op) === 1;
    }

    private static function newOp(): string
    {
        return 'furn-' . bin2hex(random_bytes(8));
    }

    /** Sets a ledger row's currants and the items that changed. */
    private static function move(Db $db, int $user, int $currants, array $after, array $before, int $now): void
    {
        $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET currants = ?, updated_at = ? WHERE user_id = ?', [max(0, $currants), gmdate('Y-m-d H:i:s', $now), $user]);
        $items = $db->table(Ledger::ITEMS);
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $c) {
            $n = (int) ($after[$c] ?? 0);
            if ($n === (int) ($before[$c] ?? 0)) {
                continue;
            }
            if ($n <= 0) {
                $db->execute("DELETE FROM {$items} WHERE user_id = ? AND class_tsid = ?", [$user, (string) $c]);
            } else {
                $db->execute("INSERT INTO {$items} (user_id, class_tsid, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)", [$user, (string) $c, $n]);
            }
        }
    }

    /** @param array<string,int> $items */
    private static function goodsJson(array $items, int $currants): string
    {
        $list = [];
        foreach ($items as $c => $n) {
            $list[] = ['c' => (string) $c, 'n' => (int) $n];
        }
        return (string) json_encode(['items' => $list, 'currants' => $currants], JSON_UNESCAPED_SLASHES);
    }
}
