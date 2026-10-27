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
 * The owner's Butler for real visitors (register G328, slice 2), the rules
 * of GameServerJS items/bag_butler.js: a visitor on someone's home street
 * leaves a package (leave_package 991-1144, onLeavePackage 3776-3845) with
 * or without a message, or a message alone (leave_message 1146-1195,
 * takeMessage 5833-5873), within the Butler's limits (classProps capacity 5,
 * package_limit 5, message_limit 10, bag_butler.js:27-32; a message is at
 * most 431 characters, 3762); the owner, back home, takes them one at a
 * time (giveNextMessage 2547-2630, giveNextPackage 2632-2776, giveStack
 * 2778-2792). Parcels wait in table groddle_butler_parcels until the owner
 * has taken them; the owner's game adds them to its own Butler.
 *
 * Anything of value moves through the ledger (Ledger, G335):
 *
 *   POST groddle/v1/ledger/butler  (the realtime server, signed: ServiceCall)
 *        {"op":"butler-<16 hex>","from":7,"to":9,"gave":Goods,"text":"…"}
 *        200 {"status":"settled"} | 409 {"status":"refused","why":"full|type|blocked|no_butler|short","text":"…"}
 *
 *   The package leaves the visitor's ledger at once, recorded as a settled
 *   trade with nobody (groddle_trades, b_id 0, as LedgerSpend does), so
 *   until the visitor's save lists the op as applied a write is compared as
 *   if it still held the package, and a load takes it out of the stored
 *   save. The realtime server (homes_butler.go) only asks for a visitor who
 *   stands on the owner's home street with a pass, so nobody leaves a
 *   parcel from afar; it never takes the client's word for the owner.
 *
 *   GET  groddle/v1/butler              the signed-in owner's parcels not yet taken, oldest first
 *   POST groddle/v1/butler/{op}/take    the owner takes one: a package goes into the owner's ledger
 *        200 {"status":"taken","id":op,"credit":"<op>-in"|null,"item":{"c":…,"n":…}|null}
 *        | 200 {"status":"done",…} (taken before)
 *
 *   Taking is recorded as a settled credit with nobody (a: the owner, got
 *   the package; the id <op>-in), applied by the owner's save as a trade is
 *   (the trade slice's applied list; a load applies it to the stored save).
 *   Between the two the package is the Butler's: in neither ledger, in
 *   this table.
 *
 * Ours: the Butler takes only what the ledger can carry (a tradeable class:
 * Economy); anything else gets the source's "Sorry, I'm not equipped to
 * carry that." A visitor the owner blocks is refused (the Butler's owner
 * would not have let them on the street: inc_pols.js:448-455).
 */
final readonly class ButlerParcels
{
    public const TABLE = 'groddle_butler_parcels';

    public const ROUTE = '/groddle/v1/ledger/butler';

    /** bag_butler.js:27-32 classProps. */
    public const CAPACITY = 5;
    public const PACKAGE_LIMIT = 5;
    public const MESSAGE_LIMIT = 10;

    /** bag_butler.js:3762, 5850: input_max_chars and the slice. */
    public const MESSAGE_MAX = 431;

    /** The most of one class in a package (a stack). */
    public const COUNT_MAX = 9999;

    // include/butler_strings.js 143, 159, 160; ours for the rest.
    public const TEXT_MESSAGE_FAIL = "Sorry, I can't remember any more messages.";
    public const TEXT_PACKAGE_FAIL = "Sorry, I can't carry any more packages.";
    public const TEXT_TYPE_FAIL = "Sorry, I'm not equipped to carry that.";
    public const TEXT_NO_BUTLER = "They haven't unboxed their Butler yet.";
    public const TEXT_BLOCKED = 'You are blocked by that player.';
    public const TEXT_SHORT = "You don't have that any more.";
    public const TEXT_EMPTY = 'Leave a package or a message.';

    public function __construct(private Seams $minn)
    {
    }

    // —— The realtime server ——

    #[Route(Method::Post, self::ROUTE, policy: new Policy(Access::Public))]
    public function leave(Request $request): Response
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', self::ROUTE, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
        $body = json_decode($request->body, true);
        $op = is_array($body) ? ($body['op'] ?? null) : null;
        $from = is_array($body) ? ($body['from'] ?? null) : null;
        $to = is_array($body) ? ($body['to'] ?? null) : null;
        $gave = is_array($body) && is_array($body['gave'] ?? null) ? LedgerMath::goods($body['gave']) : null;
        $text = is_array($body) ? self::cleanText($body['text'] ?? '') : null;
        if (!self::opId($op) || !is_int($from) || $from <= 0 || !is_int($to) || $to <= 0 || $from === $to || $gave === null || $text === null
            || $gave['currants'] !== 0 || count($gave['items']) > 1 || (int) (array_values($gave['items'])[0] ?? 1) > self::COUNT_MAX) {
            throw new RestError('groddle_ledger_invalid', 'Not a parcel.', 400);
        }
        if ($gave['items'] === [] && $text === '') {
            throw new RestError('groddle_ledger_invalid', self::TEXT_EMPTY, 400);
        }
        $out = Ledger::atomically($this->minn->db, fn () => $this->take($op, $from, $to, $gave, $text, time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    /**
     * @param array{items: array<string,int>, currants: int} $gave
     * @return array{status: string, why?: string, text?: string}
     */
    private function take(string $op, int $from, int $to, array $gave, string $text, int $now): array
    {
        $db = $this->minn->db;
        $trades = $db->table(Ledger::TRADES);
        $parcels = $db->table(self::TABLE);
        if ($db->value("SELECT 1 FROM {$parcels} WHERE id = ?", [$op]) !== null) {
            return ['status' => 'settled'];
        }
        $held = $db->row("SELECT status FROM {$trades} WHERE trade_id = ? FOR UPDATE", [$op]);
        if ($held !== null) {
            return $held['status'] === 'settled' ? ['status' => 'settled'] : ['status' => 'refused', 'why' => 'full', 'text' => self::TEXT_PACKAGE_FAIL];
        }
        $refuse = function (string $why, string $words) use ($op, $from, $now): array {
            (Ledger::fromSeams($this->minn))->log($from, 'refuse', ['butler' => $op, 'why' => $why], $now);
            return ['status' => 'refused', 'why' => $why, 'text' => $words];
        };
        $ignores = $db->table(Buddies::IGNORES);
        if ($db->value("SELECT 1 FROM {$ignores} WHERE user_id = ? AND ignored_id = ?", [$to, $from]) !== null) {
            return $refuse('blocked', self::TEXT_BLOCKED);
        }
        // The owner's Butler row first (FOR UPDATE on the save keeps two visitors from both taking the last place).
        $saves = $db->table(MultiplayerSave::TABLE);
        $db->value("SELECT version FROM {$saves} WHERE user_id = ? FOR UPDATE", [$to]);
        $state = Neighbours::state($db, $to);
        $butler = $state?->systems?->features?->butler ?? null;
        if (!is_object($butler) || ($butler->owned ?? false) !== true) {
            return $refuse('no_butler', self::TEXT_NO_BUTLER);
        }
        $room = self::room($butler, $this->waiting($to), $gave['items'] !== [], $text !== '');
        if ($room !== null) {
            return $refuse('full', $room);
        }
        $class = (string) (array_key_first($gave['items']) ?? '');
        $count = $class === '' ? 0 : (int) $gave['items'][$class];
        $stamp = gmdate('Y-m-d H:i:s', $now);
        if ($class !== '') {
            if (!Economy::shared()->tradeable($class)) {
                return $refuse('type', self::TEXT_TYPE_FAIL);
            }
            $ledger = Ledger::fromSeams($this->minn);
            $mine = $ledger->lock($from, $now);
            if (($mine['items'][$class] ?? 0) < $count) {
                $db->execute("INSERT INTO {$trades} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'void', ?, 0, ?, '{\"items\":[],\"currants\":0}', ?, ?)", [$op, $from, self::goodsJson($gave), $stamp, $stamp]);
                return $refuse('short', self::TEXT_SHORT);
            }
            self::move($db, $from, $mine, $class, -$count, $now);
            $db->execute("INSERT INTO {$trades} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, '{\"items\":[],\"currants\":0}', ?, ?)", [$op, $from, self::goodsJson($gave), $stamp, $stamp]);
            $ledger->log($from, 'butler', ['butler' => $op, 'to' => $to, 'gave' => ['items' => (object) $gave['items'], 'currants' => 0]], $now);
        }
        $db->execute("INSERT INTO {$parcels} (id, owner_id, from_id, class_tsid, count, message, at_ms, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [$op, $to, $from, $class, $count, $text, $now * 1000, $stamp]);
        return ['status' => 'settled'];
    }

    /**
     * leave_package / leave_message conditions (bag_butler.js 1022-1044,
     * 1159-1176) on the Butler as the owner's save keeps it plus the parcels
     * it has not picked up yet: the reason it is full, or null.
     *
     * @param list<array{id: string, item: bool, text: bool}> $waiting
     */
    public static function room(object $butler, array $waiting, bool $package, bool $message): ?string
    {
        $packages = is_array($butler->packages ?? null) ? $butler->packages : [];
        $messages = is_array($butler->messages ?? null) ? $butler->messages : [];
        $known = [];
        $withPackage = 0;
        foreach ($packages as $p) {
            if (is_object($p) && is_string($p->id ?? null)) {
                $known[$p->id] = true;
            }
        }
        foreach ($messages as $m) {
            if (is_object($m)) {
                if (is_string($m->id ?? null)) {
                    $known[$m->id] = true;
                }
                if (!empty($m->withPackage)) {
                    $withPackage++;
                }
            }
        }
        $nPackages = count($packages);
        $nMessages = count($messages);
        foreach ($waiting as $w) {
            if (isset($known[$w['id']]) || isset($known[$w['id'] . ':m'])) {
                continue;
            }
            if ($w['item']) {
                $nPackages++;
            }
            if ($w['text']) {
                $nMessages++;
                if ($w['item']) {
                    $withPackage++;
                }
            }
        }
        // countContents() >= capacity: the packages and the visitors' gift, against 5 plus one for the gift.
        $gift = is_object($butler->gift ?? null) ? 1 : 0;
        $packagesNoMessages = $nPackages - $withPackage;
        if ($package) {
            if ($nPackages + $gift >= self::CAPACITY + $gift || $nPackages >= self::PACKAGE_LIMIT || $nMessages + $packagesNoMessages >= self::MESSAGE_LIMIT) {
                return self::TEXT_PACKAGE_FAIL;
            }
            return $message && $nMessages >= self::MESSAGE_LIMIT ? self::TEXT_MESSAGE_FAIL : null;
        }
        if ($nMessages >= self::MESSAGE_LIMIT || $nMessages + $packagesNoMessages >= self::MESSAGE_LIMIT) {
            return self::TEXT_MESSAGE_FAIL;
        }
        return null;
    }

    /** @return list<array{id: string, item: bool, text: bool}> */
    private function waiting(int $owner): array
    {
        $db = $this->minn->db;
        $out = [];
        foreach ($db->rows('SELECT id, class_tsid, message FROM ' . $db->table(self::TABLE) . ' WHERE owner_id = ? AND taken_at IS NULL', [$owner]) as $r) {
            $out[] = ['id' => (string) $r['id'], 'item' => (string) $r['class_tsid'] !== '', 'text' => (string) $r['message'] !== ''];
        }
        return $out;
    }

    // —— The owner ——

    /** The signed-in owner's parcels the Butler holds for them, oldest first. */
    #[Route(Method::Get, '/groddle/v1/butler', policy: new Policy(Access::SignedIn))]
    public function list(Request $request): Response
    {
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        $rows = $db->rows('SELECT id, from_id, class_tsid, count, message, at_ms FROM ' . $db->table(self::TABLE) . ' WHERE owner_id = ? AND taken_at IS NULL ORDER BY at_ms, id LIMIT 40', [$me]);
        $names = (new Characters($db))->namesOf(array_map(static fn (array $r): int => (int) $r['from_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $from = (int) $r['from_id'];
            $class = (string) $r['class_tsid'];
            $out[] = [
                'id' => (string) $r['id'],
                'from' => ['id' => $from, 'name' => $names[$from] ?? 'Someone'],
                'item' => $class === '' ? null : ['c' => $class, 'n' => (int) $r['count']],
                'text' => (string) $r['message'],
                'at' => (int) $r['at_ms'],
            ];
        }
        return Response::json(['parcels' => $out]);
    }

    /** giveStack / the message read: the owner takes one parcel; a package comes into their ledger. */
    #[Route(Method::Post, '/groddle/v1/butler/{id:butler-[0-9a-f]+}/take', policy: new Policy(Access::SignedIn))]
    public function takeOne(Request $request, string $id): Response
    {
        if (!self::opId($id)) {
            throw new RestError('groddle_butler_parcel', 'The Butler has nothing like that for you.', 404);
        }
        $me = $this->minn->reader->userId;
        $out = Ledger::atomically($this->minn->db, function () use ($id, $me): ?array {
            $db = $this->minn->db;
            $parcels = $db->table(self::TABLE);
            $row = $db->row("SELECT owner_id, class_tsid, count, taken_at FROM {$parcels} WHERE id = ? FOR UPDATE", [$id]);
            if ($row === null || (int) $row['owner_id'] !== $me) {
                return null;
            }
            $class = (string) $row['class_tsid'];
            $item = $class === '' ? null : ['c' => $class, 'n' => (int) $row['count']];
            $credit = $class === '' ? null : $id . '-in';
            if ($row['taken_at'] !== null) {
                return ['status' => 'done', 'id' => $id, 'credit' => $credit, 'item' => $item];
            }
            $now = time();
            $stamp = gmdate('Y-m-d H:i:s', $now);
            if ($item !== null) {
                $ledger = Ledger::fromSeams($this->minn);
                $state = $ledger->lock($me, $now);
                self::move($db, $me, $state, $class, $item['n'], $now);
                $got = self::goodsJson(['items' => [$class => $item['n']], 'currants' => 0]);
                $db->execute('INSERT IGNORE INTO ' . $db->table(Ledger::TRADES) . " (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, '{\"items\":[],\"currants\":0}', ?, ?, ?)", [$credit, $me, $got, $stamp, $stamp]);
                $ledger->log($me, 'butler', ['butler' => $id, 'took' => [$class => $item['n']]], $now);
            }
            $db->execute("UPDATE {$parcels} SET taken_at = ? WHERE id = ?", [$stamp, $id]);
            return ['status' => 'taken', 'id' => $id, 'credit' => $credit, 'item' => $item];
        });
        if ($out === null) {
            throw new RestError('groddle_butler_parcel', 'The Butler has nothing like that for you.', 404);
        }
        return Response::json($out);
    }

    // —— Helpers ——

    /** takeMessage: utils.filter_chat, the first 431 characters; null when it is not text. */
    public static function cleanText(mixed $text): ?string
    {
        if (!is_string($text)) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        return mb_substr($text, 0, self::MESSAGE_MAX);
    }

    /** homes_butler.go newParcel: butler- and 16 hex. */
    public static function opId(mixed $op): bool
    {
        return is_string($op) && preg_match('/^butler-[0-9a-f]{16}$/', $op) === 1;
    }

    private static function move(\Minn\Db $db, int $user, array $state, string $class, int $by, int $now): void
    {
        $count = ($state['items'][$class] ?? 0) + $by;
        $items = $db->table(Ledger::ITEMS);
        $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET updated_at = ? WHERE user_id = ?', [gmdate('Y-m-d H:i:s', $now), $user]);
        if ($count <= 0) {
            $db->execute("DELETE FROM {$items} WHERE user_id = ? AND class_tsid = ?", [$user, $class]);
        } else {
            $db->execute("INSERT INTO {$items} (user_id, class_tsid, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)", [$user, $class, $count]);
        }
    }

    /** Goods as the realtime server writes them (LedgerMath::goods reads them back). */
    private static function goodsJson(array $goods): string
    {
        $items = [];
        foreach ($goods['items'] as $c => $n) {
            $items[] = ['c' => (string) $c, 'n' => (int) $n];
        }
        return (string) json_encode(['items' => $items, 'currants' => (int) $goods['currants']], JSON_UNESCAPED_SLASHES);
    }
}
