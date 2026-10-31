<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;
use Minn\Http\Exchange;
use Minn\RestError;

/**
 * The site's signed calls to the builder worker (the game's tools/builder/worker.mjs, spec 8.4):
 * POST http://127.0.0.1:8794/<call> with a JSON body, signed the ServiceCall way with the worker's
 * own prefix and secret:
 *
 *   X-Groddle-Worker: <unix seconds>.<16 hex nonce>.<base64url HMAC-SHA256>
 *   over "groddle-builder-worker/1\n" METHOD "\n" /<call> "\n" seconds "\n" nonce "\n" hex(sha256(body))
 *
 * The worker's secret is HMAC-SHA256(master, "groddle-builder-worker/1") as hex, from the same
 * master file as the builder keys, so the worker needs no secret of its own on disk. The worker
 * listens on loopback only (minn.json needs.http names it). When it is not running, or there is no
 * master, every call is 503 builder_worker_unavailable; on Kinsta there is no worker.
 */
final readonly class BuilderWorker
{
    public const URL = 'http://127.0.0.1:8794';
    public const HEADER = 'X-Groddle-Worker';
    public const PREFIX = 'groddle-builder-worker/1';
    public const LABEL = 'groddle-builder-worker/1';
    public const CALLS = ['validate', 'render', 'decos', 'style', 'guide'];

    public function __construct(private Seams $minn)
    {
    }

    public static function secret(string $master): string
    {
        return hash_hmac('sha256', self::LABEL, $master);
    }

    public static function message(string $method, string $route, int $at, string $nonce, string $body): string
    {
        return self::PREFIX . "\n" . strtoupper($method) . "\n" . $route . "\n" . $at . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    public static function sign(string $method, string $route, int $at, string $nonce, string $body, string $secret): string
    {
        $sig = hash_hmac('sha256', self::message($method, $route, $at, $nonce, $body), $secret, true);
        return $at . '.' . $nonce . '.' . rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
    }

    /**
     * The worker's answer to a call. Throws 503 builder_worker_unavailable when it cannot be asked
     * or did not answer; an answer of its own (any status) comes back as it is.
     *
     * @param array<string, mixed>|\stdClass $body
     */
    public function call(string $call, array|\stdClass $body, float $timeout = 10.0, int $maxBytes = 8_000_000): Exchange
    {
        if (!in_array($call, self::CALLS, true)) {
            throw new \InvalidArgumentException("No worker call {$call}");
        }
        $master = BuilderAuth::master($this->minn);
        if ($master === null) {
            throw new RestError('builder_worker_unavailable', 'This site has no builder master secret, so it cannot call the builder worker.', 503);
        }
        $json = json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $route = '/' . $call;
        try {
            $reply = $this->minn->http->post(self::URL . $route, body: $json, headers: [
                'Content-Type' => 'application/json',
                self::HEADER => self::sign('POST', $route, time(), bin2hex(random_bytes(8)), $json, self::secret($master)),
            ], timeout: $timeout, redirects: 0, private: ['127.0.0.1'], maxBytes: $maxBytes);
        } catch (\Throwable) {
            $reply = null;
        }
        if ($reply === null || $reply->code === 0) {
            throw new RestError('builder_worker_unavailable', 'The builder worker is not running. Start it in a game checkout: node tools/builder/worker.mjs', 503);
        }
        return $reply;
    }

    /** The worker's JSON answer, its own errors passed on with their status. @return array<string, mixed> */
    public function json(string $call, array|\stdClass $body, float $timeout = 10.0): array
    {
        $reply = $this->call($call, $body, $timeout);
        $data = json_decode($reply->body, true);
        if (!is_array($data)) {
            throw new RestError('builder_worker_unavailable', 'The builder worker gave an answer that is not JSON.', 503);
        }
        if ($reply->code >= 400) {
            throw new RestError((string) ($data['code'] ?? 'builder_worker_error'), (string) ($data['message'] ?? 'The builder worker refused that.'), $reply->code >= 500 ? 503 : $reply->code, is_array($data['data'] ?? null) ? array_diff_key($data['data'], ['status' => 1]) : []);
        }
        return $data;
    }
}
