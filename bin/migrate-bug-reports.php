<?php
/**
 * Brings the bug reports from before tickets (groddle_feedback posts) in as
 * tickets, once (src/TicketMigration.php; safe to run again, the posts stay,
 * nobody is emailed). From the site's webroot:
 *
 *   wp eval-file wp-content/themes/groddle/bin/migrate-bug-reports.php            (bring them in)
 *   wp eval-file wp-content/themes/groddle/bin/migrate-bug-reports.php dry-run    (count only)
 *
 * The console's Reports tab does the same with its "Bring in old reports" button.
 */

// No declare(strict_types): wp eval-file runs this through eval().

spl_autoload_register(static function (string $class): void {
    $prefix = 'Groddle\\Minn\\';
    if (str_starts_with($class, $prefix) && is_file($file = dirname(__DIR__) . '/src/' . substr($class, strlen($prefix)) . '.php')) {
        require $file;
    }
});

$db = Minn\Runtime\Runtime::current()->db;
if ((int) $db->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$db->table(Groddle\Minn\TicketStore::TABLE)]) === 0) {
    fwrite(STDERR, "No tickets table yet: open any page of the site once (Minn makes the tables minn.json declares), then run this again.\n");
    exit(1);
}
$dry = in_array('dry-run', $args ?? [], true);
$migration = new Groddle\Minn\TicketMigration($db);
$before = $migration->pending();
$result = $migration->run($dry);
$result['pendingBefore'] = $before;
$result['pendingAfter'] = $migration->pending();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
