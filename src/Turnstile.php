<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Content\Site;
use Minn\Http;

/**
 * Cloudflare Turnstile on the forms that send email to an address someone
 * types (New player, Send it again, Forgot your password), Austin
 * 2026-10-28. The game's card draws the widget with the site key (option
 * groddle_turnstile_site_key, handed to the browser by
 * groddle/v1/account/options); the token it gives comes here and is checked
 * with Cloudflare's siteverify using the secret (option
 * groddle_turnstile_secret, which never leaves the server and is never
 * logged). With either option unset Turnstile is off and the forms work
 * as before (local development).
 */
final readonly class Turnstile
{
    public const SITE_KEY_OPTION = 'groddle_turnstile_site_key';
    public const SECRET_OPTION = 'groddle_turnstile_secret';
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const FAILED = 'We couldn\'t check that you\'re a person. Try again.';

    public function __construct(private string $siteKey, private string $secret)
    {
    }

    public static function fromSite(Site $site): self
    {
        return new self(trim((string) ($site->option(self::SITE_KEY_OPTION) ?? '')), trim((string) ($site->option(self::SECRET_OPTION) ?? '')));
    }

    /** Whether the forms carry a widget: both keys set. */
    public function enabled(): bool
    {
        return $this->siteKey !== '' && $this->secret !== '';
    }

    /** The public key the browser draws the widget with, or '' when off. */
    public function siteKey(): string
    {
        return $this->enabled() ? $this->siteKey : '';
    }

    /**
     * Whether a form's token passes: true when Turnstile is off; otherwise
     * Cloudflare's answer ('unavailable' when Cloudflare could not be asked,
     * which the routes refuse too).
     */
    public function passes(Http $http, string $token, string $address): bool|string
    {
        if (!$this->enabled()) {
            return true;
        }
        $token = trim($token);
        // Cloudflare's tokens are at most 2048 characters.
        if ($token === '' || strlen($token) > 2048) {
            return false;
        }
        $form = ['secret' => $this->secret, 'response' => $token];
        if ($address !== '') {
            $form['remoteip'] = $address;
        }
        $reply = $http->post(self::VERIFY_URL, form: $form, timeout: 8.0, redirects: 0, maxBytes: 65536);
        if (!$reply->ok()) {
            return 'unavailable';
        }
        $data = $reply->json();
        return is_array($data) && ($data['success'] ?? false) === true;
    }
}
