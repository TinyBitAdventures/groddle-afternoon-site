<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;

/**
 * groddle/v1/me: who the game is talking to. Open to anyone, so the game
 * can ask before it knows: signed out (or a cookie sent without its REST
 * nonce) is {"signedIn": false}; signed in names the player and the
 * backup limits.
 */
final readonly class Player
{
    public function __construct(private Seams $minn)
    {
    }

    /** The signed-in player, or signedIn false. */
    #[Route(Method::Get, '/groddle/v1/me', policy: new Policy(Access::Public))]
    public function me(Request $request): Response
    {
        $user = $this->minn->user();
        if ($user === null) {
            return Response::json(['signedIn' => false, 'player' => null]);
        }
        return Response::json([
            'signedIn' => true,
            'player' => ['id' => $user->id, 'name' => $user->displayName !== '' ? $user->displayName : $user->login],
            'backups' => ['slots' => SoloBackups::SLOTS, 'maxBytes' => SaveShape::MAX_BYTES],
        ]);
    }
}
