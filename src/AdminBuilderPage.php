<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Auth\Capabilities;
use Minn\Extension\Seams;
use Minn\Html;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\Http\RouteMiss;

/**
 * /console/builder/, the level builder (the game's docs/level-builder/spec.md 6.1): a gated shell
 * page that loads the editor from the built game, so the editor draws streets with the game's own
 * renderer and never drifts from it. The page carries no data: the editor reads drafts from
 * AdminBuilder's routes with the session's REST nonce, and shipped streets from the game's files.
 *
 * The gate is the console's (AdminConsolePage): signed out it sends you to the sign-in and back,
 * signed in without manage_options it answers the plain 403. With the site option
 * groddle_builder_enabled off (AdminBuilder::enabled) the page does not exist (a 404).
 *
 * In order: <base href="/"> (the game's relative asset paths resolve from the site root, as
 * /play/'s <base href="../"> does), the site's stylesheets and theme script, the builder's
 * stylesheet, Pixi, js/data-versions.js and js/data-urls.js (content keys for data and art), then
 * the editor module. Each game file is keyed by a hash of its own bytes as this build has it; the
 * editor's bytes carry its imports' content keys (tools/module-keys), so its key changes whenever
 * anything it loads does.
 */
final readonly class AdminBuilderPage
{
    public const PATH = '/console/builder/';
    public const EDITOR = 'js/builder/editor.js';
    public const STYLES = 'css/builder.css';
    public const PIXI = 'vendor/pixi/pixi-8.22.0.min.js';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/console/builder', policy: new Policy(Access::Public))]
    public function bare(Request $request): Response
    {
        if (!AdminBuilder::enabled($this->minn)) {
            throw new RouteMiss();
        }
        return Response::redirect(self::PATH, 301);
    }

    #[Route(Method::Get, self::PATH, policy: new Policy(Access::Public))]
    public function page(Request $request): Response
    {
        if (!AdminBuilder::enabled($this->minn)) {
            throw new RouteMiss();
        }
        $user = $this->minn->user();
        if ($user === null) {
            return Response::redirect('/minn-admin/login?redirect_to=' . rawurlencode(self::PATH), 302)->withHeader('Cache-Control', 'no-store');
        }
        if (!Capabilities::fromDb($this->minn->db)->can($user->id, 'manage_options')) {
            return Response::html(AdminConsolePage::refused(), 403)->withHeader('Cache-Control', 'no-store');
        }
        return Response::html($this->minn->frontBar($this->document()))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    /** A game file's key: the first 8 hex of its bytes' sha256, or '' when this build lacks it. */
    public static function key(string $root, string $file): string
    {
        $path = $root . '/' . $file;
        return is_file($path) ? substr((string) hash_file('sha256', $path), 0, 8) : '';
    }

    private function document(): string
    {
        $root = StaticRoot::fromSite($this->minn)->dir;
        $editor = self::key($root, self::EDITOR);
        $url = static fn (string $file): string => '/' . $file . '?v=' . (self::key($root, $file) ?: 'none');
        $head = "<!DOCTYPE html>\n<html lang=\"en-US\">\n<head>\n<meta charset=\"UTF-8\" />\n"
            . "<base href=\"/\" />\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />\n"
            . "<meta name=\"robots\" content=\"noindex\" />\n"
            . '<title>' . Html::esc('Level builder') . " &#8211; Groddle Afternoon</title>\n"
            . Extension::head()
            . '<link rel="stylesheet" href="' . Html::esc($url(self::STYLES)) . "\" />\n";
        if ($editor === '') {
            return $head . "</head>\n<body class=\"notes builder-page\">\n" . SiteNav::nav(SiteNav::links($this->minn, 0), self::PATH)
                . "\n<main class=\"band\"><div class=\"wrap\"><h1>Level builder</h1><p>This build of the game has no level builder yet. Sync a build that has <code>js/builder/editor.js</code>.</p><p><a href=\"/console/\">Back to the console</a></p></div></main>\n</body>\n</html>\n";
        }
        return $head
            . '<script src="' . Html::esc($url(self::PIXI)) . "\"></script>\n"
            . '<script src="' . Html::esc($url('js/data-versions.js')) . "\"></script>\n"
            . '<script src="' . Html::esc($url('js/data-urls.js')) . "\"></script>\n"
            . '<script type="module" src="/' . self::EDITOR . '?v=' . $editor . "\"></script>\n"
            . "</head>\n<body class=\"notes builder-page\">\n"
            . SiteNav::nav(SiteNav::links($this->minn, 0), self::PATH) . "\n"
            . "<main class=\"builder\" id=\"builder\" data-builder aria-label=\"Level builder\">\n"
            . "  <p class=\"builder-loading\" data-builder-loading role=\"status\">Loading the level builder</p>\n"
            . "  <noscript><p>The level builder needs JavaScript.</p></noscript>\n"
            . "</main>\n</body>\n</html>\n";
    }
}
