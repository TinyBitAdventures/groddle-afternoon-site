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
 * /console/, the administrators' console. Six tabs, seven with the level builder on: Players (who is online,
 * the Rook attacks under way with End attack, a message to everyone online, finding a player, and acting on one: message,
 * teleport, give, refill, boot, ban; the bans and the activity log) and Reports (players' bug
 * reports as threads with the picture of the game a report may carry: read,
 * reply or add a note without an email, mark working on it, close, reopen; AdminTickets). #reports
 * opens the Reports tab, #report=<id> one report. Chat log: what players said on streets, in
 * channels and in parties, from the realtime server's log (AdminConsole::chatlog), searched by
 * player, words, kind and days; a player's menu has Chat history, which opens it filtered to them.
 * #chat opens it, #chat=<id> filtered to one player. Credits: the points each report earned for
 * the Contribute page's testers board (AdminReportCredits; a report's own Credit box sets its
 * score), the board as it stands, and the closed and answered reports not scored yet; #credits opens it. Boards
 * (2026-11-04, AdminBoards): the notes players pinned on the shared Notice Boards, reported first,
 * with Remove; #boards opens it. Community (2026-11-04, AdminCommunity): posts on the Community
 * boards, reported first, with Remove and Restore, Pin and Lock on the thread, Stop posting on the
 * writer, and who is stopped; #community opens it. Builder (only when AdminBuilder::enabled, the
 * option groddle_builder_enabled): the level builder's drafts, a new draft, and links into the
 * builder page (AdminBuilderPage, /console/builder/); #builder opens it. The page is served only to a signed-in
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
    public const ASSET_KEY = 'community-2';

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
        $builder = AdminBuilder::enabled($this->minn);
        $v = self::ASSET_KEY;
        $a = self::ASSETS;
        return "<!DOCTYPE html>\n<html lang=\"en-US\">\n<head>\n<meta charset=\"UTF-8\" />\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />\n"
            . "<meta name=\"robots\" content=\"noindex\" />\n"
            . '<title>' . Html::esc('Console') . " &#8211; Groddle Afternoon</title>\n"
            . Extension::head()
            . "<link rel=\"stylesheet\" href=\"{$a}console.css?v={$v}\" />\n"
            . "<script type=\"module\" src=\"{$a}console.js?v={$v}\"></script>\n"
            . ($builder ? "<script type=\"module\" src=\"{$a}builder-keys.js?v={$v}\"></script>\n" : '')
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
  <div class="console-tabs" role="tablist" aria-label="Console" data-console-tabs>
    <button type="button" role="tab" id="console-tab-players" aria-controls="console-players" aria-selected="true" data-console-tab="players">Players</button>
    <button type="button" role="tab" id="console-tab-reports" aria-controls="console-reports" aria-selected="false" tabindex="-1" data-console-tab="reports">Reports <span class="console-count console-count-unread" data-console-reports-unread></span></button>
    <button type="button" role="tab" id="console-tab-chat" aria-controls="console-chat" aria-selected="false" tabindex="-1" data-console-tab="chat">Chat log</button>
    <button type="button" role="tab" id="console-tab-credits" aria-controls="console-credits" aria-selected="false" tabindex="-1" data-console-tab="credits">Credits <span class="console-count" data-console-credits-unscored></span></button>
    <button type="button" role="tab" id="console-tab-boards" aria-controls="console-boards" aria-selected="false" tabindex="-1" data-console-tab="boards">Boards <span class="console-count console-count-unread" data-console-boards-reported></span></button>
    <button type="button" role="tab" id="console-tab-community" aria-controls="console-community" aria-selected="false" tabindex="-1" data-console-tab="community">Community <span class="console-count console-count-unread" data-console-community-reported></span></button>
HTML
            . ($builder ? "\n    <button type=\"button\" role=\"tab\" id=\"console-tab-builder\" aria-controls=\"console-builder\" aria-selected=\"false\" tabindex=\"-1\" data-console-tab=\"builder\">Builder</button>" : '')
            . <<<'HTML'

  </div>
  <div class="console-grid" id="console-players" role="tabpanel" aria-labelledby="console-tab-players" data-console-tabpanel="players">
   <div class="console-col">
    <section class="console-panel console-online" aria-labelledby="console-online-title">
      <div class="console-panel-head">
        <h2 id="console-online-title">Online now <span class="console-count" data-console-count></span></h2>
        <p class="console-hint">Click or right-click a player, or use their menu button, Shift+F10 or the Menu key.</p>
      </div>
      <table class="console-table" data-console-online>
        <thead><tr><th scope="col">Player</th><th scope="col">Street</th><th scope="col">Online for</th><th scope="col"><span class="console-sr">Actions</span></th></tr></thead>
        <tbody></tbody>
      </table>
      <p class="console-empty" data-console-online-empty hidden>Nobody is connected right now.</p>
    </section>
    <section class="console-panel console-rooks" aria-labelledby="console-rooks-title">
      <div class="console-panel-head">
        <h2 id="console-rooks-title">Rook attacks <span class="console-count" data-console-rooks-count></span></h2>
        <p class="console-hint">End attack sends the Rook off as when it leaves after its third wing beat: no iMG for anyone, and what it rooked stays rooked.</p>
      </div>
      <table class="console-table" data-console-rooks hidden>
        <thead><tr><th scope="col">Street</th><th scope="col">Rook</th><th scope="col">Players there</th><th scope="col"><span class="console-sr">Actions</span></th></tr></thead>
        <tbody></tbody>
      </table>
      <p class="console-empty" data-console-rooks-empty>No Rook is over a street right now.</p>
    </section>
    <section class="console-panel console-broadcast" aria-labelledby="console-broadcast-title">
      <div class="console-panel-head"><h2 id="console-broadcast-title">Message everyone</h2></div>
      <form class="console-reply" data-console-broadcast>
        <label for="console-broadcast-text" class="console-sr">Message to everyone online</label>
        <textarea id="console-broadcast-text" name="text" rows="3" maxlength="500" required placeholder="Plain text. Everyone online sees it as &quot;Message from an administrator&quot;."></textarea>
        <div class="console-reply-foot"><span class="console-sub" data-console-broadcast-count role="status" aria-live="polite">Nobody is online to see it.</span><span class="console-sub" data-console-broadcast-len></span><button type="submit" class="console-primary" data-console-broadcast-send disabled>Send</button></div>
      </form>
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
  <div class="console-reports" id="console-reports" role="tabpanel" aria-labelledby="console-tab-reports" data-console-tabpanel="reports" hidden>
    <section class="console-panel console-tickets" aria-labelledby="console-tickets-title">
      <div class="console-panel-head">
        <h2 id="console-tickets-title">Reports <span class="console-count" data-console-tickets-count></span></h2>
        <div class="console-filter" role="group" aria-label="Show" data-console-ticket-filter>
          <button type="button" aria-pressed="true" data-status="all">All</button>
          <button type="button" aria-pressed="false" data-status="open">Open <span class="console-sub" data-console-status-count="open"></span></button>
          <button type="button" aria-pressed="false" data-status="working">Working on it <span class="console-sub" data-console-status-count="working"></span></button>
          <button type="button" aria-pressed="false" data-status="answered">Answered <span class="console-sub" data-console-status-count="answered"></span></button>
          <button type="button" aria-pressed="false" data-status="closed">Closed <span class="console-sub" data-console-status-count="closed"></span></button>
        </div>
      </div>
      <p class="console-legacy" data-console-legacy hidden><span data-console-legacy-text></span> <button type="button" class="console-small" data-console-migrate>Bring in old reports</button></p>
      <ul class="console-list console-ticketlist" data-console-tickets aria-label="Reports"></ul>
      <p class="console-empty" data-console-tickets-empty hidden>No reports here.</p>
    </section>
    <section class="console-panel console-thread" aria-labelledby="console-thread-title" data-console-thread>
      <p class="console-empty" data-console-thread-empty>Choose a report to read it.</p>
      <div data-console-thread-body hidden>
        <div class="console-panel-head console-thread-head">
          <div>
            <h2 id="console-thread-title" tabindex="-1" data-console-thread-subject></h2>
            <p class="console-sub" data-console-thread-meta></p>
          </div>
          <div class="console-thread-acts">
            <span class="console-chip" data-console-thread-status></span>
            <button type="button" class="console-small" data-console-thread-working>Mark working on it</button>
            <button type="button" class="console-small" data-console-thread-unworking hidden>Not working on it</button>
            <button type="button" class="console-small" data-console-thread-close>Close</button>
            <button type="button" class="console-small" data-console-thread-reopen hidden>Reopen</button>
          </div>
        </div>
        <details class="console-context" data-console-context>
          <summary>What the game knew</summary>
          <dl data-console-context-list></dl>
        </details>
        <figure class="console-picture" data-console-picture hidden>
          <a data-console-picture-link target="_blank" rel="noopener" aria-label="Open the picture of the game full size"><img data-console-picture-img alt="The game when the player reported it" /></a>
          <p class="console-sub console-picture-failed">The picture could not be loaded.</p>
          <figcaption class="console-sub console-picture-hint">The game when they reported it. Click it to see it full size.</figcaption>
        </figure>
        <details class="console-credit" data-console-credit>
          <summary><span>Credit</span> <span class="console-sub" data-console-credit-summary>Not scored yet</span></summary>
          <p class="console-sub console-credit-now" data-console-credit-now hidden></p>
          <form class="console-credit-form" data-console-credit-form>
            <label>Kind <select name="kind" data-console-credit-kind>
              <option value="safety_fix">Safety fix (5)</option>
              <option value="bug_fixed">Bug fixed (3)</option>
              <option value="improvement_shipped">Improvement shipped (2)</option>
              <option value="partial_or_clue">Partial fix or clue (1)</option>
              <option value="none">No change (0)</option>
            </select></label>
            <label>Points <input name="points" type="number" min="0" max="8" step="1" required data-console-credit-points /></label>
            <label class="console-credit-wide">Reason <input name="reason" type="text" maxlength="200" required autocomplete="off" placeholder="One plain line: what it led to" /></label>
            <label class="console-credit-wide">Refs <input name="refs" type="text" maxlength="800" autocomplete="off" spellcheck="false" placeholder="game abc1234, game v0.12.0" /></label>
            <div class="console-reply-foot console-credit-wide"><span class="console-sub">A report with several changes: its best kind, plus 1 for each other, up to 8.</span><button type="submit" class="console-small" data-console-credit-save>Save credit</button></div>
          </form>
        </details>
        <ol class="console-list console-messages" data-console-messages aria-label="Messages"></ol>
        <form class="console-reply" data-console-reply>
          <label for="console-reply-text">Reply</label>
          <textarea id="console-reply-text" name="message" rows="4" maxlength="10000" required placeholder="Plain text. The player sees it in the game and gets an email."></textarea>
          <div class="console-reply-foot"><span class="console-sub" data-console-reply-count></span><label class="console-check"><input type="checkbox" name="noemail" data-console-reply-noemail /> Don't email</label><button type="submit" class="console-primary" data-console-reply-send>Send reply</button></div>
        </form>
      </div>
    </section>
  </div>
  <div class="console-chat" id="console-chat" role="tabpanel" aria-labelledby="console-tab-chat" data-console-tabpanel="chat" hidden>
    <section class="console-panel console-chatlog" aria-labelledby="console-chatlog-title">
      <div class="console-panel-head">
        <h2 id="console-chatlog-title">Chat log</h2>
        <p class="console-hint" data-console-chat-kept>What players said on streets, in channels and in parties, refused lines too. Times are in your time zone.</p>
      </div>
      <form class="console-chatsearch" data-console-chat-form role="search" aria-label="Search the chat log">
        <label>Player <input name="player" type="search" maxlength="64" autocomplete="off" spellcheck="false" placeholder="Name or #id" /></label>
        <label>Words <input name="q" type="search" maxlength="200" autocomplete="off" placeholder="Any words" /></label>
        <label>Kind <select name="kind"><option value="">Any</option><option value="local">Street</option><option value="channel">Channel</option><option value="party">Party</option></select></label>
        <label>From <input name="from" type="date" /></label>
        <label>To <input name="to" type="date" /></label>
        <label class="console-check"><input name="refused" type="checkbox" value="1" /> Refused only</label>
        <div class="console-chatsearch-buttons"><button type="submit" class="console-primary">Search</button><button type="button" data-console-chat-clear>Clear</button></div>
      </form>
      <p class="console-sub console-chat-status" data-console-chat-status role="status" aria-live="polite"></p>
      <table class="console-table console-chattable" data-console-chat hidden>
        <thead><tr><th scope="col">When</th><th scope="col">Where</th><th scope="col">Player</th><th scope="col">Said</th></tr></thead>
        <tbody></tbody>
      </table>
      <p class="console-empty" data-console-chat-empty hidden>Nothing in the log matches.</p>
      <button type="button" class="console-more" data-console-chat-more hidden>Older</button>
    </section>
  </div>
  <div class="console-credits" id="console-credits" role="tabpanel" aria-labelledby="console-tab-credits" data-console-tabpanel="credits" hidden>
    <section class="console-panel console-creditlist" aria-labelledby="console-creditlist-title">
      <div class="console-panel-head">
        <h2 id="console-creditlist-title">Scored reports <span class="console-count" data-console-credits-count></span></h2>
        <p class="console-hint">Points a report earned for the Contribute page's testers board. Open a report to change its score.</p>
      </div>
      <table class="console-table console-credittable" data-console-credits hidden>
        <thead><tr><th scope="col">Report</th><th scope="col">Player</th><th scope="col" class="console-num">Points</th><th scope="col">Why</th></tr></thead>
        <tbody></tbody>
      </table>
      <p class="console-empty" data-console-credits-empty hidden>No report has been scored yet.</p>
    </section>
    <div class="console-col">
      <section class="console-panel console-board" aria-labelledby="console-board-title">
        <div class="console-panel-head"><h2 id="console-board-title">Testers board</h2><a class="console-hint" href="/contribute/#thanks">On the Contribute page</a></div>
        <ol class="console-list console-boardlist" data-console-board></ol>
        <p class="console-empty" data-console-board-empty hidden>Nobody has points yet.</p>
      </section>
      <section class="console-panel console-unscored" aria-labelledby="console-unscored-title">
        <div class="console-panel-head"><h2 id="console-unscored-title">Not scored yet <span class="console-count" data-console-unscored-count></span></h2><p class="console-hint">Closed and answered reports.</p></div>
        <ul class="console-list console-unscoredlist" data-console-unscored></ul>
        <p class="console-empty" data-console-unscored-empty hidden>Every closed and answered report is scored.</p>
      </section>
    </div>
  </div>
  <div class="console-boards" id="console-boards" role="tabpanel" aria-labelledby="console-tab-boards" data-console-tabpanel="boards" hidden>
    <section class="console-panel console-boardnotes" aria-labelledby="console-boards-title">
      <div class="console-panel-head">
        <h2 id="console-boards-title">Notice board notes <span class="console-count" data-console-boards-count></span></h2>
        <div class="console-filter" role="group" aria-label="Show" data-console-board-filter>
          <button type="button" aria-pressed="true" data-show="pinned">On the boards</button>
          <button type="button" aria-pressed="false" data-show="reported">Reported</button>
          <button type="button" aria-pressed="false" data-show="all">All</button>
        </div>
      </div>
      <p class="console-hint">What players pinned on the shared Notice Boards, reported ones first. A player's report is also in Reports. Remove takes a note off its board for everyone there; nobody gets it back.</p>
      <table class="console-table console-boardtable" data-console-boards hidden>
        <thead><tr><th scope="col">Board</th><th scope="col">Note</th><th scope="col">Written by</th><th scope="col">Status</th><th scope="col"><span class="console-sr">Actions</span></th></tr></thead>
        <tbody></tbody>
      </table>
      <p class="console-empty" data-console-boards-empty hidden>No notes are pinned on the boards.</p>
    </section>
  </div>
  <div class="console-community" id="console-community" role="tabpanel" aria-labelledby="console-tab-community" data-console-tabpanel="community" hidden>
    <section class="console-panel" aria-labelledby="console-community-title">
      <div class="console-panel-head">
        <h2 id="console-community-title">Community posts</h2>
        <div class="console-filter" role="group" aria-label="Show" data-console-community-filter>
          <button type="button" aria-pressed="true" data-show="reported">Reported</button>
          <button type="button" aria-pressed="false" data-show="recent">Newest</button>
          <button type="button" aria-pressed="false" data-show="removed">Removed</button>
        </div>
      </div>
      <p class="console-hint">Posts on the Community boards (/chat/), reported ones first. A player's report is also in Reports. Remove hides a post's words for everyone (removing a thread's first post takes the thread off its board); Restore brings it back. Pin keeps a thread at the top of its board; Lock stops replies. Stop posting lets a player read but not post, react or edit.</p>
      <table class="console-table console-boardtable" data-console-community hidden>
        <thead><tr><th scope="col">Thread</th><th scope="col">Post</th><th scope="col">Written by</th><th scope="col">Status</th><th scope="col"><span class="console-sr">Actions</span></th></tr></thead>
        <tbody></tbody>
      </table>
      <p class="console-empty" data-console-community-empty hidden>No post has been reported.</p>
    </section>
    <section class="console-panel" aria-labelledby="console-community-stops-title">
      <h2 id="console-community-stops-title">Stopped from posting</h2>
      <ul class="console-list" data-console-community-stops></ul>
      <p class="console-empty" data-console-community-stops-empty>Nobody is stopped from posting.</p>
    </section>
  </div>
HTML
            . ($builder ? self::builderPanel() : '')
            . "\n</main>\n</body>\n</html>\n";
    }

    /** The Builder tab: drafts (console.js fills them from AdminBuilder) and a new draft. */
    private static function builderPanel(): string
    {
        return <<<'HTML'

  <div class="console-builder" id="console-builder" role="tabpanel" aria-labelledby="console-tab-builder" data-console-tabpanel="builder" hidden>
    <section class="console-panel console-drafts" aria-labelledby="console-drafts-title">
      <div class="console-panel-head">
        <h2 id="console-drafts-title">Drafts <span class="console-count" data-console-drafts-count></span></h2>
        <p class="console-hint">Streets you are building. Nothing here reaches players until it is published from a game checkout. <a href="/console/builder/">Open the builder</a> to look at any street.</p>
      </div>
      <ul class="console-list console-draftlist" data-console-drafts aria-label="Drafts"></ul>
      <p class="console-empty" data-console-drafts-empty hidden>No drafts yet. Start one below, then fork a street into it in the builder.</p>
    </section>
    <section class="console-panel console-newdraft" aria-labelledby="console-newdraft-title">
      <div class="console-panel-head"><h2 id="console-newdraft-title">New draft</h2></div>
      <form class="console-reply" data-console-newdraft>
        <label for="console-draft-title">Title</label>
        <input id="console-draft-title" name="title" type="text" maxlength="80" required autocomplete="off" placeholder="Mossy Hollow" />
        <label for="console-draft-id">Id</label>
        <input id="console-draft-id" name="id" type="text" maxlength="48" required autocomplete="off" spellcheck="false" pattern="[a-z][a-z0-9\-]{2,47}" placeholder="mossy-hollow" />
        <div class="console-reply-foot"><span class="console-sub">Lowercase letters, digits and hyphens. It never changes.</span><button type="submit" class="console-primary">Start the draft</button></div>
      </form>
    </section>
  </div>
HTML;
    }
}
