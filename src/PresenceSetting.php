<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Content\Users;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * "Appear offline" (Austin 2026-11-04), the account's own setting: hidden
 * players are left out of the who's-around panel and the counts, show
 * offline to their buddies and in IMs, and are in no channel roster
 * (groddle-afternoon-server presence.go); they still chat and post, by name.
 *
 *   GET groddle/v1/account/presence           {"off": false}
 *   PUT groddle/v1/account/presence {"off"}   {"off": true, "live": true}   live: the realtime server took it now
 *
 * Kept in user meta groddle_appear_offline ("1" on). Every realtime ticket
 * carries it (RealtimeTicket "off"), and a change is told to the server at
 * once (its signed presence_hide), so every socket of the player follows it.
 * Signed in only; the user is the caller, never read from the request.
 */
final readonly class PresenceSetting
{
    public const META = 'groddle_appear_offline';

    public function __construct(private Seams $minn)
    {
    }

    /** Whether a player has it on. */
    public static function off(\Minn\Db $db, int $id): bool
    {
        return $id > 0 && (string) ((new Users($db))->meta($id, self::META) ?? '') === '1';
    }

    #[Route(Method::Get, '/groddle/v1/account/presence', policy: new Policy(Access::SignedIn))]
    public function get(Request $request): Response
    {
        return self::private(Response::json(['off' => self::off($this->minn->db, $this->me())]));
    }

    #[Route(Method::Put, '/groddle/v1/account/presence', policy: new Policy(Access::SignedIn))]
    public function put(Request $request): Response
    {
        $me = $this->me();
        $off = $request->json()['off'] ?? null;
        if (!is_bool($off)) {
            throw new RestError('groddle_presence_bad', 'Say on or off.', 400);
        }
        (new Users($this->minn->db))->setMeta($me, self::META, $off ? '1' : '0');
        $answer = (new RealtimeCall($this->minn))->post('presence_hide', ['player' => $me, 'off' => $off]);
        return self::private(Response::json(['off' => $off, 'live' => in_array($answer['status'] ?? null, ['ok', 'offline'], true)]));
    }

    private function me(): int
    {
        $user = $this->minn->user();
        if ($user === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        return $user->id;
    }

    private static function private(Response $r): Response
    {
        return $r->withHeader('Cache-Control', 'private, no-store');
    }
}
