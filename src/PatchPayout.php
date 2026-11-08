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
 * The one-time hand-over of players' own street Patches when street Patches become shared (register
 * G564 slice 3, the plan's section 10; Austin 2026-11-08: "decide by the count", and the live count
 * picked option B, one winner per Patch). Before Patches were shared (groddle-afternoon-server
 * patches.go) each player kept a private copy of every street Patch in their save
 * (world.streets[tsid].features.patches.plots[key]: stage, seedling, tree). Those copies can't be
 * merged, so a release runs this once, administrators only:
 *
 *   POST groddle/v1/admin/patches/payout  {"dry": true, "users": [7, 8]?}
 *        200 {"dry": bool, "players": [{"user","name","streets":[tsid],"wins":[{street,key,tree,stage}],
 *             "items":{class:n},"paid":bool,"before":bool}], "letters": n,
 *             "seed": {"v":1,"plots":[…]} (the winners for the realtime server: patches_seed.go; a dry
 *             run shows them too, only the real run's are for the server)}
 *
 * Every save is read first, so the winner of each Patch is decided over everyone (also with "users"):
 *   - Only the streets of data/patch-streets.json (the game's tools/build-realtime-patches.mjs: every
 *     shared street) and only planted plots (a Seedling or a tree) count. Dead trees, weeds, tended or
 *     dug Patches simply go.
 *   - Where one player planted a Patch, their Seedling or tree wins it. Where several did, the most grown
 *     wins: a tree over a Seedling; between trees the higher maturity, then health, then fruit; between
 *     Seedlings the more pettings; then the one planted first, then the lower player id (Ours: a fixed
 *     order, so a dry run and the real one agree).
 *   - A win is one Patch plot (patch:<n>, patch_dark:<n>). A plot that was a dead tree's
 *     (trant_*_dead:<n>, cleared and planted) or a dead street tree's (tree:<i>) can't be handed over:
 *     the shared server keeps those itself, so they are refunded.
 *   - A player already paid (groddle_ledger_credited kind "patches", ref "payout") is no contender: a
 *     stale page that wrote their old plots back is cleared again, nothing paid, nothing won.
 * Then, in one transaction per player:
 *   - each win becomes a seed entry (the winner as planter; the realtime server plants it once at its
 *     next start, patches_seed.go, and refunds it itself if it can't land) and a letter (WIN_TEXT, no
 *     item);
 *   - every other planted plot is option A: one bean of its kind, plus the fruit on a tree or a Wood
 *     Tree's Planks at its maturity, by in-game mail (LedgerMail's letters, from nobody, id 0, one letter
 *     a class, at most LedgerMail::COUNT_MAX a letter, REFUND_TEXT), collected through the ledger;
 *   - the player is recorded as paid, so running it again never pays or wins twice;
 *   - those streets' patches slices leave the stored save, its version bumped and its writer cleared
 *     (as GardenPayout): an open page is asked before it writes its old copy back. The game drops these
 *     streets' old plots itself once the server shares them (js/features/shared-patches-feature.js).
 * "dry" reports what it would do and changes nothing; "users" limits the changes to those characters.
 * The release runs it through the game's tools/patches/payout.mjs, which writes "seed" to the file the
 * realtime server reads (-patches-seed).
 */
final readonly class PatchPayout
{
    public const ROUTE = '/groddle/v1/admin/patches/payout';
    public const KIND = 'patches';
    public const REF = 'payout';

    /** The letters (Claude's drafts, Austin to approve). Plain, at most LedgerMail::TEXT_MAX characters, no em-dashes. */
    public const WIN_START = 'Good news: street Patches are now shared! ';
    public const WIN_TREE = 'Your %s on %s is now a shared Patch tree everyone on the street can tend. It stays right where you planted it. Happy tending!';
    public const WIN_SEEDLING = 'Your %s Seedling on %s is now a shared Seedling everyone on the street can pet, and it will grow into a shared Patch tree. It stays right where you planted it. Happy tending!';
    public const REFUND_TEXT = 'Good news: street Patches are now shared! Everyone on a street now sees and tends the same Patch, so your own copy of a Patch you had planted there was cleared. Here is its bean back, with what was growing on it. Happy planting!';
    /** Their subjects in the mailbox (G570). */
    public const WIN_SUBJECT = 'Your Patch tree is shared';
    public const REFUND_SUBJECT = 'Your Patch beans';

    /** The plot keys a win can take: the layout's own Patches. */
    public const WIN_KEY = '/^(patch|patch_dark):\d{1,3}$/';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Post, self::ROUTE, policy: new Policy(Access::Cap, 'manage_options'))]
    public function payout(Request $request): Response
    {
        $body = $request->body === '' ? [] : $request->json();
        if (!is_array($body)) {
            throw new RestError('groddle_payout_invalid', 'Not a payout.', 400);
        }
        $dry = ($body['dry'] ?? false) === true;
        $only = null;
        if (isset($body['users'])) {
            if (!is_array($body['users']) || array_filter($body['users'], static fn ($u) => !is_int($u) || $u <= 0) !== []) {
                throw new RestError('groddle_payout_invalid', 'Name the players by id.', 400);
            }
            $only = $body['users'];
        }
        $data = self::data();
        if (($data['streets'] ?? []) === []) {
            throw new RestError('groddle_payout_no_streets', 'data/patch-streets.json lists no shared streets.', 500);
        }
        $db = $this->minn->db;
        $saves = $db->table(MultiplayerSave::TABLE);
        $c = $db->table(Ledger::CREDITED);
        $paid = array_map('intval', array_column($db->rows("SELECT user_id FROM {$c} WHERE kind = ? AND ref = ?", [self::KIND, self::REF]), 'user_id'));
        // Pass 1: every save, read only, for the winners.
        $all = [];
        foreach ($db->rows("SELECT user_id, data FROM {$saves} ORDER BY user_id") as $row) {
            $user = (int) $row['user_id'];
            if (in_array($user, $paid, true)) {
                continue;
            }
            $all[$user] = self::planted(self::stateOf((string) $row['data']), $data);
        }
        $winners = self::winners($all);
        $ids = array_map('intval', array_column($db->rows("SELECT user_id FROM {$saves} ORDER BY user_id"), 'user_id'));
        if ($only !== null) {
            $ids = array_values(array_intersect($ids, $only));
        }
        // Pass 2: one transaction per player.
        $players = [];
        $letters = 0;
        $seed = [];
        $now = time();
        foreach ($ids as $user) {
            $one = Ledger::atomically($db, fn () => self::payOne($this->minn, $user, $data, $winners, $dry, $now));
            if ($one !== null) {
                $letters += $one['letters'];
                array_push($seed, ...$one['seed']);
                unset($one['letters'], $one['seed']);
                $players[] = $one;
            }
        }
        $names = (new Characters($db))->namesOf(array_column($players, 'user'));
        foreach ($players as &$p) {
            $p['name'] = $names[$p['user']] ?? '';
        }
        unset($p);
        return Response::json(['dry' => $dry, 'players' => $players, 'letters' => $letters, 'seed' => ['v' => 1, 'plots' => $seed]]);
    }

    /** data/patch-streets.json. */
    public static function data(): array
    {
        static $data = null;
        if ($data === null) {
            $raw = @file_get_contents(dirname(__DIR__) . '/data/patch-streets.json');
            $json = is_string($raw) ? json_decode($raw, true) : null;
            $data = is_array($json) ? $json : ['streets' => []];
        }
        return $data;
    }

    /** A stored save's playerState, decoded (objects), or null. */
    public static function stateOf(string $json): mixed
    {
        $save = SaveShape::decode($json);
        $entries = $save === null ? [] : array_filter((array) $save->entries, 'is_string');
        return isset($entries['playerState']) ? json_decode($entries['playerState'], false, 128) : null;
    }

    /**
     * One player's planted plots on the shared streets: [{street, key, stage, tree, rank}], and the
     * shared streets their save keeps a patches slice on (planted or not) under "streets".
     *
     * @return array{streets: list<string>, plots: list<array>}
     */
    public static function planted(mixed $state, array $data): array
    {
        $streets = is_object($state) ? ($state->world->streets ?? null) : null;
        $out = ['streets' => [], 'plots' => []];
        if (!is_object($streets)) {
            return $out;
        }
        foreach (array_keys($data['streets'] ?? []) as $tsid) {
            $slice = is_object($streets->{$tsid} ?? null) ? ($streets->{$tsid}->features->patches ?? null) : null;
            if (!is_object($slice)) {
                continue;
            }
            $out['streets'][] = $tsid;
            $plots = is_object($slice->plots ?? null) ? $slice->plots : (object) [];
            foreach ($plots as $key => $plot) {
                $p = self::plantedPlot((string) $key, $plot, $data);
                if ($p !== null) {
                    $out['plots'][] = ['street' => $tsid] + $p;
                }
            }
        }
        return $out;
    }

    /** A save plot as a contender, or null when nothing is planted in it. */
    public static function plantedPlot(string $key, mixed $plot, array $data): ?array
    {
        if (!is_object($plot) || !preg_match('/^(patch|patch_dark|trant_[a-z]+_dead|tree):\d{1,3}$/', $key)) {
            return null;
        }
        $beans = $data['beans'] ?? [];
        $num = static fn ($v, int $lo, int $hi, int $or = 0): int => is_int($v) || is_float($v) ? (int) max($lo, min($hi, (int) $v)) : $or;
        $time = static fn ($v): int => is_int($v) || is_float($v) ? max(0, (int) $v) : 0;
        $stage = $plot->stage ?? null;
        if ($stage === 'seedling' && is_object($plot->seedling ?? null)) {
            $s = $plot->seedling;
            $tree = is_string($s->trantClass ?? null) ? $s->trantClass : '';
            if (!isset($beans[$tree])) {
                return null;
            }
            $petted = $num($s->petted ?? 0, 0, 3);
            $planted = $time($s->plantedAt ?? 0);
            return ['key' => $key, 'stage' => 'seedling', 'tree' => $tree, 'rank' => [0, $petted, 0, 0], 'at' => $planted,
                'seedling' => ['bean' => is_string($s->beanClass ?? null) ? $s->beanClass : $beans[$tree], 'plantedAt' => $planted, 'minAt' => $time($s->minAt ?? 0),
                    'maxAt' => $time($s->maxAt ?? 0), 'petted' => $petted, 'grow' => max($num($s->upgradeGrowLevel ?? 0, 0, 10), $num($s->growLevel ?? 0, 0, 10)),
                    'thirdAt' => $time($s->thirdPetAt ?? 0)]];
        }
        if ($stage === 'tree' && is_object($plot->tree ?? null)) {
            $t = $plot->tree;
            $tree = is_string($t->classId ?? null) ? $t->classId : '';
            $st = is_object($t->state ?? null) ? $t->state : (object) [];
            if (!isset($beans[$tree])) {
                return null;
            }
            $wood = $tree === 'wood_tree';
            $maturity = $num($st->maturity ?? 0, 0, $wood ? 6 : 10);
            $health = $wood ? 0 : $num($st->health ?? 0, 1, 10, 1);
            $fruit = $wood ? 0 : $num($st->fruitCount ?? 0, 0, 9999);
            $grown = $time($t->grownAt ?? 0);
            return ['key' => $key, 'stage' => 'tree', 'tree' => $tree, 'rank' => [1, $maturity, $health, $fruit], 'at' => $grown,
                'grown' => ['maturity' => $maturity, 'health' => $health, 'fruit' => $fruit, 'grownAt' => $grown, 'cycleAt' => $time($t->lastCycleAt ?? 0),
                    'count' => $num($st->harvestCount ?? 0, 0, 1), 'woodPetted' => $num($t->woodPetted ?? 0, 0, 99), 'woodWatered' => $num($t->woodWatered ?? 0, 0, 99)]];
        }
        return null;
    }

    /**
     * The winner of every contested Patch: {"tsid|key": user}. Only the layout's Patches (WIN_KEY) can
     * be won; the most grown wins, then the one planted first, then the lower id.
     *
     * @param array<int, array{plots: list<array>}> $all
     * @return array<string, int>
     */
    public static function winners(array $all): array
    {
        $best = [];
        foreach ($all as $user => $one) {
            foreach ($one['plots'] ?? [] as $p) {
                if (!preg_match(self::WIN_KEY, $p['key'])) {
                    continue;
                }
                $id = $p['street'] . '|' . $p['key'];
                $c = ['user' => (int) $user, 'rank' => $p['rank'], 'at' => $p['at']];
                if (!isset($best[$id]) || self::beats($c, $best[$id])) {
                    $best[$id] = $c;
                }
            }
        }
        ksort($best);
        return array_map(static fn (array $c): int => $c['user'], $best);
    }

    /** Whether contender a beats b: the most grown, then planted first, then the lower id. */
    public static function beats(array $a, array $b): bool
    {
        $r = $a['rank'] <=> $b['rank'];
        if ($r !== 0) {
            return $r > 0;
        }
        $at = ($a['at'] ?: PHP_INT_MAX) <=> ($b['at'] ?: PHP_INT_MAX);
        return $at !== 0 ? $at < 0 : $a['user'] < $b['user'];
    }

    /** A win as the realtime server's seed entry (patches_seed.go patchSeed). */
    public static function seedEntry(int $user, array $p): array
    {
        $e = ['id' => $p['street'] . '|' . $p['key'], 'street' => $p['street'], 'key' => $p['key'], 'user' => $user, 'stage' => $p['stage'], 'tree' => $p['tree']];
        return $e + ($p['stage'] === 'seedling' ? $p['seedling'] : $p['grown']);
    }

    /** Option A for one plot: its bean, and the fruit on a tree or a Wood Tree's Planks. {class: n} */
    public static function refund(array $p, array $data): array
    {
        $out = [];
        $bean = $data['beans'][$p['tree']] ?? null;
        if (is_string($bean)) {
            $out[$bean] = 1;
        }
        if ($p['stage'] === 'tree') {
            if ($p['tree'] === 'wood_tree') {
                $m = $p['grown']['maturity'];
                $n = $m >= 1 ? (int) ($data['planks'][min($m, 6) - 1] ?? 0) : 0;
                if ($n > 0) {
                    $out['plank'] = $n;
                }
            } elseif (is_string($fruit = $data['fruit'][$p['tree']] ?? null) && $p['grown']['fruit'] > 0) {
                $out[$fruit] = $p['grown']['fruit'];
            }
        }
        return $out;
    }

    /** A winner's letter. */
    public static function winText(array $p, array $data): string
    {
        $tree = (string) ($data['labels'][$p['tree']] ?? 'tree');
        $street = (string) ($data['streets'][$p['street']]['name'] ?? 'your street');
        $text = self::WIN_START . sprintf($p['stage'] === 'seedling' ? self::WIN_SEEDLING : self::WIN_TREE, $tree, $street);
        return mb_strlen($text) <= LedgerMail::TEXT_MAX ? $text : mb_substr($text, 0, LedgerMail::TEXT_MAX);
    }

    /** A Patch payout letter's subject in the mailbox (LedgerMail::subject), or null. */
    public static function subject(string $text): ?string
    {
        if ($text === self::REFUND_TEXT) {
            return self::REFUND_SUBJECT;
        }
        return str_starts_with($text, self::WIN_START) ? self::WIN_SUBJECT : null;
    }

    /**
     * One player: their wins as seed entries and letters, their other planted plots refunded by mail,
     * the paid mark and the cleared slices. Null when the save keeps no patches slice on a shared street.
     *
     * @param array<string, int> $winners
     * @return array{user: int, streets: list<string>, wins: list<array>, items: object, paid: bool, before: bool, letters: int, seed: list<array>}|null
     */
    public static function payOne(Seams $minn, int $user, array $data, array $winners, bool $dry, int $now): ?array
    {
        $db = $minn->db;
        $saves = $db->table(MultiplayerSave::TABLE);
        $row = $db->row("SELECT data, version FROM {$saves} WHERE user_id = ? FOR UPDATE", [$user]);
        $save = $row === null ? null : SaveShape::decode((string) $row['data']);
        if ($save === null) {
            return null;
        }
        $entries = array_filter((array) $save->entries, 'is_string');
        $state = isset($entries['playerState']) ? json_decode($entries['playerState'], false, 128) : null;
        $mine = self::planted($state, $data);
        if ($mine['streets'] === []) {
            return null;
        }
        $c = $db->table(Ledger::CREDITED);
        $before = $db->value("SELECT n FROM {$c} WHERE user_id = ? AND kind = ? AND ref = ? FOR UPDATE", [$user, self::KIND, self::REF]) !== null;
        $wins = [];
        $seed = [];
        $texts = [];
        $items = [];
        foreach ($before ? [] : $mine['plots'] as $p) {
            if (($winners[$p['street'] . '|' . $p['key']] ?? 0) === $user) {
                $wins[] = ['street' => $p['street'], 'key' => $p['key'], 'tree' => $p['tree'], 'stage' => $p['stage']];
                $seed[] = self::seedEntry($user, $p);
                $texts[] = self::winText($p, $data);
                continue;
            }
            foreach (self::refund($p, $data) as $class => $n) {
                if (Economy::shared()->tradeable($class)) {
                    $items[$class] = ($items[$class] ?? 0) + $n;
                }
            }
        }
        ksort($items);
        $out = ['user' => $user, 'streets' => $mine['streets'], 'wins' => $wins, 'items' => (object) $items, 'paid' => !$before && ($items !== [] || $wins !== []), 'before' => $before, 'letters' => 0, 'seed' => $seed];
        if ($dry) {
            return $out;
        }
        $stamp = gmdate('Y-m-d H:i:s', $now);
        if (!$before) {
            $t = $db->table(LedgerMail::TABLE);
            $send = static function (string $text, string $class, int $count) use ($db, $t, $user, $stamp): void {
                $db->execute("INSERT INTO {$t} (id, from_id, to_id, deliver_to, text, class_tsid, count, currants, postage, status, created_at, deliver_at) VALUES (?, 0, ?, ?, ?, ?, ?, 0, 0, 'sent', ?, ?)",
                    [bin2hex(random_bytes(8)), $user, $user, $text, $class, $count, $stamp, $stamp]);
            };
            foreach ($texts as $text) {
                $send($text, '', 0);
                $out['letters']++;
            }
            foreach ($items as $class => $n) {
                for ($left = $n; $left > 0; $left -= LedgerMail::COUNT_MAX) {
                    $send(self::REFUND_TEXT, $class, min($left, LedgerMail::COUNT_MAX));
                    $out['letters']++;
                }
            }
            $db->execute("INSERT INTO {$c} (user_id, kind, ref, n, at) VALUES (?, ?, ?, ?, ?)", [$user, self::KIND, self::REF, array_sum($items) + count($wins), $stamp]);
            Ledger::fromSeams($minn)->log($user, 'patches', ['payout' => (object) $items, 'wins' => $seed, 'streets' => $mine['streets'], 'letters' => $out['letters']], $now);
        }
        foreach ($mine['streets'] as $tsid) {
            unset($state->world->streets->{$tsid}->features->patches);
        }
        $entries['playerState'] = (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json = SaveShape::encode(['kind' => $save->kind, 'format' => $save->format, 'entries' => $entries]);
        // Nobody's page wrote this copy: an open page is asked before it writes its old plots back.
        $db->execute("UPDATE {$saves} SET data = ?, version = version + 1, updated_at = ?, writer = '', seq = 0 WHERE user_id = ? AND version = ?", [$json, $stamp, $user, (int) $row['version']]);
        return $out;
    }
}
