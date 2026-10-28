<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;

/**
 * The site's signed calls to the realtime server (groddle-afternoon-server
 * teleport.go and admin.go): POST <server>/ws?call=<name>, the body JSON,
 * signed as the server signs its own ledger calls (ServiceCall) over the
 * route /realtime/v1/<name>. Where the server is: TeleportPlace::serviceUrl
 * (option groddle_realtime_service_url, else the socket's host, else this
 * host's 127.0.0.1:8793). The URL must be in minn.json needs.http.
 */
final readonly class RealtimeCall
{
    /** How long the site waits for the realtime server. */
    public const TIMEOUT = 3.0;

    public function __construct(private Seams $minn)
    {
    }

    /**
     * The server's answer (its JSON, with "http" the status code), or null
     * when there is no secret or the server did not answer.
     *
     * @return array<string, mixed>|null
     */
    public function post(string $call, array $body): ?array
    {
        if (preg_match('/^[a-z]{1,16}$/', $call) !== 1) {
            return null;
        }
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null) {
            return null;
        }
        $json = json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $named = $this->minn->site->option('groddle_realtime_service_url');
        $url = TeleportPlace::serviceUrl(is_string($named) ? $named : null, RealtimeTicket::socketUrl());
        $loopback = TeleportPlace::loopbackHost($url);
        try {
            $reply = $this->minn->http->post($url, body: $json, query: ['call' => $call], headers: [
                'Content-Type' => 'application/json',
                'X-Groddle-Service' => ServiceCall::sign('POST', '/realtime/v1/' . $call, time(), bin2hex(random_bytes(8)), $json, $secret),
            ], timeout: self::TIMEOUT, redirects: 0, private: $loopback === null ? [] : [$loopback], maxBytes: 262144);
        } catch (\Throwable) {
            return null;
        }
        $answer = json_decode($reply->body, true);
        if (!is_array($answer)) {
            return null;
        }
        return $answer + ['http' => $reply->code];
    }
}
