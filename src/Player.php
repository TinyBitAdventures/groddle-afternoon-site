<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Auth\Capabilities;
use Minn\Content\UserRecord;
use Minn\Db;
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
 * backup limits, and whether the account is suspended.
 *
 * "admin" (register G567, Austin 2026-11-07): whether the account holds
 * manage_options, the capability every administrators' route checks. The
 * game draws Delete line on a channel line for an administrator only; it is
 * a hint for the interface and nothing more: the delete itself goes to
 * ChatHistory::deleteLine, whose route checks the capability again, so a
 * player who sets the flag in their own browser is still refused (403).
 */
final readonly class Player
{
    /** The administrators' capability (AdminConsole, ChatHistory::deleteLine). */
    public const ADMIN = 'manage_options';

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
            'player' => self::describe($user, new Characters($this->minn->db)),
            'backups' => ['slots' => SoloBackups::SLOTS, 'maxBytes' => SaveShape::MAX_BYTES],
            // The caller's own sign-in email, for the Account panel (nobody else's is ever sent): the login's (Logins), which an extra character signs in with.
            'account' => ['email' => self::loginEmail($this->minn->db, $user)],
            // A suspended account (AdminConsole bans): /play/online/ says so and does not load the game.
            'suspended' => AdminConsole::banned($this->minn->db, $user->id) !== null,
            // An administrator: the game shows Delete line on channel lines (the route checks again).
            'admin' => self::isAdmin($this->minn->db, $user->id),
        ]);
    }

    /** The email a character signs in with: its login's (Logins), its own for a first character. */
    public static function loginEmail(Db $db, UserRecord $user): string
    {
        $login = (new Logins($db))->loginOf($user->id);
        if ($login === $user->id) {
            return $user->email;
        }
        return (string) ($db->value('SELECT user_email FROM ' . $db->table('users') . ' WHERE ID = ?', [$login]) ?? '');
    }

    /** Whether an account is an administrator (manage_options); false for nobody (0). */
    public static function isAdmin(Db $db, int $id): bool
    {
        return $id > 0 && Capabilities::fromDb($db)->can($id, self::ADMIN);
    }

    /** The player as the game shows them: the character's name (Characters), never the username underneath. */
    public static function describe(UserRecord $user, Characters $characters): array
    {
        return ['id' => $user->id, 'name' => $characters->nameOf($user)];
    }
}
