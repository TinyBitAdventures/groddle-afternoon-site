<?php
/**
 * Report #246: lists what the save ledger took back from players whose level-up or card purchase
 * was put off to a later day (src/LedgerRefund.php), and with "apply" mails it back once through
 * the same letters the garden payout uses. Read-only unless "apply" is given. From the site's webroot:
 *
 *   wp eval-file wp-content/themes/groddle/bin/ledger-refund.php                 (list, changes nothing)
 *   wp eval-file wp-content/themes/groddle/bin/ledger-refund.php users=7,8       (list those characters)
 *   wp eval-file wp-content/themes/groddle/bin/ledger-refund.php apply users=7   (mail it, once a character)
 *
 *   wp eval-file wp-content/themes/groddle/bin/ledger-refund.php tables=_rehearsal (copies of the three tables)
 *
 * G895 (#432): ref=g895 lists (and with "apply" mails back, once a character) the emblems, Gameshow
 * Tickets, beads, artifact pieces and other capped drops the save checker took from the v0.43.0
 * deploy on (since=YYYY-MM-DDTHH:MM:SS, UTC, moves the start):
 *
 *   wp eval-file wp-content/themes/groddle/bin/ledger-refund.php ref=g895               (list, changes nothing)
 *   wp eval-file wp-content/themes/groddle/bin/ledger-refund.php ref=g895 apply users=7 (mail it)
 *
 * G905 (#436, #438, #439): ref=g905 lists (and with "apply" mails back, once a character) the machine
 * output, garden crops, Elemental Shake elements, tutorial gifts and rare finds (uncapped Musicblocks,
 * gems) the save checker took from the v0.43.0 deploy on, never what ref g895 or Austin's g895-hand
 * refund already paid:
 *
 *   wp eval-file wp-content/themes/groddle/bin/ledger-refund.php ref=g905               (list, changes nothing)
 *   wp eval-file wp-content/themes/groddle/bin/ledger-refund.php ref=g905 apply users=7 (mail it)
 *
 * Read the list before applying: the log cannot tell an honest card purchase from a forged pile.
 */

// No declare(strict_types): wp eval-file runs this through eval().

spl_autoload_register(static function (string $class): void {
    $prefix = 'Groddle\\Minn\\';
    if (str_starts_with($class, $prefix) && is_file($file = dirname(__DIR__) . '/src/' . substr($class, strlen($prefix)) . '.php')) {
        require $file;
    }
});

$argv2 = $args ?? [];
$apply = in_array('apply', $argv2, true);
$users = null;
$suffix = '';
$ref = Groddle\Minn\LedgerRefund::REF;
$since = null;
foreach ($argv2 as $a) {
    if (str_starts_with($a, 'users=')) {
        $users = array_values(array_filter(array_map('intval', explode(',', substr($a, 6))), static fn (int $u): bool => $u > 0));
    }
    if (str_starts_with($a, 'tables=')) {
        $suffix = substr($a, 7);
    }
    if (str_starts_with($a, 'ref=')) {
        $ref = substr($a, 4);
    }
    if (str_starts_with($a, 'since=')) {
        $since = str_replace('T', ' ', substr($a, 6));
    }
}
$db = Minn\Runtime\Runtime::current()->db;
$result = Groddle\Minn\LedgerRefund::run($db, Groddle\Minn\Economy::shared(), $apply, $users, null, $suffix, $ref, $since);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
