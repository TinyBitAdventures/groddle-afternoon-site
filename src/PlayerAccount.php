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
 * No account exists until its email is proven (Austin 2026-10-27). A
 * sign-up is checked as before, then held (Signups: the email, the
 * password's hash, the name) and a link to /play/online/#verify=<token> is
 * mailed; the answer is 202 {pending, email, name, nameHeld}. The game's
 * gate sends the token to players/verify, which makes the account exactly
 * as a sign-up used to and signs the player in. players/resend mails a new
 * link (by the link's token, or by email with the same answer whether or
 * not one is waiting). Two sign-ups may ask for one name: the first link
 * opened gets it, and the other is asked for another name.
 *
 * A password reset mails a link to /play/online/#reset=<key>&login=<login>;
 * the game's form there sends the new password to password-reset/confirm,
 * which saves it and signs the player in.
 *
 * Email abuse (Austin 2026-10-28): the forms that mail an address someone
 * typed (New player, Send it again by email, Forgot your password) carry a
 * Cloudflare Turnstile token when the site has its keys (Turnstile), resets
 * are limited per email and per address, and the whole site sends at most
 * an hourly ceiling of these emails (MailLimits). account/options tells the
 * card which widgets to draw.
 *
 * Answers: 201/200 {signedIn, player}, 202 {pending}, or 400/409/410/429
 * {code, message, fields} with the refusals keyed by the form field they
 * belong to.
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
        'signup_unknown' => 'This link doesn\'t work any more. Use the newest email we sent, or sign up again.',
        'signup_used' => 'This link was already used. Sign in with your email and password.',
        'signup_expired' => 'This link has expired. We can send you a new one.',
        'signup_wait' => 'We just sent one. Wait a minute, then ask again.',
        'signup_spent' => 'That\'s all the emails we can send for this sign-up. Sign up again tomorrow.',
        'signup_busy' => 'Too many new characters from here. Try again in an hour.',
        'mail_busy' => MailLimits::BUSY,
        'reset_wait' => MailLimits::RESET_WAIT,
        'turnstile_failed' => Turnstile::FAILED,
    ];

    /**
     * Local QA only: an option listing address patterns (fnmatch, comma
     * separated, e.g. zz-*@groddle.localhost) that sign up at once, the old
     * way, so test scripts need no mailbox. Ignored unless the site's home
     * is a .localhost address.
     */
    public const INSTANT_OPTION = 'groddle_signup_instant';

    public function __construct(private Seams $minn)
    {
    }

    /** A new player: checked, then held until the emailed link is opened (or, for a QA address on a local site, made at once). */
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
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
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
        if (!isset($fields['email']) && $this->emailTaken($email)) {
            $fields['email'] = self::WORDS['email_exists'];
        }
        if ($fields !== []) {
            $code = $fields === ['email' => self::WORDS['email_exists']] ? 'email_exists' : 'groddle_invalid';
            return self::refused($code, (string) reset($fields), $fields, 400);
        }
        $accounts = $this->minn->accounts();
        if (!$accounts->registrationOpen()) {
            return self::refused('registration_closed', self::WORDS['registration_closed'], [], 400);
        }
        if ($this->instant($email)) {
            return $this->makeNow($email, $password, $name);
        }
        $refused = $this->turnstileRefusal($request, $body);
        if ($refused !== null) {
            return $refused;
        }
        $now = time();
        $limits = new MailLimits($this->minn->db, $this->minn->site);
        $limits->purge($now);
        if ($limits->overHourCap($now, 'sign-up')) {
            return self::refused('mail_busy', self::WORDS['mail_busy'], [], 503);
        }
        $signups = new Signups($this->minn->db);
        $signups->purge($now);
        $waiting = $signups->byEmail($email);
        $limit = Signups::sendRefusal($waiting, $now);
        if ($limit === 'spent') {
            return self::refused('signup_spent', self::WORDS['signup_spent'], [], 429);
        }
        if ($waiting === null && $signups->recentFrom($request->remoteAddress, $now) >= Signups::PER_ADDRESS_HOUR) {
            return self::refused('signup_busy', self::WORDS['signup_busy'], [], 429);
        }
        $held = $signups->nameHeldByOther($name, $email, $now);
        $token = $signups->hold($email, $accounts->passwordHash($password), $name, $request->remoteAddress, $now);
        if (!$this->mailLink($email, $name, $token)) {
            return self::refused('mail_failed', 'The email could not be sent. Try again later.', [], 503);
        }
        $limits->record('signup', $email, $request->remoteAddress, true, $now);
        return Response::json(['pending' => true, 'email' => $email, 'name' => $name, 'nameHeld' => $held], 202);
    }

    /**
     * A held sign-up's link opened: the account made as a sign-up made it,
     * from the held hash, and the player signed in. A name someone else got
     * first is asked for again (409, fields.name), with the link still good.
     */
    #[Route(Method::Post, '/groddle/v1/players/verify', policy: new Policy(Access::Public))]
    public function verify(Request $request): Response
    {
        $body = $request->json();
        $token = strtolower(trim((string) ($body['token'] ?? '')));
        $signups = new Signups($this->minn->db);
        $row = Signups::looksLikeToken($token) ? $signups->byToken($token) : null;
        $now = time();
        $refusal = match (true) {
            $row === null => ['signup_unknown', 410],
            ($row['used_at'] ?? null) !== null => ['signup_used', 410],
            !Signups::pending($row, $now) => ['signup_expired', 410],
            default => null,
        };
        if ($refusal !== null) {
            return self::refused($refusal[0], self::WORDS[$refusal[0]], [], $refusal[1]);
        }
        $name = trim(preg_replace('/\s+/', ' ', (string) ($body['name'] ?? '')) ?? '');
        $name = $name !== '' ? $name : (string) $row['name'];
        $characters = new Characters($this->minn->db);
        if (preg_match(self::NAME, $name) !== 1) {
            $words = 'Names are 3 to 19 letters, numbers, spaces, - or _, starting and ending with a letter or number.';
            return self::refused('groddle_invalid', $words, ['name' => $words], 400);
        }
        if ($characters->taken($name)) {
            $words = "Someone opened their link first and took the name {$name}. Choose another name to finish.";
            return Response::json(['code' => 'name_taken', 'message' => $words, 'fields' => (object) ['name' => $words], 'name' => $name], 409);
        }
        if ($this->emailTaken((string) $row['email'])) {
            return self::refused('email_exists', self::WORDS['email_exists'], [], 409);
        }
        if (!$signups->spend($row, $now)) {
            return self::refused('signup_used', self::WORDS['signup_used'], [], 410);
        }
        $accounts = $this->minn->accounts();
        // Quietly: the site's admin isn't mailed for every player (Austin 2026-10-26); Minn Admin's users list shows them.
        $made = $accounts->registerQuietlyWithHash('player-' . bin2hex(random_bytes(5)), (string) $row['email'], (string) $row['pass_hash'], $name, self::ROLE);
        if ($made instanceof Refusal) {
            $signups->unspend($row);
            return self::fromRefusal($made);
        }
        // Two links opened at once for one name: the first keeps it, the second shows its display name until renamed (G337).
        $characters->claim($made->id, $name);
        $signups->made($row, $made->id, $name);
        return $this->answer($accounts->signInVerified(Response::json([]), $made), 201);
    }

    /**
     * Mails a held sign-up a new link: by the link's own token (an expired
     * link's page), or by email, answered the same whether or not a sign-up
     * waits for it, so the form can't be used to find out who signed up.
     * By email it needs a Turnstile token when the site has Turnstile (by
     * token the link itself proves the mailbox); either way the site-wide
     * ceiling holds.
     */
    #[Route(Method::Post, '/groddle/v1/players/resend', policy: new Policy(Access::Public))]
    public function resend(Request $request): Response
    {
        $body = $request->json();
        $token = strtolower(trim((string) ($body['token'] ?? '')));
        $signups = new Signups($this->minn->db);
        $now = time();
        $limits = new MailLimits($this->minn->db, $this->minn->site);
        if ($token !== '') {
            $row = Signups::looksLikeToken($token) ? $signups->byToken($token) : null;
            if ($row === null || ($row['used_at'] ?? null) !== null) {
                $code = $row === null ? 'signup_unknown' : 'signup_used';
                return self::refused($code, self::WORDS[$code], [], 410);
            }
            $limit = Signups::sendRefusal($row, $now);
            if ($limit !== null) {
                return self::refused("signup_{$limit}", self::WORDS["signup_{$limit}"], [], 429);
            }
            if ($limits->overHourCap($now, 'sign-up')) {
                return self::refused('mail_busy', self::WORDS['mail_busy'], [], 503);
            }
            if (!$this->mailLink((string) $row['email'], (string) $row['name'], $signups->reissue($row, $now))) {
                return self::refused('mail_failed', 'The email could not be sent. Try again later.', [], 503);
            }
            $limits->record('signup', (string) $row['email'], $request->remoteAddress, true, $now);
            return Response::json(['sent' => true, 'email' => (string) $row['email']]);
        }
        $email = trim((string) ($body['email'] ?? ''));
        if (!str_contains($email, '@')) {
            return self::refused('groddle_invalid', 'Enter your email.', ['email' => 'Enter your email.'], 400);
        }
        $refused = $this->turnstileRefusal($request, $body);
        if ($refused !== null) {
            return $refused;
        }
        if ($limits->overHourCap($now, 'sign-up')) {
            return self::refused('mail_busy', self::WORDS['mail_busy'], [], 503);
        }
        $row = $signups->byEmail($email);
        // Nothing waiting, already used, or too soon: the same answer, nothing sent.
        if ($row !== null && ($row['used_at'] ?? null) === null && Signups::sendRefusal($row, $now) === null) {
            $mailed = $this->mailLink((string) $row['email'], (string) $row['name'], $signups->reissue($row, $now));
            $limits->record('signup', $email, $request->remoteAddress, $mailed, $now);
        }
        return Response::json(['sent' => true]);
    }

    /** The old way, for QA addresses on a local site: the account at once, signed straight in. */
    private function makeNow(string $email, string $password, string $name): Response
    {
        $accounts = $this->minn->accounts();
        $made = $accounts->registerQuietly('player-' . bin2hex(random_bytes(5)), $email, $password, $name, self::ROLE);
        if ($made instanceof Refusal) {
            return self::fromRefusal($made);
        }
        (new Characters($this->minn->db))->claim($made->id, $name);
        $signedIn = $accounts->signInRemembered(Response::json([]), $email, $password);
        return $signedIn instanceof SignedIn ? $this->answer($signedIn, 201) : self::fromRefusal($signedIn);
    }

    /** Whether an address matches the local QA option (INSTANT_OPTION), on a .localhost site only. */
    private function instant(string $email): bool
    {
        $home = (string) ($this->minn->site->option('home') ?? '');
        $patterns = (string) ($this->minn->site->option(self::INSTANT_OPTION) ?? '');
        if ($patterns === '' || !str_ends_with((string) parse_url($home, PHP_URL_HOST), '.localhost')) {
            return false;
        }
        foreach (array_filter(array_map('trim', explode(',', $patterns))) as $pattern) {
            if (fnmatch(strtolower($pattern), strtolower($email))) {
                return true;
            }
        }
        return false;
    }

    /** Whether an account already signs in with this email (the sign-up form says so, as it always has). */
    private function emailTaken(string $email): bool
    {
        $users = $this->minn->db->table('users');
        return $this->minn->db->value("SELECT ID FROM {$users} WHERE user_email = ? LIMIT 1", [$email]) !== null;
    }

    /** Mails a held sign-up's link, to /play/online/#verify=<token> (the fragment never reaches a server log). */
    private function mailLink(string $email, string $name, string $token): bool
    {
        $home = rtrim((string) ($this->minn->site->option('home') ?? ''), '/');
        $from = (string) ($this->minn->site->option('groddle_mail_from') ?? '');
        [$subject, $html, $headers] = PlayerMail::verify($home, $from, $home . '/play/online/#verify=' . $token, $name);
        return $this->minn->accounts()->mail($email, $subject, $html, $headers);
    }

    /**
     * What the card draws, for the forms to ask once: the Turnstile site key
     * (null when Turnstile is off). Public keys only; never a secret.
     */
    #[Route(Method::Get, '/groddle/v1/account/options', policy: new Policy(Access::Public))]
    public function options(Request $request): Response
    {
        $turnstile = Turnstile::fromSite($this->minn->site)->siteKey();
        return Response::json(['turnstile' => $turnstile !== '' ? $turnstile : null]);
    }

    /** A form's Turnstile token checked (Turnstile): null when it passes or Turnstile is off, else the refusal. */
    private function turnstileRefusal(Request $request, array $body): ?Response
    {
        $passes = Turnstile::fromSite($this->minn->site)->passes($this->minn->http, (string) ($body['turnstile'] ?? ''), $request->remoteAddress);
        if ($passes === true) {
            return null;
        }
        return self::refused('turnstile_failed', self::WORDS['turnstile_failed'], [], $passes === 'unavailable' ? 503 : 400);
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
     * who plays. Limited (MailLimits): one a minute and five a day per
     * email, ten an hour per address, counted for every ask, player or not;
     * a stopped ask is answered as if sent, except the same address asking
     * for the same email within the minute, which is told to wait. A
     * Turnstile token when the site has Turnstile; the site-wide ceiling.
     */
    #[Route(Method::Post, '/groddle/v1/password-reset', policy: new Policy(Access::Public))]
    public function reset(Request $request): Response
    {
        $body = $request->json();
        $email = trim((string) ($body['email'] ?? ''));
        if (!str_contains($email, '@')) {
            return self::refused('groddle_invalid', 'Enter your email.', ['email' => 'Enter your email.'], 400);
        }
        $refused = $this->turnstileRefusal($request, $body);
        if ($refused !== null) {
            return $refused;
        }
        $now = time();
        $limits = new MailLimits($this->minn->db, $this->minn->site);
        $limits->purge($now);
        $decision = MailLimits::resetDecision($limits->resetCounts($email, $request->remoteAddress, $now));
        if ($decision === 'wait') {
            return self::refused('reset_wait', self::WORDS['reset_wait'], [], 429);
        }
        if ($decision === 'quiet') {
            return Response::json(['sent' => true]);
        }
        if ($limits->overHourCap($now, 'password reset')) {
            return self::refused('mail_busy', self::WORDS['mail_busy'], [], 503);
        }
        // The link opens the game's own reset form: /play/online/#reset=<key>&login=<login> (the fragment never reaches a server log).
        $home = rtrim((string) ($this->minn->site->option('home') ?? ''), '/');
        $page = $home . '/play/online/';
        $link = static fn (string $login, string $key): string => $page . '#reset=' . rawurlencode($key) . '&login=' . rawurlencode($login);
        // In the site's look, naming the character (PlayerMail).
        $from = (string) ($this->minn->site->option('groddle_mail_from') ?? '');
        $words = static fn (string $url, string $character): array => PlayerMail::reset($home, $from, $url, $character);
        $sent = $this->minn->accounts()->lostPassword($email, $link, $words);
        $limits->record('reset', $email, $request->remoteAddress, $sent === true, $now);
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
