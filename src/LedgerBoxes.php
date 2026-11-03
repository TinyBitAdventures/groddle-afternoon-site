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
 * Storage in a player's house that other players reach (ticket 51, register
 * G404): selling from a Storage Display Box, and a key-holder taking from or
 * putting into a cabinet. The rules of GameServerJS items/bag_furniture_sdb.js
 * and items/bag_furniture_cabinet.js (the game's js/home-storage.js names the
 * lines; the copy is theirs):
 *
 * - set_selling_price / edit_selling_price / stop_selling (219-327): the
 *   owner names a price per unit, 1 to 9999999 currants (input_max_chars 7;
 *   0 stops), for a box with something in it that is not up for sale itself
 *   (canVend). Kept here (groddle_home_boxes), never from a client.
 * - purchase and sellItem (577-609, 1139-1278): anyone but the owner (the
 *   sell_to_owner prop is a debug switch), standing in the owner's house
 *   (the realtime server, home_boxes.go, checks that and signs the call);
 *   the count is cut to what is for sale, the price must be the one shown
 *   ("Oops, the price appears to have changed underneath you. Please try
 *   your purchase again."), the buyer must afford it ("Sorry, you can't
 *   afford that."). The buyer pays, gets the things, and the currants go on
 *   the box (this->income += total_cost) until the owner collects them
 *   (collect_currants 804-853: "You collected N currants from a Storage
 *   Display Box for selling ..."). A box that sells out stops selling
 *   (checkEmpty 896-907).
 * - a cabinet's open (294-340) is for the owner or a key-holder (isOwner
 *   452-466: acl_keys_player_has_key); a key-holder's moves cross between
 *   two players, so they settle here (the owner's own moves are their save's).
 *
 * The ledger (Ledger, G335) moves in one transaction, as the furniture sales
 * do (LedgerFurniture): the owner's save row is locked first (as a save
 * write takes it), so two buyers of the last one wait in turn and the second
 * finds it gone; then the box row, then both ledgers in id order. Every
 * purchase and move has the caller's own op id and settles once: asked
 * again it answers as it settled. Each side is a settled trade the save
 * applies once (the trade slice's applied list): the buyer's or key-holder's
 * op (the pack and the purse, as any trade), and the owner's op-o, which
 * takes that very stack out of the box or the cabinet (catchUp here on a
 * load, the owner's game when it hears). Until the owner's save applies an
 * op-o, what the stored save shows is less what is pending, and never more
 * than the owner's ledger holds. The owner need not be online: a key-holder
 * may stand in the house without them (inc_pols.js pols_canEnter 461-476).
 *
 *   POST groddle/v1/ledger/sdb      {"op":"sdb-<16 hex>","buyer":9,"owner":7,"uid":4,"count":3,"price":12}
 *        200 {"status":"settled","class","uid","n","price","total","stock"} | 409 {"status":"refused","why","text"}
 *   POST groddle/v1/ledger/cabinet  {"op":"cab-<16 hex>","actor":9,"owner":7,"uid":5,"kind":"take|put","slot":2,"class":"apple","n":3}
 *        200 {"status":"settled","kind","class","uid","slot","n","contents"} | 409 {"status":"refused","why","text"}
 *   GET    groddle/v1/homes/boxes                     mine: {"boxes":[{uid,class,price,selling,income,stock}],"ops":[the owner's side not applied]}
 *   PUT    groddle/v1/homes/boxes                     {"uid":4,"price":12}  (0 stops selling)
 *   DELETE groddle/v1/homes/boxes/{uid}               stop selling
 *   POST   groddle/v1/homes/boxes/{uid}/collect       {"op":"sdbc-<16 hex>"} 200 {"status":"collected|done","credit","currants","class"}
 *   GET    groddle/v1/homes/{owner}/cabinets/{uid}    the owner or a key-holder: {"uid","class","contents"}
 *
 * Only the ground floor (systems.features.homes.decor.placed), the room
 * visitors walk in, as for furniture sales. Tables groddle_home_boxes and
 * groddle_home_box_ops (minn.json).
 */
final readonly class LedgerBoxes
{
    public const TABLE = 'groddle_home_boxes';
    public const OPS = 'groddle_home_box_ops';

    public const ROUTE_SDB = '/groddle/v1/ledger/sdb';
    public const ROUTE_CABINET = '/groddle/v1/ledger/cabinet';

    public const SDB = 'bag_furniture_sdb';

    /** classProps width (cols) x height (rows): bag_furniture_cabinet.js and its variants. */
    public const CABINETS = ['bag_furniture_cabinet' => [4, 8], 'bag_furniture_smallcabinet' => [4, 4], 'bag_furniture_wallcabinet' => [3, 3], 'bag_furniture_largecabinet' => [2, 3]];

    /** set_selling_price input_max_chars 7. */
    public const PRICE_MAX = 9999999;

    /** StoreBuyUI.as updateMax: 999 at a time. */
    public const BUY_MAX = 999;

    /** Ours: the most one key-holder move carries. */
    public const MOVE_MAX = 9999;

    /** Ours: the ops a house lists as pending at most. */
    public const PENDING_MAX = 500;

    // bag_furniture_sdb.js sellItem 1139-1278 and verbs; ours where marked.
    public const TEXT_NOT_SELLING = 'Oops, that store is no longer selling.';
    public const TEXT_ZERO = "You can't buy a negative/zero amount of things!";
    public const TEXT_WRONG = 'Oops, something went wrong with that store.';
    public const TEXT_PRICE = 'Oops, the price appears to have changed underneath you. Please try your purchase again.';
    public const TEXT_AFFORD = "Sorry, you can't afford that.";
    public const TEXT_CANT_FIND = "I couldn't sell you that thing, because I couldn't find it :/";
    public const TEXT_FOR_SALE = "You can't add items while it's for sale.";
    public const TEXT_NOTHING = "There's nothing to collect.";
    public const TEXT_FULL = "I'm full!";
    public const TEXT_OWN = "That's yours already.";                                   // ours: the source shows the owner no purchase verb
    public const TEXT_EMPTY_BOX = 'Put something in the box first.';                     // ours: the source shows no pricer
    public const TEXT_NOT_HERE = "That isn't in your house.";                            // ours
    public const TEXT_NO_KEY = 'Only the owner and their key-holders can open this.';    // ours: isOwner hides the verb
    public const TEXT_CANT = "That can't go in there.";                                  // ours: canContain's drop just fails
    public const TEXT_TOOLS = 'Only the owner can move tools in and out of a cabinet.';  // ours
    public const TEXT_SHORT = "You don't have that many.";                               // ours

    public function __construct(private Seams $minn)
    {
    }

    // —— The owner ——

    #[Route(Method::Get, '/groddle/v1/homes/boxes', policy: new Policy(Access::SignedIn))]
    public function mineRoute(Request $request): Response
    {
        return Response::json(self::mine($this->minn->db, $this->minn->reader->userId));
    }

    /** set_selling_price / edit_selling_price; 0 is stop_selling (onInputBoxResponse 1088-1101). */
    #[Route(Method::Put, '/groddle/v1/homes/boxes', policy: new Policy(Access::SignedIn))]
    public function priceRoute(Request $request): Response
    {
        $body = $request->json();
        $uid = $body['uid'] ?? null;
        $price = $body['price'] ?? null;
        if (!is_int($uid) || $uid <= 0 || !is_int($price) || $price < 0 || $price > self::PRICE_MAX) {
            throw new RestError('groddle_box_invalid', 'Name the box and a price of 1 to 9999999 currants.', 400);
        }
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        Ledger::atomically($db, fn () => self::setPrice($db, $me, $uid, $price, time()));
        return Response::json(self::mine($db, $me));
    }

    #[Route(Method::Delete, '/groddle/v1/homes/boxes/{uid:\d+}', policy: new Policy(Access::SignedIn))]
    public function stopRoute(Request $request, string $uid): Response
    {
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        Ledger::atomically($db, fn () => self::setPrice($db, $me, (int) $uid, 0, time()));
        return Response::json(self::mine($db, $me));
    }

    /** collect_currants 804-853. */
    #[Route(Method::Post, '/groddle/v1/homes/boxes/{uid:\d+}/collect', policy: new Policy(Access::SignedIn))]
    public function collectRoute(Request $request, string $uid): Response
    {
        $op = $request->json()['op'] ?? null;
        if (!is_string($op) || preg_match('/^sdbc-[0-9a-f]{16}$/', $op) !== 1) {
            throw new RestError('groddle_box_invalid', 'Name the collection.', 400);
        }
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        $ledger = Ledger::fromSeams($this->minn);
        $out = Ledger::atomically($db, fn () => self::collect($db, $ledger, $me, (int) $uid, $op, time()));
        if ($out === null) {
            throw new RestError('groddle_box_nothing', self::TEXT_NOTHING, 404);
        }
        return Response::json($out);
    }

    // —— A visitor ——

    /** verbs.open for a key-holder (isOwner 452-466): what the cabinet holds now. */
    #[Route(Method::Get, '/groddle/v1/homes/{id:\d+}/cabinets/{uid:\d+}', policy: new Policy(Access::SignedIn))]
    public function cabinetRoute(Request $request, string $id, string $uid): Response
    {
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        $owner = (int) $id;
        if ($me !== $owner && (!self::hasKey($db, $owner, $me) || self::blocked($db, $owner, $me))) {
            throw new RestError('groddle_box_key', self::TEXT_NO_KEY, 403);
        }
        $row = LedgerFurniture::placed(Neighbours::state($db, $owner), (int) $uid);
        if ($row === null || !isset(self::CABINETS[(string) ($row->classTsid ?? '')])) {
            throw new RestError('groddle_box_here', self::TEXT_NOT_HERE, 404);
        }
        return Response::json(['uid' => (int) $uid, 'class' => (string) $row->classTsid, 'contents' => self::cabinetNow($db, $owner, $row, (int) $uid)]);
    }

    // —— The realtime server ——

    #[Route(Method::Post, self::ROUTE_SDB, policy: new Policy(Access::Public))]
    public function buyRoute(Request $request): Response
    {
        $body = $this->signed($request, self::ROUTE_SDB);
        $op = $body['op'] ?? null;
        $ints = [];
        foreach (['buyer', 'owner', 'uid', 'count', 'price'] as $k) {
            $ints[$k] = $body[$k] ?? null;
            if (!is_int($ints[$k])) {
                throw new RestError('groddle_ledger_invalid', 'Not a purchase.', 400);
            }
        }
        if (!is_string($op) || preg_match('/^sdb-[0-9a-f]{16}$/', $op) !== 1 || $ints['buyer'] <= 0 || $ints['owner'] <= 0 || $ints['uid'] <= 0 || $ints['price'] < 0 || $ints['price'] > self::PRICE_MAX) {
            throw new RestError('groddle_ledger_invalid', 'Not a purchase.', 400);
        }
        $db = $this->minn->db;
        $ledger = Ledger::fromSeams($this->minn);
        $out = Ledger::atomically($db, fn () => self::buy($db, $ledger, $op, $ints['buyer'], $ints['owner'], $ints['uid'], $ints['count'], $ints['price'], time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    #[Route(Method::Post, self::ROUTE_CABINET, policy: new Policy(Access::Public))]
    public function cabinetOpRoute(Request $request): Response
    {
        $body = $this->signed($request, self::ROUTE_CABINET);
        $op = $body['op'] ?? null;
        $kind = $body['kind'] ?? null;
        $class = $body['class'] ?? null;
        $slot = $body['slot'] ?? null;
        $ints = [];
        foreach (['actor', 'owner', 'uid', 'n'] as $k) {
            $ints[$k] = $body[$k] ?? null;
            if (!is_int($ints[$k]) || $ints[$k] <= 0) {
                throw new RestError('groddle_ledger_invalid', 'Not a move.', 400);
            }
        }
        if (!is_string($op) || preg_match('/^cab-[0-9a-f]{16}$/', $op) !== 1 || !in_array($kind, ['take', 'put'], true) || !is_string($class) || preg_match('/^[a-z0-9_]{1,64}$/', $class) !== 1 || ($slot !== null && (!is_int($slot) || $slot < 0 || $slot > 255))) {
            throw new RestError('groddle_ledger_invalid', 'Not a move.', 400);
        }
        $db = $this->minn->db;
        $ledger = Ledger::fromSeams($this->minn);
        $out = Ledger::atomically($db, fn () => self::cabinetMove($db, $ledger, Economy::shared(), LedgerStarter::table(), $op, $ints['actor'], $ints['owner'], $ints['uid'], $kind, $slot, $class, $ints['n'], time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    private function signed(Request $request, string $route): array
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', $route, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
        $body = json_decode($request->body, true);
        return is_array($body) ? $body : [];
    }

    // —— The rules, in the caller's transaction ——

    /**
     * sellItem 1139-1278 in one transaction: the owner's save row, then the
     * box, then both ledgers in id order. The op settles once.
     */
    public static function buy(Db $db, Ledger $ledger, string $op, int $buyer, int $owner, int $uid, int $count, int $price, int $now): array
    {
        $refuse = static function (string $why, string $text) use ($ledger, $buyer, $op, $now): array {
            $ledger->log($buyer, 'refuse', ['sdb' => $op, 'why' => $why], $now);
            return ['status' => 'refused', 'why' => $why, 'text' => $text];
        };
        // Every buyer of this house waits here in turn: the second buyer of the last one finds it gone.
        $version = $db->value('SELECT version FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ? FOR UPDATE', [$owner]);
        $ops = $db->table(self::OPS);
        $had = $db->row("SELECT owner_id, actor_id, uid, kind, class_tsid, n, price FROM {$ops} WHERE op = ? FOR UPDATE", [$op]);
        if ($had !== null) {
            if ((int) $had['actor_id'] !== $buyer || (int) $had['owner_id'] !== $owner || $had['kind'] !== 'buy') {
                return $refuse('gone', self::TEXT_NOT_SELLING);
            }
            $n = (int) $had['n'];
            $p = (int) $had['price'];
            return ['status' => 'settled', 'again' => true, 'class' => (string) $had['class_tsid'], 'uid' => (int) $had['uid'], 'n' => $n, 'price' => $p, 'total' => $n * $p,
                'stock' => self::stockOf($db, $owner, Neighbours::state($db, $owner), (int) $had['uid'], (string) $had['class_tsid'])];
        }
        if ($buyer === $owner) {
            return $refuse('own', self::TEXT_OWN);
        }
        if ($count <= 0) {
            return $refuse('zero', self::TEXT_ZERO);
        }
        if (self::blocked($db, $owner, $buyer)) {
            return $refuse('blocked', Neighbours::TEXT_BLOCKED);
        }
        if ($version === null) {
            return $refuse('gone', self::TEXT_NOT_SELLING);
        }
        $t = $db->table(self::TABLE);
        $box = $db->row("SELECT class_tsid, price, selling, income FROM {$t} WHERE owner_id = ? AND uid = ? FOR UPDATE", [$owner, $uid]);
        if ($box === null || (int) $box['selling'] !== 1 || (int) $box['price'] <= 0) {
            return $refuse('gone', self::TEXT_NOT_SELLING);
        }
        $class = (string) $box['class_tsid'];
        $state = Neighbours::state($db, $owner);
        // isForSale: a box up for sale itself sells as furniture, not what is in it (canVend).
        if (LedgerFurniture::opId(self::listingOf($db, $owner, $uid))) {
            return $refuse('gone', self::TEXT_NOT_SELLING);
        }
        $stock = self::stockOf($db, $owner, $state, $uid, $class);
        if ($stock <= 0) {
            return $refuse('gone', self::TEXT_NOT_SELLING);
        }
        if ((int) $box['price'] !== $price) {
            return $refuse('price', self::TEXT_PRICE);
        }
        $n = min($count, $stock, self::BUY_MAX);
        $states = [];
        foreach ($buyer < $owner ? [$buyer, $owner] : [$owner, $buyer] as $id) {
            $states[$id] = $ledger->lock($id, $now);
        }
        $n = min($n, (int) ($states[$owner]['items'][$class] ?? 0));
        if ($n <= 0) {
            return $refuse('gone', self::TEXT_CANT_FIND);
        }
        $total = $n * $price;
        if ($states[$buyer]['currants'] < $total) {
            return $refuse('short', self::TEXT_AFFORD);
        }
        $bItems = $states[$buyer]['items'];
        $bItems[$class] = ($bItems[$class] ?? 0) + $n;
        self::move($db, $buyer, $states[$buyer]['currants'] - $total, $bItems, $states[$buyer]['items'], $now);
        $oItems = $states[$owner]['items'];
        $oItems[$class] -= $n;
        self::move($db, $owner, $states[$owner]['currants'], $oItems, $states[$owner]['items'], $now);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        self::trade($db, $op, $buyer, self::goods([], $total), self::goods([$class => $n], 0), $stamp);
        self::trade($db, $op . '-o', $owner, self::goods([$class => $n], 0), self::goods([], 0), $stamp);
        $db->execute("INSERT INTO {$ops} (op, owner_id, actor_id, uid, kind, class_tsid, n, price, slot, created_at) VALUES (?, ?, ?, ?, 'buy', ?, ?, ?, -1, ?)", [$op, $owner, $buyer, $uid, $class, $n, $price, $stamp]);
        // this.income += total_cost; checkEmpty: a box that sells out stops selling.
        $left = $stock - $n;
        $db->execute("UPDATE {$t} SET income = income + ?, selling = ?, updated_at = ? WHERE owner_id = ? AND uid = ?", [$total, $left > 0 ? 1 : 0, $stamp, $owner, $uid]);
        $ledger->log($buyer, 'sdb', ['sdb' => $op, 'from' => $owner, 'gave' => ['currants' => $total], 'got' => [$class => $n]], $now);
        $ledger->log($owner, 'sdb', ['sdb' => $op, 'to' => $buyer, 'gave' => [$class => $n], 'income' => $total], $now);
        return ['status' => 'settled', 'class' => $class, 'uid' => $uid, 'n' => $n, 'price' => $price, 'total' => $total, 'stock' => $left];
    }

    /**
     * A key-holder's take from or put into the owner's cabinet (an inventory
     * move to or from a cabinet slot, CabinetDialog.as), in one transaction.
     */
    public static function cabinetMove(Db $db, Ledger $ledger, Economy $economy, array $pack, string $op, int $actor, int $owner, int $uid, string $kind, ?int $slot, string $class, int $n, int $now): array
    {
        $refuse = static function (string $why, string $text) use ($ledger, $actor, $op, $now): array {
            $ledger->log($actor, 'refuse', ['cabinet' => $op, 'why' => $why], $now);
            return ['status' => 'refused', 'why' => $why, 'text' => $text];
        };
        $version = $db->value('SELECT version FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ? FOR UPDATE', [$owner]);
        $ops = $db->table(self::OPS);
        $had = $db->row("SELECT owner_id, actor_id, uid, kind, class_tsid, n, slot FROM {$ops} WHERE op = ? FOR UPDATE", [$op]);
        $state = Neighbours::state($db, $owner);
        $row = LedgerFurniture::placed($state, $uid);
        if ($had !== null) {
            if ((int) $had['actor_id'] !== $actor || (int) $had['owner_id'] !== $owner || $had['kind'] !== $kind) {
                return $refuse('gone', self::TEXT_WRONG);
            }
            return ['status' => 'settled', 'again' => true, 'kind' => $kind, 'class' => (string) $had['class_tsid'], 'uid' => (int) $had['uid'], 'slot' => (int) $had['slot'] >= 0 ? (int) $had['slot'] : null, 'n' => (int) $had['n'],
                'contents' => $row !== null ? self::cabinetNow($db, $owner, $row, $uid) : []];
        }
        if ($actor === $owner) {
            return $refuse('own', self::TEXT_OWN);
        }
        if (!self::hasKey($db, $owner, $actor)) {
            return $refuse('key', self::TEXT_NO_KEY);
        }
        if (self::blocked($db, $owner, $actor)) {
            return $refuse('blocked', Neighbours::TEXT_BLOCKED);
        }
        $cabinet = $row !== null ? (string) ($row->classTsid ?? '') : '';
        if ($version === null || !isset(self::CABINETS[$cabinet])) {
            return $refuse('gone', self::TEXT_NOT_HERE);
        }
        if (LedgerFurniture::opId(self::listingOf($db, $owner, $uid))) {
            return $refuse('for_sale', self::TEXT_FOR_SALE);
        }
        $flags = (string) ($pack['classes'][$class][1] ?? '');
        if (!$economy->tradeable($class) || $class === 'contraband' || !isset($pack['classes'][$class]) || strpbrk($flags, 'xen') !== false) {
            return $refuse('cant', self::TEXT_CANT);
        }
        if (str_contains($flags, 't')) {
            return $refuse('tool', self::TEXT_TOOLS);
        }
        $max = (int) ($pack['classes'][$class][0] ?? 0);
        $contents = self::cabinetNow($db, $owner, $row, $uid);
        $n = min($n, self::MOVE_MAX);
        if ($kind === 'take') {
            $have = 0;
            foreach ($contents as $i => $s) {
                if (is_array($s) && $s['classTsid'] === $class && ($slot === null || $slot === $i)) {
                    $have += $s['count'];
                }
            }
            $n = min($n, $have);
            if ($n <= 0) {
                return $refuse('gone', self::TEXT_SHORT);
            }
        } else {
            $n = min($n, self::room($contents, $class, $max, $slot));
            if ($n <= 0) {
                return $refuse('full', self::TEXT_FULL);
            }
        }
        $states = [];
        foreach ($actor < $owner ? [$actor, $owner] : [$owner, $actor] as $id) {
            $states[$id] = $ledger->lock($id, $now);
        }
        $from = $kind === 'take' ? $owner : $actor;
        $to = $kind === 'take' ? $actor : $owner;
        if ((int) ($states[$from]['items'][$class] ?? 0) < $n) {
            return $refuse($kind === 'take' ? 'gone' : 'short', self::TEXT_SHORT);
        }
        $fItems = $states[$from]['items'];
        $fItems[$class] -= $n;
        self::move($db, $from, $states[$from]['currants'], $fItems, $states[$from]['items'], $now);
        $tItems = $states[$to]['items'];
        $tItems[$class] = ($tItems[$class] ?? 0) + $n;
        self::move($db, $to, $states[$to]['currants'], $tItems, $states[$to]['items'], $now);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $moved = self::goods([$class => $n], 0);
        $none = self::goods([], 0);
        // The key-holder's side, then the owner's (op-o): what each gave and got.
        self::trade($db, $op, $actor, $kind === 'put' ? $moved : $none, $kind === 'take' ? $moved : $none, $stamp);
        self::trade($db, $op . '-o', $owner, $kind === 'take' ? $moved : $none, $kind === 'put' ? $moved : $none, $stamp);
        $db->execute("INSERT INTO {$ops} (op, owner_id, actor_id, uid, kind, class_tsid, n, price, slot, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)", [$op, $owner, $actor, $uid, $kind, $class, $n, $slot ?? -1, $stamp]);
        $ledger->log($actor, 'cabinet', ['cabinet' => $op, 'owner' => $owner, 'kind' => $kind, 'items' => [$class => $n]], $now);
        $ledger->log($owner, 'cabinet', ['cabinet' => $op, 'by' => $actor, 'kind' => $kind, 'items' => [$class => $n]], $now);
        return ['status' => 'settled', 'kind' => $kind, 'class' => $class, 'uid' => $uid, 'slot' => $slot, 'n' => $n, 'contents' => self::cabinetNow($db, $owner, $row, $uid)];
    }

    /** collect_currants: the box's income into the owner's ledger, once per collection op. Null: nothing to collect. */
    public static function collect(Db $db, Ledger $ledger, int $owner, int $uid, string $op, int $now): ?array
    {
        $ops = $db->table(self::OPS);
        $had = $db->row("SELECT owner_id, uid, kind, class_tsid, price FROM {$ops} WHERE op = ? FOR UPDATE", [$op]);
        if ($had !== null) {
            return (int) $had['owner_id'] === $owner && $had['kind'] === 'collect'
                ? ['status' => 'done', 'credit' => $op, 'currants' => (int) $had['price'], 'class' => (string) $had['class_tsid']]
                : null;
        }
        $t = $db->table(self::TABLE);
        $box = $db->row("SELECT class_tsid, income FROM {$t} WHERE owner_id = ? AND uid = ? FOR UPDATE", [$owner, $uid]);
        $income = $box !== null ? (int) $box['income'] : 0;
        if ($income <= 0) {
            return null;
        }
        $state = $ledger->lock($owner, $now);
        self::move($db, $owner, $state['currants'] + $income, $state['items'], $state['items'], $now);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        self::trade($db, $op, $owner, self::goods([], 0), self::goods([], $income), $stamp);
        $db->execute("INSERT INTO {$ops} (op, owner_id, actor_id, uid, kind, class_tsid, n, price, slot, created_at) VALUES (?, ?, ?, ?, 'collect', ?, 0, ?, -1, ?)", [$op, $owner, $owner, $uid, (string) $box['class_tsid'], $income, $stamp]);
        $db->execute("UPDATE {$t} SET income = 0, updated_at = ? WHERE owner_id = ? AND uid = ?", [$stamp, $owner, $uid]);
        $ledger->log($owner, 'sdb', ['collected' => $income, 'box' => $uid, 'op' => $op], $now);
        return ['status' => 'collected', 'credit' => $op, 'currants' => $income, 'class' => (string) $box['class_tsid']];
    }

    /** set_selling_price / stop_selling: the price per unit for the box's class; 0 stops. */
    public static function setPrice(Db $db, int $owner, int $uid, int $price, int $now): void
    {
        $t = $db->table(self::TABLE);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        if ($price === 0) {
            $db->execute("UPDATE {$t} SET selling = 0, updated_at = ? WHERE owner_id = ? AND uid = ?", [$stamp, $owner, $uid]);
            return;
        }
        $row = LedgerFurniture::placed(Neighbours::state($db, $owner), $uid);
        if ($row === null || (string) ($row->classTsid ?? '') !== self::SDB) {
            throw new RestError('groddle_box_here', self::TEXT_NOT_HERE, 404);
        }
        $class = is_object($row->display ?? null) ? (string) ($row->display->classTsid ?? '') : '';
        if ($class === '' || (int) ($row->display->count ?? 0) <= 0) {
            throw new RestError('groddle_box_empty', self::TEXT_EMPTY_BOX, 400);
        }
        if (LedgerFurniture::opId(self::listingOf($db, $owner, $uid))) {
            throw new RestError('groddle_box_for_sale', self::TEXT_FOR_SALE, 400);
        }
        $db->execute("INSERT INTO {$t} (owner_id, uid, class_tsid, price, selling, income, updated_at) VALUES (?, ?, ?, ?, 1, 0, ?) ON DUPLICATE KEY UPDATE class_tsid = VALUES(class_tsid), price = VALUES(price), selling = 1, updated_at = VALUES(updated_at)", [$owner, $uid, $class, $price, $stamp]);
    }

    // —— Reading ——

    /** The owner's boxes (price, selling, income, stock) and their side of every op not applied yet. */
    public static function mine(Db $db, int $owner): array
    {
        $state = Neighbours::state($db, $owner);
        $boxes = [];
        foreach ($db->rows('SELECT uid, class_tsid, price, selling, income FROM ' . $db->table(self::TABLE) . ' WHERE owner_id = ? ORDER BY uid LIMIT 200', [$owner]) as $r) {
            $uid = (int) $r['uid'];
            $class = (string) $r['class_tsid'];
            $stock = self::stockOf($db, $owner, $state, $uid, $class);
            $boxes[] = ['uid' => $uid, 'class' => $class, 'price' => (int) $r['price'], 'selling' => (int) $r['selling'] === 1 && $stock > 0, 'income' => (int) $r['income'], 'stock' => $stock];
        }
        return ['boxes' => $boxes, 'ops' => array_map(static fn (array $o): array => ['op' => $o['op'] . '-o', 'uid' => $o['uid'], 'kind' => $o['kind'], 'class' => $o['class'], 'n' => $o['n'], 'slot' => $o['slot']], self::pending($db, $owner))];
    }

    /**
     * What a visitor sees for sale in the owner's house: each selling box on
     * the ground floor with something left (getStoreInfo 1043-1061).
     *
     * @return list<array{uid: int, class: string, price: int, stock: int}>
     */
    public static function shown(Db $db, int $owner, ?object $state): array
    {
        $out = [];
        foreach ($db->rows('SELECT uid, class_tsid, price FROM ' . $db->table(self::TABLE) . ' WHERE owner_id = ? AND selling = 1 AND price > 0 ORDER BY uid LIMIT 200', [$owner]) as $r) {
            $stock = self::stockOf($db, $owner, $state, (int) $r['uid'], (string) $r['class_tsid']);
            if ($stock > 0) {
                $out[] = ['uid' => (int) $r['uid'], 'class' => (string) $r['class_tsid'], 'price' => (int) $r['price'], 'stock' => $stock];
            }
        }
        return $out;
    }

    /**
     * The owner's side of the box and cabinet ops their save has not applied
     * (its op-o not stamped), oldest first.
     *
     * @return list<array{op: string, uid: int, kind: string, class: string, n: int, slot: ?int}>
     */
    public static function pending(Db $db, int $owner, ?int $uid = null): array
    {
        $ops = $db->table(self::OPS);
        $trades = $db->table(Ledger::TRADES);
        $sql = "SELECT o.op, o.uid, o.kind, o.class_tsid, o.n, o.slot FROM {$ops} o JOIN {$trades} t ON t.trade_id = CONCAT(o.op, '-o') WHERE o.owner_id = ? AND o.kind IN ('buy', 'take', 'put') AND t.status = 'settled' AND t.a_applied_at IS NULL";
        $args = [$owner];
        if ($uid !== null) {
            $sql .= ' AND o.uid = ?';
            $args[] = $uid;
        }
        $out = [];
        foreach ($db->rows($sql . ' ORDER BY o.created_at, o.op LIMIT ' . self::PENDING_MAX, $args) as $r) {
            $out[] = ['op' => (string) $r['op'], 'uid' => (int) $r['uid'], 'kind' => (string) $r['kind'], 'class' => (string) $r['class_tsid'], 'n' => (int) $r['n'], 'slot' => (int) $r['slot'] >= 0 ? (int) $r['slot'] : null];
        }
        return $out;
    }

    /**
     * What a box has for sale now: the stored save's count of the class, less
     * the purchases its save has not applied, never more than the owner's
     * ledger holds of the class.
     */
    public static function stockOf(Db $db, int $owner, ?object $state, int $uid, string $class): int
    {
        $row = LedgerFurniture::placed($state, $uid);
        if ($row === null || (string) ($row->classTsid ?? '') !== self::SDB || !is_object($row->display ?? null) || (string) ($row->display->classTsid ?? '') !== $class) {
            return 0;
        }
        $n = max(0, (int) ($row->display->count ?? 0));
        foreach (self::pending($db, $owner, $uid) as $o) {
            if ($o['kind'] === 'buy' && $o['class'] === $class) {
                $n -= $o['n'];
            }
        }
        $held = (int) ($db->value('SELECT count FROM ' . $db->table(Ledger::ITEMS) . ' WHERE user_id = ? AND class_tsid = ?', [$owner, $class]) ?? 0);
        return max(0, min($n, $held));
    }

    /** A cabinet's slots now: the stored save's, with the ops its save has not applied. */
    public static function cabinetNow(Db $db, int $owner, object $row, int $uid): array
    {
        $class = (string) ($row->classTsid ?? '');
        $slots = self::slots($class, json_decode((string) json_encode($row->contents ?? []), true));
        $pack = LedgerStarter::table();
        foreach (self::pending($db, $owner, $uid) as $o) {
            $max = (int) ($pack['classes'][$o['class']][0] ?? 0);
            self::applyOne($slots, $o['kind'], $o['class'], $o['n'], $o['slot'], $max);
        }
        return $slots;
    }

    /** Whether a placed row holds anything (a box's display, a cabinet's slots): canSell. */
    public static function holdsSomething(object $row): bool
    {
        if (is_object($row->display ?? null) && (int) ($row->display->count ?? 0) > 0) {
            return true;
        }
        foreach (is_array($row->contents ?? null) ? $row->contents : [] as $s) {
            if (is_object($s) && (int) ($s->count ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    public static function hasKey(Db $db, int $owner, int $holder): bool
    {
        return $db->value('SELECT 1 FROM ' . $db->table(Neighbours::KEYS) . ' WHERE owner_id = ? AND holder_id = ?', [$owner, $holder]) !== null;
    }

    private static function blocked(Db $db, int $owner, int $other): bool
    {
        return $db->value('SELECT 1 FROM ' . $db->table(Buddies::IGNORES) . ' WHERE user_id = ? AND ignored_id = ?', [$owner, $other]) !== null;
    }

    /** The furniture listing of a piece (LedgerFurniture), or null. */
    private static function listingOf(Db $db, int $owner, int $uid): ?string
    {
        $v = $db->value('SELECT id FROM ' . $db->table(LedgerFurniture::TABLE) . " WHERE owner_id = ? AND uid = ? AND status = 'listed'", [$owner, $uid]);
        return is_string($v) ? $v : null;
    }

    // —— The cabinet's slots (js/home-storage.js normalizeContents, cabinetPut, cabinetTake) ——

    /** Slots as the save keeps them: capacity long, null or ['classTsid' => c, 'count' => n]. */
    public static function slots(string $cabinet, mixed $raw): array
    {
        [$cols, $rows] = self::CABINETS[$cabinet] ?? [0, 0];
        $list = is_array($raw) ? array_values($raw) : [];
        $out = [];
        for ($i = 0; $i < $cols * $rows; $i++) {
            $s = $list[$i] ?? null;
            $c = is_array($s) ? ($s['classTsid'] ?? null) : null;
            $n = is_array($s) ? LedgerMath::count($s['count'] ?? 0) : 0;
            $out[] = is_string($c) && $c !== '' && $n > 0 ? ['classTsid' => $c, 'count' => $n] : null;
        }
        return $out;
    }

    /** How many of a class the slots take (into $slot only, when named); $max 0 is no limit. */
    public static function room(array $slots, string $class, int $max, ?int $slot = null): int
    {
        $cap = $max > 0 ? $max : self::MOVE_MAX;
        $one = static fn (mixed $s): int => $s === null ? $cap : ($s['classTsid'] === $class ? max(0, $cap - $s['count']) : 0);
        if ($slot !== null) {
            return array_key_exists($slot, $slots) ? $one($slots[$slot]) : 0;
        }
        $room = 0;
        foreach ($slots as $s) {
            $room += $one($s);
        }
        return $room;
    }

    /** One op on the slots: take from the slot named, then the last stacks; put in the slot named, then top up, then empty slots. Returns what it could not carry. */
    public static function applyOne(array &$slots, string $kind, string $class, int $n, ?int $slot, int $max): int
    {
        $cap = $max > 0 ? $max : PHP_INT_MAX;
        $left = $n;
        if ($kind === 'take') {
            $order = $slot !== null ? [$slot] : [];
            for ($i = count($slots) - 1; $i >= 0; $i--) {
                $order[] = $i;
            }
            foreach ($order as $i) {
                $s = $slots[$i] ?? null;
                if ($left <= 0 || !is_array($s) || $s['classTsid'] !== $class) {
                    continue;
                }
                $k = min($s['count'], $left);
                $left -= $k;
                $slots[$i] = $s['count'] - $k > 0 ? ['classTsid' => $class, 'count' => $s['count'] - $k] : null;
            }
            return $left;
        }
        if ($kind === 'put') {
            $fill = static function (int $i) use (&$slots, &$left, $class, $cap): void {
                $s = $slots[$i] ?? null;
                if ($left <= 0 || ($s !== null && $s['classTsid'] !== $class)) {
                    return;
                }
                $have = $s['count'] ?? 0;
                $k = min(max(0, $cap - $have), $left);
                if ($k > 0) {
                    $slots[$i] = ['classTsid' => $class, 'count' => $have + $k];
                    $left -= $k;
                }
            };
            if ($slot !== null && array_key_exists($slot, $slots)) {
                $fill($slot);
            }
            foreach ($slots as $i => $s) {
                if ($s !== null && $s['classTsid'] === $class) {
                    $fill($i);
                }
            }
            foreach ($slots as $i => $s) {
                if ($s === null) {
                    $fill($i);
                }
            }
            return $left;
        }
        return $left;
    }

    // —— A missed op on load (Ledger::catchUp) ——

    /** The owner's side of a purchase or a key-holder's move: sdb-/cab- <16 hex> -o. */
    public static function isOwnerSide(string $id): bool
    {
        return preg_match('/^(sdb|cab)-[0-9a-f]{16}-o$/', $id) === 1;
    }

    /**
     * Applies the owner's side of an op to a stored save, as the owner's game
     * does when it hears (js/home-storage.js applyOwnerOp): the box loses what
     * was bought, the cabinet loses or gains the stack; what the house can no
     * longer carry comes out of (or goes into) the pack. The id joins the
     * trade slice's applied list.
     */
    public static function catchUp(Db $db, array $entries, int $user, array $p): array
    {
        $op = substr($p['trade'], 0, -2);
        $o = $db->row('SELECT uid, kind, class_tsid, n, slot FROM ' . $db->table(self::OPS) . ' WHERE op = ? AND owner_id = ?', [$op, $user]);
        $raw = $entries['playerState'] ?? null;
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, false) : null;
        if ($o === null || !$state instanceof \stdClass || in_array($p['trade'], LedgerMath::applied($entries), true)) {
            return LedgerMath::applyTrade($entries, $p['trade'], $p['gave'], $p['got']);
        }
        $decor = $state->systems->features->homes->decor ?? null;
        $placed = $decor instanceof \stdClass && is_array($decor->placed ?? null) ? $decor->placed : [];
        $uid = (int) $o['uid'];
        $class = (string) $o['class_tsid'];
        $n = (int) $o['n'];
        $kind = (string) $o['kind'];
        $row = null;
        foreach ($placed as $r) {
            if ($r instanceof \stdClass && (int) ($r->id ?? 0) === $uid) {
                $row = $r;
            }
        }
        $left = $n;
        if ($kind === 'buy' && $row !== null && ($row->display ?? null) instanceof \stdClass && (string) ($row->display->classTsid ?? '') === $class) {
            $have = LedgerMath::count($row->display->count ?? 0);
            $k = min($have, $left);
            $left -= $k;
            $row->display->count = $have - $k;
            if ($row->display->count <= 0) {
                unset($row->display, $row->selling);
            }
        } elseif (($kind === 'take' || $kind === 'put') && $row !== null && isset(self::CABINETS[(string) ($row->classTsid ?? '')])) {
            $slots = self::slots((string) $row->classTsid, json_decode((string) json_encode($row->contents ?? []), true));
            // Keep each slot's tool wear as the save had it: only the counts change here.
            $before = json_decode((string) json_encode($row->contents ?? []), true);
            $max = (int) (LedgerStarter::table()['classes'][$class][0] ?? 0);
            $slot = (int) $o['slot'] >= 0 ? (int) $o['slot'] : null;
            $left = self::applyOne($slots, $kind, $class, $n, $slot, $max);
            $row->contents = array_map(static function (?array $s, int $i) use ($before): mixed {
                if ($s === null) {
                    return null;
                }
                $was = is_array($before[$i] ?? null) ? $before[$i] : null;
                $out = (object) ['classTsid' => $s['classTsid'], 'count' => $s['count']];
                if ($was !== null && ($was['classTsid'] ?? null) === $s['classTsid'] && is_array($was['points'] ?? null)) {
                    $out->points = array_slice($was['points'], 0, $s['count']);
                }
                return $out;
            }, $slots, array_keys($slots));
        }
        if ($left > 0) {
            // What the house no longer carries comes out of, or goes into, the pack.
            if (!($state->inventory ?? null) instanceof \stdClass) {
                $state->inventory = new \stdClass();
            }
            $inv = $state->inventory;
            $inv->{$class} = max(0, LedgerMath::count($inv->{$class} ?? 0) + ($kind === 'put' ? $left : -$left));
        }
        if (!($state->systems->features->{LedgerMath::TRADE_SLICE} ?? null) instanceof \stdClass) {
            $state->systems->features->{LedgerMath::TRADE_SLICE} = new \stdClass();
        }
        $slice = $state->systems->features->{LedgerMath::TRADE_SLICE};
        $applied = is_array($slice->applied ?? null) ? $slice->applied : [];
        $applied[] = $p['trade'];
        $slice->applied = array_values(array_slice($applied, -LedgerMath::APPLIED_KEEP));
        $entries['playerState'] = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $entries;
    }

    // —— Helpers ——

    private static function trade(Db $db, string $id, int $user, string $gave, string $got, string $stamp): void
    {
        $db->execute('INSERT INTO ' . $db->table(Ledger::TRADES) . " (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$id, $user, $gave, $got, $stamp, $stamp]);
    }

    /** Sets a ledger row's currants and the items that changed (LedgerFurniture::move). */
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
    private static function goods(array $items, int $currants): string
    {
        $list = [];
        foreach ($items as $c => $n) {
            $list[] = ['c' => (string) $c, 'n' => (int) $n];
        }
        return (string) json_encode(['items' => $list, 'currants' => $currants], JSON_UNESCAPED_SLASHES);
    }
}
