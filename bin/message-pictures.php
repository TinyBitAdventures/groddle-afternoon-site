<?php
/**
 * Several pictures a message (Austin 2026-11-15): copies G791's one picture a reply
 * (groddle_ticket_message_pictures, never released) into groddle_message_pictures as each
 * message's first (TicketStore::adoptOldPictures). Safe to run again; the old table is left as
 * it is. From the site's webroot:
 *
 *   wp eval-file wp-content/themes/groddle/bin/message-pictures.php
 */

// No declare(strict_types): wp eval-file runs this through eval().

spl_autoload_register(static function (string $class): void {
    $prefix = 'Groddle\\Minn\\';
    if (str_starts_with($class, $prefix) && is_file($file = dirname(__DIR__) . '/src/' . substr($class, strlen($prefix)) . '.php')) {
        require $file;
    }
});

$copied = (new Groddle\Minn\TicketStore(Minn\Runtime\Runtime::current()->db))->adoptOldPictures();
echo json_encode(['copied' => $copied]) . "\n";
