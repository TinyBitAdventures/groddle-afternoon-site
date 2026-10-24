<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Extension as MinnExtension;
use Minn\Extension\Seams;

/**
 * Groddle Afternoon on Minn: the game and its site, served by the engine
 * with no WordPress API. The built game (the files groddle.localhost
 * serves) sits beside the engine in the webroot; this extension answers
 * the pages a static server answered by itself.
 */
final class Extension implements MinnExtension
{
    public function register(Seams $minn): void
    {
        $minn->routes(new StaticPages(StaticRoot::fromSite($minn)));
    }
}
