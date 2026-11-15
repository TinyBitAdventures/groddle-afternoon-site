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
 *
 * GET /report-pictures/{ticket}/{message}/{n}: a message's nth picture (n
 * from 1; up to four a message since 2026-11-15, TicketStore::MESSAGE_PICTURES),
 * to the same two people, the same way; a message that is not in that ticket
 * is missing. /report-pictures/{ticket}/{message} (G791) is its first.
 */
final readonly class TicketPictures
{
    public const PATH = '/report-pictures/';

    public function __construct(private Seams $minn)
    {
    }

    /** Where a ticket's picture is shown, or with $message a message's ($n from 1; without it G791's address of the first). */
    public static function url(int $ticket, int $message = 0, int $n = 0): string
    {
        return self::PATH . $ticket . ($message > 0 ? '/' . $message . ($n > 0 ? '/' . $n : '') : '');
    }

    #[Route(Method::Get, '/report-pictures/{id:\d+}', policy: new Policy(Access::Public))]
    public function picture(Request $request, string $id): Response
    {
        $user = $this->minn->user();
        if ($user === null) {
            throw new RouteMiss();
        }
        return $this->serve((new TicketStore($this->minn->db))->picture((int) $id), $user->id);
    }

    /** G791: a message's first picture, the same two people only. */
    #[Route(Method::Get, '/report-pictures/{id:\d+}/{message:\d+}', policy: new Policy(Access::Public))]
    public function messagePicture(Request $request, string $id, string $message): Response
    {
        return $this->messagePictureN($request, $id, $message, '1');
    }

    /** A message's nth picture (from 1), the same two people only. */
    #[Route(Method::Get, '/report-pictures/{id:\d+}/{message:\d+}/{n:\d}', policy: new Policy(Access::Public))]
    public function messagePictureN(Request $request, string $id, string $message, string $n): Response
    {
        $user = $this->minn->user();
        if ($user === null) {
            throw new RouteMiss();
        }
        return $this->serve((new TicketStore($this->minn->db))->messagePicture((int) $id, (int) $message, (int) $n), $user->id);
    }

    /** A picture row as image/jpeg to its player or an administrator; anyone else (and no row) gets the 404. */
    private function serve(?array $row, int $userId): Response
    {
        if ($row === null) {
            throw new RouteMiss();
        }
        $mine = (int) $row['player_user_id'] === $userId;
        if (!$mine && !Capabilities::fromDb($this->minn->db)->can($userId, 'manage_options')) {
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
