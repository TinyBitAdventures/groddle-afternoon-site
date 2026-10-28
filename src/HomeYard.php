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
 * - Trees grown in them: pet, water and harvest (trant_base verbs 463-600,
 *   include/trants.js; wood_tree.js 474-560), a harvest limited per player
 *   and tree a game day (canHarvest). Remove, trantsform and poison are the
 *   owner's or a key-holder's (wood_tree.js 117, 152, 303).
 *
 * Each of these acts by someone who is not the owner adds a fixed amount to
 * the owner's Magic Rock pool (inc_cultivation.js cultivation_add_img_rewards
 * 168-178; the amounts are in data/yard-verbs.json "pool", from the handlers:
 * npc_piggy.js 1664/1764, npc_butterfly.js 1187/1356, npc_chicken.js 1560,
 * patch.js 615/714, include/trants.js 90/196/420, wood_tree.js 1018).
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
 *   GET  groddle/v1/homes/yard            the owner: the acts waiting for my game
 */
final readonly class HomeYard
{
    public const TABLE = 'groddle_home_yard';
    public const ROUTE = '/groddle/v1/homes/yard';

    /** The home street's tsid in a save (js/features/homes-feature.js HOME_STREET_TSID). */
    public const STREET = 'RH_HOME_EXT';
    public const SLICE = 'yard-verbs-mp';

    public const WAITING_MAX = 200;

    public const KINDS = ['animal', 'plot', 'tree'];
    public const ANIMAL_VERBS = ['npc_piggy' => ['pet', 'nibble'], 'npc_butterfly' => ['massage', 'milk'], 'npc_chicken' => ['squeeze']];
    public const PLOT_VERBS = ['dig', 'tend'];
    public const TREE_VERBS = ['pet', 'water', 'harvest'];
    public const WOOD_VERBS = ['pet', 'water'];
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
        $out = Ledger::atomically($db, function () use ($db, $ledger, $rules, $op, $owner, $visitor, $kind, $id, $verb): array {
            $db->value('SELECT version FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ? FOR UPDATE', [$owner]);
            $day = HomePool::today();
            $opMark = 'op-' . substr($op, 3);
            $had = $db->value('SELECT amount FROM ' . $db->table(HomePool::MARKS) . ' WHERE owner_id = ? AND visitor_id = ? AND mark = ?', [$owner, $visitor, $opMark]);
            if ($had !== null) {
                return ['status' => 'settled', 'again' => true, 'added' => (int) $had, 'act' => null];
            }
            $state = Neighbours::state($db, $owner);
            if (!Neighbours::hasHome($state)) {
                return ['status' => 'refused', 'why' => 'no_home', 'text' => HomePool::TEXT_NO_HOME];
            }
            $t = $db->table(self::TABLE);
            $waiting = $db->rows("SELECT id, kind, target, verb, took, at_ms FROM {$t} WHERE owner_id = ? ORDER BY at_ms, id", [$owner]);
            if (count($waiting) >= self::WAITING_MAX) {
                return ['status' => 'refused', 'why' => 'busy', 'text' => self::TEXT_BUSY];
            }
            $skills = self::learned(Neighbours::state($db, $visitor));
            $uses = self::usesOf($db, $owner, $visitor, $day);
            $nowMs = (int) floor(microtime(true) * 1000);
            $judged = self::judge($rules, $state, $waiting, $uses, $skills, $kind, $id, $verb, $nowMs);
            if (isset($judged['why'])) {
                return ['status' => 'refused', 'why' => $judged['why'], 'text' => $judged['text']];
            }
            $pool = (int) ($rules['pool'][$judged['pool']] ?? 0);
            $n = ($uses[$verb . ':' . $id] ?? 0) + 1;
            HomePool::mark($db, $owner, $visitor, $day, 'y-' . $verb . '-' . $id . '-' . $n, $pool);
            HomePool::mark($db, $owner, $visitor, $day, $opMark, $pool);
            $took = (int) ($judged['took'] ?? 0);
            $db->execute("INSERT INTO {$t} (id, owner_id, visitor_id, kind, target, verb, took, at_ms, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$op, $owner, $visitor, $kind, $id, $verb, $took, $nowMs, gmdate('Y-m-d H:i:s')]);
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
                'act' => ['id' => $op, 'kind' => $kind, 'target' => $id, 'verb' => $verb, 'took' => $took, 'at' => $nowMs, 'got' => (object) $got],
            ];
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
        $waiting = $db->rows('SELECT id, kind, target, verb, took, at_ms FROM ' . $db->table(self::TABLE) . ' WHERE owner_id = ? ORDER BY at_ms, id', [$owner]);
        $nowMs = (int) floor(microtime(true) * 1000);
        $rules = self::rules();
        $patches = [];
        foreach (self::patchItems($state) as $key => $rx) {
            $plot = self::plotNow(self::storedPlot($state, $key), $key, $waiting, $nowMs, $rules);
            if ($plot !== null) {
                $patches[] = ['key' => $key, 'rx' => $rx, 'plot' => $plot];
            }
        }
        $animals = [];
        foreach (self::animalRows($state) as $row) {
            $animals[] = ['id' => $row->id, 'classId' => $row->classId, 'x' => is_numeric($row->x ?? null) ? (int) $row->x : 0, 'state' => is_object($row->state ?? null) ? $row->state : (object) []];
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
        $rows = $db->rows("SELECT id, visitor_id, kind, target, verb, took, at_ms FROM {$t} WHERE owner_id = ? ORDER BY at_ms, id LIMIT " . self::WAITING_MAX, [$me]);
        $names = (new Characters($db))->namesOf(array_map(static fn (array $r): int => (int) $r['visitor_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $v = (int) $r['visitor_id'];
            $out[] = ['id' => (string) $r['id'], 'from' => ['id' => $v, 'name' => $names[$v] ?? 'Someone'], 'kind' => (string) $r['kind'], 'target' => (string) $r['target'], 'verb' => (string) $r['verb'], 'took' => (int) $r['took'], 'at' => (int) $r['at_ms']];
        }
        return Response::json(['acts' => $out]);
    }

    // —— The rules (pure; tests: php -r) ——

    public static function validId(mixed $kind, mixed $id): bool
    {
        if (!is_string($id)) {
            return false;
        }
        return $kind === 'animal' ? preg_match('/^a\d{1,6}$/', $id) === 1 : preg_match('/^patch(_dark)?:\d{1,2}$/', $id) === 1;
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

    /** The owner's animals on the home street (features/animal-husbandry-feature.js slot animals). */
    public static function animalRows(?object $state): array
    {
        $rows = $state?->world?->streets?->{self::STREET}?->features?->{'animal-husbandry'}?->animals ?? [];
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (is_object($r) && is_string($r->id ?? null) && preg_match('/^a\d{1,6}$/', $r->id) === 1 && isset(self::ANIMAL_VERBS[$r->classId ?? ''])) {
                $out[] = $r;
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
