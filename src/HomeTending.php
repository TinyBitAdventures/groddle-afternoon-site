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
 * Key-holders tending a friend's gardens (register G328, slice 2). In the
 * source a garden's tend verb (items/garden_new.js 87-140) is the owner's,
 * or anyone holding a key to the owner's house
 * (locations/inc_acl_keys.js acl_keys_player_has_key; the keyring's
 * "Roommate alert! Your friend will have access to everything in your house,
 * including your crops and cabinet.", view/ui/acl/ACLCreateKeyUI.as 81-82);
 * tending is the garden's plots: water, plant, clear (hoe) and harvest
 * (items/include/garden.js). Nobody else may touch them.
 *
 * The owner's save is the owner's: a key-holder's act is checked here
 * against the owner's stored gardens (ticked as the game grows them, with
 * the seeds' times in data/garden-seeds.json, written by the game's
 * tools/build-garden-seeds.mjs) and the acts already waiting, then kept in
 * table groddle_home_tending. The owner's game applies the waiting acts to
 * its own gardens on its next load, or at once when the realtime server
 * tells it (homes.go home_tend_tell), and remembers their ids in its slice
 * (systems.features['neighbours-mp'].tended); an act the stored save lists
 * goes from the table. A visit (Neighbours) shows the gardens with the
 * waiting acts on them, so the next visitor sees them watered.
 *
 *   POST groddle/v1/homes/{owner}/tend  {"acts":[{"yard":"street"|"back","garden":"<item id>","plot":"1-2","act":"water|plant|clear|harvest","seed":"seed_carrot"}]}
 *        200 {"acts":[{"id":"tend-…","ok":true,…}|{"ok":false,"text":"…","plot":Plot}],"tell":"<proof>"}
 *   GET  groddle/v1/homes/tending        the signed-in owner's waiting acts, oldest first
 *
 * What a visitor gets from it (their energy, xp, the crop picked, the seed
 * planted) is their own client's, as any garden is (a crop is a gain the
 * ledger's budget pays for); only the owner's plots are the site's to keep.
 * Words are js/garden.js's (the game's plot answers).
 *
 * A harvest on the home street (not the back yard) also adds 5 iMG to the
 * owner's Magic Rock pool (HomePool, include/garden.js:579: pick by anyone
 * but the owner on the public half).
 */
final readonly class HomeTending
{
    public const TABLE = 'groddle_home_tending';

    /** Acts in one call; acts waiting for one owner. */
    public const PER_CALL = 8;
    public const WAITING_MAX = 200;

    public const TELL_TTL = 120;

    public const ACTS = ['water', 'plant', 'clear', 'harvest'];

    public const TEXT_NO_KEY = 'Only a key-holder can tend these gardens.';
    public const TEXT_NO_PLOT = 'No such plot.';
    public const TEXT_WET = 'That plot is already wet.';
    public const TEXT_NOTHING = 'Nothing growing there to water.';
    public const TEXT_NOT_READY = 'That plot is not ready to plant.';
    public const TEXT_HERB_ONLY = 'Only herb seeds go in an herb garden.';
    public const TEXT_CROP_ONLY = 'Only crop seeds go in a crop garden.';
    public const TEXT_CLEAR = 'That plot does not need clearing.';
    public const TEXT_HARVEST = 'Nothing ready to harvest.';
    public const TEXT_BUSY = 'Their gardens have had a lot of help today. Try again later.';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Post, '/groddle/v1/homes/{id:\d+}/tend', policy: new Policy(Access::SignedIn))]
    public function tend(Request $request, string $id): Response
    {
        $owner = (int) $id;
        $me = $this->minn->reader->userId;
        $acts = $request->json()['acts'] ?? null;
        if (!is_array($acts) || $acts === [] || count($acts) > self::PER_CALL || !array_is_list($acts)) {
            throw new RestError('groddle_tend_invalid', 'Name the acts.', 400);
        }
        if ($owner === $me || !(new Neighbours($this->minn))->holdsKeyTo($owner, $me)) {
            throw new RestError('groddle_tend_key', self::TEXT_NO_KEY, 403);
        }
        $db = $this->minn->db;
        $seeds = self::seeds();
        $out = Ledger::atomically($db, function () use ($db, $owner, $me, $acts, $seeds): array {
            $saves = $db->table(MultiplayerSave::TABLE);
            $db->value("SELECT version FROM {$saves} WHERE user_id = ? FOR UPDATE", [$owner]);
            $homes = self::homesOf(Neighbours::state($db, $owner));
            $t = $db->table(self::TABLE);
            $waiting = $db->rows("SELECT id, yard, garden, plot, act, seed, at_ms FROM {$t} WHERE owner_id = ? ORDER BY at_ms, id", [$owner]);
            if (count($waiting) >= self::WAITING_MAX) {
                throw new RestError('groddle_tend_busy', self::TEXT_BUSY, 429);
            }
            $nowMs = (int) floor(microtime(true) * 1000);
            $results = [];
            $told = [];
            foreach ($acts as $i => $raw) {
                $act = self::act($raw);
                if ($act === null) {
                    $results[] = ['ok' => false, 'text' => self::TEXT_NO_PLOT];
                    continue;
                }
                $garden = self::garden($homes, $act['yard'], $act['garden']);
                $plot = $garden !== null ? self::plotAt($garden, $act, $waiting, $nowMs, $seeds) : null;
                if ($plot === null) {
                    $results[] = $act + ['ok' => false, 'text' => self::TEXT_NO_PLOT];
                    continue;
                }
                $type = ($garden->gardenType ?? 'default') === 'herb' ? 'herb' : 'default';
                $why = self::refuse($plot, $act, $type, $nowMs, $seeds);
                if ($why !== null) {
                    $results[] = $act + ['ok' => false, 'text' => $why, 'plot' => $plot];
                    continue;
                }
                $rowId = 'tend-' . bin2hex(random_bytes(8));
                $at = $nowMs + $i;
                $db->execute("INSERT INTO {$t} (id, owner_id, visitor_id, yard, garden, plot, act, seed, at_ms, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [$rowId, $owner, $me, $act['yard'], $act['garden'], $act['plot'], $act['act'], $act['seed'], $at, gmdate('Y-m-d H:i:s')]);
                $waiting[] = ['id' => $rowId, 'yard' => $act['yard'], 'garden' => $act['garden'], 'plot' => $act['plot'], 'act' => $act['act'], 'seed' => $act['seed'], 'at_ms' => $at];
                $results[] = $act + ['ok' => true, 'id' => $rowId, 'at' => $at];
                if ($act['act'] === 'harvest' && $act['yard'] === 'street') {
                    // G201: a visitor's harvest on the home street fills the owner's Magic Rock pool (include/garden.js:579).
                    HomePool::add($db, $owner, HomePool::HARVEST, HomePool::today());
                }
                $told[] = ['y' => $act['yard'], 'g' => $act['garden'], 'p' => $act['plot'], 'a' => $act['act'], 's' => $act['seed'], 'at' => $at];
            }
            return ['results' => $results, 'told' => $told];
        });
        return Response::json(['acts' => $out['results'], 'tell' => $out['told'] === [] ? null : $this->tell($me, $owner, $out['told'])]);
    }

    /** The owner's waiting acts; those the stored save has applied go. */
    #[Route(Method::Get, '/groddle/v1/homes/tending', policy: new Policy(Access::SignedIn))]
    public function waiting(Request $request): Response
    {
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        $t = $db->table(self::TABLE);
        $applied = self::applied(Neighbours::state($db, $me));
        if ($applied !== []) {
            $db->execute("DELETE FROM {$t} WHERE owner_id = ? AND id IN (?)", [$me, $applied]);
        }
        $rows = $db->rows("SELECT id, visitor_id, yard, garden, plot, act, seed, at_ms FROM {$t} WHERE owner_id = ? ORDER BY at_ms, id LIMIT " . self::WAITING_MAX, [$me]);
        $names = (new Characters($db))->namesOf(array_map(static fn (array $r): int => (int) $r['visitor_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $v = (int) $r['visitor_id'];
            $out[] = ['id' => (string) $r['id'], 'from' => ['id' => $v, 'name' => $names[$v] ?? 'Someone'], 'yard' => (string) $r['yard'], 'garden' => (string) $r['garden'], 'plot' => (string) $r['plot'], 'act' => (string) $r['act'], 'seed' => (string) $r['seed'], 'at' => (int) $r['at_ms']];
        }
        return Response::json(['acts' => $out]);
    }

    /** The waiting acts for an owner, oldest first (for Neighbours' visit view). */
    public static function rowsFor(Db $db, int $owner): array
    {
        return $db->rows('SELECT id, yard, garden, plot, act, seed, at_ms FROM ' . $db->table(self::TABLE) . ' WHERE owner_id = ? ORDER BY at_ms, id LIMIT ' . self::WAITING_MAX, [$owner]);
    }

    /** A visit's homes slice with the waiting acts on its gardens (display: a copy, the save is untouched). */
    public static function overlay(object $homes, array $rows): object
    {
        if ($rows === []) {
            return $homes;
        }
        $copy = json_decode((string) json_encode($homes), false);
        $seeds = self::seeds();
        foreach ($rows as $r) {
            $garden = self::garden($copy, (string) $r['yard'], (string) $r['garden']);
            $plot = $garden?->plots?->{(string) $r['plot']} ?? null;
            if (!is_object($plot)) {
                continue;
            }
            $at = (int) $r['at_ms'];
            self::tickPlot($plot, $at, $seeds);
            self::applyAct($plot, (string) $r['act'], (string) $r['seed'], $at);
        }
        return $copy;
    }

    // —— The rules (js/garden.js) ——

    /**
     * tickGarden for one plot, continuously: each stage ends when its time
     * is up (not when the next frame notices), and a watering dries out
     * after waterMs, banking the stage's growth (dryPlot).
     */
    public static function tickPlot(object $plot, int $now, array $seeds): void
    {
        $water = (int) ($seeds['waterMs'] ?? 43200000);
        for ($step = 0; $step < 3; $step++) {
            if (($plot->wet ?? false) !== true) {
                return;
            }
            $wetAt = is_numeric($plot->wetAt ?? null) ? (int) $plot->wetAt : $now;
            $dryAt = $wetAt + $water;
            $at = min($now, $dryAt);
            $state = (string) ($plot->state ?? 'clean');
            $seed = is_string($plot->seed ?? null) ? ($seeds['seeds'][$plot->seed] ?? null) : null;
            $started = is_numeric($plot->stageStartedAt ?? null) ? (int) $plot->stageStartedAt : $at;
            $banked = max(0, (int) ($plot->growthMs ?? 0));
            if ($seed !== null && ($state === 'seeds' || $state === 'shoots')) {
                $mod = is_numeric($plot->growModifier ?? null) && (float) $plot->growModifier > 0 ? (float) $plot->growModifier : 1.0;
                $need = (int) ceil(($state === 'seeds' ? $seed['g1'] : $seed['g2']) * $mod);
                $doneAt = $started + max(0, $need - $banked);
                if ($doneAt <= $at) {
                    $plot->state = $state === 'seeds' ? 'shoots' : 'crop';
                    $plot->stageStartedAt = $doneAt;
                    $plot->growthMs = 0;
                    continue;
                }
            }
            if ($now >= $dryAt) {
                if ($state === 'seeds' || $state === 'shoots') {
                    $plot->growthMs = $banked + max(0, $dryAt - $started);
                }
                $plot->stageStartedAt = $dryAt;
                $plot->wet = false;
                $plot->wetAt = null;
            }
            return;
        }
    }

    /** Why the act cannot be done to the plot now (waterPlot, plantPlot, clearPlot, harvestPlot), or null. */
    public static function refuse(object $plot, array $act, string $type, int $now, array $seeds): ?string
    {
        $state = (string) ($plot->state ?? 'clean');
        switch ($act['act']) {
            case 'water':
                if ($state === 'clean' || $state === 'dirty') {
                    return self::TEXT_NOTHING;
                }
                return ($plot->wet ?? false) === true ? self::TEXT_WET : null;
            case 'plant':
                if ($state !== 'clean') {
                    return self::TEXT_NOT_READY;
                }
                $seed = $seeds['seeds'][$act['seed']] ?? null;
                if ($seed === null) {
                    return $type === 'herb' ? self::TEXT_HERB_ONLY : self::TEXT_CROP_ONLY;
                }
                if ($seed['t'] !== $type) {
                    return $type === 'herb' ? self::TEXT_HERB_ONLY : self::TEXT_CROP_ONLY;
                }
                return null;
            case 'clear':
                return $state === 'dirty' ? null : self::TEXT_CLEAR;
            case 'harvest':
                return $state === 'crop' && is_string($plot->seed ?? null) ? null : self::TEXT_HARVEST;
        }
        return self::TEXT_NO_PLOT;
    }

    /** The act's change to a plot at time at (wetPlot; plant, clear and harvest each start a stage). */
    public static function applyAct(object $plot, string $act, string $seed, int $at): void
    {
        if ($act === 'water') {
            if (($plot->wet ?? false) !== true) {
                $plot->stageStartedAt = $at;
            }
            $plot->wet = true;
            $plot->wetAt = $at;
            return;
        }
        $plot->state = match ($act) { 'plant' => 'seeds', 'clear' => 'clean', default => 'dirty' };
        $plot->seed = $act === 'plant' ? $seed : null;
        $plot->stageStartedAt = $at;
        $plot->growthMs = 0;
        if ($act === 'plant') {
            $plot->growModifier = 1;
        }
    }

    // —— Reading ——

    /** @return array{yard: string, garden: string, plot: string, act: string, seed: string}|null */
    private static function act(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $yard = ($raw['yard'] ?? 'street') === 'back' ? 'back' : 'street';
        $garden = $raw['garden'] ?? null;
        $plot = $raw['plot'] ?? null;
        $act = $raw['act'] ?? null;
        $seed = $raw['seed'] ?? '';
        if (!is_string($garden) || preg_match('/^[A-Za-z0-9_:-]{1,80}$/', $garden) !== 1 || !is_string($plot) || preg_match('/^\d{1,2}-\d{1,2}$/', $plot) !== 1
            || !in_array($act, self::ACTS, true) || !is_string($seed) || ($act === 'plant' ? preg_match('/^[a-z_]{1,64}$/', $seed) !== 1 : $seed !== '')) {
            return null;
        }
        return ['yard' => $yard, 'garden' => $garden, 'plot' => $plot, 'act' => $act, 'seed' => $seed];
    }

    private static function homesOf(?object $state): ?object
    {
        $homes = $state?->systems?->features?->homes ?? null;
        return is_object($homes) ? $homes : null;
    }

    /** The garden's plots: the home street's cultivations (cult), or the back yard's. */
    private static function garden(?object $homes, string $yard, string $id): ?object
    {
        $where = $yard === 'back' ? ($homes?->backYard ?? null) : ($homes?->cult ?? null);
        $garden = is_object($where?->gardens ?? null) ? ($where->gardens->{$id} ?? null) : null;
        return is_object($garden) && is_object($garden->plots ?? null) ? $garden : null;
    }

    /** The plot as it stands now: stored, then each waiting act on it in turn, then ticked to now. */
    private static function plotAt(object $garden, array $act, array $waiting, int $now, array $seeds): ?object
    {
        $plot = $garden->plots->{$act['plot']} ?? null;
        if (!is_object($plot)) {
            return null;
        }
        $plot = json_decode((string) json_encode($plot), false);
        foreach ($waiting as $w) {
            if ($w['yard'] === $act['yard'] && $w['garden'] === $act['garden'] && $w['plot'] === $act['plot']) {
                self::tickPlot($plot, (int) $w['at_ms'], $seeds);
                self::applyAct($plot, (string) $w['act'], (string) $w['seed'], (int) $w['at_ms']);
            }
        }
        self::tickPlot($plot, $now, $seeds);
        return $plot;
    }

    /** The tending ids the save says it applied (the neighbours-mp slice). */
    public static function applied(?object $state): array
    {
        $list = $state?->systems?->features?->{'neighbours-mp'}?->tended ?? [];
        $out = [];
        foreach (is_array($list) ? $list : [] as $id) {
            if (is_string($id) && preg_match('/^tend-[0-9a-f]{16}$/', $id) === 1) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** data/garden-seeds.json (tools/build-garden-seeds.mjs). */
    public static function seeds(): array
    {
        static $seeds = null;
        if ($seeds === null) {
            $raw = @file_get_contents(dirname(__DIR__) . '/data/garden-seeds.json');
            $json = is_string($raw) ? json_decode($raw, true) : null;
            $seeds = is_array($json) ? $json : ['waterMs' => 43200000, 'seeds' => []];
        }
        return $seeds;
    }

    /** The proof for the realtime server to tell the owner (and the others there) at once. */
    private function tell(int $me, int $owner, array $acts): ?string
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null) {
            return null;
        }
        $now = time();
        return RealtimeTicket::sign(['k' => 'home_tend', 'sub' => $me, 'o' => $owner, 'acts' => $acts, 'iat' => $now, 'exp' => $now + self::TELL_TTL, 'n' => bin2hex(random_bytes(8))], $secret);
    }
}
