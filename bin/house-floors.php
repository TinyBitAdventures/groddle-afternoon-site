<?php
/**
 * G1224 (report #655): the old floor drops of each house and Tower moved to the shared floor, as
 * Glitch had them (src/HouseFloors.php). Each save moves once by itself on its owner's next load; this
 * lists what that will move, runs it now for named characters, or puts one back while unconfirmed.
 * Read-only unless "apply" or "revert" is given. From the site's webroot:
 *
 *   wp eval-file wp-content/themes/groddle/bin/house-floors.php                    (list every save not moved yet)
 *   wp eval-file wp-content/themes/groddle/bin/house-floors.php users=500          (list those characters)
 *   wp eval-file wp-content/themes/groddle/bin/house-floors.php apply users=500    (move them now)
 *   wp eval-file wp-content/themes/groddle/bin/house-floors.php revert users=500   (put them back)
 *   wp eval-file wp-content/themes/groddle/bin/house-floors.php done               (the saves moved, with counts)
 *   wp eval-file wp-content/themes/groddle/bin/house-floors.php return-kept        (what return-kept apply would do)
 *   wp eval-file wp-content/themes/groddle/bin/house-floors.php return-kept apply  (do it; users=500 for some)
 *
 * return-kept (#670): the move took stacks it should have left in the save (a Still, which works on the
 * floor, as js/drops-mp.js shareReason keeps it); each still lying there goes back into its owner's save
 * as the backup kept it (HouseFloors::returnKept): one picked up since, or with the owner's own Still at
 * that spot now, is skipped, and a second run does nothing. Counts per user id and class, no names.
 *
 * The realtime server shows moved stacks when someone next joins the house (drops.go refresh); after a
 * revert or a return-kept it still draws the ones put back until it restarts (a pickup of one is
 * refused, and the street then forgets it).
 */

// No declare(strict_types): wp eval-file runs this through eval().

spl_autoload_register(static function (string $class): void {
    $prefix = 'Groddle\\Minn\\';
    if (str_starts_with($class, $prefix) && is_file($file = dirname(__DIR__) . '/src/' . substr($class, strlen($prefix)) . '.php')) {
        require $file;
    }
});

$argv2 = $args ?? [];
$mode = in_array('return-kept', $argv2, true) ? 'return-kept' : (in_array('apply', $argv2, true) ? 'apply' : (in_array('revert', $argv2, true) ? 'revert' : (in_array('done', $argv2, true) ? 'done' : 'list')));
$users = null;
foreach ($argv2 as $a) {
    if (str_starts_with($a, 'users=')) {
        $users = array_values(array_filter(array_map('intval', explode(',', substr($a, 6))), static fn (int $u): bool => $u > 0));
    }
}
$db = Minn\Runtime\Runtime::current()->db;
$saves = $db->table(Groddle\Minn\MultiplayerSave::TABLE);
$floors = $db->table(Groddle\Minn\HouseFloors::TABLE);
$out = ['mode' => $mode, 'saves' => []];
if (($mode === 'apply' || $mode === 'revert') && !$users) {
    fwrite(STDERR, "apply and revert need users=<id,...>\n");
    return;
}
if ($mode === 'done') {
    foreach ($db->rows("SELECT user_id, moved_at, moved, reverted_at FROM {$floors} ORDER BY moved_at") as $r) {
        $m = json_decode((string) $r['moved'], true);
        $out['saves'][] = ['user' => (int) $r['user_id'], 'at' => $r['moved_at'], 'moved' => count($m['moves'] ?? []), 'kept' => $m['kept'] ?? [], 'short' => $m['short'] ?? 0, 'reverted' => $r['reverted_at']];
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    return;
}
$rt = Minn\Runtime\Runtime::current();
$opt = static function (string $name, float $default) use ($rt): float {
    $v = $rt->site->option($name);
    return is_numeric($v) && (float) $v >= 0 ? (float) $v : $default;
};
$ledger = new Groddle\Minn\Ledger($db, Groddle\Minn\Economy::shared(), $opt('groddle_ledger_rate', Groddle\Minn\Ledger::RATE), $opt('groddle_ledger_capacity', Groddle\Minn\Ledger::CAPACITY), Groddle\Minn\Ledger::startEmptyIds($rt->site->option(Groddle\Minn\Ledger::START_EMPTY_OPTION)));
if ($mode === 'return-kept') {
    $apply = in_array('apply', $argv2, true);
    $out['apply'] = $apply;
    $totals = ['players' => 0, 'back' => 0, 'outcomes' => []];
    $in = $users ? ' AND user_id IN (' . implode(',', $users) . ')' : '';
    foreach ($db->rows("SELECT user_id FROM {$floors} WHERE reverted_at IS NULL{$in} ORDER BY user_id") as $r) {
        $row = Groddle\Minn\HouseFloors::returnKept($db, $ledger, (int) $r['user_id'], time(), $apply);
        if ($row['outcomes'] === []) {
            continue;
        }
        $out['saves'][] = $row;
        $totals['players']++;
        $totals['back'] += $row['back'];
        foreach ($row['outcomes'] as $what => $byClass) {
            foreach ($byClass as $c => $n) {
                $totals['outcomes'][$what][$c] = ($totals['outcomes'][$what][$c] ?? 0) + $n;
            }
        }
    }
    $out['totals'] = $totals;
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    return;
}
$where = $users ? ' WHERE s.user_id IN (' . implode(',', $users) . ')' : '';
$totals = ['saves' => 0, 'moves' => 0, 'kept' => []];
foreach ($db->rows("SELECT s.user_id, s.data, f.user_id AS done FROM {$saves} s LEFT JOIN {$floors} f ON f.user_id = s.user_id{$where} ORDER BY s.user_id") as $r) {
    $user = (int) $r['user_id'];
    if ($mode === 'revert') {
        $out['saves'][] = ['user' => $user] + Groddle\Minn\HouseFloors::revert($db, $ledger, $user, time());
        continue;
    }
    if ($r['done'] !== null) {
        continue;
    }
    $save = Groddle\Minn\SaveShape::decode((string) $r['data']);
    $entries = $save === null ? [] : array_filter((array) $save->entries, 'is_string');
    $plan = Groddle\Minn\HouseFloors::plan($entries, $user);
    if ($plan['moves'] === [] && $plan['kept'] === []) {
        continue;
    }
    $row = ['user' => $user, 'moves' => count($plan['moves']), 'kept' => $plan['kept']];
    if ($mode === 'apply') {
        $row += Groddle\Minn\HouseFloors::applyNow($db, $ledger, $user, time());
    }
    $out['saves'][] = $row;
    $totals['saves']++;
    $totals['moves'] += count($plan['moves']);
    foreach ($plan['kept'] as $why => $n) {
        $totals['kept'][$why] = ($totals['kept'][$why] ?? 0) + $n;
    }
}
$out['totals'] = $totals;
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
