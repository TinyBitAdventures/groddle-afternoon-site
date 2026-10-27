<?php

declare(strict_types=1);

namespace Groddle\Minn;

/**
 * The realtime server's own identity on the site (the "service identity
 * for Go's durable writes"): a call from groddle-afternoon-server carries
 *
 *   X-Groddle-Service: <unix seconds>.<16 hex nonce>.<base64url HMAC-SHA256>
 *
 * signed with the realtime secret over
 *
 *   "groddle-service/1\n" METHOD "\n" route "\n" seconds "\n" nonce "\n" hex(sha256(body))
 *
 * (route as the site declares it, e.g. /groddle/v1/ledger/trade). The prefix
 * and the newlines keep it apart from a realtime ticket (RealtimeTicket::sign
 * signs a base64url part, which has no newline). A call is good for WINDOW
 * seconds either side of the site's clock; what it asks is idempotent, so a
 * replay inside that window changes nothing. Pure, so tests share vectors
 * with the Go side (ledger.go).
 */
final class ServiceCall
{
    public const HEADER = 'x-groddle-service';

    public const WINDOW = 60;

    public const PREFIX = 'groddle-service/1';

    public static function message(string $method, string $route, int $at, string $nonce, string $body): string
    {
        return self::PREFIX . "\n" . strtoupper($method) . "\n" . $route . "\n" . $at . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    /** The header value for a call (tests; the Go side signs the same way). */
    public static function sign(string $method, string $route, int $at, string $nonce, string $body, string $secret): string
    {
        $sig = hash_hmac('sha256', self::message($method, $route, $at, $nonce, $body), $secret, true);
        return $at . '.' . $nonce . '.' . rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
    }

    /** Whether a header value is a good signature for this call now. */
    public static function verify(?string $header, string $method, string $route, string $body, string $secret, int $now): bool
    {
        if ($header === null || preg_match('/^(\d{1,12})\.([0-9a-f]{16})\.([A-Za-z0-9_-]{43})$/', trim($header), $m) !== 1) {
            return false;
        }
        $at = (int) $m[1];
        if (abs($now - $at) > self::WINDOW) {
            return false;
        }
        return hash_equals(self::sign($method, $route, $at, $m[2], $body, $secret), trim($header));
    }
}
