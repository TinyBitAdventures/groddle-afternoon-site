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
 * The one-time reset of players' own plots on the public gardens, paid by mail
 * (register G368; Austin 2026-11-07: "a one time reset for them and send them
 * their crops in an in-game mail"). Before the gardens were shared
 * (groddle-afternoon-server gardens.go) each player kept a private copy of
 * every public bed in their save (world.streets[tsid].gardens, the street
 * tsid as saves key it, "G…"). Those copies can't be merged, so a release
 * runs this once, administrators only:
 *
 *   POST groddle/v1/admin/gardens/payout  {"dry": true, "users": [7, 8]?}
 *        200 {"dry": bool, "players": [{"user","name","streets":[tsid],"items":{crop:n},"paid":bool,"before":bool}], "letters": n}
 *
 * For each stored multiplayer save holding a garden on a street of
 * data/garden-seeds.json "shared" (the game's js/shared-gardens.js
 * SHARED_GARDEN_STREETS: never a home, the tutorial's Doonboggle Trail, a
 * tower room or a party space), in one transaction per player:
 *   - every plot with seeds, shoots or a crop pays its crop's full
 *     produces_count, whatever the player's skills (Austin 2026-11-07: no
 *     Croppery I / Herbalism I halving here, though a pick by hand halves,
 *     include/garden.js 474-484), growing and dry plots alike (crops, not
 *     seeds); wetness and empty plots simply go;
 *   - the crops go by in-game mail (LedgerMail's letters, from nobody, id 0,
 *     signed LedgerMail::SYSTEM_NAME), one letter a crop, at most LedgerMail::COUNT_MAX a
 *     letter, delivered at once. Collecting one is the ordinary collect: the
 *     ledger takes the crop in (op mail-<id>-c, a settled move the save
 *     applies once), so nothing reaches a pack outside the ledger;
 *   - the player is recorded as paid (groddle_ledger_credited, kind "gardens",
 *     ref "payout", n the crops), so running it again never pays twice;
 *   - those streets' gardens leave the stored save, its version bumped and
 *     its writer cleared (as AdminTeleport rewrites a save): an open page is
 *     asked before it writes its old copy back (MultiplayerSave::decide). A
 *     save a stale page wrote back anyway is cleared again on the next run,
 *     with nothing paid; the game itself drops these streets' old plots
 *     (js/features/shared-gardens-feature.js).
 * "dry" reports what it would do and changes nothing; "users" limits it to
 * those characters.
 */
final readonly class GardenPayout
{
    public const ROUTE = '/groddle/v1/admin/gardens/payout';
    public const KIND = 'gardens';
    public const REF = 'payout';

    /** The letter (ours: Austin to approve). Plain, at most LedgerMail::TEXT_MAX characters. */
    /** Its subject in the mailbox (G570, ours: the original's letters had none). */
    public const SUBJECT = 'Your garden crops';
    public const TEXT = 'Good news: the public gardens have been upgraded from solo play to shared play! Everyone on a street now sees and tends the same plots. Your old plots there have been cleared, and here are the crops they held. Happy gardening!';

    /** spawnGarden's beds: the crop bed 4 by 2, the herb bed 3 by 2. */
    public const BEDS = ['default' => [4, 2], 'herb' => [3, 2]];

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
        $data = HomeTending::seeds();
        $shared = array_values(array_filter(is_array($data['shared'] ?? null) ? $data['shared'] : [], 'is_string'));
        $seeds = is_array($data['seeds'] ?? null) ? $data['seeds'] : [];
        if ($shared === []) {
            throw new RestError('groddle_payout_no_streets', 'data/garden-seeds.json lists no shared streets.', 500);
        }
        $db = $this->minn->db;
        $saves = $db->table(MultiplayerSave::TABLE);
        $ids = array_map('intval', array_column($db->rows("SELECT user_id FROM {$saves} ORDER BY user_id"), 'user_id'));
        if ($only !== null) {
            $ids = array_values(array_intersect($ids, $only));
        }
        $players = [];
        $letters = 0;
        $now = time();
        foreach ($ids as $user) {
            $one = Ledger::atomically($db, fn () => self::payOne($this->minn, $user, $shared, $seeds, $dry, $now));
            if ($one !== null) {
                $letters += $one['letters'];
                unset($one['letters']);
                $players[] = $one;
            }
        }
        $names = (new Characters($db))->namesOf(array_column($players, 'user'));
        foreach ($players as &$p) {
            $p['name'] = $names[$p['user']] ?? '';
        }
        unset($p);
        return Response::json(['dry' => $dry, 'players' => $players, 'letters' => $letters]);
    }

    /**
     * One player: what their save's public plots pay, the letters, the paid mark and the cleared
     * gardens. Null when the save holds no garden on a shared street.
     *
     * @return array{user: int, streets: list<string>, items: object, paid: bool, before: bool, letters: int}|null
     */
    public static function payOne(Seams $minn, int $user, array $shared, array $seeds, bool $dry, int $now): ?array
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
        $streets = self::streetsWithGardens($state, $shared);
        if ($streets === []) {
            return null;
        }
        $items = self::totals(self::owed($state, $shared, $seeds));
        $c = $db->table(Ledger::CREDITED);
        $before = $db->value("SELECT n FROM {$c} WHERE user_id = ? AND kind = ? AND ref = ? FOR UPDATE", [$user, self::KIND, self::REF]) !== null;
        $out = ['user' => $user, 'streets' => $streets, 'items' => (object) $items, 'paid' => !$before && $items !== [], 'before' => $before, 'letters' => 0];
        if ($dry) {
            return $out;
        }
        $stamp = gmdate('Y-m-d H:i:s', $now);
        if (!$before) {
            $t = $db->table(LedgerMail::TABLE);
            foreach ($items as $class => $n) {
                for ($left = $n; $left > 0; $left -= LedgerMail::COUNT_MAX) {
                    $db->execute("INSERT INTO {$t} (id, from_id, to_id, deliver_to, text, class_tsid, count, currants, postage, status, created_at, deliver_at) VALUES (?, 0, ?, ?, ?, ?, ?, 0, 0, 'sent', ?, ?)",
                        [bin2hex(random_bytes(8)), $user, $user, self::TEXT, $class, min($left, LedgerMail::COUNT_MAX), $stamp, $stamp]);
                    $out['letters']++;
                }
            }
            $db->execute("INSERT INTO {$c} (user_id, kind, ref, n, at) VALUES (?, ?, ?, ?, ?)", [$user, self::KIND, self::REF, array_sum($items), $stamp]);
            Ledger::fromSeams($minn)->log($user, 'gardens', ['payout' => (object) $items, 'streets' => $streets, 'letters' => $out['letters']], $now);
        }
        foreach ($streets as $tsid) {
            unset($state->world->streets->{$tsid}->gardens);
        }
        $entries['playerState'] = (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json = SaveShape::encode(['kind' => $save->kind, 'format' => $save->format, 'entries' => $entries]);
        // Nobody's page wrote this copy: an open page is asked before it writes its old plots back.
        $db->execute("UPDATE {$saves} SET data = ?, version = version + 1, updated_at = ?, writer = '', seq = 0 WHERE user_id = ? AND version = ?", [$json, $stamp, $user, (int) $row['version']]);
        return $out;
    }

    /** The shared streets this save keeps a garden on (planted or not). */
    public static function streetsWithGardens(mixed $state, array $shared): array
    {
        $streets = is_object($state) ? ($state->world->streets ?? null) : null;
        if (!is_object($streets)) {
            return [];
        }
        return array_values(array_filter($shared, static fn (string $tsid): bool => isset($streets->{$tsid}) && is_object($streets->{$tsid}) && isset($streets->{$tsid}->gardens)));
    }

    /** {tsid: {crop: n}} summed into {crop: n}, sorted. */
    public static function totals(array $byStreet): array
    {
        $out = [];
        foreach ($byStreet as $items) {
            foreach ($items as $c => $n) {
                $out[$c] = ($out[$c] ?? 0) + $n;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * What a save's own public plots pay, by street: {tsid: {crop: n}}. Only the shared streets,
     * only the two beds' real plots, only seeds, shoots and crops whose seed fits the bed.
     *
     * @param list<string> $shared
     * @param array<string, array{t?: string, c?: string, n?: int}> $seeds
     * @return array<string, array<string, int>>
     */
    public static function owed(mixed $state, array $shared, array $seeds): array
    {
        $out = [];
        $streets = is_object($state) ? ($state->world->streets ?? null) : null;
        if (!is_object($streets)) {
            return [];
        }
        foreach ($shared as $tsid) {
            $gardens = $streets->{$tsid}->gardens ?? null;
            if (!is_object($gardens)) {
                continue;
            }
            $items = [];
            foreach (self::BEDS as $bed => [$cols, $rows]) {
                $plots = $gardens->{$bed}->plots ?? null;
                if (!is_object($plots)) {
                    continue;
                }
                foreach ($plots as $key => $plot) {
                    if (!preg_match('/^(\d{1,2})-(\d{1,2})$/', (string) $key, $m) || (int) $m[1] < 1 || (int) $m[1] > $cols || (int) $m[2] < 1 || (int) $m[2] > $rows) {
                        continue;
                    }
                    $s = is_object($plot) ? ($plot->state ?? null) : null;
                    $seed = is_object($plot) && is_string($plot->seed ?? null) ? $seeds[$plot->seed] ?? null : null;
                    if (!in_array($s, ['seeds', 'shoots', 'crop'], true) || !is_array($seed) || ($seed['t'] ?? '') !== $bed || !is_string($seed['c'] ?? null) || !is_int($seed['n'] ?? null)) {
                        continue;
                    }
                    // The full produces_count for everyone (Austin 2026-11-07): no Croppery I / Herbalism I halving here.
                    $n = $seed['n'];
                    if ($n > 0 && Economy::shared()->tradeable($seed['c'])) {
                        $items[$seed['c']] = ($items[$seed['c']] ?? 0) + $n;
                    }
                }
            }
            if ($items !== []) {
                ksort($items);
                $out[$tsid] = $items;
            }
        }
        return $out;
    }
}
