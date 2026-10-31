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
 * GET /builder-art/{sha256}[?size=3]: the level builder's new art (BuilderAssets), the file asset
 * intake wrote, for the editor to draw (the game's js/builder/new-art.js routes gz_ deco URLs here).
 * A page route rather than REST, so an image request with the session cookie can load it (it
 * carries no REST nonce); it changes nothing.
 *
 * Administrators only (manage_options), and only while groddle_builder_enabled is on; anyone else,
 * and every miss, gets the site's 404. Spec 13: the bytes come from the database, never a served
 * folder; only a WebP that intake wrote is served, and only as image/webp, with nosniff, a CSP of
 * default-src 'none' (and sandbox), and private, no-store. The path has no file extension, so a
 * web server never answers it from disk.
 */
final readonly class BuilderArt
{
    public const PATH = '/builder-art/';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/builder-art/{sha:[0-9a-f]{64}}', policy: new Policy(Access::Public))]
    public function art(Request $request, string $sha): Response
    {
        if (!AdminBuilder::enabled($this->minn)) {
            throw new RouteMiss();
        }
        $user = $this->minn->user();
        if ($user === null || !Capabilities::fromDb($this->minn->db)->can($user->id, 'manage_options')) {
            throw new RouteMiss();
        }
        $bytes = (new BuilderAssets($this->minn->db))->file($sha, $request->query('size') === '3');
        // Only a WebP intake wrote is ever served, and only as one.
        if ($bytes === null || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            throw new RouteMiss();
        }
        return new Response(200, self::headers(strlen($bytes)), $bytes);
    }

    /** @return array<string, string> */
    public static function headers(int $length): array
    {
        return [
            'Content-Type' => 'image/webp',
            'Content-Length' => (string) $length,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ];
    }
}
