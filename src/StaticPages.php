<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Content\Posts;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\Http\RouteMiss;

/**
 * The built site's folder pages (/, /play/, /restoration/, /compare/...):
 * a static server answers a folder with its index.html, and on Minn the
 * folder path reaches the engine, so this answers it the same way. Files
 * themselves are still served by the web server straight from disk. A
 * published Minn page at a folder's path wins over the built file, so a
 * page moves into Minn by being published there (/restoration/ first).
 * A signed-in editor sees the Minn bar on each page (Seams::frontBar).
 */
final readonly class StaticPages
{
    public function __construct(private Seams $minn, private StaticRoot $root)
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

    /**
     * A folder named without its slash (/play, /play/online): sent on to the
     * slashed path, query kept, as a static server does; anything that is not
     * a built folder falls through.
     */
    #[Route(Method::Get, '/{folder:[a-z0-9][a-z0-9_-]*(?:/[a-z0-9][a-z0-9_-]*)*}', policy: new Policy(Access::Public))]
    public function bare(Request $request, string $folder): Response
    {
        if ($folder === 'changelog' || str_starts_with($folder, 'changelog/') || $this->root->indexFor($folder) === null) {
            throw new RouteMiss();
        }
        if ((new Posts($this->minn->db))->pageByPath(explode('/', $folder)) !== null) {
            throw new RouteMiss();
        }
        $query = $request->query === [] ? '' : '?' . http_build_query($request->query);
        return Response::redirect("/{$folder}/{$query}", 301);
    }

    private function serve(string $folder): Response
    {
        // Notes are Minn content now, drawn by the theme's templates.
        if ($folder === 'changelog' || str_starts_with($folder, 'changelog/')) {
            throw new RouteMiss();
        }
        if ($folder !== '' && (new Posts($this->minn->db))->pageByPath(explode('/', $folder)) !== null) {
            throw new RouteMiss();
        }
        $file = $this->root->indexFor($folder);
        if ($file === null) {
            throw new RouteMiss();
        }
        // A signed-in editor gets the Minn bar, as on any themed page.
        return Response::html($this->minn->frontBar((string) file_get_contents($file)))->withHeader('Cache-Control', 'no-cache');
    }
}
