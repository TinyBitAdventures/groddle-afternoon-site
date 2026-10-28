<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Content\UserRecord;
use Minn\Content\Users;
use Minn\Extension\Seams;
use Minn\Extension\SignedIn;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\Runtime\Refusal;

/**
 * Continue with Google (Austin 2026-10-28): Google's own Sign in with Google
 * button on the card (Google Identity Services, the credential callback, no
 * redirect and no client secret) hands the page an ID token, which comes
 * here and is checked in full (GoogleIdToken) before anything in it counts.
 * The site's client id is option groddle_google_client_id; unset, the
 * button is not drawn and these routes answer 404.
 *
 * Who it signs in: the account the token's Google id (sub) is linked to
 * (user meta groddle_google_sub); else the player whose email is the
 * token's verified email, which is linked from then on; else nobody yet: a
 * new player chooses a character name first (the same rules and the same
 * "taken" answer as New player), carried by a pending token this site signs
 * (15 minutes, the sub and the email, never Google's token), and
 * account/google/finish makes the account: no verification email (Google
 * proved the address), a subscriber, a made-up username never shown, and a
 * password nobody knows (Forgot your password sets one). Only players'
 * accounts sign in this way: an email belonging to the site's own people
 * (any role but subscriber) is sent to the password sign-in.
 *
 * Signed in with the same cookies as the password sign-in; "Keep me signed
 * in" unticked makes them end with the browser (the engine's
 * signInVerified always opens a remembered session, so the session itself
 * still lasts its fourteen days).
 *
 * Local QA only: option groddle_google_qa_jwks (a JWKS) replaces Google's
 * keys on a .localhost site, so tests sign their own tokens.
 */
final readonly class GoogleSignIn
{
    public const CLIENT_OPTION = 'groddle_google_client_id';
    public const SUB_META = 'groddle_google_sub';
    public const KEYS_OPTION = 'groddle_google_jwks';
    public const STATE_OPTION = 'groddle_google_state_key';
    public const QA_KEYS_OPTION = 'groddle_google_qa_jwks';

    /** How long the name step's pending token works. */
    public const PENDING_LIFETIME = 900;

    private const WORDS = [
        'google_failed' => 'Google sign-in didn\'t work. Try again, or use your email.',
        'google_off' => 'Google sign-in isn\'t on here. Use your email.',
        'google_staff' => 'That email belongs to one of the site\'s own accounts. Sign in with your email and password.',
        'google_other' => 'That email is linked to a different Google account. Sign in with your email and password.',
        'google_expired' => 'That took too long. Continue with Google again.',
        'google_used' => 'Your character is already made. Continue with Google to sign in.',
        'name_taken' => 'Someone already has that name. Try another.',
        'name_invalid' => 'Names are 3 to 19 letters, numbers, spaces, - or _, starting and ending with a letter or number.',
        'registration_closed' => 'New characters can\'t be made right now.',
    ];

    public function __construct(private Seams $minn)
    {
    }

    /** The site's Google client id, or '' when Google sign-in is off. */
    public static function clientId(Seams $minn): string
    {
        return trim((string) ($minn->site->option(self::CLIENT_OPTION) ?? ''));
    }

    /**
     * A credential from Sign in with Google: signed in (200 {signedIn,
     * player}), a name to choose (202 {pending, email, name}), or refused.
     */
    #[Route(Method::Post, '/groddle/v1/account/google', policy: new Policy(Access::Public))]
    public function credential(Request $request): Response
    {
        $clientId = self::clientId($this->minn);
        if ($clientId === '') {
            return self::refused('google_off', 404);
        }
        $body = $request->json();
        $claims = $this->verifier()->verify((string) ($body['credential'] ?? ''), $clientId, time());
        if (!is_array($claims)) {
            return self::refused('google_failed', 401);
        }
        $sub = (string) $claims['sub'];
        $email = (string) $claims['email'];
        $remember = !empty($body['remember']);
        $plan = self::plan($this->describe($this->linkedTo($sub)), $this->describe((new Users($this->minn->db))->findByEmail($email)), $sub);
        if ($plan[0] === 'refuse') {
            return self::refused($plan[1], 403);
        }
        if ($plan[0] === 'signin') {
            return $this->signIn((int) $plan[1], $plan[2] ? $sub : null, $remember, 200);
        }
        if (!$this->minn->accounts()->registrationOpen()) {
            return self::refused('registration_closed', 403);
        }
        $suggested = self::suggestedName((string) ($claims['given_name'] ?? ''));
        return Response::json(['pending' => $this->pendingToken($sub, $email, time()), 'email' => $email, 'name' => $suggested], 202);
    }

    /** The name step: the pending token and the chosen name make the account, then sign it in (201). */
    #[Route(Method::Post, '/groddle/v1/account/google/finish', policy: new Policy(Access::Public))]
    public function finish(Request $request): Response
    {
        if (self::clientId($this->minn) === '') {
            return self::refused('google_off', 404);
        }
        $body = $request->json();
        $pending = $this->readPending((string) ($body['pending'] ?? ''), time());
        if ($pending === null) {
            return self::refused('google_expired', 410);
        }
        [$sub, $email] = $pending;
        $remember = !empty($body['remember']);
        // Between the two steps the sub may have been linked (a second tab) or the email signed up: decide again.
        if ($this->linkedTo($sub) !== null) {
            return self::refused('google_used', 410);
        }
        $plan = self::plan(null, $this->describe((new Users($this->minn->db))->findByEmail($email)), $sub);
        if ($plan[0] === 'refuse') {
            return self::refused($plan[1], 403);
        }
        if ($plan[0] === 'signin') {
            return $this->signIn((int) $plan[1], $sub, $remember, 200);
        }
        $name = trim(preg_replace('/\s+/', ' ', (string) ($body['name'] ?? '')) ?? '');
        if (preg_match(PlayerAccount::NAME, $name) !== 1) {
            return self::refused('name_invalid', 400, ['name' => self::WORDS['name_invalid']]);
        }
        $characters = new Characters($this->minn->db);
        if ($characters->taken($name)) {
            return Response::json(['code' => 'name_taken', 'message' => self::WORDS['name_taken'], 'fields' => (object) ['name' => self::WORDS['name_taken']], 'name' => $name], 409);
        }
        $accounts = $this->minn->accounts();
        // A password nobody knows: the account is Google's until Forgot your password sets one.
        $made = $accounts->registerQuietly('player-' . bin2hex(random_bytes(5)), $email, bin2hex(random_bytes(24)), $name, PlayerAccount::ROLE);
        if ($made instanceof Refusal) {
            return Response::json(['code' => $made->code, 'message' => $made->code === 'registration_closed' ? self::WORDS['registration_closed'] : self::WORDS['google_failed'], 'fields' => (object) []], 400);
        }
        $characters->claim($made->id, $name);
        return $this->signIn($made->id, $sub, $remember, 201);
    }

    /**
     * What a verified token leads to, from the account its sub is linked to
     * and the account its email names (each null, or ['id', 'player', 'sub']):
     * ['signin', id, link?], ['refuse', code] or ['new'].
     *
     * @param array{id: int, player: bool, sub: string}|null $linked
     * @param array{id: int, player: bool, sub: string}|null $byEmail
     * @return array{0: string, 1?: int|string, 2?: bool}
     */
    public static function plan(?array $linked, ?array $byEmail, string $sub): array
    {
        if ($linked !== null) {
            return $linked['player'] ? ['signin', $linked['id'], false] : ['refuse', 'google_staff'];
        }
        if ($byEmail === null) {
            return ['new'];
        }
        if (!$byEmail['player']) {
            return ['refuse', 'google_staff'];
        }
        if ($byEmail['sub'] !== '' && $byEmail['sub'] !== $sub) {
            return ['refuse', 'google_other'];
        }
        return ['signin', $byEmail['id'], true];
    }

    /** A name from Google's given_name the name step can offer, or '' when it wouldn't pass the rules. */
    public static function suggestedName(string $given): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $given) ?? '');
        return preg_match(PlayerAccount::NAME, $name) === 1 ? $name : '';
    }

    /** The pending token for the name step: the sub, the email and when it ends, signed with the site's own key. */
    public function pendingToken(string $sub, string $email, int $now): string
    {
        $payload = GoogleIdToken::encode((string) json_encode(['s' => $sub, 'e' => $email, 'x' => $now + self::PENDING_LIFETIME]));
        return $payload . '.' . GoogleIdToken::encode(hash_hmac('sha256', 'groddle-google-pending/1.' . $payload, $this->stateKey(), true));
    }

    /** @return array{0: string, 1: string}|null the sub and the email of a pending token still good */
    public function readPending(string $token, int $now): ?array
    {
        if (strlen($token) > 1024 || substr_count($token, '.') !== 1) {
            return null;
        }
        [$payload, $mac] = explode('.', $token);
        $want = GoogleIdToken::encode(hash_hmac('sha256', 'groddle-google-pending/1.' . $payload, $this->stateKey(), true));
        if (!hash_equals($want, $mac)) {
            return null;
        }
        $data = json_decode((string) GoogleIdToken::decode($payload), true);
        if (!is_array($data) || !is_string($data['s'] ?? null) || !is_string($data['e'] ?? null) || !is_int($data['x'] ?? null) || $data['x'] <= $now) {
            return null;
        }
        return [$data['s'], $data['e']];
    }

    /** The checker, with Google's keys kept in an option (or the local QA keys). */
    private function verifier(): GoogleIdToken
    {
        $site = $this->minn->site;
        $load = static fn (): mixed => json_decode((string) ($site->option(self::KEYS_OPTION) ?? ''), true);
        $save = static function (array $copy) use ($site): void {
            $site->setOption(self::KEYS_OPTION, (string) json_encode($copy), 'off');
        };
        return new GoogleIdToken($this->minn->http, $load, $save, $this->qaKeys());
    }

    /** The local QA keys: option groddle_google_qa_jwks, honoured only on a .localhost site. */
    private function qaKeys(): ?array
    {
        $home = (string) ($this->minn->site->option('home') ?? '');
        $json = (string) ($this->minn->site->option(self::QA_KEYS_OPTION) ?? '');
        if ($json === '' || !str_ends_with((string) parse_url($home, PHP_URL_HOST), '.localhost')) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) && is_array($data['keys'] ?? null) ? array_values($data['keys']) : null;
    }

    /** The key the pending tokens are signed with, made once and kept in an option nobody is shown. */
    private function stateKey(): string
    {
        $key = (string) ($this->minn->site->option(self::STATE_OPTION) ?? '');
        if (strlen($key) < 64) {
            $key = bin2hex(random_bytes(32));
            $this->minn->site->setOption(self::STATE_OPTION, $key, 'off');
        }
        return $key;
    }

    /** The account a Google sub is linked to. */
    private function linkedTo(string $sub): ?UserRecord
    {
        $meta = $this->minn->db->table('usermeta');
        $id = $this->minn->db->value("SELECT user_id FROM {$meta} WHERE meta_key = ? AND meta_value = ? ORDER BY user_id LIMIT 1", [self::SUB_META, $sub]);
        return $id === null ? null : (new Users($this->minn->db))->find((int) $id);
    }

    /** @return array{id: int, player: bool, sub: string}|null an account as plan() reads it */
    private function describe(?UserRecord $user): ?array
    {
        if ($user === null) {
            return null;
        }
        $users = new Users($this->minn->db);
        return ['id' => $user->id, 'player' => self::onlySubscriber($users->meta($user->id, $this->minn->db->prefix() . 'capabilities')), 'sub' => (string) ($users->meta($user->id, self::SUB_META) ?? '')];
    }

    /** Whether stored capabilities name the subscriber role and nothing else (a player). */
    public static function onlySubscriber(?string $stored): bool
    {
        if ($stored === null || !str_starts_with($stored, 'a:')) {
            return false;
        }
        $roles = @unserialize($stored, ['allowed_classes' => false]);
        return is_array($roles) && array_keys(array_filter($roles)) === [PlayerAccount::ROLE];
    }

    /** Signs a player in (linking the sub first when asked), as the password sign-in does. */
    private function signIn(int $id, ?string $link, bool $remember, int $status): Response
    {
        $users = new Users($this->minn->db);
        $user = $users->find($id);
        if ($user === null) {
            return self::refused('google_failed', 401);
        }
        if ($link !== null) {
            $users->setMeta($id, self::SUB_META, $link);
        }
        return $this->answer($this->minn->accounts()->signInVerified(Response::json([]), $user), $status, $remember);
    }

    /** The signed-in answer with the session's cookies; not remembered, they end with the browser. */
    private function answer(SignedIn $signedIn, int $status, bool $remember): Response
    {
        $response = Response::json(['signedIn' => true, 'player' => Player::describe($signedIn->user, new Characters($this->minn->db))], $status);
        foreach ($signedIn->response->cookies as [$name, $value, $options]) {
            if (!$remember && (int) ($options['expires'] ?? 0) > time()) {
                $options['expires'] = 0;
            }
            $response = $response->withCookie($name, $value, $options);
        }
        return $response;
    }

    /** @param array<string, string> $fields */
    private static function refused(string $code, int $status, array $fields = []): Response
    {
        return Response::json(['code' => $code, 'message' => self::WORDS[$code], 'fields' => (object) $fields], $status);
    }
}
