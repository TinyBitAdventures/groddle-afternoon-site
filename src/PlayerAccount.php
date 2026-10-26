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
 * sign-up and kept in the extension's own table (Characters; also the
 * account's display name):
 * 3 to 19 letters, numbers, spaces, - or _, starting and ending with a
 * letter or a number, and no two players share one. Passwords are at least
 * 8 characters.
 *
 * A password reset mails a link to /play/online/#reset=<key>&login=<login>;
 * the game's form there sends the new password to password-reset/confirm,
 * which saves it and signs the player in.
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
        'password_refused' => 'password',
        'incorrect_password' => 'current',
    ];

    /** Our own words for the engine's refusals. */
    private const WORDS = [
        'email_exists' => 'That email already has a character. Sign in instead.',
        'invalid_email' => 'That doesn\'t look like an email address.',
        'empty_email' => 'Enter your email.',
        'empty_password' => 'Choose a password.',
        'registration_closed' => 'New characters can\'t be made right now.',
        'incorrect_credentials' => 'That email and password don\'t match.',
        'incorrect_password' => 'That isn\'t your current password.',
        'too_many_attempts' => 'Too many tries. Wait a few minutes, then try again.',
        'invalid_key' => 'This reset link doesn\'t work any more. Ask for a new one.',
        'expired_key' => 'This reset link has expired. Ask for a new one.',
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
        } elseif ((new Characters($this->minn->db))->taken($name)) {
            $fields['name'] = 'Someone already has that name. Try another.';
        }
        if ($fields !== []) {
            return self::refused('groddle_invalid', (string) reset($fields), $fields, 400);
        }
        $accounts = $this->minn->accounts();
        // Quietly: the site's admin isn't mailed for every player (Austin 2026-10-26); Minn Admin's users list shows them.
        $made = $accounts->registerQuietly('player-' . bin2hex(random_bytes(5)), $email, $password, $name, self::ROLE);
        if ($made instanceof Refusal) {
            return self::fromRefusal($made);
        }
        // Two sign-ups racing for one name: the first keeps it, the second shows its display name until renamed (G337).
        (new Characters($this->minn->db))->claim($made->id, $name);
        $signedIn = $accounts->signInRemembered(Response::json([]), $email, $password);
        return $signedIn instanceof SignedIn ? $this->answer($signedIn, 201) : self::fromRefusal($signedIn);
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
        return $result instanceof SignedIn ? $this->answer($result, 200) : self::fromRefusal($result);
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
        // The link opens the game's own reset form: /play/online/#reset=<key>&login=<login> (the fragment never reaches a server log).
        $home = rtrim((string) ($this->minn->site->option('home') ?? ''), '/');
        $page = $home . '/play/online/';
        $link = static fn (string $login, string $key): string => $page . '#reset=' . rawurlencode($key) . '&login=' . rawurlencode($login);
        // In the site's look, naming the character (PlayerMail).
        $from = (string) ($this->minn->site->option('groddle_mail_from') ?? '');
        $words = static fn (string $url, string $character): array => PlayerMail::reset($home, $from, $url, $character);
        $sent = $this->minn->accounts()->lostPassword($email, $link, $words);
        if ($sent instanceof Refusal && !in_array($sent->code, ['invalid_email', 'invalidcombo'], true)) {
            return self::refused($sent->code, 'The email could not be sent. Try again later.', [], 503);
        }
        return Response::json(['sent' => true]);
    }

    /** A new password from a reset link's key, then signed straight in. */
    #[Route(Method::Post, '/groddle/v1/password-reset/confirm', policy: new Policy(Access::Public))]
    public function confirmReset(Request $request): Response
    {
        $body = $request->json();
        $login = (string) ($body['login'] ?? '');
        $password = (string) ($body['password'] ?? '');
        if (strlen($password) < self::MIN_PASSWORD) {
            $words = 'Passwords need at least ' . self::MIN_PASSWORD . ' characters.';
            return self::refused('groddle_invalid', $words, ['password' => $words], 400);
        }
        $accounts = $this->minn->accounts();
        $user = $accounts->resetPassword($login, (string) ($body['key'] ?? ''), $password);
        if ($user instanceof Refusal) {
            return self::fromRefusal($user);
        }
        $signedIn = $accounts->signInRemembered(Response::json([]), $user->email, $password);
        return $signedIn instanceof SignedIn ? $this->answer($signedIn, 200) : self::fromRefusal($signedIn);
    }

    /** A new password, once the current one checks out: the player stays signed in here, everywhere else is signed out. */
    #[Route(Method::Put, '/groddle/v1/account/password', policy: new Policy(Access::SignedIn))]
    public function changePassword(Request $request): Response
    {
        $body = $request->json();
        $password = (string) ($body['password'] ?? '');
        if (strlen($password) < self::MIN_PASSWORD) {
            $words = 'Passwords need at least ' . self::MIN_PASSWORD . ' characters.';
            return self::refused('groddle_invalid', $words, ['password' => $words], 400);
        }
        $notice = $this->notice(static fn (string $home, string $from, string $character): array => PlayerMail::passwordChanged($home, $from, $character));
        $result = $this->minn->accounts()->changePassword(Response::json([]), $this->minn->user(), (string) ($body['current'] ?? ''), $password, $notice);
        return $result instanceof SignedIn ? $this->answer($result, 200) : self::fromRefusal($result);
    }

    /** A new email, once the current password checks out; the old address is told. */
    #[Route(Method::Put, '/groddle/v1/account/email', policy: new Policy(Access::SignedIn))]
    public function changeEmail(Request $request): Response
    {
        $body = $request->json();
        $email = trim((string) ($body['email'] ?? ''));
        $contact = (string) ($this->minn->site->option('admin_email') ?? '');
        $notice = $this->notice(static fn (string $home, string $from, string $character): array => PlayerMail::emailChanged($home, $from, $character, $email, $contact));
        $result = $this->minn->accounts()->changeEmail($this->minn->user(), (string) ($body['current'] ?? ''), $email, $notice);
        return $result instanceof Refusal ? self::fromRefusal($result) : Response::json(['email' => $result->email]);
    }

    /**
     * The words for an account notice, naming the character (not the
     * display name the engine passes, which a profile can change).
     *
     * @param \Closure(string, string, string): array $mail
     */
    private function notice(\Closure $mail): \Closure
    {
        $home = rtrim((string) ($this->minn->site->option('home') ?? ''), '/');
        $from = (string) ($this->minn->site->option('groddle_mail_from') ?? '');
        $user = $this->minn->user();
        $character = $user === null ? '' : (new Characters($this->minn->db))->nameOf($user);
        return static fn (string $shown): array => $mail($home, $from, $character !== '' ? $character : $shown);
    }

    /** The signed-in answer: who, with the session's cookies. */
    private function answer(SignedIn $signedIn, int $status): Response
    {
        $response = Response::json(['signedIn' => true, 'player' => Player::describe($signedIn->user, new Characters($this->minn->db))], $status);
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
        // A refusal of the engine's own (no notices) still belongs to a field: the wrong current password.
        $own = self::FIELDS[$refusal->code] ?? null;
        if ($fields === [] && $own !== null) {
            $fields[$own] = self::WORDS[$refusal->code] ?? $refusal->message;
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
