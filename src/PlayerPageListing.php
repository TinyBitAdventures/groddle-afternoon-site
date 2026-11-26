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
 * GET groddle/v1/players/<id>/page (G1326), a REST route: registered through Seams::rest so it answers
 * under /wp-json/ where the game asks (js/profile-page-verb lookUpPage). It sat in PlayerPages, whose
 * routes are page routes, and answered only at /groddle/v1/... (v0.54.0).
 */
final readonly class PlayerPageListing
{
    public function __construct(private Seams $minn)
    {
    }

    /**
     * G1326 (Austin 2026-11-26): whether a character's page opens for the reader, and its own
     * address, so the game's Open Profile Page row asks this rather than fetching the page, whose
     * 404 for every player below level 5 put an error in the console on each street:
     * {listed: true, url: "/players/<slug>/"} or {listed: false}, always 200 (a missing, retired,
     * suspended or not-yet-public character are told apart by nothing). Public and read-only,
     * under the page's own rule (shown()). Kept by nobody but the reader: the owner's answer
     * differs from everyone else's.
     */
    #[Route(Method::Get, '/groddle/v1/players/{id:[1-9][0-9]{0,18}}/page', policy: new Policy(Access::Public))]
    public function listing(Request $request, string $id): Response
    {
        $n = (int) $id;
        $db = $this->minn->db;
        $slug = Profiles::slugs($db)[$n] ?? null;
        $listed = $slug !== null && PlayerPages::shown($db, $n, $this->minn->reader->userId) !== null;
        return Response::json($listed ? ['listed' => true, 'url' => "/players/{$slug}/"] : ['listed' => false])
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
