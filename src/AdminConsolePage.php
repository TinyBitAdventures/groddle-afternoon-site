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

/**
 * /console/, the administrators' console: who is online, finding a player,
 * and acting on one (message, teleport, give, refill, boot, ban), with the
 * bans and the activity log. The page is served only to a signed-in
 * administrator (manage_options): signed out it sends you to the Minn
 * sign-in and back; signed in without the capability it answers a plain 403.
 * The page itself carries no data: console/console.js reads everything from
 * groddle/v1/admin/* (AdminConsole, manage_options again) with the session's
 * REST nonce, so a copy of the HTML shows nothing.
 *
 * Judged here rather than by a route Policy: the front's gate answers a
 * refused policy as JSON, and a page wants a redirect and a page.
 */
final readonly class AdminConsolePage
{
    public const PATH = '/console/';

    /** console/ in the theme folder (the theme is the site repo). */
    private const ASSETS = '/wp-content/themes/groddle/console/';

    /** Bumped when console.js or console.css changes. */
    public const ASSET_KEY = 'g407-1';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/console', policy: new Policy(Access::Public))]
    public function bare(Request $request): Response
    {
        return Response::redirect(self::PATH, 301);
    }

    #[Route(Method::Get, self::PATH, policy: new Policy(Access::Public))]
    public function page(Request $request): Response
    {
        $user = $this->minn->user();
        if ($user === null) {
            return Response::redirect('/minn-admin/login?redirect_to=' . rawurlencode(self::PATH), 302)->withHeader('Cache-Control', 'no-store');
        }
        if (!Capabilities::fromDb($this->minn->db)->can($user->id, 'manage_options')) {
            return Response::html(self::refused(), 403)->withHeader('Cache-Control', 'no-store');
        }
        return Response::html($this->minn->frontBar($this->document()))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    /** The plain refusal: no header menu, nothing about the console. */
    public static function refused(): string
    {
        return "<!DOCTYPE html>\n<html lang=\"en-US\">\n<head>\n<meta charset=\"UTF-8\" />\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />\n"
            . "<meta name=\"robots\" content=\"noindex\" />\n<title>Not allowed &#8211; Groddle Afternoon</title>\n" . Extension::head() . "</head>\n"
            . "<body class=\"notes\">\n<main class=\"band\"><div class=\"wrap\"><h1>Not allowed</h1><p>This page is for the site's administrators.</p><p><a href=\"/\">Back to Groddle Afternoon</a></p></div></main>\n</body>\n</html>\n";
    }

    private function document(): string
    {
        $path = $this->minn->request->path;
        $v = self::ASSET_KEY;
        $a = self::ASSETS;
        return "<!DOCTYPE html>\n<html lang=\"en-US\">\n<head>\n<meta charset=\"UTF-8\" />\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />\n"
            . "<meta name=\"robots\" content=\"noindex\" />\n"
            . '<title>' . Html::esc('Console') . " &#8211; Groddle Afternoon</title>\n"
            . Extension::head()
            . "<link rel=\"stylesheet\" href=\"{$a}console.css?v={$v}\" />\n"
            . "<script type=\"module\" src=\"{$a}console.js?v={$v}\"></script>\n"
            . "</head>\n<body class=\"notes console-page\">\n"
            . SiteNav::nav(SiteNav::links($this->minn, 0), $path) . "\n"
            . <<<'HTML'
<main class="console" id="console" data-console>
  <header class="console-head">
    <div>
      <p class="kicker">Administrators</p>
      <h1>Console</h1>
    </div>
    <p class="console-server" data-console-server role="status" aria-live="polite">Connecting</p>
  </header>
  <p class="console-note" data-console-note role="status" aria-live="polite" hidden></p>
  <div class="console-grid">
   <div class="console-col">
    <section class="console-panel console-online" aria-labelledby="console-online-title">
      <div class="console-panel-head">
        <h2 id="console-online-title">Online now <span class="console-count" data-console-count></span></h2>
        <p class="console-hint">Right-click a player, or use their menu button, Shift+F10 or the Menu key.</p>
      </div>
      <table class="console-table" data-console-online>
        <thead><tr><th scope="col">Player</th><th scope="col">Street</th><th scope="col">Online for</th><th scope="col"><span class="console-sr">Actions</span></th></tr></thead>
        <tbody></tbody>
      </table>
      <p class="console-empty" data-console-online-empty hidden>Nobody is connected right now.</p>
    </section>
    <section class="console-panel console-log" aria-labelledby="console-log-title">
      <div class="console-panel-head"><h2 id="console-log-title">Activity</h2></div>
      <ol class="console-list console-loglist" data-console-log></ol>
      <p class="console-empty" data-console-log-empty hidden>No administrator has acted yet.</p>
      <button type="button" class="console-more" data-console-log-more hidden>Older</button>
    </section>
   </div>
   <div class="console-col">
    <section class="console-panel console-find" aria-labelledby="console-find-title">
      <div class="console-panel-head"><h2 id="console-find-title">Find a player</h2></div>
      <form class="console-search" data-console-search role="search">
        <label for="console-q" class="console-sr">Character name</label>
        <input id="console-q" name="q" type="search" autocomplete="off" spellcheck="false" placeholder="Character name" maxlength="32" />
        <button type="submit">Find</button>
      </form>
      <table class="console-table" data-console-found hidden>
        <thead><tr><th scope="col">Player</th><th scope="col">Last seen on</th><th scope="col">Saved</th><th scope="col"><span class="console-sr">Actions</span></th></tr></thead>
        <tbody></tbody>
      </table>
      <p class="console-empty" data-console-found-empty hidden>No character goes by that name.</p>
    </section>
    <section class="console-panel console-bans" aria-labelledby="console-bans-title">
      <div class="console-panel-head"><h2 id="console-bans-title">Bans <span class="console-count" data-console-bans-count></span></h2></div>
      <ul class="console-list" data-console-bans></ul>
      <p class="console-empty" data-console-bans-empty hidden>Nobody is banned.</p>
    </section>
   </div>
  </div>
</main>
HTML
            . "\n</body>\n</html>\n";
    }
}
