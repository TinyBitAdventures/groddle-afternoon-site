<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;

/**
 * The old floor drops of a house and its Tower moved to the shared floor, once (G1224, report #655;
 * Austin 2026-11-24: "move the old floor drops to the shared floor, as Glitch").
 *
 * In Glitch a stack on a house's floor was anyone's once its dropper walked away (GameServerJS
 * items/include/takeable.js 29-44, 157-213); only one the owner set on a furniture plat was locked
 * to them (house_lock, 33-36, 179-198, cleared by a pickup, 84). Before shared house floors (G1190,
 * v0.59.0) every drop in a house stayed in its owner's save (the droppedItems entry, RH_HOME_INT and
 * RH_HOME_TOWER_<floor>, js/world-items.js), and after it those old stacks read as the owner's locked
 * decor: Decoration Mode could not stack the new drops with them, and visitors could not take them.
 *
 * Here each save's floor stacks there (no `on`: nothing holds them; not a Decoration Mode
 * placement, which the game marks `decor` from G1224 on; earlier ones carry no mark and go too) are
 * moved, on the owner's first load (MultiplayerSave::read, the save row locked), into the site's
 * groddle_drops as stacks the owner dropped (LedgerDrop::fromFloor): each leaves the owner's ledger
 * as a settled drop already applied, in the same transaction that takes it out of the stored save,
 * so it is counted once, never twice. A class the ledger does not carry (a bag with things in it, a
 * named bag, an untradeable class), a class the game keeps the player's own on shared ground (a note,
 * a Still: Economy::own, report #670) or a tool stack whose copies wear differently stays.
 *
 * Once: the save's row in groddle_house_floors says it was done (with nothing to move, too), and
 * keeps the save's droppedItems entry as it was and the ops it made, so it can be put back
 * (bin/house-floors.php revert) until it is confirmed. Each op is the stack's own id
 * (drop-<16 hex> from the owner, the place and the row), so a second run over the same row finds it
 * recorded. A copy of the save still open elsewhere (a window loaded before) that is kept over this
 * one (MultiplayerSave takeover, Ledger::reapply) has the same rows taken out by catchUp, never its
 * pack. The realtime server finds the stacks when someone next joins the house (drops.go refresh).
 */
final readonly class HouseFloors
{
    public const TABLE = 'groddle_house_floors';

    /** The places whose floor stacks move, and the site's name for each (drops.go dropSiteStreet). */
    public const HOUSE = 'RH_HOME_INT';
    public const TOWER = 'RH_HOME_TOWER_';

    /**
     * What moves from one save, without the database: each floor stack that goes ('place', 'row', 'op',
     * 'street', 'x', 'y', 'c', 'n', 'r', 't', 'at'), what stays and why, and the droppedItems entry
     * after (null: nothing moves).
     *
     * @return array{moves: list<array>, kept: array<string,int>, dropped: ?string}
     */
    public static function plan(array $entries, int $owner, ?Economy $economy = null): array
    {
        $economy ??= Economy::shared();
        $out = ['moves' => [], 'kept' => [], 'dropped' => null];
        $dropped = is_string($entries['droppedItems'] ?? null) ? json_decode($entries['droppedItems'], true) : null;
        if ($owner < 1 || !is_array($dropped) || !is_array($dropped['streets'] ?? null)) {
            return $out;
        }
        $state = is_string($entries['playerState'] ?? null) ? json_decode($entries['playerState'], true) : null;
        $homes = is_array($state) ? ($state['systems']['features']['homes'] ?? null) : null;
        $houseX = is_array($homes) && is_numeric($homes['houseX'] ?? null) ? (int) round((float) $homes['houseX']) : 0;
        $keep = static function (string $why) use (&$out): void {
            $out['kept'][$why] = ($out['kept'][$why] ?? 0) + 1;
        };
        $changed = false;
        foreach ($dropped['streets'] as $key => $rows) {
            $street = self::streetOf((string) $key, $owner);
            if ($street === null || !is_array($rows)) {
                continue;
            }
            $left = [];
            foreach ($rows as $row) {
                $why = is_array($row) ? self::stays($row, $economy) : 'bad';
                if ($why !== '') {
                    $keep($why);
                    $left[] = $row;
                    continue;
                }
                $n = (int) $row['count'];
                $points = is_array($row['points'] ?? null) ? array_values($row['points']) : [];
                $tags = is_array($row['tags'] ?? null) ? array_values($row['tags']) : [];
                $t = $n === 1 && isset($tags[0]) ? CopyTags::json($economy, (string) $row['classTsid'], 1, $tags[0]) : null;
                $at = is_numeric($row['droppedAt'] ?? null) ? (int) floor((float) $row['droppedAt'] / 1000) : 0;
                $out['moves'][] = [
                    'place' => (string) $key,
                    'row' => (string) $row['id'],
                    'op' => self::op($owner, (string) $key, $row),
                    'street' => $street,
                    'x' => (int) round((float) $row['x']) - ((string) $key === self::HOUSE ? $houseX : 0),
                    'y' => (int) round((float) $row['y']),
                    'c' => (string) $row['classTsid'],
                    'n' => $n,
                    'r' => $points === [] || $points[0] === null ? null : (int) $points[0],
                    't' => $t,
                    'at' => $at,
                ];
                $changed = true;
            }
            if ($left === []) {
                unset($dropped['streets'][$key]);
            } else {
                $dropped['streets'][$key] = $left;
            }
        }
        if ($changed) {
            $out['dropped'] = (string) json_encode($dropped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $out;
    }

    /** Why a floor row stays in the save ('' when it moves). */
    public static function stays(array $row, Economy $economy): string
    {
        $c = $row['classTsid'] ?? null;
        $n = $row['count'] ?? null;
        if (!is_string($c) || preg_match('/^[a-z0-9_]{1,64}$/', $c) !== 1 || !is_int($n) || $n < 1 || !is_string($row['id'] ?? null) || !is_numeric($row['x'] ?? null) || !is_numeric($row['y'] ?? null)) {
            return 'bad';
        }
        if (is_int($row['on'] ?? null) && $row['on'] > 0) {
            return 'furniture';
        }
        if (($row['decor'] ?? false) === true) {
            return 'decor';
        }
        if (!empty($row['contents']) || !empty($row['name']) || !empty($row['sort'])) {
            return 'bag';
        }
        if (!$economy->tradeable($c)) {
            return 'type';
        }
        // A note, and a Still that works on the floor (#670): the player's own, as the game drops them
        // (js/drops-mp.js shareReason, the same list through the economy table's "own").
        $own = $economy->own($c);
        if ($own !== '') {
            return $own;
        }
        // groddle_drops keeps one wear for a stack: a tool stack whose copies differ stays as it is. The
        // points line up with the stack's first tools, any without one are full (js/world-items.js
        // pointsFor), so [40] on three picks is one worn and two full: they differ.
        $points = is_array($row['points'] ?? null) ? array_values($row['points']) : [];
        if ($points !== [] && count($points) < $n) {
            $points = array_pad($points, $n, null);
        }
        $points = array_values(array_unique(array_map(static fn ($p) => is_numeric($p) ? (int) $p : 'full', array_slice($points, 0, $n))));
        if (count($points) > 1) {
            return 'wear';
        }
        $tags = is_array($row['tags'] ?? null) ? array_filter($row['tags'], static fn ($t) => $t !== null) : [];
        if ($tags !== [] && $n !== 1) {
            return 'words';
        }
        return '';
    }

    /** The site's street for a save's place key (HOUSE-<owner>, TOWER-<owner>-<floor>), or null for any other. */
    public static function streetOf(string $key, int $owner): ?string
    {
        if ($key === self::HOUSE) {
            return 'HOUSE-' . $owner;
        }
        if (preg_match('/^' . self::TOWER . '([0-9])$/', $key, $m) === 1) {
            return 'TOWER-' . $owner . '-' . $m[1];
        }
        return null;
    }

    /** A moved stack's op: drop- and 16 hex of the owner, the place and the row (drops.go dropOpRe). */
    public static function op(int $owner, string $key, array $row): string
    {
        $seed = implode('|', ['floor', $owner, $key, (string) ($row['id'] ?? ''), (string) ($row['classTsid'] ?? ''), (int) ($row['count'] ?? 0), (int) ($row['droppedAt'] ?? 0)]);
        return 'drop-' . substr(hash('sha256', $seed), 0, 16);
    }

    /**
     * MultiplayerSave::read, inside its transaction (the save row locked): the first load moves the
     * floor stacks. Returns the entries to store and whether they changed.
     *
     * @return array{entries: array, changed: bool, moved: int}
     */
    public static function onLoad(Db $db, Ledger $ledger, int $user, array $entries, int $now): array
    {
        $t = $db->table(self::TABLE);
        if ($db->row("SELECT user_id FROM {$t} WHERE user_id = ? FOR UPDATE", [$user]) !== null) {
            return ['entries' => $entries, 'changed' => false, 'moved' => 0];
        }
        $plan = self::plan($entries, $user);
        $moved = [];
        $back = [];
        if ($plan['moves'] !== []) {
            foreach ($plan['moves'] as $m) {
                if (LedgerDrop::fromFloor($db, $ledger, $m, $user, $now)) {
                    $moved[] = $m;
                } else {
                    $back[] = $m;
                }
            }
        }
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $db->execute("INSERT INTO {$t} (user_id, moved_at, backup, moved, reverted_at) VALUES (?, ?, ?, ?, NULL)", [
            $user, $stamp, (string) ($entries['droppedItems'] ?? ''), (string) json_encode(['moves' => $moved, 'kept' => $plan['kept'], 'short' => count($back)], JSON_UNESCAPED_SLASHES),
        ]);
        $ledger->log($user, 'floor', ['moved' => count($moved), 'kept' => $plan['kept'], 'short' => count($back)], $now);
        if ($moved === []) {
            return ['entries' => $entries, 'changed' => false, 'moved' => 0];
        }
        $entries['droppedItems'] = self::without($entries['droppedItems'], array_map(static fn (array $m): array => [$m['place'], $m['row']], $moved));
        return ['entries' => $entries, 'changed' => true, 'moved' => count($moved)];
    }

    /** A droppedItems entry without the rows named ([place, row id] pairs); a place left empty goes. */
    public static function without(string $json, array $gone): string
    {
        $dropped = json_decode($json, true);
        if (!is_array($dropped) || !is_array($dropped['streets'] ?? null)) {
            return $json;
        }
        $drop = [];
        foreach ($gone as [$place, $row]) {
            $drop[$place . "\n" . $row] = true;
        }
        foreach ($dropped['streets'] as $key => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            $left = array_values(array_filter($rows, static fn ($r) => !(is_array($r) && isset($drop[$key . "\n" . ($r['id'] ?? '')]))));
            if ($left === []) {
                unset($dropped['streets'][$key]);
            } elseif (count($left) !== count($rows)) {
                $dropped['streets'][$key] = $left;
            }
        }
        return (string) json_encode($dropped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Puts a save's moved floor stacks back (bin/house-floors.php revert), while unconfirmed: each still on
     * the ground goes back to the owner's ledger and its row, as the backup kept it, back into the stored
     * save's droppedItems; one picked up or eaten since stays where it went. The save row is locked and
     * left unclaimed (writer ''), so a window open on the moved copy is asked before it writes again.
     * The realtime server still draws them until it restarts (a pickup is refused: no longer on the ground).
     *
     * @return array{back: int, gone: int, done: bool}
     */
    public static function revert(Db $db, Ledger $ledger, int $user, int $now): array
    {
        return Ledger::atomically($db, static function () use ($db, $ledger, $user, $now): array {
            $saves = $db->table(MultiplayerSave::TABLE);
            $save = $db->row("SELECT data FROM {$saves} WHERE user_id = ? FOR UPDATE", [$user]);
            $t = $db->table(self::TABLE);
            $row = $db->row("SELECT backup, moved, reverted_at FROM {$t} WHERE user_id = ? FOR UPDATE", [$user]);
            if ($save === null || $row === null || $row['reverted_at'] !== null) {
                return ['back' => 0, 'gone' => 0, 'done' => false];
            }
            $moved = json_decode((string) $row['moved'], true);
            $rows = self::backupRows((string) $row['backup']);
            $data = SaveShape::decode((string) $save['data']);
            $entries = $data === null ? [] : array_filter((array) $data->entries, 'is_string');
            $dropped = is_string($entries['droppedItems'] ?? null) ? json_decode($entries['droppedItems'], true) : null;
            $dropped = is_array($dropped) ? $dropped : ['version' => 1, 'nextId' => 1, 'streets' => []];
            $back = 0;
            $gone = 0;
            foreach (is_array($moved['moves'] ?? null) ? $moved['moves'] : [] as $m) {
                $r = $rows[$m['place'] . "\n" . $m['row']] ?? null;
                if ($r === null || !LedgerDrop::backToFloor($db, $ledger, (string) $m['op'], $user, $now)) {
                    $gone++;
                    continue;
                }
                $dropped['streets'][$m['place']][] = $r;
                $back++;
            }
            if ($data !== null && $back > 0) {
                $entries['droppedItems'] = (string) json_encode($dropped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $db->execute("UPDATE {$saves} SET data = ?, version = version + 1, updated_at = ?, writer = '', seq = 0 WHERE user_id = ?", [SaveShape::encode(SaveShape::with($data, $entries)), gmdate('Y-m-d H:i:s', $now), $user]);
            }
            $db->execute("UPDATE {$t} SET reverted_at = ? WHERE user_id = ?", [gmdate('Y-m-d H:i:s', $now), $user]);
            $ledger->log($user, 'floor', ['reverted' => $back, 'gone' => $gone], $now);
            return ['back' => $back, 'gone' => $gone, 'done' => true];
        });
    }

    /** How near (px, either way) a stack of the same class in the save stands "at that spot" (returnKept). */
    public const SPOT = 24;

    /**
     * #670: the moved stacks the move should have left in the save (a Still, which works on the floor:
     * stays() keeps it now), each put back where the owner left it (bin/house-floors.php return-kept).
     * Like revert, but for just those rows, and the rest of the save as it is now: each still lying
     * there, the owner's, unchanged, goes back to the owner's ledger (LedgerDrop::backToFloor, why
     * 'kept', <op>-r) and its row, as the backup kept it (a Still's hopper and Hooch with it), joins
     * the stored save's droppedItems, in one transaction per player. Skipped: one picked up since
     * ('taken'), eaten or gone ('gone'), changed ('changed'), its row already in the save ('there'),
     * or the owner's own stack of that class at that spot now ('spot'). One returned before is 'done',
     * so a second run changes nothing. $apply false only counts (no lock, no write). The save row is
     * left unclaimed (writer ''), as revert leaves it. The realtime server keeps drawing a returned
     * stack until it restarts or someone tries to pick it up (the site answers it is gone).
     *
     * @return array{user: int, back: int, outcomes: array<string, array<string, int>>}
     */
    public static function returnKept(Db $db, Ledger $ledger, int $user, int $now, bool $apply): array
    {
        $run = static function () use ($db, $ledger, $user, $now, $apply): array {
            $lock = $apply ? ' FOR UPDATE' : '';
            $out = ['user' => $user, 'back' => 0, 'outcomes' => []];
            $saves = $db->table(MultiplayerSave::TABLE);
            $save = $db->row("SELECT data FROM {$saves} WHERE user_id = ?{$lock}", [$user]);
            $t = $db->table(self::TABLE);
            $row = $db->row("SELECT backup, moved, reverted_at FROM {$t} WHERE user_id = ?{$lock}", [$user]);
            $data = $save === null ? null : SaveShape::decode((string) $save['data']);
            if ($data === null || $row === null || $row['reverted_at'] !== null) {
                return $out;
            }
            $economy = Economy::shared();
            $moved = json_decode((string) $row['moved'], true);
            $rows = self::backupRows((string) $row['backup']);
            $entries = array_filter((array) $data->entries, 'is_string');
            $dropped = is_string($entries['droppedItems'] ?? null) ? json_decode($entries['droppedItems'], true) : null;
            $dropped = is_array($dropped) ? $dropped : ['version' => 1, 'nextId' => 1, 'streets' => []];
            if (!is_array($dropped['streets'] ?? null)) {
                $dropped['streets'] = [];
            }
            // What the save holds now: a stack returned in this run never makes another one's spot taken.
            $had = $dropped;
            $drops = $db->table(LedgerDrop::TABLE);
            $note = static function (string $what, string $c) use (&$out): void {
                $out['outcomes'][$what][$c] = ($out['outcomes'][$what][$c] ?? 0) + 1;
            };
            foreach (is_array($moved['moves'] ?? null) ? $moved['moves'] : [] as $m) {
                $r = is_array($m) ? ($rows[($m['place'] ?? '') . "\n" . ($m['row'] ?? '')] ?? null) : null;
                if ($r === null || self::stays($r, $economy) === '') {
                    continue;
                }
                $c = (string) $m['c'];
                $d = $db->row("SELECT dropper_id, class_tsid, count, status, why, merged_into FROM {$drops} WHERE id = ?{$lock}", [(string) $m['op']]);
                $what = match (true) {
                    $d === null => 'gone',
                    $d['status'] === 'reverted' && $d['why'] === 'kept' => 'done',
                    $d['status'] === 'taken' => 'taken',
                    $d['status'] !== 'ground' => 'gone',
                    (int) $d['dropper_id'] !== $user || $d['class_tsid'] !== $c || (int) $d['count'] !== (int) $m['n'] || $d['merged_into'] !== null => 'changed',
                    default => self::inSave($had, (string) $m['place'], $r),
                };
                if ($what === '' && $apply) {
                    $what = LedgerDrop::backToFloor($db, $ledger, (string) $m['op'], $user, $now, 'kept') ? 'back' : 'gone';
                } elseif ($what === '') {
                    $what = 'back';
                }
                if ($what === 'back') {
                    $dropped['streets'][(string) $m['place']][] = $r;
                    $out['back']++;
                }
                $note($what, $c);
            }
            if ($apply && $out['back'] > 0) {
                $entries['droppedItems'] = (string) json_encode($dropped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $db->execute("UPDATE {$saves} SET data = ?, version = version + 1, updated_at = ?, writer = '', seq = 0 WHERE user_id = ?", [SaveShape::encode(SaveShape::with($data, $entries)), gmdate('Y-m-d H:i:s', $now), $user]);
                $ledger->log($user, 'floor', ['kept' => $out['outcomes']], $now);
            }
            return $out;
        };
        return $apply ? Ledger::atomically($db, $run) : $run();
    }

    /**
     * Whether a backed-up row's place in the save is taken now (returnKept): 'there' when the row
     * itself is back, 'spot' when a stack of its class lies within SPOT of it, '' when it is free.
     */
    public static function inSave(array $dropped, string $place, array $row): string
    {
        foreach (is_array($dropped['streets'][$place] ?? null) ? $dropped['streets'][$place] : [] as $r) {
            if (!is_array($r)) {
                continue;
            }
            if (($r['id'] ?? null) === ($row['id'] ?? '')) {
                return 'there';
            }
            if (($r['classTsid'] ?? null) === ($row['classTsid'] ?? '') && is_numeric($r['x'] ?? null) && is_numeric($r['y'] ?? null)
                && abs((float) $r['x'] - (float) $row['x']) <= self::SPOT && abs((float) $r['y'] - (float) $row['y']) <= self::SPOT) {
                return 'spot';
            }
        }
        return '';
    }

    /** A backup's rows by place and row id ("<place>\n<id>"). */
    private static function backupRows(string $backup): array
    {
        $b = json_decode($backup, true);
        $rows = [];
        foreach (is_array($b['streets'] ?? null) ? $b['streets'] : [] as $key => $list) {
            foreach (is_array($list) ? $list : [] as $r) {
                if (is_array($r)) {
                    $rows[$key . "\n" . ($r['id'] ?? '')] = $r;
                }
            }
        }
        return $rows;
    }

    /**
     * The one-off run for a save now (bin/house-floors.php apply), as its first load would: the save row
     * locked, the moved copy stored and left unclaimed (writer ''), so a window open on the old copy is
     * asked before it writes again (and a copy kept over it loses the same rows, Ledger::reapply).
     *
     * @return array{moved: int}
     */
    public static function applyNow(Db $db, Ledger $ledger, int $user, int $now): array
    {
        return Ledger::atomically($db, static function () use ($db, $ledger, $user, $now): array {
            $saves = $db->table(MultiplayerSave::TABLE);
            $save = $db->row("SELECT data FROM {$saves} WHERE user_id = ? FOR UPDATE", [$user]);
            $data = $save === null ? null : SaveShape::decode((string) $save['data']);
            if ($data === null) {
                return ['moved' => 0];
            }
            $entries = array_filter((array) $data->entries, 'is_string');
            $done = self::onLoad($db, $ledger, $user, $entries, $now);
            if ($done['changed']) {
                $db->execute("UPDATE {$saves} SET data = ?, version = version + 1, updated_at = ?, writer = '', seq = 0 WHERE user_id = ?", [SaveShape::encode(SaveShape::with($data, $done['entries'])), gmdate('Y-m-d H:i:s', $now), $user]);
            }
            return ['moved' => $done['moved']];
        });
    }

    /** The move a trade id is, for this owner (Ledger::applyOne), or null. */
    public static function moveOf(Db $db, int $user, string $trade): ?array
    {
        if (!LedgerDrop::opId($trade)) {
            return null;
        }
        $raw = $db->value('SELECT moved FROM ' . $db->table(self::TABLE) . ' WHERE user_id = ?', [$user]);
        $moved = is_string($raw) ? json_decode($raw, true) : null;
        foreach (is_array($moved['moves'] ?? null) ? $moved['moves'] : [] as $m) {
            if (($m['op'] ?? '') === $trade) {
                return $m;
            }
        }
        return null;
    }

    /**
     * A moved stack put back (<op>-r: revert, or returnKept for a Still): [the move, its backed-up row]
     * for this owner, or null. A copy of the save kept over the one it went back into (Ledger::reapply)
     * gets the row back on its floor, as it was (catchUpBack), never a bare count in its pack.
     */
    public static function backOf(Db $db, int $user, string $trade): ?array
    {
        if (!str_ends_with($trade, '-r') || ($move = self::moveOf($db, $user, substr($trade, 0, -2))) === null) {
            return null;
        }
        $backup = $db->value('SELECT backup FROM ' . $db->table(self::TABLE) . ' WHERE user_id = ?', [$user]);
        $row = self::backupRows(is_string($backup) ? $backup : '')[$move['place'] . "\n" . $move['row']] ?? null;
        return $row === null ? null : [$move, $row];
    }

    /** Ledger::applyOne for a stack put back (backOf): its row on that copy's floor, once. */
    public static function catchUpBack(array $entries, array $back): array
    {
        [$move, $row] = $back;
        $dropped = is_string($entries['droppedItems'] ?? null) ? json_decode($entries['droppedItems'], true) : null;
        $dropped = is_array($dropped) ? $dropped : ['version' => 1, 'nextId' => 1, 'streets' => []];
        if (!is_array($dropped['streets'] ?? null)) {
            $dropped['streets'] = [];
        }
        if (self::inSave($dropped, (string) $move['place'], $row) === 'there') {
            return $entries;
        }
        $dropped['streets'][(string) $move['place']][] = $row;
        $entries['droppedItems'] = (string) json_encode($dropped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $entries;
    }

    /**
     * Ledger::applyOne for a moved stack on a copy of the save that still has it (a window kept over
     * the moved one, Ledger::reapply): the row comes off that copy's floor, never out of its pack.
     */
    public static function catchUp(array $entries, array $move): array
    {
        if (!is_string($entries['droppedItems'] ?? null)) {
            return $entries;
        }
        $entries['droppedItems'] = self::without($entries['droppedItems'], [[(string) $move['place'], (string) $move['row']]]);
        return $entries;
    }
}
