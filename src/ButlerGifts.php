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
 * The owner's Butler hands a gift to each real visitor and remembers who
 * came (register G328, ticket 112), the rules of GameServerJS
 * items/bag_butler.js:
 *
 * - The gift for visitors (verbs.give 600-800, onLeaveVisitorGift
 *   3846-3912): the owner gives the Butler a stack; the same class merges
 *   into what it holds, anything else sends the old gift back to the owner
 *   (onReturnOldGift 4494-4517).
 * - Each visitor greeted gets one of it (giveGift 2482-2540, called from
 *   greetPlayer 2841/2867/2898), once per visitor while the gift is that
 *   class (gifts[pc.tsid] == gift_item.class); the owner gets none (2483).
 *   When the stock runs out the gift and the list of who had one go
 *   (2534-2537), so a new gift reaches everyone again. The source says
 *   nothing to the owner then: the give verb's tip just goes back to "Leave
 *   a gift for your visitors" (get_tooltip 618-629).
 * - Visits (onPlayerEnter 4119-4166): one timestamp per entry for each
 *   visitor, never the owner; at most 5 kept per visitor
 *   (include/butler_data.js clearOldVisitorsData 812-843). "who visited?"
 *   (bag_butler.js verbs.visitors 1373-1447, butler_data.js getVisitors
 *   927-1025) lists, in the owner's game, the visitors since the owner last
 *   asked, at most 20 names.
 *
 * The stock lives here, not in the owner's save, so a visitor gets the gift
 * while the owner is away. Everything of value moves through the ledger
 * (Ledger, G335), as ButlerParcels does the other way:
 *
 *   POST groddle/v1/ledger/butler/visit  (the realtime server, signed: ServiceCall)
 *        {"op":"bvisit-<16 hex>","owner":7,"visitor":9}
 *        200 {"status":"visited","gift":{"c":…,"n":1}|null,"credit":op|null}
 *        | 409 {"status":"refused","why":"blocked|no_butler"}
 *
 *   The realtime server (homes_help.go) asks when a visitor comes onto the
 *   owner's home street (HOME_<owner>, which only a holder of the site's pass
 *   may join). A gift is a settled credit with nobody for the visitor (a:
 *   the visitor, got the item; the id the op), applied by the visitor's
 *   save as a trade is (the trade slice's applied list; a load applies it
 *   to the stored save). The op is the visit's id, so a resend changes
 *   nothing.
 *
 *   GET  groddle/v1/butler/stock          the signed-in owner's gift and visits
 *        200 {"gift":{"c":…,"n":…}|null,"legacy":bool,"visits":[{"id":9,"name":"Bo","at":ms}]}
 *   POST groddle/v1/butler/stock          the owner gives the Butler a gift
 *        {"op":"bgift-<16 hex>","c":"apple","n":3,"keep":true}
 *        200 {"status":"given|done","op":…,"gave":{"c","n"},"got":{"c","n"}|null,"gift":{"c","n"}}
 *        | 409 {"status":"refused","why":"type|short|no_butler","text":"…"}
 *   POST groddle/v1/butler/stock/adopt    the gift a save held before the stock lived here
 *        200 {"status":"adopted|done|none","gift":…} | 409 {"status":"refused","why":"type|budget"}
 *
 *   A give is a settled trade with nobody (a: the owner, gave the stack,
 *   got the old gift back), applied by the owner's save as a trade is.
 *   `keep` is the owner's game's merge rule (bag_butler.js 3894: same class
 *   and within the stack's most); the site merges only when it says so and
 *   the class matches, which moves no value either way.
 *
 * Ours: the Butler takes only what the ledger can carry (a tradeable class:
 * Economy). Before this a save kept the gift in the Butler's slice, which the
 * ledger does not count (LedgerMath::holdings), so the stack left the
 * owner's ledger when it was given; adopting it brings it into the stock
 * once per owner, paid from the owner's ledger budget like any other gain of
 * that value (so a save that claims a gift it never had cannot mint one).
 * Each visit's row is kept 90 days.
 */
final readonly class ButlerGifts
{
    public const STOCK = 'groddle_butler_gift';

    public const GIFTED = 'groddle_butler_gifted';

    public const VISITS = 'groddle_butler_visits';

    public const ROUTE = '/groddle/v1/ledger/butler/visit';

    /** include/butler_data.js clearOldVisitorsData: "Keep a maximum of 5 timestamps for each player". */
    public const VISITS_EACH = 5;

    /** The visits the owner's game is sent (getVisitors names at most 20 of them). */
    public const VISITS_READ = 200;

    /** Ours: a visit row is kept this long. */
    public const VISITS_KEEP_DAYS = 90;

    /** The most of one class the Butler holds (a stack, ButlerParcels::COUNT_MAX). */
    public const COUNT_MAX = 9999;

    public const TEXT_TYPE_FAIL = ButlerParcels::TEXT_TYPE_FAIL;
    public const TEXT_SHORT = ButlerParcels::TEXT_SHORT;
    public const TEXT_NO_BUTLER = "You haven't yet unboxed your butler in your front yard.";

    private const EMPTY_GOODS = '{"items":[],"currants":0}';

    public function __construct(private Seams $minn)
    {
    }

    // —— The realtime server ——

    #[Route(Method::Post, self::ROUTE, policy: new Policy(Access::Public))]
    public function visit(Request $request): Response
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', self::ROUTE, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
        $body = json_decode($request->body, true);
        $op = is_array($body) ? ($body['op'] ?? null) : null;
        $owner = is_array($body) ? ($body['owner'] ?? null) : null;
        $visitor = is_array($body) ? ($body['visitor'] ?? null) : null;
        if (!self::visitId($op) || !is_int($owner) || $owner <= 0 || !is_int($visitor) || $visitor <= 0 || $owner === $visitor) {
            throw new RestError('groddle_ledger_invalid', 'Not a visit.', 400);
        }
        $out = Ledger::atomically($this->minn->db, fn () => $this->greet($op, $owner, $visitor, time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    /** onPlayerEnter for a visitor: the visit noted, then giveGift. */
    private function greet(string $op, int $owner, int $visitor, int $now): array
    {
        $db = $this->minn->db;
        $visits = $db->table(self::VISITS);
        $trades = $db->table(Ledger::TRADES);
        if ($db->value("SELECT 1 FROM {$visits} WHERE id = ?", [$op]) !== null) {
            $row = $db->row("SELECT b_gave FROM {$trades} WHERE trade_id = ? AND a_id = ?", [$op, $visitor]);
            $got = $row === null ? null : self::firstItem((string) $row['b_gave']);
            return ['status' => 'visited', 'gift' => $got, 'credit' => $got === null ? null : $op];
        }
        $ignores = $db->table(Buddies::IGNORES);
        if ($db->value("SELECT 1 FROM {$ignores} WHERE user_id = ? AND ignored_id = ?", [$owner, $visitor]) !== null) {
            return ['status' => 'refused', 'why' => 'blocked'];
        }
        // The owner's save row first, as ButlerParcels does: two visitors at once take the last egg once.
        $db->value('SELECT version FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ? FOR UPDATE', [$owner]);
        $butler = Neighbours::state($db, $owner)?->systems?->features?->butler ?? null;
        if (!is_object($butler) || ($butler->owned ?? false) !== true) {
            return ['status' => 'refused', 'why' => 'no_butler'];
        }
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $db->execute("INSERT INTO {$visits} (id, owner_id, visitor_id, at_ms, created_at) VALUES (?, ?, ?, ?, ?)", [$op, $owner, $visitor, $now * 1000, $stamp]);
        $old = array_map(static fn (array $r): string => (string) $r['id'], $db->rows("SELECT id FROM {$visits} WHERE owner_id = ? AND visitor_id = ? ORDER BY at_ms DESC, id DESC LIMIT " . self::VISITS_EACH . ', 1000', [$owner, $visitor]));
        if ($old !== []) {
            $db->execute("DELETE FROM {$visits} WHERE id IN (?)", [$old]);
        }
        $db->execute("DELETE FROM {$visits} WHERE owner_id = ? AND created_at < ?", [$owner, gmdate('Y-m-d H:i:s', $now - self::VISITS_KEEP_DAYS * 86400)]);

        $stock = $db->table(self::STOCK);
        $gifted = $db->table(self::GIFTED);
        $held = self::stack($db->row("SELECT class_tsid, count FROM {$stock} WHERE owner_id = ? FOR UPDATE", [$owner]));
        $had = $held === null ? null : $db->value("SELECT class_tsid FROM {$gifted} WHERE owner_id = ? AND visitor_id = ?", [$owner, $visitor]);
        $out = self::handOut($held, is_string($had) ? $had : null);
        if ($out['give'] === null) {
            return ['status' => 'visited', 'gift' => null, 'credit' => null];
        }
        $class = $out['give'];
        $ledger = Ledger::fromSeams($this->minn);
        $mine = $ledger->lock($visitor, $now);
        self::move($db, $visitor, $mine, [$class => 1], $now);
        $db->execute("INSERT INTO {$trades} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$op, $visitor, self::EMPTY_GOODS, self::goodsJson([$class => 1]), $stamp, $stamp]);
        $ledger->log($visitor, 'butler', ['butler' => $op, 'from' => $owner, 'gift' => [$class => 1]], $now);
        $db->execute("UPDATE {$stock} SET class_tsid = ?, count = ?, updated_at = ? WHERE owner_id = ?", [$out['after']['c'] ?? '', $out['after']['n'] ?? 0, $stamp, $owner]);
        if ($out['clear']) {
            $db->execute("DELETE FROM {$gifted} WHERE owner_id = ?", [$owner]);
        } else {
            $db->execute("INSERT INTO {$gifted} (owner_id, visitor_id, class_tsid, op, given_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE class_tsid = VALUES(class_tsid), op = VALUES(op), given_at = VALUES(given_at)", [$owner, $visitor, $class, $op, $stamp]);
        }
        return ['status' => 'visited', 'gift' => ['c' => $class, 'n' => 1], 'credit' => $op];
    }

    // —— The owner ——

    /** The gift the Butler holds for visitors, and who came (newest first). */
    #[Route(Method::Get, '/groddle/v1/butler/stock', policy: new Policy(Access::SignedIn))]
    public function stock(Request $request): Response
    {
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        $row = $db->row('SELECT class_tsid, count FROM ' . $db->table(self::STOCK) . ' WHERE owner_id = ?', [$me]);
        $rows = $db->rows('SELECT visitor_id, at_ms FROM ' . $db->table(self::VISITS) . ' WHERE owner_id = ? ORDER BY at_ms DESC, id DESC LIMIT ' . self::VISITS_READ, [$me]);
        $names = (new Characters($db))->namesOf(array_map(static fn (array $r): int => (int) $r['visitor_id'], $rows));
        $visits = [];
        foreach ($rows as $r) {
            $id = (int) $r['visitor_id'];
            $visits[] = ['id' => $id, 'name' => $names[$id] ?? 'Someone', 'at' => (int) $r['at_ms']];
        }
        return Response::json(['gift' => self::stack($row), 'legacy' => $row === null, 'visits' => $visits]);
    }

    /** give (onLeaveVisitorGift): the stack goes to the Butler, an old gift of another class comes back. */
    #[Route(Method::Post, '/groddle/v1/butler/stock', policy: new Policy(Access::SignedIn))]
    public function give(Request $request): Response
    {
        Logins::guard($request, (int) $this->minn->reader->userId); // 409 character_changed from a page of another character
        $body = json_decode($request->body, true);
        $op = is_array($body) ? ($body['op'] ?? null) : null;
        $class = is_array($body) ? ($body['c'] ?? null) : null;
        $n = is_array($body) ? ($body['n'] ?? null) : null;
        $keep = is_array($body) && ($body['keep'] ?? false) === true;
        if (!self::giftId($op) || !is_string($class) || preg_match('/^[a-z0-9_]{1,64}$/', $class) !== 1 || !is_int($n) || $n < 1 || $n > self::COUNT_MAX) {
            throw new RestError('groddle_butler_gift', 'Not a gift.', 400);
        }
        $me = $this->minn->reader->userId;
        $out = Ledger::atomically($this->minn->db, fn () => $this->take($op, $me, $class, $n, $keep, time()));
        if ($out === null) {
            throw new RestError('groddle_butler_gift', 'Not a gift.', 409);
        }
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    private function take(string $op, int $me, string $class, int $n, bool $keep, int $now): ?array
    {
        $db = $this->minn->db;
        $trades = $db->table(Ledger::TRADES);
        $stock = $db->table(self::STOCK);
        $was = $db->row("SELECT a_id, a_gave, b_gave FROM {$trades} WHERE trade_id = ?", [$op]);
        if ($was !== null) {
            if ((int) $was['a_id'] !== $me) {
                return null;
            }
            return ['status' => 'done', 'op' => $op, 'gave' => self::firstItem((string) $was['a_gave']), 'got' => self::firstItem((string) $was['b_gave']), 'gift' => self::stack($db->row("SELECT class_tsid, count FROM {$stock} WHERE owner_id = ?", [$me]))];
        }
        if (!Economy::shared()->tradeable($class)) {
            return ['status' => 'refused', 'why' => 'type', 'text' => self::TEXT_TYPE_FAIL];
        }
        $db->value('SELECT version FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ? FOR UPDATE', [$me]);
        $butler = Neighbours::state($db, $me)?->systems?->features?->butler ?? null;
        if (!is_object($butler) || ($butler->owned ?? false) !== true) {
            return ['status' => 'refused', 'why' => 'no_butler', 'text' => self::TEXT_NO_BUTLER];
        }
        $ledger = Ledger::fromSeams($this->minn);
        $mine = $ledger->lock($me, $now);
        if (($mine['items'][$class] ?? 0) < $n) {
            return ['status' => 'refused', 'why' => 'short', 'text' => self::TEXT_SHORT];
        }
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $merged = self::leave(self::stack($db->row("SELECT class_tsid, count FROM {$stock} WHERE owner_id = ? FOR UPDATE", [$me])), $class, $n, $keep);
        $after = $merged['after'];
        $got = $merged['back'] === null ? [] : [$merged['back']['c'] => $merged['back']['n']];
        if ($merged['clear']) {
            $db->execute('DELETE FROM ' . $db->table(self::GIFTED) . ' WHERE owner_id = ?', [$me]);
        }
        $by = [$class => -$n];
        foreach ($got as $c => $k) {
            $by[$c] = ($by[$c] ?? 0) + $k;
        }
        self::move($db, $me, $mine, $by, $now);
        $db->execute("INSERT INTO {$stock} (owner_id, class_tsid, count, adopted, updated_at) VALUES (?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE class_tsid = VALUES(class_tsid), count = VALUES(count), adopted = 1, updated_at = VALUES(updated_at)", [$me, $after['c'], $after['n'], $stamp]);
        $db->execute("INSERT INTO {$trades} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$op, $me, self::goodsJson([$class => $n]), $got === [] ? self::EMPTY_GOODS : self::goodsJson($got), $stamp, $stamp]);
        $ledger->log($me, 'butler', ['butler' => $op, 'gift' => [$class => $n], 'back' => (object) $got], $now);
        $gotItem = $got === [] ? null : ['c' => (string) array_key_first($got), 'n' => (int) reset($got)];
        return ['status' => 'given', 'op' => $op, 'gave' => ['c' => $class, 'n' => $n], 'got' => $gotItem, 'gift' => $after];
    }

    /** Once per owner: the gift a save's Butler held before the stock lived on the site. */
    #[Route(Method::Post, '/groddle/v1/butler/stock/adopt', policy: new Policy(Access::SignedIn))]
    public function adopt(Request $request): Response
    {
        Logins::guard($request, (int) $this->minn->reader->userId); // 409 character_changed from a page of another character
        $me = $this->minn->reader->userId;
        $out = Ledger::atomically($this->minn->db, fn () => $this->adoptFor($me, time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    private function adoptFor(int $me, int $now): array
    {
        $db = $this->minn->db;
        $stock = $db->table(self::STOCK);
        $db->value('SELECT version FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ? FOR UPDATE', [$me]);
        $row = $db->row("SELECT class_tsid, count FROM {$stock} WHERE owner_id = ? FOR UPDATE", [$me]);
        if ($row !== null) {
            return ['status' => 'done', 'gift' => self::stack($row)];
        }
        $legacy = self::legacyGift(Neighbours::state($db, $me));
        $stamp = gmdate('Y-m-d H:i:s', $now);
        if ($legacy === null) {
            $db->execute("INSERT INTO {$stock} (owner_id, class_tsid, count, adopted, updated_at) VALUES (?, '', 0, 1, ?)", [$me, $stamp]);
            return ['status' => 'none', 'gift' => null];
        }
        $economy = Economy::shared();
        if (!$economy->tradeable($legacy['c'])) {
            return ['status' => 'refused', 'why' => 'type'];
        }
        $ledger = Ledger::fromSeams($this->minn);
        if (!$ledger->charge($me, $economy->value($legacy['c']) * $legacy['n'], $now)) {
            return ['status' => 'refused', 'why' => 'budget'];
        }
        $db->execute("INSERT INTO {$stock} (owner_id, class_tsid, count, adopted, updated_at) VALUES (?, ?, ?, 1, ?)", [$me, $legacy['c'], $legacy['n'], $stamp]);
        $ledger->log($me, 'butler', ['adopt' => [$legacy['c'] => $legacy['n']]], $now);
        return ['status' => 'adopted', 'gift' => $legacy];
    }

    // —— The rules, pure ——

    /**
     * giveGift (bag_butler.js 2482-2540) for one visitor: the class they get
     * (null: nothing, when there is no gift or they had one of this class),
     * the stock after, and whether the list of who had one goes (the last
     * one given: 2534-2537).
     *
     * @param array{c: string, n: int}|null $held
     * @return array{give: ?string, after: array{c: string, n: int}|null, clear: bool}
     */
    public static function handOut(?array $held, ?string $had): array
    {
        if ($held === null || $held['n'] <= 0 || $had === $held['c']) {
            return ['give' => null, 'after' => $held, 'clear' => false];
        }
        $left = $held['n'] - 1;
        return ['give' => $held['c'], 'after' => $left > 0 ? ['c' => $held['c'], 'n' => $left] : null, 'clear' => $left <= 0];
    }

    /**
     * onLeaveVisitorGift (bag_butler.js 3846-3912): a stack of the same class
     * merges when the owner's game says it fits (keep), anything else takes
     * the old gift's place and sends it back (onReturnOldGift 4494-4517), and
     * the visitors start over (the remake's setGift).
     *
     * @param array{c: string, n: int}|null $held
     * @return array{after: array{c: string, n: int}, back: array{c: string, n: int}|null, clear: bool}
     */
    public static function leave(?array $held, string $class, int $n, bool $keep): array
    {
        if ($held !== null && $held['c'] === $class && $keep && $held['n'] + $n <= self::COUNT_MAX) {
            return ['after' => ['c' => $class, 'n' => $held['n'] + $n], 'back' => null, 'clear' => false];
        }
        return ['after' => ['c' => $class, 'n' => $n], 'back' => $held, 'clear' => true];
    }

    // —— Helpers ——

    /** The gift in a save's Butler slice (js/butler.js normalizeStack: classTsid, count), or null. */
    public static function legacyGift(?object $state): ?array
    {
        $gift = $state?->systems?->features?->butler?->gift ?? null;
        if (!is_object($gift)) {
            return null;
        }
        $c = $gift->classTsid ?? null;
        $n = $gift->count ?? null;
        if (!is_string($c) || preg_match('/^[a-z0-9_]{1,64}$/', $c) !== 1 || !is_numeric($n) || (int) $n < 1) {
            return null;
        }
        return ['c' => $c, 'n' => min(self::COUNT_MAX, (int) $n)];
    }

    /** homes_help.go: bvisit- and 16 hex. */
    public static function visitId(mixed $op): bool
    {
        return is_string($op) && preg_match('/^bvisit-[0-9a-f]{16}$/', $op) === 1;
    }

    /** The owner's game (js/neighbours-mp.js butlerGiftOp): bgift- and 16 hex. */
    public static function giftId(mixed $op): bool
    {
        return is_string($op) && preg_match('/^bgift-[0-9a-f]{16}$/', $op) === 1;
    }

    /** @return array{c: string, n: int}|null */
    public static function stack(?array $row): ?array
    {
        if ($row === null || (string) $row['class_tsid'] === '' || (int) $row['count'] <= 0) {
            return null;
        }
        return ['c' => (string) $row['class_tsid'], 'n' => (int) $row['count']];
    }

    /** The first stack in goods as the trades table keeps them. */
    private static function firstItem(string $json): ?array
    {
        $goods = LedgerMath::goods(json_decode($json, true));
        if ($goods['items'] === []) {
            return null;
        }
        $c = (string) array_key_first($goods['items']);
        return ['c' => $c, 'n' => (int) $goods['items'][$c]];
    }

    /** @param array<string,int> $by */
    private static function move(\Minn\Db $db, int $user, array $state, array $by, int $now): void
    {
        $items = $db->table(Ledger::ITEMS);
        $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET updated_at = ? WHERE user_id = ?', [gmdate('Y-m-d H:i:s', $now), $user]);
        foreach ($by as $class => $delta) {
            $count = ($state['items'][$class] ?? 0) + $delta;
            if ($count <= 0) {
                $db->execute("DELETE FROM {$items} WHERE user_id = ? AND class_tsid = ?", [$user, (string) $class]);
            } else {
                $db->execute("INSERT INTO {$items} (user_id, class_tsid, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)", [$user, (string) $class, $count]);
            }
        }
    }

    /** @param array<string,int> $items */
    private static function goodsJson(array $items): string
    {
        $out = [];
        foreach ($items as $c => $n) {
            $out[] = ['c' => (string) $c, 'n' => (int) $n];
        }
        return (string) json_encode(['items' => $out, 'currants' => 0], JSON_UNESCAPED_SLASHES);
    }
}
