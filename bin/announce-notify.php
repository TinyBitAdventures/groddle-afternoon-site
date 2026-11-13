<?php
/**
 * A one-off for an announcement posted before every player was notified of new ones (thread 11 on
 * the live site): gives each character but its writer the /chat/ notice "New announcement: <title>"
 * (Community::announceBackfill, then Community::announce). Changes nothing unless "apply" is given;
 * a character that already has the notice is skipped, so running it again writes nothing more.
 * From the site's webroot:
 *
 *   wp eval-file wp-content/themes/groddle/bin/announce-notify.php thread=11          (counts, changes nothing)
 *   wp eval-file wp-content/themes/groddle/bin/announce-notify.php thread=11 apply    (writes the notices)
 *
 * Refuses a thread that is not an open topic on Announcements started by an administrator.
 */

// No declare(strict_types): wp eval-file runs this through eval().

spl_autoload_register(static function (string $class): void {
    $prefix = 'Groddle\\Minn\\';
    if (str_starts_with($class, $prefix) && is_file($file = dirname(__DIR__) . '/src/' . substr($class, strlen($prefix)) . '.php')) {
        require $file;
    }
});

$given = $args ?? [];
$apply = in_array('apply', $given, true);
$thread = 0;
foreach ($given as $a) {
    if (str_starts_with($a, 'thread=')) {
        $thread = (int) substr($a, 7);
    }
}
if ($thread <= 0) {
    echo "Give the thread: thread=<id> (add apply to write the notices).\n";
    return;
}
$result = Groddle\Minn\Community::announceBackfill(Minn\Runtime\Runtime::current()->db, $thread, $apply);
$result['mode'] = $apply ? 'applied' : 'dry run (add apply to write)';
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
