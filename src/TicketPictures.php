<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Auth\Capabilities;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\Http\RouteMiss;

/**
 * GET /report-pictures/{ticket}: the picture of the game a bug report
 * carries (ReportPicture, TicketStore::PICTURES), as image/jpeg, to two
 * people only: the player whose report it is, and an administrator
 * (manage_options). Everyone else, signed out included, gets the site's 404,
 * as for a report with no picture. It is a page route rather than REST so an
 * <img> can show it with the session cookie (an image request carries no REST
 * nonce); it changes nothing. Never cached by anyone else: private, no-store.
 * The path has no .jpg: Kinsta's nginx answers a missing picture file itself.
 */
final readonly class TicketPictures
{
    public const PATH = '/report-pictures/';

    public function __construct(private Seams $minn)
    {
    }

    /** Where a ticket's picture is shown. */
    public static function url(int $ticket): string
    {
        return self::PATH . $ticket;
    }

    #[Route(Method::Get, '/report-pictures/{id:\d+}', policy: new Policy(Access::Public))]
    public function picture(Request $request, string $id): Response
    {
        $user = $this->minn->user();
        if ($user === null) {
            throw new RouteMiss();
        }
        $row = (new TicketStore($this->minn->db))->picture((int) $id);
        if ($row === null) {
            throw new RouteMiss();
        }
        $mine = (int) $row['player_user_id'] === $user->id;
        if (!$mine && !Capabilities::fromDb($this->minn->db)->can($user->id, 'manage_options')) {
            throw new RouteMiss();
        }
        $bytes = (string) $row['picture'];
        return new Response(200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ], $bytes);
    }
}
