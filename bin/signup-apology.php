<?php
/**
 * G1211 (2026-11-23): a spam filter on the live site refused every new account from November 21 to 23, so
 * sign-ups made then never got their character. This mails each such sign-up (unused, made in the window,
 * with no account on that address since) an apology with a fresh one-day link (Signups::reissue,
 * PlayerMail::signupApology). Addresses never leave the site: the script prints only character names.
 * A dry run unless SEND=1. From the site's webroot:
 *
 *   FROM=2026-11-21 UNTIL=2026-11-24 wp eval-file wp-content/themes/groddle/bin/signup-apology.php
 *   SEND=1 FROM=2026-11-21 UNTIL=2026-11-24 wp eval-file wp-content/themes/groddle/bin/signup-apology.php
 */

// No declare(strict_types): wp eval-file runs this through eval().

spl_autoload_register(static function (string $class): void {
    $prefix = 'Groddle\\Minn\\';
    if (str_starts_with($class, $prefix) && is_file($file = dirname(__DIR__) . '/src/' . substr($class, strlen($prefix)) . '.php')) {
        require $file;
    }
});

$from = (string) getenv('FROM');
$until = (string) getenv('UNTIL');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) {
    fwrite(STDERR, "FROM and UNTIL (YYYY-MM-DD) are required\n");
    return;
}
$send = getenv('SEND') === '1';
$db = Minn\Runtime\Runtime::current()->db;
$options = Minn\Runtime\Runtime::options();
$signups = new Groddle\Minn\Signups($db);
$table = $db->table(Groddle\Minn\Signups::TABLE);
$users = $db->table('users');
$rows = $db->rows("SELECT * FROM {$table} WHERE used_at IS NULL AND created_at >= ? AND created_at < ? ORDER BY created_at", [$from, $until]);
$home = rtrim((string) $options->filtered('home'), '/');
$mailFrom = (string) $options->filtered('groddle_mail_from');
$now = time();
$sent = 0;
foreach ($rows as $row) {
    $name = (string) $row['name'];
    if ((int) $db->value("SELECT COUNT(*) FROM {$users} WHERE user_email = ?", [(string) $row['email']]) > 0) {
        echo "skip {$name}: that address has an account now\n";
        continue;
    }
    if (!$send) {
        echo "would mail {$name} (signed up {$row['created_at']})\n";
        continue;
    }
    $token = $signups->reissue($row, $now);
    [$subject, $html, $headers] = Groddle\Minn\PlayerMail::signupApology($home, $mailFrom, $home . '/play/online/#verify=' . $token, $name);
    $ok = (bool) Minn\Runtime\Handoff::mail((string) $row['email'], $subject, $html, $headers);
    echo ($ok ? 'mailed ' : 'MAIL FAILED ') . $name . "\n";
    $sent += $ok ? 1 : 0;
}
echo count($rows) . " sign-ups in the window, {$sent} mailed" . ($send ? '' : ' (dry run: SEND=1 to mail)') . "\n";
