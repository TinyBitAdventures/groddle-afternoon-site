<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\Http\RouteMiss;

/**
 * Built pages that moved keep their old addresses: the Item catalog went from
 * /items/ into the encyclopedia at /encyclopedia/items/ (Austin 2026-11-02),
 * so /items/, /items/<id>/ and the files under it answer a 301 to the same
 * path at the new place, query kept. Registered before StaticPages, so it
 * answers first. On groddle.localhost every folder address reaches PHP (Caddy
 * serves files, not folder indexes); on Kinsta nginx serves a folder's
 * index.html itself, so this only answers there once the old folder is gone
 * (the game's tools/deploy/kinsta.sh drops it with the webroot link).
 */
final readonly class MovedPages
{
    /** Old first path segment => where its pages are now. */
    public const MOVED = [
        'items' => '/encyclopedia/items',
    ];

    /** /items, /items/ and everything under it. */
    #[Route(Method::Get, '/items{rest:(?:/[A-Za-z0-9_.@-]*)*}', policy: new Policy(Access::Public))]
    public function items(Request $request, string $rest = ''): Response
    {
        return Response::redirect(self::target('items', $rest, $request->query), 301);
    }

    /**
     * The new address of a moved path: the new folder, the rest of the path as
     * it was (a bare /items gets its slash), then the query.
     *
     * @param array<string, mixed> $query
     */
    public static function target(string $from, string $rest, array $query = []): string
    {
        $to = self::MOVED[$from] ?? null;
        if ($to === null || str_contains($rest, '..') || str_contains($rest, '//')) {
            throw new RouteMiss();
        }
        return $to . ($rest === '' ? '/' : $rest) . ($query === [] ? '' : '?' . http_build_query($query));
    }
}
