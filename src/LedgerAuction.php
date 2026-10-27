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
 * The Auction House between real players (register G333), the rules of
 * GameServerJS players/inc_auctions.js and the prices of glitch.com's
 * auction pages. Listings outlive sessions, so they are the site's (table
 * groddle_auctions), never the client's; anything of value moves through
 * the ledger (Ledger, G335). Only signed-in players call it, and every
 * decision comes from what the site holds: the seller's and buyer's
 * ledgers, the listing row and data/auctions.json (the game repo's
 * tools/build-auctions.mjs). The realtime server takes no part.
 *
 *   GET  groddle/v1/auctions                 active listings: ?q= (3+ letters: an item's name or a seller's) &cat= &c= &seller=<id> &sort=new|unit|price|ends &page=
 *        {"auctions":[Listing],"total","page","per","cats":[[key,name]],"rules":{…}}
 *   GET  groddle/v1/auctions/mine            {"active":[Listing],"ended":[Listing],"deliveries":[Delivery],"receipts":[Receipt],"listable":{c:{n,v}},"rules":{…}}
 *   POST groddle/v1/auctions                 {"id":"<16 hex>","c":"cherry","n":3,"cost":45,"r"?:40}
 *        200 {"status":"listed","auction":Listing,"receipt":Receipt} | 409 {"status":"refused","why","text"}
 *   POST groddle/v1/auctions/{id}/buy        200 {"status":"bought","auction":Listing,"receipt":Receipt,"delivery":Delivery} | 409 refused
 *   POST groddle/v1/auctions/{id}/cancel     200 {"status":"cancelled","auction":Listing,"delivery":Delivery} | 409 refused
 *   POST groddle/v1/auctions/{id}/collect    200 {"status":"collected"|"done","receipt":Receipt|null,"delivery":Delivery} | 409 refused ("early")
 *
 *   Receipt: {"op":"auc-<id>-l|b|p|d","gave":{"items":[{c,n}],"currants"},"got":{…}}, the move a save applies once
 *   (the trade slice's applied list, features/trade-feature.js ledgerReceipt), as a settled trade with
 *   nobody (groddle_trades, b_id 0: LedgerProject's record): until the save lists the op a write is
 *   compared as if it had not happened, and a load applies it to the stored save (Ledger::catchUp).
 *
 * The moves (each recorded once per op, so asking again answers as recorded):
 * - Listing (auctions_start 74-237): the stack and the listing fee leave the
 *   seller's ledger (op -l); the stack waits in escrow (this table: in no
 *   ledger), as the source hid it in a private bag (auctions_find_container
 *   20-36). At most 100 active (84-90); a count from 1 to what the ledger
 *   holds (130-147); a price from 1 to 9,999,999 (152-161); the fee is
 *   intval(cost / 100 * percent), at least the minimum (166-178), never
 *   refunded. Classes the ledger cannot carry, and those tagged no_auction or
 *   bag (admin_auctions_start 789), cannot be listed.
 * - A purchase (auctions_purchase 323-547): not your own (349-356), the
 *   buyer's ledger holds the price (362-369), at most 50 uncollected
 *   deliveries (383-389); the price leaves the buyer's ledger (op -b) and the
 *   seller's ledger gets round(cost * (100 - commission) / 100) (op -p,
 *   504-506) when the seller's game next asks (pay: the source paid at once);
 *   the stack goes to the buyer by mail.
 * - Cancel (auctions_cancel 239-286) and expiry (auctions_expire 288-321,
 *   auctions_check_expired 852-861, run before every answer here): the stack
 *   goes back to the seller by mail; listings last 72 hours (216).
 * - Delivery (mail_add_auction_delivery, inc_mail.js 1209-1212): the stack
 *   arrives in the mailbox config.auction_delivery_time after the sale, the
 *   cancel or the expiry (600 s, config_prod.js:44); collecting it puts it in
 *   the receiver's ledger (op -d). Until then it is in no ledger.
 *
 * Prices: listing fee 1.5%, at least 3 currants, commission 8% (glitch.com
 * /auctions/create/ costs = {min_list_fee: 3, list_percent: 1.5, commission:
 * 8}, Wayback 20120427114524, research/wayback/raw/auctions/pages/). The
 * source passed them from the web (admin_auctions_start fee_percent,
 * fee_min; admin_auctions_purchase commission).
 */
final readonly class LedgerAuction
{
    public const TABLE = 'groddle_auctions';

    /** inc_auctions.js:84. */
    public const MAX_ACTIVE = 100;
    /** inc_auctions.js:157. */
    public const COST_MAX = 9999999;
    /** inc_auctions.js:216: 72 hours (24 on dev). */
    public const DURATION = 259200;
    /** config_prod.js:44 auction_delivery_time. */
    public const DELIVERY = 600;
    /** inc_auctions.js:383. */
    public const MAILBOX_MAX = 50;
    /** One stack: MakingSlot.as:25 MAX_COUNT, as a trade slot. */
    public const COUNT_MAX = 9999;

    /** glitch.com /auctions/create/ (2012-04-27): costs. */
    public const LIST_PERCENT = 1.5;
    public const LIST_MIN = 3;
    public const COMMISSION = 8;

    /** A page of listings (the 2012 list pages showed about this many). */
    public const PER_PAGE = 20;
    /** "Give us at least 3 letters to search for" (glitch.com/auctions/). */
    public const SEARCH_MIN = 3;
    /** The ended listings "mine" shows (sold, cancelled, expired), newest first. */
    public const ENDED_KEEP = 30;

    public const TEXT_FEE = "You don't have enough currants for the listing fee.";
    public const TEXT_FORBIDDEN = 'This type of item can not be auctioned.';
    public const TEXT_SHORT = "You don't have enough of that item.";
    public const TEXT_MAX = 'You already have 100 auctions running.';
    public const TEXT_COST = 'Ask for a price from 1 to 9,999,999 currants.';
    public const TEXT_COUNT = 'Choose how many to sell.';
    public const TEXT_SNOOZE = 'You snooze, you lose... Someone purchased this auction.';
    public const TEXT_ENDED = 'That auction has ended.';
    public const TEXT_OURS = "That's your own auction.";
    public const TEXT_NO_CASH = "You don't have enough currants.";
    public const TEXT_MAILBOX = 'Your mailbox is full of auction deliveries. Collect some first.';
    public const TEXT_EARLY = 'That delivery is still on its way.';
    public const TEXT_NOT_YOURS = 'That is not your auction.';

    public function __construct(private Seams $minn)
    {
    }

    // —— Browsing ——

    #[Route(Method::Get, '/groddle/v1/auctions', policy: new Policy(Access::SignedIn))]
    public function browse(Request $request): Response
    {
        $db = $this->minn->db;
        $now = time();
        $this->sweep($now);
        $t = $db->table(self::TABLE);
        $where = ["a.status = 'active'", 'a.expires_at >= ?'];
        $params = [gmdate('Y-m-d H:i:s', $now)];
        $table = self::table();
        $cat = (string) $request->query('cat', '');
        if ($cat !== '' && isset(self::catNames()[$cat])) {
            $classes = array_keys(array_filter($table['items'], static fn ($k) => $k === $cat));
            $where[] = $classes === [] ? '0 = 1' : 'a.class_tsid IN (?)';
            if ($classes !== []) {
                $params[] = $classes;
            }
        }
        $class = (string) $request->query('c', '');
        if ($class !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $class) === 1) {
            $where[] = 'a.class_tsid = ?';
            $params[] = $class;
        }
        $seller = (int) $request->query('seller', '0');
        if ($seller > 0) {
            $where[] = 'a.seller_id = ?';
            $params[] = $seller;
        }
        $q = self::searchText((string) $request->query('q', ''));
        if ($q !== '') {
            $named = self::classesNamed($q);
            $like = '%' . addcslashes(strtolower($q), '%_\\') . '%';
            $players = $db->table(Characters::TABLE);
            $where[] = '(' . ($named === [] ? '' : 'a.class_tsid IN (?) OR ') . "a.seller_id IN (SELECT user_id FROM {$players} WHERE name_key LIKE ?))";
            if ($named !== []) {
                $params[] = $named;
            }
            $params[] = $like;
        }
        $order = match ((string) $request->query('sort', 'new')) {
            'unit' => 'a.cost / a.count ASC, a.created_at DESC',
            'price' => 'a.cost ASC, a.created_at DESC',
            'ends' => 'a.expires_at ASC',
            default => 'a.created_at DESC, a.id',
        };
        $page = max(1, min(500, (int) $request->query('page', '1')));
        $sql = implode(' AND ', $where);
        $total = (int) $db->value("SELECT COUNT(*) FROM {$t} a WHERE {$sql}", $params);
        $rows = $db->rows("SELECT a.* FROM {$t} a WHERE {$sql} ORDER BY {$order} LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        return Response::json([
            'auctions' => $this->listings($rows),
            'total' => $total,
            'page' => $page,
            'per' => self::PER_PAGE,
            'q' => $q,
            'cats' => $table['cats'],
            'rules' => self::rules(),
        ]);
    }

    /** The signed-in player's auctions, deliveries, unapplied receipts and what their ledger may list. */
    #[Route(Method::Get, '/groddle/v1/auctions/mine', policy: new Policy(Access::SignedIn))]
    public function mine(Request $request): Response
    {
        $db = $this->minn->db;
        $me = $this->me();
        $now = time();
        $this->sweep($now);
        Ledger::atomically($db, fn () => $this->pay($me, $now));
        $t = $db->table(self::TABLE);
        $active = $db->rows("SELECT * FROM {$t} WHERE seller_id = ? AND status = 'active' ORDER BY created_at DESC, id", [$me]);
        $ended = $db->rows("SELECT * FROM {$t} WHERE (seller_id = ? AND status <> 'active') OR buyer_id = ? ORDER BY ended_at DESC, id LIMIT " . self::ENDED_KEEP, [$me, $me]);
        $deliveries = $db->rows("SELECT * FROM {$t} WHERE deliver_to = ? AND collected_at IS NULL ORDER BY deliver_at, id LIMIT 100", [$me]);
        $listable = [];
        $held = Ledger::fromSeams($this->minn)->peek($me);
        foreach ((array) ($held['items'] ?? []) as $c => $n) {
            if (self::listable((string) $c) && (int) $n > 0) {
                $listable[(string) $c] = ['n' => (int) $n, 'v' => (int) round(Economy::shared()->value((string) $c))];
            }
        }
        return Response::json([
            'active' => $this->listings($active),
            'ended' => $this->listings($ended),
            'deliveries' => array_map(fn (array $r): array => self::delivery($r, $me), $deliveries),
            'receipts' => $this->receipts($me),
            'listable' => (object) $listable,
            'currants' => (int) ($held['currants'] ?? 0),
            'rules' => self::rules(),
            'now' => $now * 1000,
        ]);
    }

    // —— Listing ——

    #[Route(Method::Post, '/groddle/v1/auctions', policy: new Policy(Access::SignedIn))]
    public function start(Request $request): Response
    {
        $body = $request->json();
        $id = $body['id'] ?? null;
        $c = $body['c'] ?? null;
        $n = $body['n'] ?? null;
        $cost = $body['cost'] ?? null;
        $r = $body['r'] ?? null;
        if (!self::isId($id) || !is_string($c) || preg_match('/^[a-z0-9_]{1,64}$/', $c) !== 1 || !is_int($n) || !is_int($cost)) {
            throw new RestError('groddle_auction_invalid', 'Not an auction.', 400);
        }
        $me = $this->me();
        $out = Ledger::atomically($this->minn->db, fn () => $this->list($id, $me, $c, $n, $cost, is_int($r) && $r >= 0 && $r <= 1000000 ? $r : null, time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    /** @return array{status: string, why?: string, text?: string, auction?: array, receipt?: array} */
    private function list(string $id, int $me, string $c, int $n, int $cost, ?int $r, int $now): array
    {
        $db = $this->minn->db;
        $t = $db->table(self::TABLE);
        $this->sweep($now);
        $was = $db->row("SELECT * FROM {$t} WHERE id = ? FOR UPDATE", [$id]);
        if ($was !== null) {
            return (int) $was['seller_id'] === $me
                ? ['status' => 'listed', 'auction' => $this->listings([$was])[0], 'receipt' => $this->receipt(self::op($id, 'l'))]
                : self::refused('bad', 'Not an auction.');
        }
        $why = self::startRefusal($c, $n, $cost);
        if ($why !== null) {
            return self::refused(...$why);
        }
        if ((int) $db->value("SELECT COUNT(*) FROM {$t} WHERE seller_id = ? AND status = 'active'", [$me]) >= self::MAX_ACTIVE) {
            return self::refused('max', self::TEXT_MAX);
        }
        $fee = self::fee($cost);
        $op = self::op($id, 'l');
        $moved = $this->settleAlone($op, $me, ['items' => [$c => $n], 'currants' => $fee], ['items' => [], 'currants' => 0], 'list', $now);
        if ($moved !== null) {
            return $moved === 'currants' ? self::refused('fee', self::TEXT_FEE) : self::refused('short', self::TEXT_SHORT);
        }
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $db->execute("INSERT INTO {$t} (id, seller_id, class_tsid, count, cost, fee, r, status, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)", [
            $id, $me, $c, $n, $cost, $fee, $r, $stamp, gmdate('Y-m-d H:i:s', $now + self::DURATION),
        ]);
        $row = $db->row("SELECT * FROM {$t} WHERE id = ?", [$id]);
        return ['status' => 'listed', 'auction' => $this->listings([$row])[0], 'receipt' => $this->receipt($op)];
    }

    // —— Buying, cancelling, collecting ——

    #[Route(Method::Post, '/groddle/v1/auctions/{id:[0-9a-f]+}/buy', policy: new Policy(Access::SignedIn))]
    public function buy(Request $request, string $id): Response
    {
        self::mustBeId($id);
        $me = $this->me();
        $out = Ledger::atomically($this->minn->db, fn () => $this->purchase($id, $me, time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    private function purchase(string $id, int $me, int $now): array
    {
        $db = $this->minn->db;
        $t = $db->table(self::TABLE);
        $this->sweep($now);
        $row = $db->row("SELECT * FROM {$t} WHERE id = ? FOR UPDATE", [$id]);
        if ($row === null) {
            return self::refused('not_found', self::TEXT_ENDED);
        }
        if ($row['status'] === 'bought') {
            return (int) $row['buyer_id'] === $me
                ? ['status' => 'bought', 'auction' => $this->listings([$row])[0], 'receipt' => $this->receipt(self::op($id, 'b')), 'delivery' => self::delivery($row, $me)]
                : self::refused('sold', self::TEXT_SNOOZE);
        }
        if ($row['status'] !== 'active') {
            return self::refused('not_found', self::TEXT_ENDED);
        }
        $seller = (int) $row['seller_id'];
        if ($seller === $me) {
            return self::refused('is_ours', self::TEXT_OURS);
        }
        if ((int) $db->value("SELECT COUNT(*) FROM {$t} WHERE deliver_to = ? AND collected_at IS NULL", [$me]) > self::MAILBOX_MAX) {
            return self::refused('mailbox_full', self::TEXT_MAILBOX);
        }
        $cost = (int) $row['cost'];
        $proceeds = self::proceeds($cost);
        $none = ['items' => [], 'currants' => 0];
        // Both ledgers locked in id order (Ledger::settle's rule), so two purchases never deadlock.
        $ledger = Ledger::fromSeams($this->minn);
        foreach ($me < $seller ? [$me, $seller] : [$seller, $me] as $who) {
            $ledger->lock($who, $now);
        }
        if ($this->settleAlone(self::op($id, 'b'), $me, ['items' => [], 'currants' => $cost], $none, 'buy', $now) !== null) {
            return self::refused('no_cash', self::TEXT_NO_CASH);
        }
        // The seller is paid when their game next asks (pay), so the receipt is fresh when their save applies it.
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $db->execute("UPDATE {$t} SET status = 'bought', buyer_id = ?, proceeds = ?, ended_at = ?, deliver_to = ?, deliver_at = ? WHERE id = ?", [
            $me, $proceeds, $stamp, $me, gmdate('Y-m-d H:i:s', $now + self::DELIVERY), $id,
        ]);
        $row = $db->row("SELECT * FROM {$t} WHERE id = ?", [$id]);
        return ['status' => 'bought', 'auction' => $this->listings([$row])[0], 'receipt' => $this->receipt(self::op($id, 'b')), 'delivery' => self::delivery($row, $me)];
    }

    #[Route(Method::Post, '/groddle/v1/auctions/{id:[0-9a-f]+}/cancel', policy: new Policy(Access::SignedIn))]
    public function cancel(Request $request, string $id): Response
    {
        self::mustBeId($id);
        $me = $this->me();
        $out = Ledger::atomically($this->minn->db, function () use ($id, $me): array {
            $db = $this->minn->db;
            $t = $db->table(self::TABLE);
            $now = time();
            $this->sweep($now);
            $row = $db->row("SELECT * FROM {$t} WHERE id = ? FOR UPDATE", [$id]);
            if ($row === null || (int) $row['seller_id'] !== $me) {
                return self::refused('not_found', self::TEXT_NOT_YOURS);
            }
            if ($row['status'] === 'cancelled') {
                return ['status' => 'cancelled', 'auction' => $this->listings([$row])[0], 'delivery' => self::delivery($row, $me)];
            }
            if ($row['status'] !== 'active') {
                return self::refused($row['status'] === 'bought' ? 'sold' : 'not_found', $row['status'] === 'bought' ? 'Someone already bought it.' : self::TEXT_ENDED);
            }
            $stamp = gmdate('Y-m-d H:i:s', $now);
            $db->execute("UPDATE {$t} SET status = 'cancelled', ended_at = ?, deliver_to = seller_id, deliver_at = ? WHERE id = ?", [$stamp, gmdate('Y-m-d H:i:s', $now + self::DELIVERY), $id]);
            (Ledger::fromSeams($this->minn))->log($me, 'auction', ['auction' => $id, 'did' => 'cancel'], $now);
            $row = $db->row("SELECT * FROM {$t} WHERE id = ?", [$id]);
            return ['status' => 'cancelled', 'auction' => $this->listings([$row])[0], 'delivery' => self::delivery($row, $me)];
        });
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    /** The mailbox's Receive on an auction delivery: the stack comes into the receiver's ledger. */
    #[Route(Method::Post, '/groddle/v1/auctions/{id:[0-9a-f]+}/collect', policy: new Policy(Access::SignedIn))]
    public function collect(Request $request, string $id): Response
    {
        self::mustBeId($id);
        $me = $this->me();
        $out = Ledger::atomically($this->minn->db, function () use ($id, $me): array {
            $db = $this->minn->db;
            $t = $db->table(self::TABLE);
            $now = time();
            $this->sweep($now);
            $row = $db->row("SELECT * FROM {$t} WHERE id = ? FOR UPDATE", [$id]);
            if ($row === null || (int) ($row['deliver_to'] ?? 0) !== $me) {
                return self::refused('not_found', 'There is nothing to collect.');
            }
            $op = self::op($id, 'd');
            if ($row['collected_at'] !== null) {
                return ['status' => 'done', 'receipt' => $this->receipt($op), 'delivery' => self::delivery($row, $me)];
            }
            if (strtotime((string) $row['deliver_at'] . ' UTC') > $now) {
                return self::refused('early', self::TEXT_EARLY);
            }
            $this->settleAlone($op, $me, ['items' => [], 'currants' => 0], ['items' => [(string) $row['class_tsid'] => (int) $row['count']], 'currants' => 0], 'collect', $now);
            $db->execute("UPDATE {$t} SET collected_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', $now), $id]);
            $row = $db->row("SELECT * FROM {$t} WHERE id = ?", [$id]);
            return ['status' => 'collected', 'receipt' => $this->receipt($op), 'delivery' => self::delivery($row, $me)];
        });
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    // —— The ledger ——

    /**
     * One move in one character's ledger, recorded once as a settled trade
     * with nobody (LedgerProject::settle's record: a the player, b 0, b's
     * side applied). Returns null when it moved (or was recorded before),
     * else what the ledger lacked: 'currants' or the class.
     *
     * @param array{items: array<string,int>, currants: int} $gave
     * @param array{items: array<string,int>, currants: int} $got
     */
    private function settleAlone(string $op, int $user, array $gave, array $got, string $why, int $now): ?string
    {
        $db = $this->minn->db;
        $trades = $db->table(Ledger::TRADES);
        $held = $db->row("SELECT status FROM {$trades} WHERE trade_id = ? FOR UPDATE", [$op]);
        if ($held !== null) {
            return $held['status'] === 'settled' ? null : 'void';
        }
        $ledger = Ledger::fromSeams($this->minn);
        $state = $ledger->lock($user, $now);
        foreach ($gave['items'] as $c => $n) {
            if (!Economy::shared()->tradeable((string) $c) || ($state['items'][$c] ?? 0) < $n) {
                return (string) $c;
            }
        }
        if ($state['currants'] < $gave['currants']) {
            return 'currants';
        }
        $delta = ['items' => [], 'currants' => $got['currants'] - $gave['currants']];
        foreach ($gave['items'] as $c => $n) {
            $delta['items'][$c] = ($delta['items'][$c] ?? 0) - $n;
        }
        foreach ($got['items'] as $c => $n) {
            $delta['items'][$c] = ($delta['items'][$c] ?? 0) + $n;
        }
        $after = LedgerMath::add(['items' => $state['items'], 'currants' => $state['currants']], $delta);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET currants = ?, updated_at = ? WHERE user_id = ?', [$after['currants'], $stamp, $user]);
        $items = $db->table(Ledger::ITEMS);
        foreach (array_keys($delta['items']) as $c) {
            $count = (int) ($after['items'][$c] ?? 0);
            if ($count <= 0) {
                $db->execute("DELETE FROM {$items} WHERE user_id = ? AND class_tsid = ?", [$user, (string) $c]);
            } else {
                $db->execute("INSERT INTO {$items} (user_id, class_tsid, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)", [$user, (string) $c, $count]);
            }
        }
        $db->execute("INSERT INTO {$trades} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$op, $user, self::goodsJson($gave), self::goodsJson($got), $stamp, $stamp]);
        $ledger->log($user, 'auction', ['auction' => $op, 'did' => $why, 'gave' => ['items' => (object) $gave['items'], 'currants' => $gave['currants']], 'got' => ['items' => (object) $got['items'], 'currants' => $got['currants']]], $now);
        return null;
    }

    /**
     * The proceeds of the seller's sales not paid yet (op -p): the source paid
     * at once (inc_auctions.js:506 stats_add_currants); here they go into the
     * seller's ledger when the seller's game next asks (GET auctions/mine,
     * within a minute while it runs, and at every start), so a sale made
     * while the seller is away for days never leaves a receipt older than
     * the ledger's pending window (Ledger::PENDING_FOR), which a load would
     * stamp without applying.
     */
    private function pay(int $seller, int $now): void
    {
        $db = $this->minn->db;
        $t = $db->table(self::TABLE);
        $trades = $db->table(Ledger::TRADES);
        $rows = $db->rows("SELECT a.id, a.proceeds FROM {$t} a LEFT JOIN {$trades} x ON x.trade_id = CONCAT('auc-', a.id, '-p') WHERE a.seller_id = ? AND a.status = 'bought' AND x.trade_id IS NULL ORDER BY a.ended_at, a.id LIMIT 100 FOR UPDATE", [$seller]);
        foreach ($rows as $r) {
            $this->settleAlone(self::op((string) $r['id'], 'p'), $seller, ['items' => [], 'currants' => 0], ['items' => [], 'currants' => (int) $r['proceeds']], 'sold', $now);
        }
    }

    /** One recorded move as a save applies it, or null. */
    private function receipt(string $op): ?array
    {
        $r = $this->minn->db->row('SELECT trade_id, a_gave, b_gave FROM ' . $this->minn->db->table(Ledger::TRADES) . " WHERE trade_id = ? AND status = 'settled'", [$op]);
        return $r === null ? null : self::receiptOf($r);
    }

    /** The auction moves this character's save has not applied yet. */
    private function receipts(int $me): array
    {
        $db = $this->minn->db;
        $rows = $db->rows('SELECT trade_id, a_gave, b_gave FROM ' . $db->table(Ledger::TRADES) . " WHERE a_id = ? AND b_id = 0 AND status = 'settled' AND a_applied_at IS NULL AND trade_id LIKE 'auc-%' AND created_at >= ? ORDER BY created_at, trade_id LIMIT 60", [$me, gmdate('Y-m-d H:i:s', time() - Ledger::PENDING_FOR)]);
        return array_map(self::receiptOf(...), $rows);
    }

    private static function receiptOf(array $r): array
    {
        $out = ['op' => (string) $r['trade_id']];
        foreach (['gave' => 'a_gave', 'got' => 'b_gave'] as $k => $col) {
            $g = LedgerMath::goods(json_decode((string) $r[$col], true));
            $items = [];
            foreach ($g['items'] as $c => $n) {
                $items[] = ['c' => (string) $c, 'n' => $n];
            }
            $out[$k] = ['items' => $items, 'currants' => $g['currants']];
        }
        return $out;
    }

    // —— Expiry ——

    /** auctions_check_expired: every listing past its time ends, the stack mailed back to its seller. */
    private function sweep(int $now): void
    {
        $t = $this->minn->db->table(self::TABLE);
        $this->minn->db->execute("UPDATE {$t} SET status = 'expired', ended_at = expires_at, deliver_to = seller_id, deliver_at = DATE_ADD(expires_at, INTERVAL " . self::DELIVERY . " SECOND) WHERE status = 'active' AND expires_at < ?", [gmdate('Y-m-d H:i:s', $now)]);
    }

    // —— Answers ——

    private function listings(array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['seller_id'];
            if ($r['buyer_id'] !== null) {
                $ids[] = (int) $r['buyer_id'];
            }
        }
        $names = (new Characters($this->minn->db))->namesOf($ids);
        $me = $this->me();
        $items = self::table()['items'];
        $out = [];
        foreach ($rows as $r) {
            $seller = (int) $r['seller_id'];
            $row = [
                'id' => (string) $r['id'],
                'c' => (string) $r['class_tsid'],
                'n' => (int) $r['count'],
                'cost' => (int) $r['cost'],
                'cat' => $items[(string) $r['class_tsid']] ?? 'other',
                'seller' => ['id' => $seller, 'name' => $names[$seller] ?? 'Someone'],
                'status' => (string) $r['status'],
                'at' => self::ms($r['created_at']),
                'ends' => self::ms($r['expires_at']),
                'mine' => $seller === $me,
            ];
            if ($r['r'] !== null) {
                $row['r'] = (int) $r['r'];
            }
            if ($r['status'] !== 'active') {
                $row['ended'] = self::ms($r['ended_at']);
            }
            if ($r['status'] === 'bought') {
                $buyer = (int) $r['buyer_id'];
                $row['buyer'] = ['id' => $buyer, 'name' => $names[$buyer] ?? 'Someone'];
                if ($seller === $me) {
                    $row['proceeds'] = (int) $r['proceeds'];
                }
            }
            if ($seller === $me) {
                $row['fee'] = (int) $r['fee'];
            }
            $out[] = $row;
        }
        return $out;
    }

    /** A delivery as the mailbox shows it: what, why (purchased, cancelled, expired), when it arrives. */
    private static function delivery(array $r, int $me): ?array
    {
        if ($r['deliver_to'] === null || (int) $r['deliver_to'] !== $me) {
            return null;
        }
        $kind = match ((string) $r['status']) {
            'bought' => 'purchased',
            'cancelled' => 'cancelled',
            default => 'expired',
        };
        $out = ['id' => (string) $r['id'], 'op' => self::op((string) $r['id'], 'd'), 'kind' => $kind, 'item' => ['c' => (string) $r['class_tsid'], 'n' => (int) $r['count']], 'at' => self::ms($r['deliver_at']), 'collected' => $r['collected_at'] !== null];
        if ($r['r'] !== null) {
            $out['item']['r'] = (int) $r['r'];
        }
        return $out;
    }

    private static function refused(string $why, string $text): array
    {
        return ['status' => 'refused', 'why' => $why, 'text' => $text];
    }

    private static function ms(mixed $datetime): int
    {
        return is_string($datetime) && $datetime !== '' ? (int) strtotime($datetime . ' UTC') * 1000 : 0;
    }

    private function me(): int
    {
        return $this->minn->reader->userId;
    }

    // —— Pure rules (tests share them) ——

    /** The rules the game shows (the create page's copy). */
    public static function rules(): array
    {
        return ['listPercent' => self::LIST_PERCENT, 'listMin' => self::LIST_MIN, 'commission' => self::COMMISSION, 'hours' => intdiv(self::DURATION, 3600), 'delivery' => self::DELIVERY, 'maxActive' => self::MAX_ACTIVE, 'costMax' => self::COST_MAX, 'searchMin' => self::SEARCH_MIN];
    }

    /** inc_auctions.js:168-171: intval(cost / 100 * fee_percent), at least fee_min. */
    public static function fee(int $cost): int
    {
        $fee = (int) ($cost / 100 * self::LIST_PERCENT);
        return $fee > self::LIST_MIN ? $fee : self::LIST_MIN;
    }

    /** inc_auctions.js:504: Math.round(cost * ((100 - commission) / 100)). */
    public static function proceeds(int $cost): int
    {
        return (int) floor($cost * ((100 - self::COMMISSION) / 100) + 0.5);
    }

    /** Why a listing is refused before the ledger is asked ([why, words]), or null. */
    public static function startRefusal(string $c, int $n, int $cost): ?array
    {
        if (!self::listable($c)) {
            return ['type', self::TEXT_FORBIDDEN];
        }
        if ($n < 1 || $n > self::COUNT_MAX) {
            return ['bad_count', self::TEXT_COUNT];
        }
        if ($cost < 1 || $cost > self::COST_MAX) {
            return [$cost < 1 ? 'bad_cost' : 'cost_too_high', self::TEXT_COST];
        }
        return null;
    }

    /** What the ledger carries and the source would auction (data/auctions.json). */
    public static function listable(string $c): bool
    {
        return isset(self::table()['items'][$c]) && Economy::shared()->tradeable($c);
    }

    /** The ledger record of one move: auc-<id>-l (listed), -b (bought), -p (proceeds), -d (delivered). */
    public static function op(string $id, string $what): string
    {
        return "auc-{$id}-{$what}";
    }

    private static function mustBeId(string $id): void
    {
        if (!self::isId($id)) {
            throw new RestError('groddle_auction_unknown', self::TEXT_ENDED, 404);
        }
    }

    /** A listing's id: 16 hex the seller's game makes, so a listing asked twice is one listing. */
    public static function isId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9a-f]{16}$/', $id) === 1;
    }

    /** A search as the browse takes it: trimmed, at most 32 characters, '' under SEARCH_MIN letters. */
    public static function searchText(string $q): string
    {
        $q = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $q)), 0, 32);
        return mb_strlen($q) >= self::SEARCH_MIN ? $q : '';
    }

    /** Listable classes whose name (single or plural) holds the words. */
    public static function classesNamed(string $q): array
    {
        $q = strtolower($q);
        $out = [];
        $economy = Economy::shared();
        foreach (array_keys(self::table()['items']) as $c) {
            $one = (string) preg_replace('/^\d[\d,]* /', '', $economy->label((string) $c, 1));
            $many = (string) preg_replace('/^\d[\d,]* /', '', $economy->label((string) $c, 2));
            if (str_contains(strtolower($one), $q) || str_contains(strtolower($many), $q) || str_contains((string) $c, str_replace(' ', '_', $q))) {
                $out[] = (string) $c;
            }
        }
        return $out;
    }

    /** @return array<string, string> category key -> name */
    public static function catNames(): array
    {
        $out = [];
        foreach (self::table()['cats'] as $row) {
            if (is_array($row) && is_string($row[0] ?? null)) {
                $out[$row[0]] = (string) ($row[1] ?? $row[0]);
            }
        }
        return $out;
    }

    /** data/auctions.json, read once per request. @return array{cats: list<array>, items: array<string,string>} */
    public static function table(): array
    {
        static $table = null;
        if ($table === null) {
            $file = dirname(__DIR__) . '/data/auctions.json';
            $raw = json_decode(is_file($file) ? (string) file_get_contents($file) : '', true);
            $table = ['cats' => is_array($raw['cats'] ?? null) ? $raw['cats'] : [], 'items' => is_array($raw['items'] ?? null) ? $raw['items'] : []];
        }
        return $table;
    }

    private static function goodsJson(array $goods): string
    {
        $items = [];
        foreach ($goods['items'] as $c => $n) {
            $items[] = ['c' => (string) $c, 'n' => (int) $n];
        }
        return (string) json_encode(['items' => $items, 'currants' => (int) $goods['currants']], JSON_UNESCAPED_SLASHES);
    }
}
