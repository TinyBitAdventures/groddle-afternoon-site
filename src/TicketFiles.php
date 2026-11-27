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
 * GET /report-files/{ticket}/{message}/{n}: a text file a player added to a
 * bug report or a reply on the website (ReportFile, TicketStore::FILES), to
 * two people only, as TicketPictures serves pictures: the player whose report
 * it is and an administrator (manage_options). Everyone else, signed out
 * included, gets the site's 404, as for a file that is not there.
 *
 * Always text/plain (utf-8), a download (Content-Disposition: attachment with
 * the kept, plain name), never sniffed (X-Content-Type-Options: nosniff), in a
 * sandbox with nothing allowed, and never cached by anyone else. A page route
 * rather than REST so a plain link opens it with the session cookie.
 */
final readonly class TicketFiles
{
    public const PATH = '/report-files/';

    public function __construct(private Seams $minn)
    {
    }

    /** Where a message's nth text file (from 1) is served. */
    public static function url(int $ticket, int $message, int $n): string
    {
        return self::PATH . $ticket . '/' . $message . '/' . $n;
    }

    #[Route(Method::Get, '/report-files/{id:\d+}/{message:\d+}/{n:\d}', policy: new Policy(Access::Public))]
    public function file(Request $request, string $id, string $message, string $n): Response
    {
        $user = $this->minn->user();
        if ($user === null) {
            throw new RouteMiss();
        }
        $row = (new TicketStore($this->minn->db))->messageFile((int) $id, (int) $message, (int) $n);
        if ($row === null) {
            throw new RouteMiss();
        }
        $mine = (int) $row['player_user_id'] === $user->id;
        if (!$mine && !Capabilities::fromDb($this->minn->db)->can($user->id, 'manage_options')) {
            throw new RouteMiss();
        }
        $text = (string) $row['body'];
        return new Response(200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Length' => (string) strlen($text),
            'Content-Disposition' => 'attachment; filename="' . ReportFile::safeName((string) $row['name']) . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ], $text);
    }
}
