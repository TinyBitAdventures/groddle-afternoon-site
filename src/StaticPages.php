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
 * The built site's folder pages (/, /play/, /restoration/, /changelog/...):
 * a static server answers a folder with its index.html, and on Minn the
 * folder path reaches the engine, so this answers it the same way. Files
 * themselves are still served by the web server straight from disk.
 */
final readonly class StaticPages
{
    public function __construct(private StaticRoot $root)
    {
    }

    /** The home page: the built site's own index.html. */
    #[Route(Method::Get, '/', policy: new Policy(Access::Public))]
    public function home(Request $request): Response
    {
        return $this->serve('');
    }

    /** A folder's index.html; any other path falls through to the engine's front. */
    #[Route(Method::Get, '/{folder:[a-z0-9][a-z0-9_-]*(?:/[a-z0-9][a-z0-9_-]*)*}/', policy: new Policy(Access::Public))]
    public function folder(Request $request, string $folder): Response
    {
        return $this->serve($folder);
    }

    private function serve(string $folder): Response
    {
        $file = $this->root->indexFor($folder);
        if ($file === null) {
            throw new RouteMiss();
        }
        return Response::html((string) file_get_contents($file))->withHeader('Cache-Control', 'no-cache');
    }
}
