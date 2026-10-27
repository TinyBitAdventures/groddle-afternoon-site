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
 * groddle/v1/realtime: a short-lived ticket the game hands the realtime
 * server (groddle-afternoon-server, Go) when it opens the /ws socket. The ticket names the
 * signed-in player and expires in a minute; the server checks its HMAC with
 * the secret both sides read and never asks Minn who someone is.
 *
 * The secret is the GRODDLE_REALTIME_SECRET constant, else the file the
 * groddle_realtime_secret_file option names, else realtime.secret beside
 * the webroot. With none of them the route answers 503 and the game plays
 * on without other players.
 *
 * Where the socket is: /ws on the site's own host, unless the
 * GRODDLE_REALTIME_URL constant names the realtime server's own address
 * (wss://play.example.com/ws, for a server on another host than the site;
 * that server then needs the site's host in its -origins). The answer's
 * url carries it; path stays /ws for pages that only know path.
 *
 * Ticket: base64url(JSON payload) "." base64url(HMAC-SHA256(secret, payload part)).
 */
final readonly class RealtimeTicket
{
    /** Seconds a ticket stays good for opening a socket. */
    public const TTL = 60;

    public function __construct(private Seams $minn)
    {
    }

    /** A fresh ticket for the signed-in player. */
    #[Route(Method::Post, '/groddle/v1/realtime', policy: new Policy(Access::SignedIn))]
    public function ticket(Request $request): Response
    {
        $user = $this->minn->user();
        $secret = $this->secret();
        if ($user === null) {
            throw new RestError('groddle_realtime_signed_out', 'Sign in to play with others.', 401);
        }
        if ($secret === null) {
            throw new RestError('groddle_realtime_off', 'Playing with others is not set up on this site.', 503);
        }
        $now = time();
        $payload = [
            'sub' => $user->id,
            'name' => (new Characters($this->minn->db))->nameOf($user),
            'iat' => $now,
            'exp' => $now + self::TTL,
            'jti' => bin2hex(random_bytes(8)),
        ];
        $answer = ['ticket' => self::sign($payload, $secret), 'expiresIn' => self::TTL, 'path' => '/ws'];
        $url = self::socketUrl();
        if ($url !== null) {
            $answer['url'] = $url;
        }
        return Response::json($answer);
    }

    /**
     * The GRODDLE_REALTIME_URL constant when it is a ws:// or wss:// address
     * with a host and no credentials or fragment, else null (the page's own /ws).
     */
    public static function socketUrl(): ?string
    {
        if (!defined('GRODDLE_REALTIME_URL')) {
            return null;
        }
        $value = trim((string) constant('GRODDLE_REALTIME_URL'));
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['ws', 'wss'], true) || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }
        return $value;
    }

    /** Signs a payload into a ticket. */
    public static function sign(array $payload, string $secret): string
    {
        $body = self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $body . '.' . self::b64(hash_hmac('sha256', $body, $secret, true));
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** The shared secret, or null when this site has none (at least 32 bytes counts). Buddies signs with it too. */
    public function secret(): ?string
    {
        if (defined('GRODDLE_REALTIME_SECRET')) {
            $value = (string) constant('GRODDLE_REALTIME_SECRET');
            return strlen($value) >= 32 ? $value : null;
        }
        $named = trim((string) ($this->minn->site->option('groddle_realtime_secret_file') ?? ''));
        $file = $named !== '' ? $named : (defined('ABSPATH') ? dirname(rtrim(ABSPATH, '/')) . '/realtime.secret' : '');
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            return null;
        }
        $value = trim((string) file_get_contents($file));
        return strlen($value) >= 32 ? $value : null;
    }
}
