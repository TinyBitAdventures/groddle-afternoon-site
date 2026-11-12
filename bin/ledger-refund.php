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
foreach ($argv2 as $a) {
    if (str_starts_with($a, 'users=')) {
        $users = array_values(array_filter(array_map('intval', explode(',', substr($a, 6))), static fn (int $u): bool => $u > 0));
    }
    if (str_starts_with($a, 'tables=')) {
        $suffix = substr($a, 7);
    }
}
$db = Minn\Runtime\Runtime::current()->db;
$result = Groddle\Minn\LedgerRefund::run($db, Groddle\Minn\Economy::shared(), $apply, $users, null, $suffix);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
