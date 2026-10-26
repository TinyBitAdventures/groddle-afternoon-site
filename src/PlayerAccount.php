<?php

declare(strict_types=1);

namespace Groddle\Minn;

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
 * The game's own sign-in: a player makes an account, signs in and out, and
 * asks for a password reset from /play/online/, never seeing the site's
 * admin sign-in page. Each route goes through the engine's accounts
 * (Seams::accounts): the same throttle, sessions and cookies as
 * wp-login.php, so a player's account is an ordinary site account, always
 * a subscriber.
 *
 * A player signs in with their email. The account still has a username
 * underneath (the site needs one): it is made up here (player-<hex>) and
 * never shown. What other players see is the character's name, chosen at
 * sign-up and kept as the account's display name (Player, RealtimeTicket):
 * 3 to 19 letters, numbers, spaces, - or _, starting and ending with a
 * letter or a number, and no two players share one. Passwords are at least
 * 8 characters.
 *
 * Answers: 201/200 {signedIn, player}, or 400/429 {code, message, fields}
 * with the refusals keyed by the form field they belong to.
 */
final readonly class PlayerAccount
{
    public const NAME = '/^[A-Za-z0-9](?:[A-Za-z0-9 _-]{1,17})[A-Za-z0-9]$/';

    public const MIN_PASSWORD = 8;

    /** Players are subscribers whatever the site's default role says: they play, they never edit (no Minn bar). */
    public const ROLE = 'subscriber';

    /** The engine's refusal codes, by the field of the form they are about. */
    private const FIELDS = [
        'empty_email' => 'email', 'invalid_email' => 'email', 'email_exists' => 'email',
        'empty_password' => 'password',
    ];

    /** Our own words for the engine's refusals. */
    private const WORDS = [
        'email_exists' => 'That email already has a character. Sign in instead.',
        'invalid_email' => 'That doesn\'t look like an email address.',
        'empty_email' => 'Enter your email.',
        'empty_password' => 'Choose a password.',
        'registration_closed' => 'New characters can\'t be made right now.',
        'incorrect_credentials' => 'That email and password don\'t match.',
        'too_many_attempts' => 'Too many tries. Wait a few minutes, then try again.',
    ];

    public function __construct(private Seams $minn)
    {
    }

    /** A new player: the account, then signed straight in. */
    #[Route(Method::Post, '/groddle/v1/players', policy: new Policy(Access::Public))]
    public function create(Request $request): Response
    {
        $body = $request->json();
        $name = trim(preg_replace('/\s+/', ' ', (string) ($body['name'] ?? '')) ?? '');
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        // A field people never see: a form that fills it is a script.
        if (trim((string) ($body['website'] ?? '')) !== '') {
            return self::refused('groddle_spam', 'That didn\'t work. Try again.', [], 400);
        }
        $fields = [];
        if (!str_contains($email, '@')) {
            $fields['email'] = self::WORDS['invalid_email'];
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            $fields['password'] = 'Passwords need at least ' . self::MIN_PASSWORD . ' characters.';
        }
        if (preg_match(self::NAME, $name) !== 1) {
            $fields['name'] = 'Names are 3 to 19 letters, numbers, spaces, - or _, starting and ending with a letter or number.';
        } elseif ($this->nameTaken($name)) {
            $fields['name'] = 'Someone already has that name. Try another.';
        }
        if ($fields !== []) {
            return self::refused('groddle_invalid', (string) reset($fields), $fields, 400);
        }
        $accounts = $this->minn->accounts();
        $made = $accounts->register('player-' . bin2hex(random_bytes(5)), $email, $password, $name, self::ROLE);
        if ($made instanceof Refusal) {
            return self::fromRefusal($made);
        }
        $signedIn = $accounts->signInRemembered(Response::json([]), $email, $password);
        return $signedIn instanceof SignedIn ? self::answer($signedIn, 201) : self::fromRefusal($signedIn);
    }

    /** Signs a player in with their email. */
    #[Route(Method::Post, '/groddle/v1/session', policy: new Policy(Access::Public))]
    public function signIn(Request $request): Response
    {
        $body = $request->json();
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if (!str_contains($email, '@') || $password === '') {
            return self::refused('groddle_invalid', 'Enter your email and password.', [], 400);
        }
        $accounts = $this->minn->accounts();
        $answer = Response::json([]);
        $result = empty($body['remember']) ? $accounts->signIn($answer, $email, $password) : $accounts->signInRemembered($answer, $email, $password);
        return $result instanceof SignedIn ? self::answer($result, 200) : self::fromRefusal($result);
    }

    /** Signs the player out. */
    #[Route(Method::Delete, '/groddle/v1/session', policy: new Policy(Access::Public))]
    public function signOut(Request $request): Response
    {
        return $this->minn->accounts()->signOut(Response::json(['signedIn' => false]));
    }

    /**
     * A password reset link by email. The answer is the same whether or not
     * an account has that address, so the form can't be used to find out
     * who plays.
     */
    #[Route(Method::Post, '/groddle/v1/password-reset', policy: new Policy(Access::Public))]
    public function reset(Request $request): Response
    {
        $email = trim((string) ($request->json()['email'] ?? ''));
        if (!str_contains($email, '@')) {
            return self::refused('groddle_invalid', 'Enter your email.', ['email' => 'Enter your email.'], 400);
        }
        $sent = $this->minn->accounts()->lostPassword($email);
        if ($sent instanceof Refusal && !in_array($sent->code, ['invalid_email', 'invalidcombo'], true)) {
            return self::refused($sent->code, 'The email could not be sent. Try again later.', [], 503);
        }
        return Response::json(['sent' => true]);
    }

    /** Whether another account already shows this name (letter case aside). */
    private function nameTaken(string $name): bool
    {
        $users = $this->minn->db->table('users');
        return $this->minn->db->value("SELECT ID FROM {$users} WHERE LOWER(display_name) = LOWER(?) LIMIT 1", [$name]) !== null;
    }

    /** The signed-in answer: who, with the session's cookies. */
    private static function answer(SignedIn $signedIn, int $status): Response
    {
        $response = Response::json(['signedIn' => true, 'player' => Player::describe($signedIn->user)], $status);
        foreach ($signedIn->response->cookies as $cookie) {
            $response = $response->withCookie(...$cookie);
        }
        return $response;
    }

    private static function fromRefusal(Refusal $refusal): Response
    {
        $fields = [];
        foreach ((array) ($refusal->data['notices'] ?? []) as $notice) {
            $field = self::FIELDS[$notice['code'] ?? ''] ?? null;
            if ($field !== null && !isset($fields[$field])) {
                $fields[$field] = self::WORDS[$notice['code']] ?? (string) ($notice['message'] ?? '');
            }
        }
        $status = $refusal->code === 'too_many_attempts' ? 429 : 400;
        return self::refused($refusal->code, self::WORDS[$refusal->code] ?? $refusal->message, $fields, $status);
    }

    /** @param array<string, string> $fields */
    private static function refused(string $code, string $message, array $fields, int $status): Response
    {
        return Response::json(['code' => $code, 'message' => $message, 'fields' => (object) $fields], $status);
    }
}
