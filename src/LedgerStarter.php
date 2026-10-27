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
 * Starter bags for multiplayer characters made before bags (G152, Austin
 * 2026-10-27: "starter bags yes"). Bags gave the pack 16 slots (game
 * e3162838); an old solo save is packed once into a Red Toolbox and Bigger
 * Bags (the game's js/pack-slots.js packIntoBags), but a multiplayer save
 * could not be: the ledger would see bags it never granted. This grants them
 * through the ledger, the way a refund or a gift arrives:
 *
 *   POST groddle/v1/ledger/starter-bags   (signed in, nonce-checked; no body)
 *        200 {"status":"granted","trade":"starter-bags-7","got":Goods,"text":…,"again"?:true}
 *        | 200 {"status":"none","again"?:true} (everything already fits: nothing, as the solo migration)
 *        | 200 {"status":"refused","why":"new"|"nosave","text":…}
 *
 * Who: a character whose player row (groddle_players.created_at, else the
 * account's user_registered) is older than CUTOFF, the bags commit, and that
 * has a stored multiplayer save. Newer characters buy their own bags in the
 * tutorial (buy_two_bags). Once per character, ever: the decision is one
 * groddle_trades row with the fixed id starter-bags-<user>, settled with the
 * bags given (asking again answers as recorded). An eligible character always
 * gets bags; a void row (none) is only ever read back, never written now.
 *
 * What (Austin 2026-10-27: a floor for everyone): the solo migration's kit for
 * the character's holdings (packIntoBags: nothing while the pack holds
 * everything; else a Red Toolbox when 4 or more tool stacks, then Bigger Bags
 * of 16 until the stacks fit, at most 15 bags), then Bigger Bags topped up to
 * at least FLOOR as a welcome gift (still at most 15 bags in all; starterKit),
 * computed here from data the site keeps, never from a count the page sends:
 * the stored save's pack inventory, only classes the ledger holds (tradeable),
 * each at most what the ledger holds of it. The pack facts (stack sizes, bag
 * kinds, order) are data/pack-slots.json, written by the game's
 * tools/build-site-pack-slots.mjs; layout() and kit() port layoutPack (no
 * saved arrangement) and packIntoBags, and the game's
 * tests/starter-bags-mp.test.js holds them to the same answers.
 *
 * How: the bags go into the ledger at once and the grant is a settled trade
 * from nobody (a_id the player, a_gave nothing, b_gave the bags, b's side
 * applied, as LedgerSpend's refund), so until the save lists the id as
 * applied a write is compared as if the bags were not there yet, a load
 * applies it to the stored save (Ledger::catchUp), and the page that asked
 * applies it once through the trade slice's applied list (ledgerReceipt).
 * The game's bags feature then lays the held stacks into the new bags.
 */
final readonly class LedgerStarter
{
    public const ROUTE = '/groddle/v1/ledger/starter-bags';

    /** The bags commit (game e3162838, site 314b92e), UTC: characters made before it get starter bags. */
    public const CUTOFF = '2026-10-27 12:14:43';

    public const PREFIX = 'starter-bags-';

    /** The ledger log's kind for a decision. */
    public const LOG = 'starter';

    /** Austin 2026-10-27: every character made before bags gets at least this many Bigger Bags. */
    public const FLOOR = 2;

    public const TEXT_NEW = 'Your character is newer than bags: buy your own from a vendor.';
    public const TEXT_NOSAVE = 'Play a while first: starter bags are for characters made before bags.';

    public function __construct(private Seams $minn)
    {
    }

    public static function tradeId(int $user): string
    {
        return self::PREFIX . $user;
    }

    #[Route(Method::Post, self::ROUTE, policy: new Policy(Access::SignedIn))]
    public function ask(Request $request): Response
    {
        $user = $this->minn->reader->userId;
        if (!is_int($user) || $user <= 0) {
            throw new RestError('groddle_starter_signed_out', 'Sign in first.', 401);
        }
        return Response::json(Ledger::atomically($this->minn->db, fn () => $this->grant($user, time())));
    }

    /** @return array<string, mixed> */
    public function grant(int $user, int $now): array
    {
        $db = $this->minn->db;
        $trades = $db->table(Ledger::TRADES);
        $id = self::tradeId($user);
        $economy = Economy::shared();
        $done = $db->row("SELECT trade_id, status, b_gave FROM {$trades} WHERE trade_id = ?", [$id]);
        $madeAt = $this->madeAt($user);
        $stored = $db->value('SELECT data FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ?', [$user]);
        $inventory = self::inventoryOf(is_string($stored) ? $stored : '');
        $first = self::decide($done, $madeAt, $inventory, [], $economy, self::table());
        if ($first['status'] === 'refused' || !empty($first['again'])) {
            return self::answer($first);
        }
        // The ledger row first (FOR UPDATE): two asks for one character take turns here.
        $ledger = Ledger::fromSeams($this->minn);
        $state = $ledger->lock($user, $now);
        $done = $db->row("SELECT trade_id, status, b_gave FROM {$trades} WHERE trade_id = ? FOR UPDATE", [$id]);
        $d = self::decide($done, $madeAt, $inventory, $state['items'], $economy, self::table());
        if ($d['status'] === 'refused' || !empty($d['again'])) {
            return self::answer($d);
        }
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $none = '{"items":[],"currants":0}';
        $why = ['trade' => $id, 'stacks' => $d['kit']['stacks'], 'tools' => $d['kit']['tools'], 'overflow' => $d['kit']['overflow'], 'solo' => $d['kit']['solo']];
        $items = $d['items'];
        $after = LedgerMath::add(['items' => $state['items'], 'currants' => $state['currants']], ['items' => $items, 'currants' => 0]);
        $t = $db->table(Ledger::ITEMS);
        foreach ($items as $c => $_) {
            $db->execute("INSERT INTO {$t} (user_id, class_tsid, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)", [$user, (string) $c, (int) $after['items'][$c]]);
        }
        $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET updated_at = ? WHERE user_id = ?', [$stamp, $user]);
        $db->execute("INSERT INTO {$trades} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$id, $user, $none, self::json(['items' => $items, 'currants' => 0]), $stamp, $stamp]);
        $ledger->log($user, self::LOG, $why + ['got' => ['items' => (object) $items, 'currants' => 0]], $now);
        return self::answer($d + ['trade' => $id]);
    }

    /**
     * The decision, from what the site holds (pure, so the game's tests run it):
     * an answer already recorded stands (again); a character made at or after
     * CUTOFF, or with no stored save, is refused; else starterKit for the
     * pack's holdings the ledger keeps (never nothing).
     *
     * @param array{trade_id: string, status: string, b_gave: string}|null $row the recorded starter-bags-<user> trade
     * @param array<string, int>|null $inventory the stored save's pack (inventoryOf)
     * @param array<string, int> $ledgerItems what the ledger holds
     * @return array{status: string, again?: bool, why?: string, trade?: string, items?: array<string,int>, kit?: array}
     */
    public static function decide(?array $row, ?string $madeAt, ?array $inventory, array $ledgerItems, Economy $economy, array $table): array
    {
        if ($row !== null) {
            if ((string) $row['status'] === 'settled') {
                return ['status' => 'granted', 'again' => true, 'trade' => (string) $row['trade_id'], 'items' => LedgerMath::goods(json_decode((string) $row['b_gave'], true))['items']];
            }
            return ['status' => 'none', 'again' => true];
        }
        if ($madeAt === null || $madeAt === '' || strcmp($madeAt, self::CUTOFF) >= 0) {
            return ['status' => 'refused', 'why' => 'new'];
        }
        if ($inventory === null) {
            return ['status' => 'refused', 'why' => 'nosave'];
        }
        $kit = self::starterKit(self::counted($inventory, $ledgerItems, $economy), $table);
        $items = [];
        foreach ($kit['mint'] as $c) {
            $items[$c] = ($items[$c] ?? 0) + 1;
        }
        return ['status' => 'granted', 'items' => $items, 'kit' => $kit];
    }

    /** A decision as the page reads it. */
    private static function answer(array $d): array
    {
        $out = ['status' => $d['status']];
        if ($d['status'] === 'refused') {
            $out['why'] = $d['why'];
            $out['text'] = $d['why'] === 'new' ? self::TEXT_NEW : self::TEXT_NOSAVE;
        }
        if ($d['status'] === 'granted') {
            $out['trade'] = $d['trade'];
            $out['got'] = json_decode(self::json(['items' => $d['items'], 'currants' => 0]), true);
            $out['text'] = self::text($d['items']);
        }
        if (!empty($d['again'])) {
            $out['again'] = true;
        }
        return $out;
    }

    /** When the character was made: its player row, else its account (UTC, Y-m-d H:i:s). */
    private function madeAt(int $user): ?string
    {
        $db = $this->minn->db;
        $at = $db->value('SELECT created_at FROM ' . $db->table(Characters::TABLE) . ' WHERE user_id = ?', [$user]);
        if (!is_string($at) || $at === '') {
            $at = $db->value('SELECT user_registered FROM ' . $db->table('users') . ' WHERE ID = ?', [$user]);
        }
        return is_string($at) && $at !== '' ? $at : null;
    }

    /** The words the page shows (no em dashes): what was given and why (the game's bags feature words its own the same way). */
    public static function text(array $items): string
    {
        $economy = Economy::shared();
        $parts = [];
        foreach ($items as $c => $n) {
            $n = (int) $n;
            if ($n > 0) {
                $parts[] = $n === 1 ? 'a ' . substr($economy->label((string) $c, 1), 2) : $economy->label((string) $c, $n);
            }
        }
        return 'Your pack has 16 slots now. As a character from before bags, you get starter bags: ' . implode(' and ', $parts) . '.';
    }

    /**
     * The stored save's pack inventory (class => count, in the save's order),
     * or null when there is no character to look at.
     *
     * @return array<string, int>|null
     */
    public static function inventoryOf(string $stored): ?array
    {
        $save = json_decode($stored, true);
        $entries = is_array($save['entries'] ?? null) ? $save['entries'] : null;
        $state = $entries['playerState'] ?? null;
        if (is_string($state)) {
            $state = json_decode($state, true);
        }
        if (!is_array($state) || !is_array($state['inventory'] ?? null)) {
            return null;
        }
        $out = [];
        foreach ($state['inventory'] as $c => $n) {
            $k = LedgerMath::count($n);
            if (is_string($c) && $k > 0) {
                $out[$c] = $k;
            }
        }
        return $out;
    }

    /**
     * What the kit is computed from: each class the pack holds that the ledger
     * keeps, at most what the ledger holds of it (a count the page wrote never
     * raises it).
     *
     * @param array<string, int> $inventory
     * @param array<string, int> $ledgerItems
     * @return array<string, int>
     */
    public static function counted(array $inventory, array $ledgerItems, Economy $economy): array
    {
        $out = [];
        foreach ($inventory as $c => $n) {
            if (!$economy->tradeable((string) $c)) {
                continue;
            }
            $k = min($n, (int) ($ledgerItems[$c] ?? 0));
            if ($k > 0) {
                $out[(string) $c] = $k;
            }
        }
        return $out;
    }

    /** data/pack-slots.json, read once per request. */
    public static function table(): array
    {
        static $table = null;
        if ($table === null) {
            $file = dirname(__DIR__) . '/data/pack-slots.json';
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $table = is_array($decoded) ? $decoded : ['capacity' => 16, 'bags' => [], 'classes' => []];
        }
        return $table;
    }

    // —— The game's pack rules (js/pack-slots.js), over data/pack-slots.json ——

    /** Classes in auto-place order: the catalog's order first, then the rest as the inventory reads (classOrder). */
    private static function order(array $inv, array $table): array
    {
        $known = [];
        $rest = [];
        foreach ($inv as $c => $n) {
            if ($n <= 0) {
                continue;
            }
            $at = (int) ($table['classes'][$c][2] ?? -1);
            if ($at >= 0) {
                $known[$c] = $at;
            } else {
                $rest[] = (string) $c;
            }
        }
        asort($known);
        return array_merge(array_map('strval', array_keys($known)), $rest);
    }

    private static function flags(string $c, array $table): string
    {
        return (string) ($table['classes'][$c][1] ?? '');
    }

    /** splitStacks with stackMaxOf: full stacks first, the remainder last (0: one stack whatever the count). */
    private static function stacks(string $c, int $n, array $table): array
    {
        $max = (int) ($table['classes'][$c][0] ?? 0);
        if ($n <= 0) {
            return [];
        }
        if ($max <= 0 || $max >= $n) {
            return [$n];
        }
        $out = [];
        for ($left = $n; $left > 0; $left -= $max) {
            $out[] = min($max, $left);
        }
        return $out;
    }

    /** bagAccepts. */
    private static function accepts(array $bag, string $c, array $table): bool
    {
        $f = self::flags($c, $table);
        if (str_contains($f, 'x') || isset($table['bags'][$c]) || str_starts_with($c, 'bag_')) {
            return false;
        }
        return match ($bag['holds']) {
            'tool' => str_contains($f, 't'),
            'spice' => str_contains($f, 's'),
            'compound' => str_contains($f, 'c'),
            'cubimal' => str_contains($f, 'u'),
            'element' => str_contains($f, 'e'),
            default => !str_contains($f, 'e') && !str_contains($f, 'n'),
        };
    }

    /**
     * layoutPack with no saved arrangement: the stacks (and bags) the magic
     * rock would hold.
     *
     * @param array<string, int> $inv
     */
    public static function overflow(array $inv, array $table): int
    {
        $capacity = max(1, (int) ($table['capacity'] ?? 16));
        $order = self::order($inv, $table);
        $instances = [];
        $left = [];
        foreach ($order as $c) {
            if (isset($table['bags'][$c])) {
                for ($i = 0; $i < $inv[$c]; $i++) {
                    $instances[] = $c;
                }
            } elseif (!str_contains(self::flags($c, $table), 'x')) {
                $left[$c] = self::stacks($c, $inv[$c], $table);
            }
        }
        $used = 0;
        $boxes = [];
        $unplaced = 0;
        foreach ($instances as $c) {
            if ($used < $capacity) {
                $used++;
                $boxes[] = $table['bags'][$c] + ['slots' => []];
            } else {
                $unplaced++;
            }
        }
        $takes = static function (array $box, string $c) use ($table): bool {
            return count($box['slots']) < (int) $box['cap'] && self::accepts($box, $c, $table) && (!$box['one'] || !in_array($c, $box['slots'], true));
        };
        foreach ($order as $c) {
            while (($left[$c] ?? []) !== []) {
                $target = null;
                foreach ($boxes as $i => $box) {
                    if ($box['limited'] && $takes($box, $c)) {
                        $target = $i;
                        break;
                    }
                }
                if ($target === null && $used < $capacity) {
                    $target = 'pack';
                }
                if ($target === null) {
                    foreach ($boxes as $i => $box) {
                        if (!$box['limited'] && $takes($box, $c)) {
                            $target = $i;
                            break;
                        }
                    }
                }
                if ($target === null) {
                    break;
                }
                array_shift($left[$c]);
                if ($target === 'pack') {
                    $used++;
                } else {
                    $boxes[$target]['slots'][] = $c;
                }
            }
        }
        $rock = $unplaced;
        foreach ($left as $queue) {
            $rock += count($queue);
        }
        return $rock;
    }

    /**
     * packIntoBags: nothing while everything fits; else a Red Toolbox when
     * there are 4 or more tool stacks, then Bigger Bags of 16 until the
     * stacks fit, at most capacity - 1 bags in all.
     *
     * @param array<string, int> $inv
     * @return array{mint: list<string>, stacks: int, tools: int, overflow: int}
     */
    public static function kit(array $inv, array $table): array
    {
        $capacity = max(1, (int) ($table['capacity'] ?? 16));
        $tools = 0;
        $others = 0;
        foreach (self::order($inv, $table) as $c) {
            if (isset($table['bags'][$c]) || str_contains(self::flags($c, $table), 'x')) {
                continue;
            }
            $n = count(self::stacks($c, $inv[$c], $table));
            if (str_contains(self::flags($c, $table), 't')) {
                $tools += $n;
            } else {
                $others += $n;
            }
        }
        $overflow = self::overflow($inv, $table);
        $out = ['mint' => [], 'stacks' => $tools + $others, 'tools' => $tools, 'overflow' => $overflow];
        if ($overflow === 0) {
            return $out;
        }
        $toolsLeft = $tools;
        if ($tools >= 4) {
            $out['mint'][] = 'bag_toolbox_red';
            $toolsLeft = max(0, $tools - (int) ($table['bags']['bag_toolbox_red']['cap'] ?? 16));
        }
        $room = (int) ($table['bags']['bag_bigger']['cap'] ?? 16);
        for ($rest = $toolsLeft + $others; $rest > 0 && count($out['mint']) < $capacity - 1; $rest -= $room) {
            $out['mint'][] = 'bag_bigger';
        }
        return $out;
    }

    /**
     * The starter kit: the solo migration's (kit), then Bigger Bags until there
     * are at least FLOOR of them, never more than capacity - 1 bags in all.
     * mint keeps the solo kit's order (the toolbox first) with the welcome
     * bags after; solo is the solo kit's own length.
     *
     * @param array<string, int> $inv
     * @return array{mint: list<string>, stacks: int, tools: int, overflow: int, solo: int}
     */
    public static function starterKit(array $inv, array $table): array
    {
        $capacity = max(1, (int) ($table['capacity'] ?? 16));
        $kit = self::kit($inv, $table);
        $kit['solo'] = count($kit['mint']);
        $bigger = count(array_filter($kit['mint'], static fn (string $c) => $c === 'bag_bigger'));
        for (; $bigger < self::FLOOR && count($kit['mint']) < $capacity - 1; $bigger++) {
            $kit['mint'][] = 'bag_bigger';
        }
        return $kit;
    }

    /** Goods as the ledger's trades keep them (LedgerMath::goods reads them back). */
    private static function json(array $goods): string
    {
        $items = [];
        foreach ($goods['items'] as $c => $n) {
            $items[] = ['c' => (string) $c, 'n' => $n];
        }
        return (string) json_encode(['items' => $items, 'currants' => $goods['currants']], JSON_UNESCAPED_SLASHES);
    }
}
