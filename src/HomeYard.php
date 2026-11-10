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
 * Visitors' verbs on the animals, Patches and trees of another player's
 * home street (registers G328 and G201). In the original a home street was
 * public: anyone standing there could use the ordinary verbs of what grew
 * and walked on it, and nothing in those verbs asked for the owner or a key:
 *
 * - Piggies: pet and nibble (items/npc_piggy.js verbs.pet 912-960,
 *   verbs.nibble 818-910), Butterflies: massage and milk (npc_butterfly.js
 *   753, 695), Chickens: squeeze (npc_chicken.js 739). Each has a daily
 *   limit per player and animal (canPet / canNibble ...: the player's own
 *   Animal Kinship package, checkSkillPackageOverLimit). Setting an animal
 *   free is the owner's (set_free: container.owner == pc).
 * - Patches: dig with a shovel and tend with a hoe (items/patch.js verbs.dig
 *   120-214, verbs.tend 315-); removing one is the owner's (verbs.remove 63).
 *   HOMES2: plant a Seasoned Bean in a tended Patch (verbs.plant 216-310,
 *   no owner check; not planted, messy or dug, 235-254): the bean leaves the
 *   visitor's ledger as a spend, the Seedling is the owner's street's with
 *   the visitor as its planter (plantBean 742-790, owner = pc :747), and the
 *   planter's grow card sets its timers (patch_seedling.js 218-226); pet a
 *   Seedling, three pets in all from anyone (patch_seedling.js onPetted
 *   196-250). Neither adds to the pool.
 * - Trees grown in them: pet, water and harvest (trant_base verbs 463-600,
 *   include/trants.js; wood_tree.js 474-560), a harvest limited per player
 *   and tree a game day (canHarvest). Remove, trantsform and poison are the
 *   owner's or a key-holder's (wood_tree.js 117, 152, 303). HOMES2: a Wood
 *   Tree's harvest too (once a game day per player, canHarvest 586-612): the
 *   Planks by its maturity are the visitor's (plankProdMap 880-887), and it
 *   loses a maturity every second harvest (744-764); no pool.
 *
 * - Rocks and resource nodes the owner cultivated (MPVISIT; game
 *   js/yard-stock-mp.js): mine a rock (items/include/mining.js verbs.mine:
 *   a pick and Mining I), harvest a Peat Bog (peat_1.js), dig a Dirt Pile
 *   (dirt_pile.js), scrape a Mortar Barnacle (mortar_barnacle.js), scoop a
 *   Jellisac (jellisac.js). None asks for the owner or a key; the yield is
 *   the actor's (mining.js:344, peat_1.js:268, dirt_pile.js 413/431,
 *   mortar_barnacle.js:261). Here the site rolls a swing's chunks and keeps
 *   the rock's chunks, each node's stock and daily limits per visitor and
 *   the cultivation's wear, from the owner's stored street (the world rock
 *   slots, the resource-nodes slot, the cultivations' wear) and the acts
 *   still waiting for the owner's game, so every visitor and the owner see
 *   one rock. A worn-out cultivation takes no more (cultivation.js addWear,
 *   replaceWithDepleted: the owner's game turns it into its proto).
 * - HOMES2: a Firefly Hive's Fireflies (firefly_hive.js: one every 60 s up to
 *   10), whistled into the visitor's own jar (firefly_jar.js verbs.open
 *   129-216, no owner check); each caught is gone 10 s for everyone and wears
 *   the hive a use. A jar filled on a home street pays its pool 25
 *   (firefly_jar.js:383); the site cannot see the visitor's jar, so each
 *   seventh Firefly a visitor catches there in a game day counts as one
 *   (data "jarFull").
 *
 * Each of these acts by someone who is not the owner adds a fixed amount to
 * the owner's Magic Rock pool (inc_cultivation.js cultivation_add_img_rewards
 * 168-178; the amounts are in data/yard-verbs.json "pool", from the handlers:
 * npc_piggy.js 1664/1764, npc_butterfly.js 1187/1356, npc_chicken.js 1560,
 * patch.js 615/714, include/trants.js 90/196/420, wood_tree.js 1018; a rock
 * mined out include/mining.js 388-401, peat_1.js:301, dirt_pile.js:470,
 * mortar_barnacle.js:304, jellisac.js:234).
 *
 * The realtime server sees the visitor on HOME_<owner> and asks here, signed
 * (groddle-afternoon-server home_yard.go); this class decides from the
 * owner's stored save, the acts already waiting for the owner (table
 * groddle_home_yard) and the visitor's own skills in their stored save, and
 * never from an amount the client names. A visitor's uses of a thing today
 * are marks in HomePool's groddle_home_pool_marks ("y-<verb>-<id>-<n>", the
 * amount it added), so the source's daily limits hold and each act counts
 * into the pool once. The owner's game applies the waiting acts to its own
 * home on load, or at once when the realtime server tells it, and lists the
 * ids it applied in its slice (systems.features['yard-verbs-mp'].applied);
 * those leave the table. What the visitor gets (mood, energy, iMG, tool
 * wear) is their own game's, as with any verb; the items an act gives that
 * the site can count (the Animal Kinship items, a dig's earth, a harvest's
 * fruit taken from the owner's tree) are a credit receipt in the ledger
 * (LedgerCredits, why "take"), so the visitor's next save shows them exactly.
 *
 *   POST groddle/v1/homes/yard            (the realtime server, signed)
 *        {"op":"yd-<16 hex>","owner":7,"visitor":9,"kind":"animal|plot|tree","id":"a3|patch:0","verb":"pet"}
 *        200 {"status":"settled","act":{…},"added":4,"today":12} | 409 {"status":"refused","why":"…","text":"…"}
 *   GET  groddle/v1/homes/{owner}/yard    a visitor: the owner's Patches, trees and animals as they stand, and my uses today
 *        (and, to look at only, the owner's placed Magic Sticks and yard machines: "sticks", "machines")
 *   GET  groddle/v1/homes/yard            the owner: the acts waiting for my game
 */
final readonly class HomeYard
{
    public const TABLE = 'groddle_home_yard';
    public const ROUTE = '/groddle/v1/homes/yard';

    /** The home street's tsid in a save (js/features/homes-feature.js HOME_STREET_TSID). */
    public const STREET = 'RH_HOME_EXT';
    /** The game's js/animal-husbandry.js MAGIC_STICKS. */
    public const MAGIC_STICKS = ['magic_pig_stick', 'magic_chicken_stick', 'magic_butterfly_stick'];
    /** The game's js/animal-husbandry.js REMOTE_MACHINES: what Remote Herdkeeping sets down on the home street. */
    public const YARD_MACHINES = ['meat_collector', 'butterfly_milker', 'piggy_feeder'];
    /** The most yard machines one view lists (the game works three collectors of a kind at most; more fail). */
    public const YARD_MACHINES_MAX = 24;
    public const SLICE = 'yard-verbs-mp';

    public const WAITING_MAX = 200;

    public const KINDS = ['animal', 'plot', 'tree', 'rock', 'node'];
    public const ANIMAL_VERBS = ['npc_piggy' => ['pet', 'nibble'], 'npc_butterfly' => ['massage', 'milk'], 'npc_chicken' => ['squeeze']];
    public const PLOT_VERBS = ['dig', 'tend', 'pet', 'plant'];
    public const TREE_VERBS = ['pet', 'water', 'harvest'];
    public const WOOD_VERBS = ['pet', 'water', 'harvest'];
    public const LABELS = ['npc_piggy' => 'Piggy', 'npc_butterfly' => 'Butterfly', 'npc_chicken' => 'Chicken'];

    /** Animal Kinship VI lets you nibble without petting and milk without a massage first (js/animal-kinship.js). */
    public const AK_NO_WARMUP = 6;

    public const TEXT_GONE = 'That is not there any more.';
    public const TEXT_BUSY = 'Their yard has had a lot of help today. Try again later.';
    public const TEXT_BLOCKED = 'You can\'t do that here.';
    public const TEXT_NEEDS_PET = 'Give that Piggy some love first!'; // js/animal-kinship.js
    public const TEXT_NEEDS_MASSAGE = 'What? No warmup? Massage this Butterfly first.'; // js/animal-kinship.js
    public const TEXT_NO_TEND = 'Nope, no tending needed.'; // patch.js verbs.tend
    public const TEXT_DUG = 'There is already a hole there.'; // patch.js verbs.dig
    public const TEXT_SEEDLING = 'A seedling is growing - why would you dig it out?'; // patch.js verbs.dig
    public const TEXT_PETTED = 'I don\'t want to be petted!'; // trant_base verbs.pet
    public const TEXT_WATERED = 'I don\'t want to be watered!'; // trant_base verbs.water
    public const TEXT_WOOD_PETTED = 'I don\'t want to be petted right now!'; // wood_tree.js verbs.pet
    public const TEXT_WOOD_WATERED = 'I don\'t want to be watered right now!'; // wood_tree.js verbs.water
    public const TEXT_NOTHING = 'There\'s nothing to pick, cowboy!'; // trant_base verbs.harvest
    public const TEXT_SMALL = 'This tree is too small to harvest.'; // js/trants.js canHarvestTrant
    public const TEXT_MINED = 'This rock is mined out. Wait for it to respawn.'; // js/mining.js mineRock
    public const TEXT_WORN = 'That has been used up. It needs restoring first.'; // ours: cultivation.js replaceWithDepleted
    public const TEXT_WOOD_DAILY = 'You can only harvest from this tree once per game day.'; // wood_tree.js:495
    public const TEXT_PET_NO_MORE = "You don't need to pet this seedling any more. Just wait a few minutes."; // patch_seedling.js:249
    public const TEXT_PLANTED = "There's already a seedling here."; // patch.js:238
    public const TEXT_MESSY = 'You should probably tend to the weeds first.'; // patch.js:245
    public const TEXT_HOLE = 'You need to wait for the hole to fill in first.'; // patch.js:252
    public const TEXT_NO_BEAN = "You don't have a seasoned bean to plant."; // ours (js/patches.js NO_SEASONED_BEAN_MSG)
    /** js/resource-nodes.js gateVerb (the objects.go notReady words). */
    public const NOT_READY = [
        'mortar_barnacle' => 'This barnacle is not ready yet!', // mortar_barnacle.js:64
        'jellisac' => 'This Jellisac is not ready yet!', // jellisac.js:54
        'dirt_pile' => 'There isn\'t enough dirt left to dig.', // dirt_pile.js:113
        'peat_1' => 'No more peat here!', // peat_1.js:143
    ];
    /** js/resource-nodes.js gateVerb daily limit words. */
    public const DAILY_TEXT = [
        'dirt_pile' => 'You\'ve dug this dirt pile enough for today.',
        'peat_1' => 'You can\'t harvest this peat more than once per game day.',
        'peat_1:2' => 'You can\'t harvest this peat more than twice per game day.',
    ];

    public function __construct(private Seams $minn)
    {
    }

    // —— The realtime server ——

    #[Route(Method::Post, self::ROUTE, policy: new Policy(Access::Public))]
    public function act(Request $request): Response
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', self::ROUTE, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
        $b = json_decode($request->body, true);
        $op = is_array($b) ? ($b['op'] ?? null) : null;
        $owner = is_array($b) ? ($b['owner'] ?? null) : null;
        $visitor = is_array($b) ? ($b['visitor'] ?? null) : null;
        $kind = is_array($b) ? ($b['kind'] ?? null) : null;
        $id = is_array($b) ? ($b['id'] ?? null) : null;
        $verb = is_array($b) ? ($b['verb'] ?? null) : null;
        $what = is_array($b) ? ($b['what'] ?? null) : null;
        if ($verb === 'plant' && (!is_string($what) || !isset(self::rules()['beans'][$what]))) {
            throw new RestError('groddle_yard_invalid', 'Name the bean.', 400);
        }
        if (!is_string($op) || preg_match('/^yd-[0-9a-f]{16}$/', $op) !== 1 || !is_int($owner) || !is_int($visitor) || $owner <= 0 || $visitor <= 0
            || !in_array($kind, self::KINDS, true) || !self::validId($kind, $id) || !is_string($verb) || preg_match('/^[a-z]{2,8}$/', $verb) !== 1) {
            throw new RestError('groddle_yard_invalid', 'Name the act.', 400);
        }
        if ($owner === $visitor) {
            return Response::json(['status' => 'refused', 'why' => 'own', 'text' => HomePool::TEXT_OWN], 409);
        }
        if ($this->blocked($owner, $visitor)) {
            return Response::json(['status' => 'refused', 'why' => 'blocked', 'text' => self::TEXT_BLOCKED], 409);
        }
        $db = $this->minn->db;
        $rules = self::rules();
        $ledger = Ledger::fromSeams($this->minn);
        $out = Ledger::atomically($db, function () use ($db, $ledger, $rules, $op, $owner, $visitor, $kind, $id, $verb, $what): array {
            $db->value('SELECT version FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ? FOR UPDATE', [$owner]);
            $day = HomePool::today();
            $opMark = 'op-' . substr($op, 3);
            $had = $db->value('SELECT amount FROM ' . $db->table(HomePool::MARKS) . ' WHERE owner_id = ? AND visitor_id = ? AND mark = ?', [$owner, $visitor, $opMark]);
            if ($had !== null) {
                // The same act asked again (the server's retry): a planting's spend as recorded.
                $spent = $db->value('SELECT a_gave FROM ' . $db->table(Ledger::TRADES) . " WHERE trade_id = ? AND a_id = ? AND status = 'settled'", [$op, $visitor]);
                return ['status' => 'settled', 'again' => true, 'added' => (int) $had, 'act' => null] + ($spent !== null ? ['receipt' => ['op' => $op, 'gave' => json_decode((string) $spent, true)]] : []);
            }
            $state = Neighbours::state($db, $owner);
            if (!Neighbours::hasHome($state)) {
                return ['status' => 'refused', 'why' => 'no_home', 'text' => HomePool::TEXT_NO_HOME];
            }
            $t = $db->table(self::TABLE);
            $waiting = self::unapplied($state, $db->rows("SELECT id, kind, target, verb, took, what, at_ms FROM {$t} WHERE owner_id = ? ORDER BY at_ms, id", [$owner]));
            if (count($waiting) >= self::WAITING_MAX) {
                return ['status' => 'refused', 'why' => 'busy', 'text' => self::TEXT_BUSY];
            }
            $visitorState = Neighbours::state($db, $visitor);
            $skills = self::learned($visitorState);
            $uses = self::usesOf($db, $owner, $visitor, $day);
            $nowMs = (int) floor(microtime(true) * 1000);
            $judged = $kind === 'rock' || $kind === 'node'
                ? self::judgeStock($rules, $state, $waiting, $uses, self::upgrades($visitorState), $kind, $id, $verb, $nowMs, static fn (int $lo, int $hi): int => random_int($lo, $hi))
                : self::judge($rules, $state, $waiting, $uses, $skills, $kind, $id, $verb, $nowMs);
            if (isset($judged['why'])) {
                return ['status' => 'refused', 'why' => $judged['why'], 'text' => $judged['text']];
            }
            // HOMES2: a bean planted leaves the visitor's ledger as a spend (LedgerSpend's bookkeeping), once.
            $receipt = null;
            $planted = null;
            if ($verb === 'plant') {
                $bean = (string) $what;
                $vstate = $ledger->lock($visitor, time());
                if (($vstate['items'][$bean] ?? 0) < 1) {
                    return ['status' => 'refused', 'why' => 'short', 'text' => self::TEXT_NO_BEAN];
                }
                self::spendOne($db, $ledger, $op, $visitor, $vstate, $bean);
                $receipt = ['op' => $op, 'gave' => ['items' => [['c' => $bean, 'n' => 1]], 'currants' => 0]];
                $card = self::growCard($rules, self::upgrades($visitorState));
                $planted = $bean . ($card !== null ? ':' . $card : '');
            }
            $pool = (int) ($rules['pool'][$judged['pool'] ?? ''] ?? 0);
            $n = ($uses[$verb . ':' . $id] ?? 0) + 1;
            HomePool::mark($db, $owner, $visitor, $day, 'y-' . $verb . '-' . $id . '-' . $n, $pool);
            HomePool::mark($db, $owner, $visitor, $day, $opMark, $pool);
            $took = (int) ($judged['took'] ?? 0);
            $db->execute("INSERT INTO {$t} (id, owner_id, visitor_id, kind, target, verb, took, what, at_ms, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$op, $owner, $visitor, $kind, $id, $verb, $took, $planted, $nowMs, gmdate('Y-m-d H:i:s')]);
            HomePool::add($db, $owner, $pool, $day);
            // What the visitor's game will show of the act, as a receipt (credits.go's "take"; LedgerCredits).
            $got = $judged['got'] ?? [];
            if ($got !== []) {
                $items = array_map(static fn (string $c, int $n): array => ['c' => $c, 'n' => $n], array_keys($got), array_values($got));
                $ev = LedgerCredits::event(['id' => 'cr-y-' . substr($op, 3), 'user' => $visitor, 'why' => 'take', 'got' => ['items' => $items, 'currants' => 0], 'at' => time()], Economy::shared());
                if ($ev !== null) {
                    LedgerCredits::record($db, $ledger, $visitor, [$ev], Economy::shared(), time());
                }
            }
            $today = (int) ($db->value('SELECT amount FROM ' . $db->table(HomePool::TABLE) . ' WHERE owner_id = ? AND day = ?', [$owner, $day]) ?? 0);
            return [
                'status' => 'settled',
                'added' => $pool,
                'today' => $today,
                'act' => ['id' => $op, 'kind' => $kind, 'target' => $id, 'verb' => $verb, 'took' => $took, 'at' => $nowMs, 'got' => (object) $got] + (isset($judged['left']) ? ['left' => $judged['left']] : []) + ($planted !== null ? ['what' => $planted] : []),
            ] + ($receipt !== null ? ['receipt' => $receipt] : []);
        });
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    // —— A visitor ——

    /** The owner's Patches, trees and animals on their home street as they stand now, and my uses of them today. */
    #[Route(Method::Get, '/groddle/v1/homes/{id:\d+}/yard', policy: new Policy(Access::SignedIn))]
    public function view(Request $request, string $id): Response
    {
        $owner = (int) $id;
        $me = $this->minn->reader->userId;
        $db = $this->minn->db;
        if ($owner <= 0 || ($owner !== $me && $this->blocked($owner, $me))) {
            throw new RestError('groddle_yard_blocked', self::TEXT_BLOCKED, 403);
        }
        $state = Neighbours::state($db, $owner);
        if (!Neighbours::hasHome($state)) {
            throw new RestError('groddle_yard_none', HomePool::TEXT_NO_HOME, 404);
        }
        $waiting = self::unapplied($state, $db->rows('SELECT id, kind, target, verb, took, what, at_ms FROM ' . $db->table(self::TABLE) . ' WHERE owner_id = ? ORDER BY at_ms, id', [$owner]));
        $nowMs = (int) floor(microtime(true) * 1000);
        $rules = self::rules();
        // MPVISIT: the rocks and resource nodes as they stand now (worn-out ones are protos: not listed).
        $rocks = [];
        foreach (self::homeRocks($rules, $state) as $r) {
            if (!self::worn($rules, $state, $waiting, $r['item'])) {
                $rocks[] = ['id' => $r['id'], 'classId' => $r['classId'], 'rx' => $r['rx'], 'rock' => self::rockNow($rules, $state, $waiting, $r, $nowMs)];
            }
        }
        $nodes = [];
        foreach (self::homeNodes($rules, $state, $nowMs) as $n) {
            if (!self::worn($rules, $state, $waiting, $n['item'], $nowMs)) {
                $node = self::nodeNow($rules, $state, $waiting, $n, $nowMs);
                // A Firefly caught is away until it comes back (resource-nodes 'respawn' stock).
                if ($n['cls'] === 'npc_firefly' && $node['units'] < 1) {
                    $node += ['depleted' => true, 'respawnAt' => $node['regrowAt']];
                }
                $nodes[] = ['id' => $n['id'], 'classTsid' => $n['classTsid'], 'rx' => $n['rx'], 'dy' => $n['dy'], 'node' => $node];
            }
        }
        $patches = [];
        foreach (self::patchItems($state) as $key => $rx) {
            $plot = self::plotNow(self::storedPlot($state, $key), $key, $waiting, $nowMs, $rules);
            if ($plot !== null) {
                $patches[] = ['key' => $key, 'rx' => $rx, 'plot' => $plot];
            }
        }
        $animals = [];
        foreach (self::animalRows($state) as $row) {
            $x = is_numeric($row->x ?? null) ? (int) $row->x : 0;
            // Ticket 99: its own spot, the middle of its walk (the game's js/yard-wander.js).
            $homeX = is_numeric($row->homeX ?? null) ? (int) $row->homeX : $x;
            $animals[] = ['id' => $row->id, 'classId' => $row->classId, 'x' => $x, 'homeX' => $homeX, 'state' => is_object($row->state ?? null) ? $row->state : (object) []];
        }
        $mine = [];
        foreach (self::usesOf($db, $owner, $me, HomePool::today()) as $what => $n) {
            $mine[$what] = $n;
        }
        $homes = Neighbours::homesSlice($state);
        return Response::json([
            'owner' => $owner,
            'name' => (new Characters($db))->namesOf([$owner])[$owner] ?? '',
            'day' => HomePool::today(),
            'cult' => is_object($homes?->cult ?? null) ? $homes->cult : (object) [],
            'patches' => $patches,
            'animals' => $animals,
            'sticks' => self::stickRows($state),
            'machines' => self::machineRows($state),
            'rocks' => $rocks,
            'nodes' => $nodes,
            'mine' => (object) $mine,
        ]);
    }

    // —— The owner ——

    /** The acts waiting for my game; those my stored save says it applied go. */
    #[Route(Method::Get, self::ROUTE, policy: new Policy(Access::SignedIn))]
    public function waiting(Request $request): Response
    {
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        $t = $db->table(self::TABLE);
        $applied = self::applied(Neighbours::state($db, $me));
        if ($applied !== []) {
            $db->execute("DELETE FROM {$t} WHERE owner_id = ? AND id IN (?)", [$me, $applied]);
        }
        $rows = $db->rows("SELECT id, visitor_id, kind, target, verb, took, what, at_ms FROM {$t} WHERE owner_id = ? ORDER BY at_ms, id LIMIT " . self::WAITING_MAX, [$me]);
        $names = (new Characters($db))->namesOf(array_map(static fn (array $r): int => (int) $r['visitor_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $v = (int) $r['visitor_id'];
            $out[] = ['id' => (string) $r['id'], 'from' => ['id' => $v, 'name' => $names[$v] ?? 'Someone'], 'kind' => (string) $r['kind'], 'target' => (string) $r['target'], 'verb' => (string) $r['verb'], 'took' => (int) $r['took'], 'at' => (int) $r['at_ms']]
                + (is_string($r['what'] ?? null) && $r['what'] !== '' ? ['what' => (string) $r['what']] : []);
        }
        return Response::json(['acts' => $out]);
    }

    // —— The rules (pure; tests: php -r) ——

    public static function validId(mixed $kind, mixed $id): bool
    {
        if (!is_string($id)) {
            return false;
        }
        return match ($kind) {
            'animal' => preg_match('/^a\d{1,6}$/', $id) === 1,
            'rock' => preg_match('/^rock:\d{1,2}$/', $id) === 1,
            'node' => preg_match('/^node:[a-z][a-z0-9_]{2,19}:\d{1,2}$/', $id) === 1,
            default => preg_match('/^patch(_dark)?:\d{1,2}$/', $id) === 1,
        };
    }

    /**
     * Whether the act may happen now, and what it does: the pool key, the
     * fruit it takes off the owner's tree, the items the visitor's game will
     * show. A refusal is ['why' => …, 'text' => …].
     *
     * @param array<string, int> $uses "<verb>:<id>" => the visitor's uses today
     * @param list<string> $skills the visitor's learned skills
     * @return array<string, mixed>
     */
    public static function judge(array $rules, ?object $state, array $waiting, array $uses, array $skills, string $kind, string $id, string $verb, int $nowMs): array
    {
        if ($kind === 'animal') {
            $row = null;
            foreach (self::animalRows($state) as $r) {
                if ($r->id === $id) {
                    $row = $r;
                }
            }
            if ($row === null || !in_array($verb, self::ANIMAL_VERBS[$row->classId] ?? [], true)) {
                return ['why' => 'gone', 'text' => self::TEXT_GONE];
            }
            $rank = self::akRank($skills);
            $pkg = $rules['ak'][$row->classId . ':' . $verb][$rank] ?? ['l' => 1, 'b' => 0];
            $used = $uses[$verb . ':' . $id] ?? 0;
            if ($used >= (int) $pkg['l']) {
                $l = (int) $pkg['l'];
                return ['why' => 'limit', 'text' => 'You can only ' . $verb . ' this ' . self::LABELS[$row->classId] . ' ' . $l . ' ' . ($l === 1 ? 'time' : 'times') . ' per game day.'];
            }
            if ($verb === 'nibble' && $rank < self::AK_NO_WARMUP && ($uses['pet:' . $id] ?? 0) < 1) {
                return ['why' => 'warmup', 'text' => self::TEXT_NEEDS_PET];
            }
            if ($verb === 'milk' && $rank < self::AK_NO_WARMUP && ($uses['massage:' . $id] ?? 0) < 1) {
                return ['why' => 'warmup', 'text' => self::TEXT_NEEDS_MASSAGE];
            }
            $item = $rules['akItems'][$row->classId . ':' . $verb] ?? null;
            $got = is_string($item) && (int) $pkg['b'] > 0 ? [$item => (int) $pkg['b']] : [];
            return ['pool' => $row->classId . ':' . $verb, 'got' => $got];
        }
        $plot = self::plotNow(self::storedPlot($state, $id), $id, $waiting, $nowMs, $rules);
        if ($plot === null || !array_key_exists($id, self::patchItems($state))) {
            return ['why' => 'gone', 'text' => self::TEXT_GONE];
        }
        $stage = (string) ($plot->stage ?? 'dirty');
        if ($kind === 'plot') {
            if (!in_array($verb, self::PLOT_VERBS, true) || $stage === 'tree' || $stage === 'dead') {
                return ['why' => 'gone', 'text' => self::TEXT_GONE];
            }
            if ($verb === 'pet') {
                if ($stage !== 'seedling') {
                    return ['why' => 'gone', 'text' => self::TEXT_GONE];
                }
                return (int) ($plot->seedling->petted ?? 0) >= (int) $rules['seedling']['pets'] ? ['why' => 'petted', 'text' => self::TEXT_PET_NO_MORE] : ['pool' => 'patch_seedling:pet'];
            }
            if ($verb === 'plant') {
                return match ($stage) {
                    'seedling' => ['why' => 'stage', 'text' => self::TEXT_PLANTED],
                    'dirty' => ['why' => 'stage', 'text' => self::TEXT_MESSY],
                    'dug' => ['why' => 'stage', 'text' => self::TEXT_HOLE],
                    default => ['pool' => 'patch:plant'],
                };
            }
            if ($verb === 'tend') {
                return $stage === 'dirty' ? ['pool' => 'patch:tend'] : ['why' => 'stage', 'text' => self::TEXT_NO_TEND];
            }
            if ($stage === 'seedling') {
                return ['why' => 'stage', 'text' => self::TEXT_SEEDLING];
            }
            if ($stage === 'dug') {
                return ['why' => 'stage', 'text' => self::TEXT_DUG];
            }
            return ['pool' => 'patch:dig', 'got' => ['earth' => self::digEarth($rules, $skills)]];
        }
        $tree = $plot->tree ?? null;
        if ($stage !== 'tree' || !is_object($tree) || !is_object($tree->state ?? null)) {
            return ['why' => 'gone', 'text' => self::TEXT_GONE];
        }
        $wood = ($tree->classId ?? '') === 'wood_tree';
        if (!in_array($verb, $wood ? self::WOOD_VERBS : self::TREE_VERBS, true)) {
            return ['why' => 'gone', 'text' => self::TEXT_GONE];
        }
        $s = $tree->state;
        $poolKey = ($wood ? 'wood_tree:' : 'trant:') . $verb;
        if ($verb === 'pet') {
            return ($s->petted ?? false) === true ? ['why' => 'tended', 'text' => $wood ? self::TEXT_WOOD_PETTED : self::TEXT_PETTED] : ['pool' => $poolKey];
        }
        if ($verb === 'water') {
            return ($s->watered ?? false) === true ? ['why' => 'tended', 'text' => $wood ? self::TEXT_WOOD_WATERED : self::TEXT_WATERED] : ['pool' => $poolKey];
        }
        if ($wood) {
            // wood_tree.js doHarvest: once a game day per player (canHarvest 586-612), Planks by maturity (plankProdMap 880-887).
            $m = (int) ($s->maturity ?? 0);
            if ($m < 1) {
                return ['why' => 'gone', 'text' => self::TEXT_GONE];
            }
            if (($uses['harvest:' . $id] ?? 0) >= 1) {
                return ['why' => 'limit', 'text' => self::TEXT_WOOD_DAILY];
            }
            $map = ($s->enchanted ?? false) === true ? $rules['wood']['enchanted'] : $rules['wood']['planks'];
            return ['pool' => $poolKey, 'got' => ['plank' => (int) $map[min(6, $m) - 1]]];
        }
        $arbor = self::arbor($rules, $skills);
        if ((int) ($s->maturity ?? 0) < 3) {
            return ['why' => 'small', 'text' => self::TEXT_SMALL];
        }
        $fruit = max(0, (int) ($s->fruitCount ?? 0));
        if ($fruit <= 0) {
            return ['why' => 'empty', 'text' => self::TEXT_NOTHING];
        }
        $l = (int) $arbor['l'];
        if (($uses['harvest:' . $id] ?? 0) >= $l) {
            return ['why' => 'limit', 'text' => 'You can only harvest from each tree ' . $l . ' ' . ($l === 1 ? 'time' : 'times') . ' per game day.'];
        }
        $took = min((int) $arbor['b'], $fruit);
        $class = $rules['fruit'][(string) ($tree->classId ?? '')] ?? null;
        return ['pool' => $poolKey, 'took' => $took, 'got' => is_string($class) ? [$class => $took] : []];
    }

    /**
     * A plot as it stands at now: the stored one (a copy), the day's tending
     * flags gone when the owner's game has not turned the day over, then each
     * waiting act on it in turn, a tended or dug Patch going back to weeds
     * when its time is up (patch.js onRegrowTimer / onFillTimer).
     */
    public static function plotNow(?object $stored, string $key, array $waiting, int $nowMs, array $rules): ?object
    {
        if ($stored === null) {
            return null;
        }
        $plot = json_decode((string) json_encode($stored), false);
        $dayStart = HomePool::GAME_EPOCH_MS + HomePool::dayOf($nowMs) * HomePool::GAME_DAY_MS;
        $s = is_object($plot->tree ?? null) && is_object($plot->tree->state ?? null) ? $plot->tree->state : null;
        if ($s !== null && (int) ($plot->tree->resetAt ?? 0) < $dayStart) {
            $s->petted = false;
            $s->watered = false;
        }
        foreach ($waiting as $w) {
            if ((string) $w['target'] !== $key || (string) $w['kind'] === 'animal') {
                continue;
            }
            $at = (int) $w['at_ms'];
            self::regrow($plot, $at);
            $verb = (string) $w['verb'];
            if ((string) $w['kind'] === 'plot') {
                if ($verb === 'pet') {
                    if (($plot->stage ?? '') === 'seedling' && is_object($plot->seedling ?? null)) {
                        $plot->seedling->petted = min((int) $rules['seedling']['pets'], (int) ($plot->seedling->petted ?? 0) + 1);
                    }
                    continue;
                }
                if ($verb === 'plant') {
                    $bean = explode(':', (string) ($w['what'] ?? ''))[0];
                    if (($plot->stage ?? '') === 'clean' && isset($rules['beans'][$bean])) {
                        $plot->stage = 'seedling';
                        $plot->dueAt = 0;
                        $plot->since = $at;
                        $plot->seedling = (object) ['trantClass' => $rules['beans'][$bean], 'beanClass' => $bean, 'plantedAt' => $at, 'petted' => 0];
                    }
                    continue;
                }
                if ($verb === 'tend' && ($plot->stage ?? '') === 'dirty') {
                    $plot->stage = 'clean';
                    $plot->dueAt = $at + (int) $rules['soil']['cleanRegrowMs'];
                } elseif ($verb === 'dig' && in_array($plot->stage ?? '', ['dirty', 'clean'], true)) {
                    $plot->stage = 'dug';
                    $plot->dueAt = $at + (int) $rules['soil']['dugFillMs'];
                }
                $plot->since = $at;
                continue;
            }
            if ($s === null) {
                continue;
            }
            if (($verb === 'pet' || $verb === 'water') && $at >= $dayStart) {
                $s->{$verb === 'pet' ? 'petted' : 'watered'} = true;
            } elseif ($verb === 'harvest' && ($plot->tree->classId ?? '') === 'wood_tree') {
                // wood_tree.js 744-764: a maturity off every second harvest, at once at 2 or less.
                $m = (int) ($s->maturity ?? 0);
                if ($m > 0 && ($m <= 2 || (int) ($s->harvestCount ?? 0) !== 0)) {
                    $s->maturity = $m - 1;
                    $s->harvestCount = 0;
                } elseif ($m > 0) {
                    $s->harvestCount = 1;
                }
            } elseif ($verb === 'harvest') {
                $s->fruitCount = max(0, (int) ($s->fruitCount ?? 0) - (int) $w['took']);
            }
        }
        self::regrow($plot, $nowMs);
        return $plot;
    }

    private static function regrow(object $plot, int $at): void
    {
        $stage = $plot->stage ?? 'dirty';
        $due = is_numeric($plot->dueAt ?? null) ? (int) $plot->dueAt : 0;
        if (($stage === 'clean' || $stage === 'dug') && $due > 0 && $at >= $due) {
            $plot->stage = 'dirty';
            $plot->since = $due;
            $plot->dueAt = 0;
        }
    }

    // —— Rocks and resource nodes (MPVISIT; game js/yard-stock-mp.js) ——

    /**
     * Whether a visitor's mining swing or node verb may happen now, and what it
     * does: the pool key (a rock pays only when the swing mines it out), the
     * chunks a swing takes ("took", rolled here), the items credited. A refusal
     * is ['why' => …, 'text' => …].
     *
     * @param array<string, int> $uses "<verb>:<id>" => the visitor's uses today
     * @param list<string> $upgrades the visitor's imagination upgrades (daily limits)
     * @param callable(int, int): int $roll random_int
     * @return array<string, mixed>
     */
    public static function judgeStock(array $rules, ?object $state, array $waiting, array $uses, array $upgrades, string $kind, string $id, string $verb, int $nowMs, callable $roll): array
    {
        if ($kind === 'rock') {
            $row = null;
            foreach (self::homeRocks($rules, $state) as $r) {
                if ($r['id'] === $id) {
                    $row = $r;
                }
            }
            $def = $row !== null ? ($rules['rocks'][$row['classId']] ?? null) : null;
            if ($row === null || $def === null || $verb !== 'mine') {
                return ['why' => 'gone', 'text' => self::TEXT_GONE];
            }
            if (self::worn($rules, $state, $waiting, $row['item'])) {
                return ['why' => 'worn', 'text' => self::TEXT_WORN];
            }
            $rock = self::rockNow($rules, $state, $waiting, $row, $nowMs);
            if ($rock['depleted'] || $rock['chunksRemaining'] <= 0) {
                return ['why' => 'empty', 'text' => self::TEXT_MINED];
            }
            // include/mining.js: the whole roll, even past what is left (js/mining.js mineRock).
            $took = $roll((int) $def['min'], (int) $def['max']);
            $cleared = $rock['chunksRemaining'] - $took <= 0;
            return ['pool' => $cleared ? 'rock:' . $def['type'] : '', 'took' => $took, 'got' => [(string) $def['chunk'] => $took], 'left' => max(0, $rock['chunksRemaining'] - $took)];
        }
        $row = null;
        foreach (self::homeNodes($rules, $state, $nowMs) as $n) {
            if ($n['id'] === $id) {
                $row = $n;
            }
        }
        $rule = $row !== null ? ($rules['nodes'][$row['cls']] ?? null) : null;
        if ($row === null || $rule === null || $verb !== $rule['verb']) {
            return ['why' => 'gone', 'text' => self::TEXT_GONE];
        }
        if (self::worn($rules, $state, $waiting, $row['item'], $nowMs)) {
            return ['why' => 'worn', 'text' => self::TEXT_WORN];
        }
        $node = self::nodeNow($rules, $state, $waiting, $row, $nowMs);
        if ($node['units'] < (int) $rule['ready']) {
            return ['why' => 'not_ready', 'text' => self::NOT_READY[$row['cls']] ?? self::TEXT_GONE];
        }
        if (is_array($rule['daily'] ?? null)) {
            $limit = (int) $rule['daily']['base'];
            foreach ($rule['daily']['upgrades'] as $up => $n) {
                if (in_array($up, $upgrades, true)) {
                    $limit = max($limit, (int) $n);
                }
            }
            if (($uses[$verb . ':' . $id] ?? 0) >= $limit) {
                return ['why' => 'limit', 'text' => self::DAILY_TEXT[$row['cls'] . ($limit > 1 ? ':2' : '')] ?? self::DAILY_TEXT[$row['cls']] ?? 'You have done that enough for today.'];
            }
        }
        $left = $rule['after'] !== null ? (int) $rule['after'] : max((int) $rule['min'], $node['units'] - max(1, (int) $rule['consume']));
        if ($row['cls'] === 'npc_firefly') {
            // Into the visitor's jar (a tool's state): nothing credited; every jarFull-th catch here today pays as a full jar (firefly_jar.js:383).
            $caught = 1;
            foreach ($uses as $what => $count) {
                if (str_starts_with((string) $what, 'open:node:npc_firefly:')) {
                    $caught += (int) $count;
                }
            }
            return ['pool' => $caught % (int) $rules['jarFull'] === 0 ? 'firefly_jar:full' : '', 'took' => 1, 'got' => [], 'left' => $left];
        }
        [$c, $n] = $rule['yield'];
        return ['pool' => 'node:' . $row['cls'], 'took' => 1, 'got' => [(string) $c => (int) $n], 'left' => $left];
    }

    /** The street's live rocks in cultivation order (homes-feature rockItems): id, class, rx, the cultivation. */
    public static function homeRocks(array $rules, ?object $state): array
    {
        $out = [];
        foreach (self::cultItems($state) as $item) {
            if (($rules['cult'][$item->protoClass ?? '']['kind'] ?? '') !== 'rock' || ($item->depleted ?? false) === true) {
                continue;
            }
            $i = count($out);
            $class = preg_replace('/_\d+$/', '_1', is_string($item->endClass ?? null) ? $item->endClass : 'rock_metal_1');
            $out[] = ['id' => 'rock:' . $i, 'index' => $i, 'classId' => $class, 'rx' => is_numeric($item->rx ?? null) ? (int) $item->rx : 0, 'item' => $item];
        }
        return $out;
    }

    /** The street's resource nodes a visitor may use (homes-feature nodeKeyMap order), each with its cultivation. */
    public static function homeNodes(array $rules, ?object $state, ?int $nowMs = null): array
    {
        $out = [];
        $counters = [];
        $nowMs ??= (int) floor(microtime(true) * 1000);
        foreach (self::cultItems($state) as $item) {
            $cult = $rules['cult'][$item->protoClass ?? ''] ?? null;
            if ($cult !== null && $cult['kind'] === 'hive' && ($item->depleted ?? false) !== true && isset($rules['nodes']['npc_firefly'])) {
                // HOMES2: the hive's Fireflies (game home-cultivation hiveFireflySpots), keyed npc_firefly:<n>.
                [$every, $most, $radius] = array_map('intval', $cult['hive']);
                $placed = is_numeric($item->placedAt ?? null) ? (int) $item->placedAt : 0;
                $count = min($most, 1 + intdiv(max(0, $nowMs - $placed), max(1, $every)));
                for ($i = 0; $i < $count; $i++) {
                    $n = $counters['npc_firefly'] ?? 0;
                    $counters['npc_firefly'] = $n + 1;
                    $a = ($i / max(1, $count)) * M_PI * 2 + 0.6;
                    $out[] = [
                        'id' => 'node:npc_firefly:' . $n, 'key' => 'npc_firefly:' . $n, 'cls' => 'npc_firefly', 'classTsid' => 'npc_firefly',
                        'rx' => (is_numeric($item->rx ?? null) ? (int) $item->rx : 0) + (int) round(cos($a) * $radius * 0.6),
                        'dy' => -40 - ($i % 3) * 22,
                        'item' => $item,
                    ];
                }
                continue;
            }
            if ($cult === null || $cult['kind'] !== 'node' || ($item->depleted ?? false) === true) {
                continue;
            }
            foreach ($cult['nodes'] as [$cls, $dx, $dy]) {
                $n = $counters[$cls] ?? 0;
                $counters[$cls] = $n + 1;
                if (!isset($rules['nodes'][$cls])) {
                    continue;
                }
                $end = is_string($item->endClass ?? null) && preg_match('/^peat_[123]$/', $item->endClass) === 1 ? $item->endClass : 'peat_1';
                $out[] = [
                    'id' => 'node:' . $cls . ':' . $n,
                    'key' => $cls . ':' . $n,
                    'cls' => $cls,
                    'classTsid' => $cls === 'peat_1' ? $end : $cls,
                    'rx' => (is_numeric($item->rx ?? null) ? (int) $item->rx : 0) + (int) $dx,
                    'dy' => (int) $dy,
                    'item' => $item,
                ];
            }
        }
        return $out;
    }

    /**
     * A rock as it stands at now: the stored world slot (game.js
     * streetWorldState().rocks[i]), then each waiting swing on it, a mined-out
     * rock reforming respawnMs on with its full chunks (js/mining.js tickMineRock).
     *
     * @return array{chunksRemaining: int, depleted: bool, respawnAt: int}
     */
    public static function rockNow(array $rules, ?object $state, array $waiting, array $row, int $nowMs): array
    {
        $def = $rules['rocks'][$row['classId']] ?? ['chunks' => 50];
        $full = (int) $def['chunks'];
        $slot = $state?->world?->streets?->{self::STREET}?->rocks[$row['index']] ?? null;
        $s = is_object($slot) && ($slot->classId ?? '') === $row['classId'] && is_object($slot->rock ?? null) ? $slot->rock : null;
        $rock = [
            'chunksRemaining' => $s !== null && is_numeric($s->chunksRemaining ?? null) ? max(0, (int) $s->chunksRemaining) : $full,
            'depleted' => $s !== null && ($s->depleted ?? false) === true,
            'respawnAt' => $s !== null && is_numeric($s->respawnAt ?? null) ? (int) $s->respawnAt : 0,
        ];
        $back = $s !== null && is_numeric($s->respawnChunks ?? null) && (int) $s->respawnChunks > 0 ? (int) $s->respawnChunks : $full;
        $tick = static function (int $at) use (&$rock, &$back, $full): void {
            if ($rock['depleted'] && $at >= $rock['respawnAt']) {
                $rock = ['chunksRemaining' => $back, 'depleted' => false, 'respawnAt' => 0];
                $back = $full;
            }
        };
        foreach ($waiting as $w) {
            if ((string) $w['kind'] !== 'rock' || (string) $w['target'] !== $row['id']) {
                continue;
            }
            $at = (int) $w['at_ms'];
            $tick($at);
            if ($rock['depleted']) {
                continue;
            }
            $rock['chunksRemaining'] -= (int) $w['took'];
            if ($rock['chunksRemaining'] <= 0) {
                $rock = ['chunksRemaining' => 0, 'depleted' => true, 'respawnAt' => $at + (int) ($rules['respawnMs'] ?? 45000)];
            }
        }
        $tick($nowMs);
        return $rock;
    }

    /**
     * A node as it stands at now: the stored resource-nodes slot (or a fresh
     * one), then each waiting use, one regrow step every mean regrow delay
     * (js/resource-nodes.js tickResourceNode runs the owner's own timer).
     *
     * @return array{units: int, regrowAt: int, totalHarvests: int}
     */
    public static function nodeNow(array $rules, ?object $state, array $waiting, array $row, int $nowMs): array
    {
        $rule = $rules['nodes'][$row['cls']];
        $s = $state?->world?->streets?->{self::STREET}?->features?->{'resource-nodes'}?->nodes?->{$row['key']} ?? null;
        $node = [
            'units' => is_object($s) && is_numeric($s->units ?? null) ? (int) $s->units : (int) $rule['initial'],
            'regrowAt' => is_object($s) && is_numeric($s->regrowAt ?? null) ? (int) $s->regrowAt : 0,
            'totalHarvests' => is_object($s) && is_numeric($s->totalHarvests ?? null) ? (int) $s->totalHarvests : 0,
        ];
        [$lo, $hi, $step] = array_map('intval', $rule['regrow']);
        $step = max(1, $step);
        $mean = (int) (round((($lo + $hi) / 2) / $step) * $step);
        $max = (int) $rule['max'];
        $tick = static function (int $at) use (&$node, $mean, $max): void {
            if ($node['units'] >= $max) {
                $node['regrowAt'] = 0;
                return;
            }
            if ($node['regrowAt'] <= 0) {
                $node['regrowAt'] = $at + $mean;
                return;
            }
            for ($guard = 0; $guard < 64 && $node['units'] < $max && $at >= $node['regrowAt']; $guard++) {
                $node['units']++;
                $node['regrowAt'] = $node['units'] < $max ? $node['regrowAt'] + $mean : 0;
            }
        };
        foreach ($waiting as $w) {
            if ((string) $w['kind'] !== 'node' || (string) $w['target'] !== $row['id']) {
                continue;
            }
            $at = (int) $w['at_ms'];
            $tick($at);
            $node['units'] = $rule['after'] !== null ? (int) $rule['after'] : max((int) $rule['min'], $node['units'] - max(1, (int) $rule['consume']));
            $node['regrowAt'] = $at + $mean;
            $node['totalHarvests']++;
        }
        $tick($nowMs);
        return $node;
    }

    /**
     * Whether a cultivation is worn out (include/cultivation.js addWear,
     * getMaxWear: max wear x 1 + 0.05 a restore, js/home-cultivation.js): its
     * stored wear (a node cultivation's harvests the owner's game has not
     * counted in yet too) and the waiting acts on its rocks or nodes.
     */
    public static function worn(array $rules, ?object $state, array $waiting, object $item, ?int $nowMs = null): bool
    {
        $cult = $rules['cult'][$item->protoClass ?? ''] ?? null;
        if ($cult === null) {
            return true;
        }
        $life = $rules['life'] ?? ['step' => 0.05, 'max' => 20];
        $steps = max(0, min((int) $life['max'], is_numeric($item->lifeSteps ?? null) ? (int) floor((float) $item->lifeSteps) : 0));
        $max = (float) $cult['maxWear'] * (1 + (float) $life['step'] * $steps);
        $used = is_numeric($item->wear ?? null) ? (float) $item->wear : 0.0;
        $mine = [];
        if ($cult['kind'] === 'rock') {
            foreach (self::homeRocks($rules, $state) as $r) {
                if ($r['item'] === $item) {
                    $mine[] = $r['id'];
                }
            }
        } else {
            $harvests = 0;
            foreach (self::homeNodes($rules, $state, $nowMs ?? (int) floor(microtime(true) * 1000)) as $n) {
                if ($n['item'] === $item) {
                    $mine[] = $n['id'];
                    $s = $state?->world?->streets?->{self::STREET}?->features?->{'resource-nodes'}?->nodes?->{$n['key']} ?? null;
                    $harvests += is_object($s) && is_numeric($s->totalHarvests ?? null) ? (int) $s->totalHarvests : 0;
                }
            }
            $used = max($used, (float) $harvests);
        }
        foreach ($waiting as $w) {
            if (in_array((string) $w['target'], $mine, true) && in_array((string) $w['kind'], ['rock', 'node'], true)) {
                $used += 1;
            }
        }
        return $max > 0 && $used >= $max;
    }

    /** The home street's cultivations in the owner's save. */
    private static function cultItems(?object $state): array
    {
        $items = $state?->systems?->features?->homes?->cult?->items ?? [];
        return array_values(array_filter(is_array($items) ? $items : [], static fn ($i): bool => is_object($i) && is_string($i->protoClass ?? null)));
    }

    /** playerState.player.imaginationUpgrades of a stored save. */
    public static function upgrades(?object $state): array
    {
        $list = $state?->player?->imaginationUpgrades ?? [];
        return array_values(array_filter(is_array($list) ? $list : [], 'is_string'));
    }

    /** The planter's grow card (patch_seedling.js 218-226: the better one first), or null. */
    public static function growCard(array $rules, array $upgrades): ?string
    {
        foreach (array_keys($rules['seedling']['grow']) as $card) {
            if (in_array($card, $upgrades, true)) {
                return (string) $card;
            }
        }
        return null;
    }

    /** One bean leaves the visitor's ledger: a settled trade with nobody (LedgerSpend's bookkeeping), op the act's id. */
    private static function spendOne(Db $db, Ledger $ledger, string $op, int $id, array $state, string $class): void
    {
        $now = time();
        $t = $db->table(Ledger::ITEMS);
        $count = (int) ($state['items'][$class] ?? 0) - 1;
        if ($count <= 0) {
            $db->execute("DELETE FROM {$t} WHERE user_id = ? AND class_tsid = ?", [$id, $class]);
        } else {
            $db->execute("INSERT INTO {$t} (user_id, class_tsid, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)", [$id, $class, $count]);
        }
        $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET updated_at = ? WHERE user_id = ?', [gmdate('Y-m-d H:i:s', $now), $id]);
        $gave = (string) json_encode(['items' => [['c' => $class, 'n' => 1]], 'currants' => 0], JSON_UNESCAPED_SLASHES);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $db->execute('INSERT INTO ' . $db->table(Ledger::TRADES) . " (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, '{\"items\":[],\"currants\":0}', ?, ?)", [$op, $id, $gave, $stamp, $stamp]);
        $ledger->log($id, 'spend', ['spend' => $op, 'why' => 'home_plant', 'gave' => ['items' => (object) [$class => 1], 'currants' => 0]], $now);
    }

    /** Waiting acts the owner's stored save does not yet list as applied (those are in its things already). */
    public static function unapplied(?object $state, array $rows): array
    {
        $done = array_flip(self::applied($state));
        return array_values(array_filter($rows, static fn (array $r): bool => !isset($done[(string) $r['id']])));
    }

    /** cultivation_get_available / js/homes-feature.js soilKeyMap: the home street's Patches as plot keys => their x from the house. */
    public static function patchItems(?object $state): array
    {
        $items = $state?->systems?->features?->homes?->cult?->items ?? [];
        $out = [];
        $counters = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_object($item) || ($item->protoClass ?? '') !== 'proto_patch' || ($item->depleted ?? false) === true) {
                continue;
            }
            $end = ($item->endClass ?? 'patch') === 'patch_dark' ? 'patch_dark' : 'patch';
            $idx = $counters[$end] ?? 0;
            $counters[$end] = $idx + 1;
            $out[$end . ':' . $idx] = is_numeric($item->rx ?? null) ? (int) $item->rx : 0;
        }
        return $out;
    }

    public static function storedPlot(?object $state, string $key): ?object
    {
        $plot = $state?->world?->streets?->{self::STREET}?->features?->patches?->plots?->{$key} ?? null;
        return is_object($plot) ? $plot : null;
    }

    /**
     * The owner's animals on the home street (features/animal-husbandry-feature.js
     * slot animals) as anyone else is shown them, and so the only ones a
     * visitor's verb can reach (judge). HERDCAP (Austin 2026-11-09): the save
     * is the owner's game's own (G335) and only that game keeps the crowding
     * limit, so a save edited by hand could hold thousands of animals; no more
     * of a class than capAnimals keeps.
     */
    public static function animalRows(?object $state): array
    {
        $rows = $state?->world?->streets?->{self::STREET}?->features?->{'animal-husbandry'}?->animals ?? [];
        $crowd = self::crowd();
        $width = self::herdWidth($crowd, $state?->systems?->features?->homes?->cult ?? null);
        $upgrades = self::upgrades($state);
        $kept = self::capAnimals(is_array($rows) ? $rows : [], $crowd, static fn (string $classId): int => self::herdCap($crowd, $classId, $width, $upgrades));
        return array_values(array_filter($kept, static fn (object $r): bool => isset(self::ANIMAL_VERBS[$r->classId])));
    }

    /**
     * HERDCAP, the game's js/animal-crowding.js capYardAnimals: the first
     * `scan` rows read, a row whose id was seen before skipped, and of each
     * class the cap with the lowest ids (the oldest: the husbandry slot's ids
     * count up), so the same save always shows the same animals; kept rows
     * stay in their saved order. Rows without an a<n> id or a class go.
     *
     * @param list<mixed> $rows
     * @param callable(string): int $capFor
     * @return list<object>
     */
    public static function capAnimals(array $rows, array $crowd, callable $capFor): array
    {
        $seen = [];
        $valid = [];
        $byClass = [];
        foreach (array_slice($rows, 0, max(0, (int) ($crowd['scan'] ?? 0))) as $r) {
            if (!is_object($r) || !is_string($r->id ?? null) || preg_match('/^a(\d{1,6})$/', $r->id, $m) !== 1 || !is_string($r->classId ?? null) || $r->classId === '' || isset($seen[$r->id])) {
                continue;
            }
            $seen[$r->id] = true;
            $i = count($valid);
            $valid[] = $r;
            $byClass[$r->classId][] = [$i, (int) $m[1]];
        }
        $keep = [];
        foreach ($byClass as $classId => $entries) {
            usort($entries, static fn (array $a, array $b): int => $a[1] <=> $b[1] ?: $a[0] <=> $b[0]);
            foreach (array_slice($entries, 0, max(0, $capFor((string) $classId))) as $e) {
                $keep[$e[0]] = true;
            }
        }
        $out = [];
        foreach ($valid as $i => $r) {
            if (isset($keep[$i])) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * HERDCAP: the most of a class anyone else is shown on the owner's home
     * street, the game's js/animal-crowding.js yardClassCap: getSadnessMax
     * (items/include/animal_sadness.js 126-151) for a POL, not halved, with
     * the owner's best herdkeeping_animal_sadness card; our cap for a class
     * the source never limits (Batterflies, the babies, anything else).
     *
     * @param list<string> $upgrades the owner's imagination cards
     */
    public static function herdCap(array $crowd, string $classId, float $width, array $upgrades): int
    {
        $c = $crowd['controls'][$classId] ?? null;
        if (!is_array($c) || count($c) !== 3) {
            return (int) ($crowd['ours'] ?? 0);
        }
        $max = (int) min(max((int) round($width / 1000) * (int) $c[0], (int) $c[1]), (int) $c[2]);
        foreach ($crowd['cards'] ?? [] as $card) {
            if (is_array($card) && in_array($card[0] ?? null, $upgrades, true)) {
                $max += (int) round($max * (float) $card[1]);
                break;
            }
        }
        return $max;
    }

    /**
     * The home street's width from the owner's street cultivation state, the
     * game's js/yard-verbs-mp.js yardStreetWidth (js/home-cultivation.js
     * homeStreetLayout: the left yard, the house lot, the right yard), each
     * side's expansions held to what the owner's game allows
     * (normalizeCultivationState: 0 up to most - start); a count that is not a
     * number is none.
     */
    public static function herdWidth(array $crowd, mixed $cult): float
    {
        $st = is_array($crowd['street'] ?? null) ? $crowd['street'] : [];
        $side = static function (mixed $parts, mixed $v): float {
            [$start, $most] = is_array($parts) && count($parts) === 2 ? [(float) $parts[0], (float) $parts[1]] : [0.0, 0.0];
            $f = (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) ? (float) $v : 0.0;
            return $start + (is_finite($f) ? floor(max(0.0, min($most - $start, $f))) : 0.0);
        };
        $left = $side($st['left'] ?? null, is_object($cult) ? ($cult->yardLeft ?? null) : null);
        $right = $side($st['right'] ?? null, is_object($cult) ? ($cult->yardRight ?? null) : null);
        return ($left + $right) * (float) ($st['segment'] ?? 0) + (float) ($st['lot'] ?? 0);
    }

    /** data/yard-verbs.json "crowd" (HERDCAP); without it every class at 40 and 300 rows read. */
    public static function crowd(): array
    {
        $crowd = self::rules()['crowd'] ?? null;
        return is_array($crowd) ? $crowd : ['controls' => [], 'cards' => [], 'ours' => 40, 'scan' => 300, 'street' => []];
    }

    /** The owner's placed Magic Sticks on the home street ({ classTsid, x, distance }): where the animals of each class stay. */
    public static function stickRows(?object $state): array
    {
        $rows = $state?->world?->streets?->{self::STREET}?->features?->{'animal-husbandry'}?->sticks ?? [];
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $k) {
            if (is_object($k) && in_array($k->classTsid ?? '', self::MAGIC_STICKS, true) && is_numeric($k->x ?? null)) {
                $out[] = ['classTsid' => $k->classTsid, 'x' => (int) $k->x, 'distance' => is_numeric($k->distance ?? null) && (int) $k->distance > 0 ? (int) $k->distance : 250];
            }
        }
        return $out;
    }

    /**
     * The owner's Meat Collectors, Butterfly Milkers and Piggy Feeders on the home street, for a
     * visitor to see standing there ({ classTsid, x }). In the original each was an item in the
     * location, so everyone there saw it; someone who was not the owner and held no key had no verb
     * on a collector or a milker (meat_collector.js collect 131-134, canPickup 171-180;
     * butterfly_milker.js 131-134, 172-181). What one holds is not sent: it is the owner's.
     *
     * @return list<array{classTsid: string, x: int}>
     */
    public static function machineRows(?object $state): array
    {
        $rows = $state?->world?->streets?->{self::STREET}?->features?->{'animal-husbandry'}?->machines ?? [];
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $m) {
            if (count($out) >= self::YARD_MACHINES_MAX) {
                break;
            }
            if (is_object($m) && in_array($m->classTsid ?? '', self::YARD_MACHINES, true) && is_numeric($m->x ?? null)) {
                $out[] = ['classTsid' => $m->classTsid, 'x' => (int) $m->x];
            }
        }
        return $out;
    }

    /** systems.skills.learned of a stored save. */
    public static function learned(?object $state): array
    {
        $list = $state?->systems?->skills?->learned ?? [];
        return array_values(array_filter(is_array($list) ? $list : [], 'is_string'));
    }

    /** js/animal-kinship.js highestAnimalKinshipRank. */
    public static function akRank(array $skills): int
    {
        $rank = 0;
        for ($i = 1; $i <= 7; $i++) {
            if (in_array('animalkinship_' . $i, $skills, true)) {
                $rank = $i;
            }
        }
        return $rank;
    }

    /** js/trants.js resolveArborologyPackage: the highest gardening_<n> row. */
    public static function arbor(array $rules, array $skills): array
    {
        $best = null;
        foreach ($rules['arbor'] as $row) {
            if ($row['skill'] === null) {
                $best ??= $row;
            } elseif (in_array($row['skill'], $skills, true)) {
                $best = $row;
            }
        }
        return $best ?? ['l' => 1, 'b' => 3];
    }

    /** js/patches.js resolveSoilPackage('dig_patch'): the earth a dig gives. */
    public static function digEarth(array $rules, array $skills): int
    {
        $b = 2;
        foreach ($rules['dig'] as $row) {
            if ($row['skill'] === null || in_array($row['skill'], $skills, true)) {
                $b = (int) $row['b'];
            }
        }
        return $b;
    }

    /** A visitor's uses today of what is on an owner's street: "<verb>:<id>" => n. */
    public static function usesOf(Db $db, int $owner, int $visitor, int $day): array
    {
        $out = [];
        foreach ($db->rows('SELECT mark FROM ' . $db->table(HomePool::MARKS) . " WHERE owner_id = ? AND visitor_id = ? AND day = ? AND mark LIKE 'y-%'", [$owner, $visitor, $day]) as $r) {
            if (preg_match('/^y-([a-z]{2,8})-(.+)-\d+$/', (string) $r['mark'], $m) === 1) {
                $out[$m[1] . ':' . $m[2]] = ($out[$m[1] . ':' . $m[2]] ?? 0) + 1;
            }
        }
        return $out;
    }

    /** The yard act ids the save says it applied (the yard-verbs-mp slice). */
    public static function applied(?object $state): array
    {
        $list = $state?->systems?->features?->{self::SLICE}?->applied ?? [];
        $out = [];
        foreach (is_array($list) ? $list : [] as $id) {
            if (is_string($id) && preg_match('/^yd-[0-9a-f]{16}$/', $id) === 1) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** data/yard-verbs.json (the game's tools/build-yard-verbs.mjs). */
    public static function rules(): array
    {
        static $rules = null;
        if ($rules === null) {
            $raw = @file_get_contents(dirname(__DIR__) . '/data/yard-verbs.json');
            $json = is_string($raw) ? json_decode($raw, true) : null;
            $rules = is_array($json) ? $json : ['pool' => [], 'ak' => [], 'akItems' => [], 'arbor' => [], 'dig' => [], 'soil' => ['cleanRegrowMs' => 90000, 'dugFillMs' => 600000], 'fruit' => []];
        }
        return $rules;
    }

    private function blocked(int $a, int $b): bool
    {
        $t = $this->minn->db->table(Buddies::IGNORES);
        return $this->minn->db->value("SELECT 1 FROM {$t} WHERE (user_id = ? AND ignored_id = ?) OR (user_id = ? AND ignored_id = ?)", [$a, $b, $b, $a]) !== null;
    }
}
