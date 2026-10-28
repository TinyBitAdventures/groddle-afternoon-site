<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Closure;
use Minn\Http;

/**
 * Checks a Google ID token (the credential Sign in with Google hands the
 * page) before anything in it is believed: an RS256 signature by one of
 * Google's published keys (https://www.googleapis.com/oauth2/v3/certs,
 * kept as long as its Cache-Control max-age says and fetched again when a
 * token names a key the copy doesn't have, at most once a minute), then the
 * issuer, the audience (the site's client id), the expiry (a minute's
 * skew) and a verified email. openssl_verify does the RSA; no library.
 *
 * The key cache is two closures (load, save) so the routes keep it in an
 * option and a test keeps it in memory; $fixed replaces Google's keys
 * altogether (tests, and the local QA option GoogleSignIn names).
 */
final class GoogleIdToken
{
    public const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    public const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /** Seconds a clock may be off. */
    public const SKEW = 60;

    /** The least time between two fetches for a key the copy doesn't have. */
    public const REFETCH_GAP = 60;

    /** How long Google's keys are kept when the answer names no max-age, and the longest any answer may ask for. */
    public const DEFAULT_AGE = 3600;
    public const LONGEST_AGE = 7 * 86400;

    /** An ID token is about a kilobyte; anything far bigger is not one. */
    public const MAX_LENGTH = 8192;

    /**
     * @param Closure(): mixed $load the cached copy: ['keys' => list<array>, 'fetched' => int, 'expires' => int], or anything else for none
     * @param Closure(array): void $save
     * @param list<array<string, mixed>>|null $fixed keys to use instead of Google's
     */
    public function __construct(private Http $http, private Closure $load, private Closure $save, private ?array $fixed = null)
    {
    }

    /**
     * The token's claims once every check passes, or why it failed (a short
     * reason for logs and tests, never shown to players): 'malformed',
     * 'algorithm', 'unknown_key', 'signature', 'issuer', 'audience',
     * 'expired', 'not_yet', 'unverified_email', 'claims'.
     *
     * @return array<string, mixed>|string
     */
    public function verify(string $jwt, string $clientId, int $now): array|string
    {
        if ($clientId === '' || strlen($jwt) > self::MAX_LENGTH) {
            return 'malformed';
        }
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            return 'malformed';
        }
        [$head, $body, $sig] = $parts;
        $header = self::jsonPart($head);
        $signature = self::decode($sig);
        if ($header === null || $signature === null || self::decode($body) === null) {
            return 'malformed';
        }
        if (($header['alg'] ?? null) !== 'RS256') {
            return 'algorithm';
        }
        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '' || strlen($kid) > 128) {
            return 'unknown_key';
        }
        $key = $this->key($kid, $now);
        if ($key === null) {
            return 'unknown_key';
        }
        if (openssl_verify("{$head}.{$body}", $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return 'signature';
        }
        // Signed by Google: only now are the claims read.
        $claims = self::jsonPart($body);
        if ($claims === null) {
            return 'malformed';
        }
        return self::claimsRefusal($claims, $clientId, $now) ?? $claims;
    }

    /** Why signed claims are refused, or null when they pass. */
    public static function claimsRefusal(array $claims, string $clientId, int $now): ?string
    {
        if (!in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            return 'issuer';
        }
        if (!is_string($claims['aud'] ?? null) || !hash_equals($clientId, $claims['aud'])) {
            return 'audience';
        }
        if (!is_int($claims['exp'] ?? null) || $claims['exp'] + self::SKEW <= $now) {
            return 'expired';
        }
        if ((isset($claims['iat']) && (!is_int($claims['iat']) || $claims['iat'] - self::SKEW > $now)) || (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] - self::SKEW > $now))) {
            return 'not_yet';
        }
        $verified = $claims['email_verified'] ?? null;
        if ($verified !== true && $verified !== 'true') {
            return 'unverified_email';
        }
        $sub = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        if (!is_string($sub) || preg_match('/^[A-Za-z0-9_-]{1,255}$/', $sub) !== 1 || !is_string($email) || strlen($email) > 100 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'claims';
        }
        return null;
    }

    /** The public key a kid names, from the fixed keys or Google's (cached, fetched when stale or when the kid is new). */
    private function key(string $kid, int $now): ?\OpenSSLAsymmetricKey
    {
        if ($this->fixed !== null) {
            return self::find($this->fixed, $kid);
        }
        $copy = ($this->load)();
        $copy = is_array($copy) && is_array($copy['keys'] ?? null) ? $copy : null;
        $fresh = $copy !== null && (int) ($copy['expires'] ?? 0) > $now;
        $found = $copy !== null ? self::find($copy['keys'], $kid) : null;
        if ($found !== null && $fresh) {
            return $found;
        }
        // Stale, or a key the copy doesn't have (Google rotated): fetch, but not more than once a minute for unknown kids.
        if (!$fresh || (int) ($copy['fetched'] ?? 0) <= $now - self::REFETCH_GAP) {
            $fetched = $this->fetch($now);
            if ($fetched !== null) {
                return self::find($fetched['keys'], $kid);
            }
        }
        // Google could not be asked: a key the old copy has still checks a signature.
        return $found;
    }

    /** Google's keys, fetched and saved with the time they may be kept; null when they could not be had. */
    private function fetch(int $now): ?array
    {
        $reply = $this->http->get(self::CERTS_URL, timeout: 5.0, redirects: 0, maxBytes: 65536);
        $data = $reply->ok() ? $reply->json() : null;
        if (!is_array($data) || !is_array($data['keys'] ?? null)) {
            return null;
        }
        $keys = array_values(array_filter($data['keys'], 'is_array'));
        $copy = ['keys' => $keys, 'fetched' => $now, 'expires' => $now + self::maxAge((string) $reply->header('cache-control'))];
        ($this->save)($copy);
        return $copy;
    }

    /** Seconds a Cache-Control header lets the keys be kept: its max-age, within a minute and a week, else an hour. */
    public static function maxAge(string $cacheControl): int
    {
        if (preg_match('/(?:^|[,\s])max-age\s*=\s*"?(\d{1,10})"?/i', $cacheControl, $m) !== 1) {
            return self::DEFAULT_AGE;
        }
        return max(60, min(self::LONGEST_AGE, (int) $m[1]));
    }

    /** @param list<array<string, mixed>> $keys */
    private static function find(array $keys, string $kid): ?\OpenSSLAsymmetricKey
    {
        foreach ($keys as $jwk) {
            if (is_array($jwk) && ($jwk['kid'] ?? null) === $kid) {
                return self::publicKey($jwk);
            }
        }
        return null;
    }

    /** An RSA signing key from its JWK (n and e), as OpenSSL reads it; null for anything else. */
    public static function publicKey(array $jwk): ?\OpenSSLAsymmetricKey
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || (isset($jwk['alg']) && $jwk['alg'] !== 'RS256') || (isset($jwk['use']) && $jwk['use'] !== 'sig')) {
            return null;
        }
        $n = is_string($jwk['n'] ?? null) ? self::decode($jwk['n']) : null;
        $e = is_string($jwk['e'] ?? null) ? self::decode($jwk['e']) : null;
        // 2048 bits at the least; Google's are 2048.
        if ($n === null || $e === null || strlen(ltrim($n, "\0")) < 256 || $e === '') {
            return null;
        }
        $rsa = self::der(0x30, self::der(0x02, self::unsigned($n)) . self::der(0x02, self::unsigned($e)));
        // SubjectPublicKeyInfo: rsaEncryption (1.2.840.113549.1.1.1), NULL, then the key as a BIT STRING.
        $spki = self::der(0x30, self::der(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00") . self::der(0x03, "\0" . $rsa));
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        return $key === false ? null : $key;
    }

    /** A DER element: its tag, its length, its bytes. */
    private static function der(int $tag, string $bytes): string
    {
        $length = strlen($bytes);
        if ($length < 0x80) {
            return chr($tag) . chr($length) . $bytes;
        }
        $size = ltrim(pack('N', $length), "\0");
        return chr($tag) . chr(0x80 | strlen($size)) . $size . $bytes;
    }

    /** A big-endian unsigned integer as DER wants it: no leading zeros, one 0x00 when the top bit is set. */
    private static function unsigned(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        return $bytes === '' || ord($bytes[0]) & 0x80 ? "\0" . $bytes : $bytes;
    }

    /** base64url without padding, decoded; null when it isn't. */
    public static function decode(string $part): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $part) !== 1 || strlen($part) % 4 === 1) {
            return null;
        }
        $bytes = base64_decode(strtr($part, '-_', '+/') . str_repeat('=', (4 - strlen($part) % 4) % 4), true);
        return $bytes === false ? null : $bytes;
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @return array<string, mixed>|null a base64url JSON object */
    private static function jsonPart(string $part): ?array
    {
        $bytes = self::decode($part);
        if ($bytes === null) {
            return null;
        }
        $data = json_decode($bytes, true, 16);
        return is_array($data) && !array_is_list($data) ? $data : null;
    }
}
