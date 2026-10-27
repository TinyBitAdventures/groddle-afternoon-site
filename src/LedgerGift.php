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
 * Giving things to another player (register G332): the pack's Give verb
 * (GameServerJS items/include/takeable.js takeable_give 248-292), a written
 * Note given with its writing (items/note.js), and a wrapped Gift Box given
 * whole (items/bag_gift_box.js wrap 254-297, bag_gift_box_wrapped.js unwrap
 * 419-484). The realtime server (groddle-afternoon-server gifts.go) only asks
 * for two players on the same street; it never takes a client's word for
 * who is there. Anything of value moves through the ledger (Ledger, G335):
 *
 *   POST groddle/v1/ledger/gift  (the realtime server, signed: ServiceCall)
 *        {"op":"gift-<16 hex>","from":7,"to":9,"gave":Goods,"box":false,"ref":"…","note":{"title","body"}|null}
 *        200 {"status":"settled","level":4} | 409 {"status":"refused","why":"blocked|blocking|type|short|bad","text":"…"}
 *
 *   A give is a settled trade (groddle_trades: a the giver, who gave the
 *   goods; b the receiver, who gave nothing), made by Ledger::settle, so the
 *   ledger's own bookkeeping carries it: both ledgers move at once or
 *   neither does, the op is recorded once (asking again answers as
 *   recorded), and each save applies it through the trade slice's applied
 *   list. What a give carries besides counts (a note's writing, whether it
 *   was a wrapped box) waits in groddle_gifts for whoever has not applied it.
 *   level: the receiver's level as their save has it, for the giver's
 *   kindly_randomness (takeable.js 283-289).
 *
 *   GET  groddle/v1/gifts       the signed-in player's gifts, either way, that their save has not applied
 *        {"gifts":[{"op","side":"gave"|"got","with":{"id","name"},"items":[{"c","n","r"?}],"box":bool,"ref"?,"note"|null,"at"}]}
 *
 *   A load applies a pending give to the stored save as the game does
 *   (Ledger::catchUp calls catchUp here): a stack in or out of the pack, a
 *   note with its writing in or out of the carried notes, a wrapped box in
 *   or out of the gifts slice.
 *
 * Words: takeable.js 259, 263; inc_trading.js:456 for a giver whose ledger
 * lacks the goods.
 */
final readonly class LedgerGift
{
    public const TABLE = 'groddle_gifts';

    public const ROUTE = '/groddle/v1/ledger/gift';

    /** features/gifts-feature.js keeps wrapped boxes in this slice; street-furniture keeps carried notes. */
    public const SLICE = 'gifts';
    public const NOTES_SLICE = 'street-furniture';
    public const WRAPPED = 'bag_gift_box_wrapped';
    public const BOX = 'bag_gift_box';
    public const NOTE = 'note';

    /** bag_gift_box.js onCreate 687: capacity 3. */
    public const BOX_SLOTS = 3;

    /** note.js:246-247. */
    public const NOTE_TITLE = 150;
    public const NOTE_BODY = 1000;

    /** A wrapped box the gifts slice keeps (js/gifts.js BOX_KEEP). */
    public const BOX_KEEP = 40;

    public const TEXT_BLOCKED_BY = 'You are blocked by that player and cannot give them anything.';
    public const TEXT_BLOCKING = 'You are blocking that player and cannot give them anything.';
    public const TEXT_SHORT = "You don't have enough of that item.";
    public const TEXT_TYPE = "That can't be given to another player.";

    public function __construct(private Seams $minn)
    {
    }

    // —— The realtime server ——

    #[Route(Method::Post, self::ROUTE, policy: new Policy(Access::Public))]
    public function give(Request $request): Response
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', self::ROUTE, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
        $body = json_decode($request->body, true);
        $gift = is_array($body) ? self::call($body) : null;
        if ($gift === null) {
            throw new RestError('groddle_ledger_invalid', 'Not a gift.', 400);
        }
        $out = Ledger::atomically($this->minn->db, fn () => $this->settle($gift, time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    /**
     * A give as the realtime server sends it, checked: null when it is not one.
     *
     * @return array{op: string, from: int, to: int, items: list<array{c: string, n: int, r?: int}>, box: bool, ref: string, note: ?array{title: string, body: string}}|null
     */
    public static function call(array $body): ?array
    {
        $op = $body['op'] ?? null;
        $from = $body['from'] ?? null;
        $to = $body['to'] ?? null;
        $box = ($body['box'] ?? false) === true;
        $gave = $body['gave'] ?? null;
        if (!self::opId($op) || !is_int($from) || $from <= 0 || !is_int($to) || $to <= 0 || $from === $to || !is_array($gave) || !is_array($gave['items'] ?? null)) {
            return null;
        }
        if (LedgerMath::count($gave['currants'] ?? 0) !== 0) {
            return null;
        }
        $items = [];
        $seen = [];
        foreach ($gave['items'] as $it) {
            $c = is_array($it) ? ($it['c'] ?? null) : null;
            $n = is_array($it) ? ($it['n'] ?? null) : null;
            if (!is_string($c) || preg_match('/^[a-z0-9_]{1,64}$/', $c) !== 1 || !is_int($n) || $n < 1 || $n > 9999 || isset($seen[$c])) {
                return null;
            }
            $seen[$c] = true;
            $row = ['c' => $c, 'n' => $n];
            if (is_int($it['r'] ?? null) && $it['r'] >= 0 && $it['r'] <= 1000000) {
                $row['r'] = $it['r'];
            }
            $items[] = $row;
        }
        if ($box) {
            // The box itself first, then one to three stacks (gifts.go).
            if (count($items) < 2 || count($items) > self::BOX_SLOTS + 1 || $items[0]['c'] !== self::BOX || $items[0]['n'] !== 1) {
                return null;
            }
        } elseif (count($items) !== 1) {
            return null;
        }
        $note = null;
        if (is_array($body['note'] ?? null)) {
            if ($box || $items[0]['c'] !== self::NOTE || $items[0]['n'] !== 1) {
                return null;
            }
            $note = ['title' => self::text($body['note']['title'] ?? '', self::NOTE_TITLE, false), 'body' => self::text($body['note']['body'] ?? '', self::NOTE_BODY, true)];
        }
        $ref = $body['ref'] ?? '';
        $ref = is_string($ref) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ref) === 1 ? $ref : '';
        return ['op' => $op, 'from' => $from, 'to' => $to, 'items' => $items, 'box' => $box, 'ref' => $ref, 'note' => $note];
    }

    /** @return array{status: string, why?: string, text?: string, level?: int} */
    private function settle(array $gift, int $now): array
    {
        $db = $this->minn->db;
        $gifts = $db->table(self::TABLE);
        $trades = $db->table(Ledger::TRADES);
        $level = fn (): int => self::level(Neighbours::state($db, $gift['to']));
        if ($db->value("SELECT 1 FROM {$gifts} WHERE id = ?", [$gift['op']]) !== null) {
            return ['status' => 'settled', 'level' => $level()];
        }
        $held = $db->row("SELECT status FROM {$trades} WHERE trade_id = ? FOR UPDATE", [$gift['op']]);
        if ($held !== null) {
            return $held['status'] === 'settled' ? ['status' => 'settled', 'level' => $level()] : ['status' => 'refused', 'why' => 'short', 'text' => self::TEXT_SHORT];
        }
        $ledger = Ledger::fromSeams($this->minn);
        $refuse = function (string $why, string $text) use ($ledger, $gift, $now): array {
            $ledger->log($gift['from'], 'refuse', ['gift' => $gift['op'], 'to' => $gift['to'], 'why' => $why], $now);
            return ['status' => 'refused', 'why' => $why, 'text' => $text];
        };
        $ignores = $db->table(Buddies::IGNORES);
        if ($db->value("SELECT 1 FROM {$ignores} WHERE user_id = ? AND ignored_id = ?", [$gift['to'], $gift['from']]) !== null) {
            return $refuse('blocked', self::TEXT_BLOCKED_BY);
        }
        if ($db->value("SELECT 1 FROM {$ignores} WHERE user_id = ? AND ignored_id = ?", [$gift['from'], $gift['to']]) !== null) {
            return $refuse('blocking', self::TEXT_BLOCKING);
        }
        foreach ($gift['items'] as $it) {
            if (!Economy::shared()->tradeable($it['c'])) {
                return $refuse('type', self::TEXT_TYPE);
            }
        }
        $goods = ['items' => $gift['items'], 'currants' => 0];
        $out = $ledger->settle($gift['op'], [$gift['from'], $gift['to']], [$goods, ['items' => [], 'currants' => 0]], $now);
        if ($out['status'] !== 'settled') {
            return ['status' => 'refused', 'why' => 'short', 'text' => self::TEXT_SHORT];
        }
        $db->execute("INSERT INTO {$gifts} (id, from_id, to_id, goods, box, ref, note_title, note_body, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $gift['op'], $gift['from'], $gift['to'], (string) json_encode($gift['items'], JSON_UNESCAPED_SLASHES), $gift['box'] ? 1 : 0, $gift['ref'],
            $gift['note']['title'] ?? null, $gift['note']['body'] ?? null, gmdate('Y-m-d H:i:s', $now),
        ]);
        $ledger->log($gift['from'], 'gift', ['gift' => $gift['op'], 'to' => $gift['to'], 'box' => $gift['box'], 'note' => $gift['note'] !== null], $now);
        return ['status' => 'settled', 'level' => $level()];
    }

    // —— The player ——

    /** The signed-in player's gifts (given or got) their save has not applied, oldest first. */
    #[Route(Method::Get, '/groddle/v1/gifts', policy: new Policy(Access::SignedIn))]
    public function mine(Request $request): Response
    {
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        $since = gmdate('Y-m-d H:i:s', time() - Ledger::PENDING_FOR);
        $rows = $db->rows('SELECT g.id, g.from_id, g.to_id, g.goods, g.box, g.ref, g.note_title, g.note_body, g.created_at FROM ' . $db->table(self::TABLE) . ' g JOIN ' . $db->table(Ledger::TRADES) . " t ON t.trade_id = g.id
            WHERE t.status = 'settled' AND g.created_at >= ? AND ((t.a_id = ? AND t.a_applied_at IS NULL) OR (t.b_id = ? AND t.b_applied_at IS NULL))
            ORDER BY g.created_at, g.id LIMIT 40", [$since, $me, $me]);
        $other = array_map(static fn (array $r): int => (int) $r['from_id'] === $me ? (int) $r['to_id'] : (int) $r['from_id'], $rows);
        $names = (new Characters($db))->namesOf($other);
        $out = [];
        foreach ($rows as $i => $r) {
            $g = self::row($r);
            $with = $other[$i];
            $entry = ['op' => $g['op'], 'side' => $g['from'] === $me ? 'gave' : 'got', 'with' => ['id' => $with, 'name' => $names[$with] ?? 'Someone'], 'items' => $g['items'], 'box' => $g['box'], 'note' => $g['note'], 'at' => $g['at']];
            if ($entry['side'] === 'gave' && $g['ref'] !== '') {
                $entry['ref'] = $g['ref'];
            }
            $out[] = $entry;
        }
        return Response::json(['gifts' => $out]);
    }

    // —— A load (Ledger::catchUp) ——

    /** A give's ledger record (gifts.go gift-). */
    public static function isGift(string $id): bool
    {
        return self::opId($id);
    }

    /**
     * Applies one pending give to a stored save (Ledger::catchUp): $p is the
     * pending trade (trade, gave, got as counts), the gift row says the rest.
     */
    public static function catchUp(Db $db, array $entries, int $user, array $p): array
    {
        $r = $db->row('SELECT id, from_id, to_id, goods, box, ref, note_title, note_body, created_at FROM ' . $db->table(self::TABLE) . ' WHERE id = ?', [$p['trade']]);
        if ($r === null) {
            return LedgerMath::applyTrade($entries, $p['trade'], $p['gave'], $p['got']);
        }
        $g = self::row($r);
        $names = (new Characters($db))->namesOf([$g['from']]);
        $g['fromName'] = $names[$g['from']] ?? 'Someone';
        return self::apply($entries, $g['from'] === $user ? 'gave' : 'got', $g);
    }

    /**
     * The game's own apply (js/gifts.js applyGiftToGame), on a stored save:
     * a plain stack moves through LedgerMath::applyTrade; a note keeps its
     * writing (the street-furniture slice's carried notes); a wrapped box is
     * a box in the gifts slice and one bag_gift_box_wrapped in the pack.
     *
     * @param array{op: string, from: int, to: int, items: list<array>, box: bool, ref: string, note: ?array, at: int, fromName?: string} $g
     */
    public static function apply(array $entries, string $side, array $g): array
    {
        $counts = ['items' => [], 'currants' => 0];
        foreach ($g['items'] as $it) {
            $counts['items'][$it['c']] = ($counts['items'][$it['c']] ?? 0) + $it['n'];
        }
        $none = ['items' => [], 'currants' => 0];
        $raw = $entries['playerState'] ?? null;
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, false) : null;
        if (!$state instanceof \stdClass || in_array($g['op'], LedgerMath::applied($entries), true)) {
            return $entries;
        }
        if (!$g['box'] && $g['note'] === null) {
            return $side === 'gave' ? LedgerMath::applyTrade($entries, $g['op'], $counts, $none) : LedgerMath::applyTrade($entries, $g['op'], $none, $counts);
        }
        foreach (['inventory', 'systems'] as $k) {
            if (!($state->{$k} ?? null) instanceof \stdClass) {
                $state->{$k} = new \stdClass();
            }
        }
        if (!($state->systems->features ?? null) instanceof \stdClass) {
            $state->systems->features = new \stdClass();
        }
        $features = $state->systems->features;
        $inv = $state->inventory;
        $put = static function (string $c, int $n) use ($inv): void {
            $inv->{$c} = max(0, LedgerMath::count($inv->{$c} ?? 0) + $n);
        };
        if ($g['box']) {
            if (!($features->{self::SLICE} ?? null) instanceof \stdClass) {
                $features->{self::SLICE} = new \stdClass();
            }
            $slice = $features->{self::SLICE};
            $boxes = is_array($slice->boxes ?? null) ? $slice->boxes : [];
            $inside = array_slice($g['items'], 1);
            if ($side === 'gave') {
                $at = null;
                foreach ($boxes as $i => $b) {
                    if (is_object($b) && ($b->id ?? null) === $g['ref'] && $g['ref'] !== '') {
                        $at = $i;
                    }
                }
                if ($at === null) {
                    foreach ($boxes as $i => $b) {
                        if ($at === null && is_object($b) && self::sameItems($b->items ?? [], $inside)) {
                            $at = $i;
                        }
                    }
                }
                if ($at !== null) {
                    array_splice($boxes, $at, 1);
                    $put(self::WRAPPED, -1);
                }
            } else {
                $boxes[] = (object) ['id' => $g['op'], 'items' => array_map(static fn (array $it): object => (object) $it, $inside), 'from' => (object) ['id' => $g['from'], 'name' => $g['fromName'] ?? 'Someone'], 'at' => $g['at']];
                $boxes = array_slice($boxes, -self::BOX_KEEP);
                $put(self::WRAPPED, 1);
            }
            $slice->boxes = array_values($boxes);
        } else {
            if (!($features->{self::NOTES_SLICE} ?? null) instanceof \stdClass) {
                $features->{self::NOTES_SLICE} = new \stdClass();
            }
            $slice = $features->{self::NOTES_SLICE};
            $carried = is_array($slice->carried ?? null) ? $slice->carried : [];
            $next = max(1, (int) ($slice->nextNoteId ?? 0), count($carried) + 1);
            $note = $g['note'];
            if ($side === 'gave') {
                $at = null;
                foreach ($carried as $i => $n) {
                    if (is_object($n) && (string) ($n->title ?? '') === $note['title'] && (string) ($n->body ?? '') === $note['body']) {
                        $at = $i;
                    }
                }
                if ($at !== null) {
                    array_splice($carried, $at, 1);
                }
                $put(self::NOTE, -1);
            } else {
                $carried[] = (object) ['id' => 'n' . $next, 'title' => $note['title'], 'body' => $note['body'], 'lastEditor' => 'player:' . $g['from'], 'lastEdited' => intdiv($g['at'], 1000), 'history' => []];
                $slice->nextNoteId = $next + 1;
                $put(self::NOTE, 1);
            }
            $slice->carried = array_values($carried);
        }
        $entries['playerState'] = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // Remembered as applied in the trade slice (the counts already moved above).
        return LedgerMath::applyTrade($entries, $g['op'], $none, $none);
    }

    // —— Helpers ——

    /** A gift row as the apply and the list read it. */
    private static function row(array $r): array
    {
        $items = [];
        foreach (json_decode((string) $r['goods'], true) ?: [] as $it) {
            if (is_array($it) && is_string($it['c'] ?? null) && LedgerMath::count($it['n'] ?? 0) > 0) {
                $row = ['c' => $it['c'], 'n' => LedgerMath::count($it['n'])];
                if (is_int($it['r'] ?? null)) {
                    $row['r'] = $it['r'];
                }
                $items[] = $row;
            }
        }
        $note = $r['note_title'] === null && $r['note_body'] === null ? null : ['title' => (string) $r['note_title'], 'body' => (string) $r['note_body']];
        return [
            'op' => (string) $r['id'], 'from' => (int) $r['from_id'], 'to' => (int) $r['to_id'], 'items' => $items, 'box' => (int) $r['box'] === 1,
            'ref' => (string) $r['ref'], 'note' => $note, 'at' => (int) (strtotime((string) $r['created_at'] . ' UTC') * 1000),
        ];
    }

    private static function sameItems(mixed $a, array $b): bool
    {
        $key = static function (mixed $list): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $it) {
                $it = (array) $it;
                if (is_string($it['c'] ?? null)) {
                    $out[$it['c']] = ($out[$it['c']] ?? 0) + LedgerMath::count($it['n'] ?? 0);
                }
            }
            ksort($out);
            return $out;
        };
        return $key($a) === $key($b);
    }

    /** The level a save says (Profiles' rule: 1 to 999), 0 with no save. */
    public static function level(?object $state): int
    {
        $level = $state?->player?->level ?? null;
        if (is_int($level) || (is_numeric($level) && (int) $level == $level)) {
            return max(1, min(999, (int) $level));
        }
        return 0;
    }

    /** gifts.go cleanNoteText: no tags or control characters, a body keeps its lines, at most $max characters. */
    public static function text(mixed $s, int $max, bool $lines): string
    {
        if (!is_string($s)) {
            return '';
        }
        $s = (string) mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        $s = str_replace(["\r\n", "\r", "\t"], [' ', ' ', ' '], $s);
        if (!$lines) {
            $s = str_replace("\n", ' ', $s);
        }
        $s = (string) preg_replace('/[<>\x{0}-\x{8}\x{b}-\x{1f}\x{7f}]/u', '', $s);
        return trim(mb_substr(trim($s), 0, $max));
    }

    /** gifts.go: gift- and 16 hex. */
    public static function opId(mixed $op): bool
    {
        return is_string($op) && preg_match('/^gift-[0-9a-f]{16}$/', $op) === 1;
    }
}
