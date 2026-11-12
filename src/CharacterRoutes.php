<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Auth\Nonce;
use Minn\Content\Users;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\Runtime\Refusal;
use Minn\RestError;

/**
 * A login's characters (Logins), for the signed-in player only:
 *
 *   GET  characters               {"login","current","limit","canCreate","nextCreateAt","characters":[Character]}
 *   POST characters {"name"}      201 {"character":Character}; 409 character_limit (3, a retired character
 *                                 counting until its 30 day name hold ends), 429 character_daily
 *                                 (with nextCreateAt), 422 groddle_invalid / name_taken (fields.name)
 *   POST characters/{id}/play     {"ok":true,"nonce","player":{id,name}}: the session ends and a new one
 *                                 opens as that character (its cookies on the answer, its REST nonce in the body)
 *   POST characters/{id}/retire   {"character":Character}  an extra character, not the one playing now
 *   POST characters/{id}/restore  {"character":Character}  {"name"} when its name was let go (409 name_needed)
 *   Character: {"id","name","level","lastPlayedAt":unix|null,"retired":bool,"first":bool}
 *
 * Only characters of the caller's own login are ever named, played, retired
 * or restored: any other id answers 404 exactly as an id nobody has. A new
 * character is checked by the sign-up's rules (PlayerAccount::NAME,
 * WordFilter, Characters::taken, a name a waiting sign-up asked for) and is
 * an account of its own, a subscriber, with a password nobody knows and an
 * address that is never delivered (Logins::email): it is reached only by
 * switching here.
 */
final readonly class CharacterRoutes
{
    private const NAME_WORDS = 'Names are 3 to 19 letters, numbers, spaces, - or _, starting and ending with a letter or number.';

    private const WORDS = [
        'character_limit' => 'You have as many characters as a login can have.',
        'character_daily' => 'You can make one new character a day.',
        'name_taken' => 'Someone already has that name. Try another.',
        'name_needed' => 'Someone else has this character\'s name now. Choose a new name to bring them back.',
        'groddle_character_missing' => 'That character isn\'t one of yours.',
        'groddle_character_retired' => 'That character is retired. Bring them back first.',
        'groddle_character_first' => 'Your first character can\'t be retired.',
        'groddle_character_current' => 'Switch to another character before retiring this one.',
        'groddle_character_active' => 'That character isn\'t retired.',
        'groddle_suspended' => AdminConsole::SUSPENDED,
        'registration_closed' => 'New characters can\'t be made right now.',
    ];

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/characters', policy: new Policy(Access::SignedIn))]
    public function list(Request $request): Response
    {
        $me = $this->me();
        $logins = new Logins($this->minn->db);
        $login = $logins->loginOf($me->id);
        $now = time();
        $refusal = $logins->createRefusal($login, $now);
        $banned = AdminConsole::banned($this->minn->db, $me->id) !== null;
        return Response::json([
            'login' => $login,
            'current' => $me->id,
            'limit' => Logins::LIMIT,
            'canCreate' => $refusal === null && !$banned,
            'nextCreateAt' => $logins->nextCreateAt($login, $now),
            'characters' => $this->described($logins->charactersOf($login)),
        ])->withHeader('Cache-Control', 'private, no-store');
    }

    #[Route(Method::Post, '/groddle/v1/characters', policy: new Policy(Access::SignedIn))]
    public function create(Request $request): Response
    {
        $me = $this->me();
        $db = $this->minn->db;
        $logins = new Logins($db);
        $login = $logins->loginOf($me->id);
        if (AdminConsole::banned($db, $me->id) !== null) {
            return self::refused('groddle_suspended', 403);
        }
        $name = self::cleanName($request->json()['name'] ?? '');
        // One login's two windows can't both slip past the limit or the one a day.
        $lock = 'groddle_characters_' . $login;
        $db->value('SELECT GET_LOCK(?, 5)', [$lock]);
        try {
            $now = time();
            $refusal = $logins->createRefusal($login, $now);
            if ($refusal !== null) {
                return self::refused($refusal['code'], $refusal['status'], [], ['nextCreateAt' => $refusal['nextCreateAt']]);
            }
            $bad = $this->nameRefusal($name, $login, $now);
            if ($bad !== null) {
                return $bad;
            }
            $made = $this->minn->accounts()->registerQuietly('player-' . bin2hex(random_bytes(5)), Logins::email(), bin2hex(random_bytes(24)), $name, PlayerAccount::ROLE);
            if ($made instanceof Refusal) {
                return self::refused($made->code === 'registration_closed' ? 'registration_closed' : 'groddle_character_failed', 503);
            }
            $logins->link($made->id, $login, $now);
            // Two logins asking for one name at once: the first keeps it, the other shows its display name until renamed (as G337).
            (new Characters($db))->claim($made->id, $name);
        } finally {
            $db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
        $row = array_values(array_filter($logins->charactersOf($login), static fn (array $c): bool => $c['id'] === $made->id));
        return Response::json(['character' => $this->described($row)[0]], 201);
    }

    /** Plays as another character of the caller's login: the session ends and one opens as them. */
    #[Route(Method::Post, '/groddle/v1/characters/{id:\d+}/play', policy: new Policy(Access::SignedIn))]
    public function play(Request $request, string $id): Response
    {
        $me = $this->me();
        $target = (int) $id;
        $refusal = (new Logins($this->minn->db))->playRefusal($me->id, $target);
        if ($refusal !== null) {
            return self::refused($refusal['code'], $refusal['status']);
        }
        $user = (new Users($this->minn->db))->find($target);
        if ($user === null) {
            return self::refused('groddle_character_missing', 404);
        }
        $accounts = $this->minn->accounts();
        $signedIn = $accounts->signInVerified($accounts->signOut(Response::json([])), $user);
        $token = self::sessionToken($signedIn->response->cookies);
        $answer = Response::json([
            'ok' => true,
            'nonce' => $token === '' ? '' : Nonce::create($user->id, $token),
            'player' => Player::describe($user, new Characters($this->minn->db)),
        ])->withHeader('Cache-Control', 'private, no-store');
        foreach ($signedIn->response->cookies as $cookie) {
            $answer = $answer->withCookie(...$cookie);
        }
        return $answer;
    }

    #[Route(Method::Post, '/groddle/v1/characters/{id:\d+}/retire', policy: new Policy(Access::SignedIn))]
    public function retire(Request $request, string $id): Response
    {
        $me = $this->me();
        $target = (int) $id;
        $logins = new Logins($this->minn->db);
        $character = $this->ownCharacter($logins, $me->id, $target);
        if ($character === null) {
            return self::refused('groddle_character_missing', 404);
        }
        if ($character['first']) {
            return self::refused('groddle_character_first', 409);
        }
        if ($target === $me->id) {
            return self::refused('groddle_character_current', 409);
        }
        if ($character['retiredAt'] === null) {
            $logins->retire($target, time());
        }
        return Response::json(['character' => $this->described([$this->ownCharacter($logins, $me->id, $target)])[0]]);
    }

    #[Route(Method::Post, '/groddle/v1/characters/{id:\d+}/restore', policy: new Policy(Access::SignedIn))]
    public function restore(Request $request, string $id): Response
    {
        $me = $this->me();
        $target = (int) $id;
        $db = $this->minn->db;
        $logins = new Logins($db);
        $character = $this->ownCharacter($logins, $me->id, $target);
        if ($character === null) {
            return self::refused('groddle_character_missing', 404);
        }
        if ($character['retiredAt'] === null) {
            return self::refused('groddle_character_active', 409);
        }
        $login = $logins->loginOf($me->id);
        // Within its name hold it still holds its place; after it, it needs a free one.
        if ($logins->counted($login, time(), $target) >= Logins::LIMIT) {
            return self::refused('character_limit', 409);
        }
        $characters = new Characters($db);
        if (!$characters->holdsOwnName($target)) {
            // Its name was let go after the hold and someone may have it: a new one, by the sign-up's rules.
            $name = self::cleanName($request->json()['name'] ?? '');
            if ($name === '') {
                return self::refused('name_needed', 409, ['name' => self::WORDS['name_needed']]);
            }
            $bad = $this->nameRefusal($name, $login, time());
            if ($bad !== null) {
                return $bad;
            }
            if (!$characters->rename($target, $name)) {
                return self::refused('name_taken', 422, ['name' => self::WORDS['name_taken']]);
            }
        }
        $logins->restore($target);
        return Response::json(['character' => $this->described([$this->ownCharacter($logins, $me->id, $target)])[0]]);
    }

    /** The REST nonce's session token from a sign-in's logged_in cookie (login|expiration|token|hmac). @param list<array> $cookies */
    public static function sessionToken(array $cookies): string
    {
        $token = '';
        foreach ($cookies as [$name, $value]) {
            if (str_starts_with((string) $name, 'wordpress_logged_in_')) {
                $parts = explode('|', rawurldecode((string) $value));
                $token = count($parts) === 4 ? $parts[2] : $token;
            }
        }
        return $token;
    }

    /**
     * Characters as the player's list shows them: name, level and when last played from the save.
     *
     * @param list<array{id: int, first: bool, createdAt: ?int, retiredAt: ?int}> $characters
     * @return list<array<string, mixed>>
     */
    private function described(array $characters): array
    {
        $ids = array_map(static fn (array $c): int => $c['id'], $characters);
        if ($ids === []) {
            return [];
        }
        $db = $this->minn->db;
        $names = (new Characters($db))->namesOf($ids);
        $saves = [];
        foreach ($db->rows('SELECT s.user_id, s.updated_at, ' . Profiles::levelSql('s') . ' AS level FROM ' . $db->table(MultiplayerSave::TABLE) . ' s WHERE s.user_id IN (?)', [$ids]) as $r) {
            $saves[(int) $r['user_id']] = $r;
        }
        $out = [];
        foreach ($characters as $c) {
            if (!isset($names[$c['id']])) {
                continue;
            }
            $save = $saves[$c['id']] ?? null;
            $out[] = [
                'id' => $c['id'],
                'name' => $names[$c['id']],
                'level' => max(1, (int) ($save['level'] ?? 1)),
                'lastPlayedAt' => $save === null ? null : SaveShape::time((string) $save['updated_at']),
                'retired' => $c['retiredAt'] !== null,
                'first' => $c['first'],
            ];
        }
        return $out;
    }

    /** One of the caller's login's characters, or null (never another login's). @return array{id: int, first: bool, createdAt: ?int, retiredAt: ?int}|null */
    private function ownCharacter(Logins $logins, int $me, int $target): ?array
    {
        foreach ($logins->charactersOf($logins->loginOf($me)) as $c) {
            if ($c['id'] === $target) {
                return $c;
            }
        }
        return null;
    }

    /** The sign-up's name rules, and a name a waiting sign-up of another email asked for: null when the name will do. */
    private function nameRefusal(string $name, int $login, int $now): ?Response
    {
        if (preg_match(PlayerAccount::NAME, $name) !== 1) {
            return self::refused('groddle_invalid', 422, ['name' => self::NAME_WORDS], [], self::NAME_WORDS);
        }
        if (WordFilter::has($name)) {
            return self::refused('groddle_invalid', 422, ['name' => WordFilter::NAME_REFUSED], [], WordFilter::NAME_REFUSED);
        }
        $email = (string) ($this->minn->db->value('SELECT user_email FROM ' . $this->minn->db->table('users') . ' WHERE ID = ?', [$login]) ?? '');
        if ((new Characters($this->minn->db))->taken($name, $now) || (new Signups($this->minn->db))->nameHeldByOther($name, $email, $now)) {
            return self::refused('name_taken', 422, ['name' => self::WORDS['name_taken']]);
        }
        return null;
    }

    private static function cleanName(mixed $name): string
    {
        return trim(preg_replace('/\s+/', ' ', is_string($name) ? $name : '') ?? '');
    }

    private function me(): \Minn\Content\UserRecord
    {
        return $this->minn->user() ?? throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed> $extra
     */
    private static function refused(string $code, int $status, array $fields = [], array $extra = [], ?string $message = null): Response
    {
        $message ??= self::WORDS[$code] ?? 'That didn\'t work. Try again.';
        return Response::json(['code' => $code, 'message' => $message, 'fields' => (object) $fields] + $extra + ['data' => ['status' => $status]], $status);
    }
}
