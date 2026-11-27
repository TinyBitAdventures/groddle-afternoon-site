<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Content\UserRecord;
use Minn\Extension\Seams;
use Minn\Html;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\Http\RouteMiss;
use Minn\RestError;

/**
 * Support (Austin 2026-11-27: "power users would like to be able to manage
 * their bug reports outside of the game ... bolted into the community section
 * as a new support channel but with more abilities like photo/file uploads
 * and filter by status"): a signed-in player's own bug reports in the
 * Community app, drawn whole on the server like the boards (CommunityPages'
 * frame and rail), so they read and work without JavaScript:
 *
 *   /chat/support/                 your reports, the newest activity first, by status (?status=, the game's
 *                                  Reports tabs: All, Open, Answered, Working on it, Closed), with a count on
 *                                  each and pages of PER_PAGE (?page=); a New reply mark on one the team
 *                                  answered since you last looked
 *   /chat/support/new/             a new report: a subject, your words, pictures and text files
 *   /chat/support/<id>/            one report: the whole conversation, the pictures (thumbnails that open full
 *                                  size), the text files, what the game sent with it, a reply box with
 *                                  uploads, Close and Reopen; opening it reads it (as the game's)
 *   POST <id>/reply/, <id>/close/, <id>/reopen/, new/   the forms (multipart), back to the report (303)
 *
 * The same data and rules as the game's Reports list: every write goes
 * through Tickets (openFor, replyFor, closeFor, reopenFor), so the limits,
 * the owner check, the picture checks and closedBy are the game's own, a reply
 * here is in the game's list and the console's Reports tab at once, and the
 * other way round. Forms carry the page's REST nonce (CommunityPages::
 * nonceRefusal), as the boards' do. Another player's report, and a report
 * that is not there, are the site's 404 (never 403): CommunityPages leaves
 * /chat/support/<id>/ alone.
 *
 * Uploads (one file field, several files): pictures by their name's ending
 * (.jpg, .jpeg, .png, .webp), each read from its bytes and drawn again as a
 * JPEG (ReportPicture::acceptUpload), up to ReportPicture::MAX_PER_MESSAGE a
 * message; text files (.txt, .log) as text (ReportFile), up to
 * ReportFile::MAX_PER_MESSAGE. Anything else is refused, and a refused file
 * keeps the whole message back, its words still in the box.
 *
 * A new report from here says so in its context (from: website, with the
 * browser line), so the console shows where it came from. It is shown in the
 * Community pages' look (community/community.css, the site's tokens for
 * both themes).
 */
final readonly class SupportPages
{
    /** The address under /chat/ (never a board's slug: CommunityText::BOARDS). */
    public const SLUG = 'support';
    /** What CommunityPages' rail marks on these pages. */
    public const ACTIVE = 'support';
    public const BASE = '/chat/support/';
    /** Reports a page of the list shows. */
    public const PER_PAGE = 20;

    /** The status filters, in the game's Reports tabs' order and words (js/reports.js REPORT_TABS, STATUS_WORDS). */
    public const FILTERS = ['all' => 'All', TicketStore::OPEN => 'Open', TicketStore::ANSWERED => 'Answered', TicketStore::WORKING => 'Working on it', TicketStore::CLOSED => 'Closed'];
    /** What each status means, for a hover and screen readers. */
    public const STATUS_HINTS = [TicketStore::OPEN => 'Waiting for the team', TicketStore::ANSWERED => 'The team replied', TicketStore::WORKING => 'The team is working on it', TicketStore::CLOSED => 'Closed'];

    /** Endings a picture may have (its bytes still decide: ReportPicture::uploadKind). */
    public const PICTURE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    /** The file field's accept list. */
    public const ACCEPT = '.jpg,.jpeg,.png,.webp,.txt,.log,image/jpeg,image/png,image/webp,text/plain';

    public const COPY = [
        'lead' => 'Bug reports, ideas and questions you sent us, and our replies. Only you and the team can see them.',
        'signedOut' => 'Sign in to see your bug reports and our replies.',
        'none' => 'You have not sent us a report yet. Start one here, or with the bug button in the game.',
        'noneHere' => 'None of your reports have this status right now.',
        'rail' => 'Your reports and our replies',
        'drop' => 'Drop pictures or a text file here, or',
        'dropChoose' => 'choose files',
        'dropTap' => 'Tap to add pictures or a text file',
        'uploads' => 'Up to 4 pictures (JPEG, PNG or WebP) and 2 text files (.txt or .log, up to 256 KB) a message. Only you and the team can see them.',
        'closed' => 'This report is closed. A reply opens it again, or open it again without one.',
        'closeHint' => 'Close it when the problem is gone. You can open it again.',
        'tooBig' => 'Those files are too large to send at once. Send fewer or smaller ones.',
        'again' => 'Add your files again before you send.',
        'sent' => 'Thanks! Your report was sent.',
        'replied' => 'Reply sent.',
        'closedDone' => 'Report closed.',
        'reopened' => 'Report open again.',
    ];

    public function __construct(private Seams $minn)
    {
    }

    // The list

    #[Route(Method::Get, '/chat/support', policy: new Policy(Access::Public))]
    public function bare(Request $request): Response
    {
        return Response::redirect(self::BASE . self::query($request), 301);
    }

    #[Route(Method::Get, '/chat/support/', policy: new Policy(Access::Public))]
    public function index(Request $request): Response
    {
        $community = new Community($this->minn);
        $viewer = $community->viewer();
        if ($viewer['id'] <= 0) {
            return $this->signedOut($community, $viewer, 'Support');
        }
        $status = self::status($request->query('status'));
        $store = new TicketStore($this->minn->db);
        $counts = $store->countsFor((int) $viewer['id']);
        $total = $counts[$status] ?? 0;
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($request->query('page') ?? '1')));
        $list = $store->pageFor((int) $viewer['id'], $status === 'all' ? null : $status, $page, self::PER_PAGE);
        $now = time();
        $rows = '';
        foreach ($list['rows'] as $r) {
            $rows .= "\n      " . self::rowHtml($r, $now);
        }
        $link = static fn (string $s, int $n = 1): string => self::BASE . self::listQuery($s, $n);
        $filters = '';
        foreach (self::FILTERS as $key => $label) {
            $filters .= '<a class="cx-chip csupport-filter" href="' . Html::attr($link($key)) . '"' . ($key === $status ? ' aria-current="page"' : '') . '>'
                . Html::esc($label) . ' <span class="csupport-n">' . number_format($counts[$key] ?? 0) . '</span></a>';
        }
        $empty = $counts['all'] === 0 ? self::COPY['none'] : self::COPY['noneHere'];
        $note = CommunityText::count($counts['all'], 'report', 'reports') . ($counts['unread'] > 0 ? ' · ' . CommunityText::count($counts['unread'], 'new reply', 'new replies') : '');
        $main = '<section class="cx-conv csupport" aria-labelledby="cx-conv-title" data-support-list="">'
            . CommunityPages::head('Support', $note, 'cx-conv-title', '', true, self::COPY['lead'])
            . '<div class="cx-log cx-list-log">'
            . '<nav class="csupport-filters" aria-label="Your reports by status">' . $filters . '</nav>'
            . self::said($request)
            . ($rows === '' ? '<p class="cempty cx-empty">' . Html::esc($empty) . '</p>' : "<ul class=\"cx-list csupport-list\">{$rows}\n    </ul>")
            . CommunityPages::pager($page, $pages, static fn (int $n): string => $link($status, $n))
            . '</div><div class="cx-compose cx-compose-bar"><a class="cx-chip cbutton" href="' . self::BASE . 'new/">New report</a></div></section>';
        $title = 'Support – Community' . ($status !== 'all' ? ' – ' . self::FILTERS[$status] : '') . ($page > 1 ? " – page {$page}" : '');
        return $this->page($title, $community, $viewer, $main);
    }

    /** One report as a row of the list: its subject, number, last activity, status, and New reply when the team answered since. */
    public static function rowHtml(array $r, int $now): string
    {
        $id = (int) $r['id'];
        $status = (string) $r['status'];
        $unread = TicketStore::unreadFor($r, TicketStore::PLAYER);
        $last = (string) $r['last_author_role'] === TicketStore::ADMIN ? 'The team replied' : 'You wrote last';
        return '<li><a class="cx-row csupport-row' . ($unread ? ' is-unread' : '') . '" href="' . Html::attr(self::BASE . $id . '/') . '" data-ticket="' . $id . '">'
            . '<span class="cx-txt"><span class="cx-name"><span class="cx-label">' . Html::esc((string) $r['subject'] !== '' ? (string) $r['subject'] : '(no words)') . '</span>'
            . ($unread ? '<span class="ctag ctag-new" data-unread="">New reply</span>' : '') . '</span>'
            . '<span class="cx-last">#' . $id . ' · ' . Html::esc($last) . '</span></span>'
            . '<span class="cx-meta">' . self::statusTag($status) . CommunityPages::time(Community::unix((string) $r['last_message_at']), $now) . '</span></a></li>';
    }

    /** A status as the list and a report show it: the game's words, its meaning on hover. */
    public static function statusTag(string $status): string
    {
        $label = self::FILTERS[$status] ?? $status;
        return '<span class="ctag csupport-status" data-status="' . Html::attr($status) . '" title="' . Html::attr(self::STATUS_HINTS[$status] ?? $label) . '">' . Html::esc($label) . '</span>';
    }

    /** The rail's Support entry (CommunityPages::shell, signed-in players): the list, with how many reports have a reply not read yet. */
    public static function railSection(int $unread, bool $current): string
    {
        return '<section class="cx-sec" aria-labelledby="cx-sec-support"><h2 class="cx-sec-title" id="cx-sec-support">Support</h2><ul class="cx-list">'
            . '<li><a class="cx-row" href="' . self::BASE . '"' . ($current ? ' aria-current="page"' : '') . '>'
            . '<span class="cx-av cx-av-lg" data-hue="' . CommunityPages::hue('Support') . '" aria-hidden="true">?</span>'
            . '<span class="cx-txt"><span class="cx-name"><span class="cx-label">Your reports</span></span><span class="cx-last">' . Html::esc(self::COPY['rail']) . '</span></span>'
            . ($unread > 0 ? '<span class="cx-badge" title="' . Html::attr(CommunityText::count($unread, 'new reply', 'new replies')) . '">' . $unread . '</span>' : '')
            . '</a></li></ul></section>';
    }

    // A new report

    #[Route(Method::Get, '/chat/support/new/', policy: new Policy(Access::Public))]
    public function newReport(Request $request): Response
    {
        return $this->newPage([], '', 200);
    }

    #[Route(Method::Post, '/chat/support/new/', policy: new Policy(Access::Public))]
    public function create(Request $request): Response
    {
        $form = $request->json();
        try {
            $me = $this->writer($request, $form);
            $subject = BugReports::clean(is_string($form['subject'] ?? null) ? $form['subject'] : '', 200, false);
            $words = is_string($form['message'] ?? null) ? $form['message'] : '';
            $message = trim($words) === '' ? '' : ($subject === '' ? $words : $subject . "\n\n" . $words);
            // Marked as sent from the website (the game's context never has "from"; the console names it Sent from).
            $context = ['from' => 'website'] + BugReports::context(['page' => self::BASE . 'new/', 'agent' => (string) ($request->header('user-agent') ?? '')]);
            [$pictures, $files] = $this->uploads($request);
            $id = (new Tickets($this->minn))->openFor($me, $message, $context, $pictures, $files);
            return Response::redirect(self::BASE . $id . '/?said=sent', 303);
        } catch (RestError $e) {
            return $this->newPage($form, self::refusal($e, $request), $e->status);
        }
    }

    /** @param array<string, mixed> $form */
    private function newPage(array $form, string $error, int $status): Response
    {
        $community = new Community($this->minn);
        $viewer = $community->viewer();
        if ($viewer['id'] <= 0) {
            return $this->signedOut($community, $viewer, 'New report');
        }
        $subject = is_string($form['subject'] ?? null) ? $form['subject'] : '';
        $words = is_string($form['message'] ?? null) ? $form['message'] : '';
        $box = '    <form class="cform csupport-form" method="post" action="' . self::BASE . 'new/" enctype="multipart/form-data" data-support-new="">' . "\n"
            . self::nonceField($viewer)
            . '      <label class="csupport-label" for="csupport-subject">Subject</label>' . "\n"
            . '      <input class="cinput csupport-subject" id="csupport-subject" name="subject" type="text" required maxlength="' . TicketStore::SUBJECT_MAX . '" autocomplete="off" placeholder="In a few words, what is wrong?" value="' . Html::attr($subject) . "\" />\n"
            . '      <label class="csupport-label" for="csupport-message">What happened?</label>' . "\n"
            . '      <textarea class="ctext cinput csupport-text" id="csupport-message" name="message" rows="6" required maxlength="' . (BugReports::MAX_MESSAGE - TicketStore::SUBJECT_MAX - 2) . '" placeholder="Where you were, what you did, and what you expected to happen.">' . Html::esc($words) . "</textarea>\n"
            . self::uploadRow('Send report')
            . self::saidLine($error)
            . "    </form>\n";
        $main = '<section class="cx-conv csupport csupport-new" aria-labelledby="cx-conv-title">'
            . CommunityPages::head('New report', 'Support', 'cx-conv-title', '', true, 'Tell us about a bug, an idea or a question. We read every one and reply here and in the game.', self::BASE)
            . '<div class="cx-log csupport-new-log">' . $box . '</div></section>';
        return $this->page('New report – Support', $community, $viewer, $main, $status);
    }

    // One report

    #[Route(Method::Get, '/chat/support/{id:\d+}', policy: new Policy(Access::Public))]
    public function bareReport(Request $request, string $id): Response
    {
        return Response::redirect(self::BASE . $id . '/' . self::query($request), 301);
    }

    #[Route(Method::Get, '/chat/support/{id:\d+}/', policy: new Policy(Access::Public))]
    public function report(Request $request, string $id): Response
    {
        return $this->reportPage($request, (int) $id, [], '', 200);
    }

    #[Route(Method::Post, '/chat/support/{id:\d+}/reply/', policy: new Policy(Access::Public))]
    public function reply(Request $request, string $id): Response
    {
        $form = $request->json();
        try {
            $me = $this->writer($request, $form);
            // Theirs first: another player's report is missing before anything is read.
            $this->mine($me, (int) $id);
            [$pictures, $files] = $this->uploads($request);
            $ticket = (new Tickets($this->minn))->replyFor($me, (int) $id, $form['message'] ?? null, $pictures, $files);
            return Response::redirect(self::BASE . $ticket . '/?said=replied#latest', 303);
        } catch (RestError $e) {
            if ($e->status === 404) {
                throw new RouteMiss();
            }
            return $this->reportPage($request, (int) $id, $form, self::refusal($e, $request), $e->status);
        }
    }

    #[Route(Method::Post, '/chat/support/{id:\d+}/close/', policy: new Policy(Access::Public))]
    public function close(Request $request, string $id): Response
    {
        return $this->act($request, (int) $id, 'close');
    }

    #[Route(Method::Post, '/chat/support/{id:\d+}/reopen/', policy: new Policy(Access::Public))]
    public function reopen(Request $request, string $id): Response
    {
        return $this->act($request, (int) $id, 'reopen');
    }

    private function act(Request $request, int $id, string $what): Response
    {
        $form = $request->json();
        try {
            $me = $this->writer($request, $form);
            $tickets = new Tickets($this->minn);
            $ticket = $what === 'close' ? $tickets->closeFor($me, $id) : $tickets->reopenFor($me, $id);
            return Response::redirect(self::BASE . $ticket . '/?said=' . ($what === 'close' ? 'closed' : 'reopened'), 303);
        } catch (RestError $e) {
            if ($e->status === 404) {
                throw new RouteMiss();
            }
            return $this->reportPage($request, $id, [], self::refusal($e, $request), $e->status);
        }
    }

    /** @param array<string, mixed> $form what the reply box held, when it is drawn again */
    private function reportPage(Request $request, int $id, array $form, string $error, int $status): Response
    {
        $community = new Community($this->minn);
        $viewer = $community->viewer();
        if ($viewer['id'] <= 0) {
            return $this->signedOut($community, $viewer, 'Support');
        }
        $store = new TicketStore($this->minn->db);
        $row = $store->mine((int) $viewer['id'], $id);
        if ($row === null) {
            throw new RouteMiss();
        }
        $store->markRead($id, TicketStore::PLAYER, time());
        $row = (array) $store->find($id);
        $now = time();
        $messages = TicketStore::messagesOut($store->messages($id), null, $store->messagePictures($id), $store->messageFiles($id));
        $own = $store->pictureOut($id);
        $items = '';
        $count = count($messages);
        foreach ($messages as $i => $m) {
            // The report's own picture (the game's view when it was sent) goes with its first message.
            if ($i === 0 && $own !== null) {
                array_unshift($m['pictures'], $own);
            }
            $items .= "\n      " . self::messageHtml($m, (string) $viewer['name'], $now, $i === $count - 1);
        }
        $closedBy = TicketStore::closedBy($row);
        $meta = 'Report #' . $id . ' · sent ' . CommunityPages::time(Community::unix((string) $row['created_at']), $now) . ' · ' . self::statusTag((string) $row['status'])
            . (TicketStore::readByTeamAt($row) !== null ? ' · <span class="ctag csupport-read" title="The team has opened it since your last message">Read by the team</span>' : '')
            . ($closedBy === TicketStore::PLAYER ? ' · Closed by you' : ($closedBy === TicketStore::ADMIN ? ' · Closed by the team' : ''));
        $main = '<section class="cx-conv cx-topic csupport csupport-report" aria-labelledby="cx-conv-title" data-support-report="' . $id . '" data-status="' . Html::attr((string) $row['status']) . '">'
            . CommunityPages::head((string) $row['subject'] !== '' ? (string) $row['subject'] : '(no words)', 'Support', 'cx-conv-title', '', true, '', self::BASE, '<p class="cmeta">' . $meta . '</p>')
            . '<div class="cx-log" data-cx-log>'
            . self::said($request)
            . self::contextHtml(TicketStore::contextOf($row))
            . "<ol class=\"cposts csupport-messages\">{$items}\n    </ol>"
            . '</div><div class="cx-compose">'
            . $this->composeHtml($row, $viewer, is_string($form['message'] ?? null) ? $form['message'] : '', $error)
            . '</div></section>';
        return $this->page('#' . $id . ' ' . (string) $row['subject'] . ' – Support', $community, $viewer, $main, $status);
    }

    /**
     * One message: who wrote it (You, or Administrator: a role, never a name), when, its words
     * (escaped, links by the chat's rule), its pictures as thumbnails that open full size, its files.
     *
     * @param array<string, mixed> $m TicketStore::messagesOut
     */
    public static function messageHtml(array $m, string $you, int $now, bool $latest = false): string
    {
        $admin = $m['author'] === TicketStore::ADMIN;
        $name = $admin ? 'Administrator' : 'You';
        $face = '<span class="cpost-initial" aria-hidden="true">' . Html::esc($admin ? 'A' : mb_strtoupper(mb_substr(trim($you) === '' ? 'Y' : trim($you), 0, 1))) . '</span>';
        $at = (int) strtotime((string) $m['at']);
        $head = '<header class="cpost-head"><span class="cpost-name">' . Html::esc($name) . '</span>' . ($admin ? '<span class="ctag ctag-staff">Team</span>' : '')
            . '<span class="cpost-time">' . CommunityPages::time($at, $now) . '</span>'
            . (!empty($m['edited']) ? '<span class="cpost-edited">edited</span>' : '') . '</header>';
        $lines = array_map(static fn (string $l): string => CommunityText::lineHtml($l, CommunityText::HOME_HOSTS), explode("\n", (string) $m['body']));
        $body = '<div class="cpost-body csupport-body"><p>' . implode('<br />', $lines) . '</p></div>';
        $pics = '';
        $list = array_values(array_filter((array) ($m['pictures'] ?? []), static fn (mixed $p): bool => is_array($p) && preg_match('#^/report-pictures/\d+(?:/\d+(?:/[1-9])?)?$#', (string) ($p['url'] ?? '')) === 1));
        foreach ($list as $i => $p) {
            $n = $i + 1;
            $pics .= '<a class="csupport-pic" href="' . Html::attr((string) $p['url']) . '" target="_blank" rel="noopener" aria-label="' . Html::attr("Open picture {$n} of " . count($list) . ' full size') . '">'
                . '<img src="' . Html::attr((string) $p['url']) . '" width="' . (int) $p['width'] . '" height="' . (int) $p['height'] . '" alt="' . Html::attr(($admin ? 'A picture from the team' : 'A picture you sent') . " ({$n})") . '" loading="lazy" decoding="async" /></a>';
        }
        $files = '';
        foreach ((array) ($m['files'] ?? []) as $f) {
            if (!is_array($f) || preg_match('#^/report-files/\d+/\d+/[1-9]$#', (string) ($f['url'] ?? '')) !== 1) {
                continue;
            }
            $files .= '<li><a class="csupport-file" href="' . Html::attr((string) $f['url']) . '" download="' . Html::attr((string) $f['name']) . '">' . Html::esc((string) $f['name']) . '</a> <small class="cmuted">' . Html::esc(ReportFile::size((int) $f['bytes'])) . '</small></li>';
        }
        $main = $head . $body
            . ($pics === '' ? '' : '<div class="csupport-pics">' . $pics . '</div>')
            . ($files === '' ? '' : '<ul class="csupport-files">' . $files . '</ul>');
        return '<li class="cpost csupport-msg" id="' . ($latest ? 'latest' : 'm' . (int) $m['id']) . '" data-author="' . ($admin ? 'admin' : 'player') . '" data-message="' . (int) $m['id'] . '">'
            . '<div class="cpost-face">' . $face . '</div><div class="cpost-main">' . $main . '</div></li>';
    }

    /** What the game (or the website) sent with the report, plainly: where, which build, which device. @param array<string, mixed> $ctx */
    public static function contextHtml(array $ctx): string
    {
        $text = static fn (string $k): string => is_string($ctx[$k] ?? null) ? trim($ctx[$k]) : '';
        $rows = [];
        if ($text('from') === 'website') {
            $rows[] = ['Sent from', 'The website'];
        }
        $street = $text('streetName') !== '' ? $text('streetName') : $text('street');
        if ($street !== '') {
            $rows[] = ['Street', $street];
        }
        foreach (['build' => 'Build', 'agent' => 'Browser', 'screen' => 'Screen'] as $k => $label) {
            if ($text($k) !== '') {
                $rows[] = [$label, $text($k)];
            }
        }
        $errors = is_array($ctx['errors'] ?? null) ? count($ctx['errors']) : 0;
        if ($errors > 0) {
            $rows[] = ['Page errors', (string) $errors];
        }
        if ($rows === []) {
            return '';
        }
        $dl = '';
        foreach ($rows as [$k, $v]) {
            $dl .= '<dt>' . Html::esc($k) . '</dt><dd>' . Html::esc($v) . '</dd>';
        }
        return '<details class="csupport-context"><summary>' . ($text('from') === 'website' ? 'Sent with it' : 'What the game sent with it') . '</summary><dl>' . $dl . '</dl></details>';
    }

    /** The reply box with uploads, and Close or Reopen. @param array<string, mixed> $viewer */
    private function composeHtml(array $row, array $viewer, string $text, string $error): string
    {
        $id = (int) $row['id'];
        $closed = (string) $row['status'] === TicketStore::CLOSED;
        $act = static fn (string $what, string $label, string $hint): string => '    <form class="csupport-act" method="post" action="' . self::BASE . $id . '/' . $what . '/">' . self::nonceField($viewer)
            . ($hint === '' ? '' : '<p class="chint">' . Html::esc($hint) . '</p>')
            . '<button class="cbutton cbutton-quiet" type="submit">' . Html::esc($label) . "</button></form>\n";
        return ($closed ? $act('reopen', 'Open it again', self::COPY['closed']) : '')
            . '    <form class="cform csupport-form" method="post" action="' . self::BASE . $id . '/reply/" enctype="multipart/form-data" data-support-reply="' . $id . '">' . "\n"
            . self::nonceField($viewer)
            . '      <label class="visually-hidden" for="csupport-message">Your reply</label>' . "\n"
            . '      <textarea class="ctext cinput csupport-text" id="csupport-message" name="message" rows="3" required maxlength="' . TicketStore::MAX_MESSAGE . '" placeholder="Write a reply">' . Html::esc($text) . "</textarea>\n"
            . self::uploadRow('Send reply')
            . self::saidLine($error)
            . "    </form>\n"
            . ($closed ? '' : $act('close', 'Close report', self::COPY['closeHint']));
    }

    // Pieces

    /**
     * The file field and the send button, and the drop zone community/support-files.js shows (hidden
     * without it, so the plain field stays as it is): a box to drop pictures or a text file on, or to
     * click or tap to choose them (Austin 2026-11-27: "a drag and drop zone for photos/files"), the
     * files chosen under it, and a line for what could not be added.
     */
    private static function uploadRow(string $send): string
    {
        return '      <div class="csupport-drop" data-drop="" hidden>'
            . '<button class="csupport-drop-zone" type="button" data-drop-zone="">'
            . '<span class="csupport-drop-mouse">' . Html::esc(self::COPY['drop']) . ' <span class="csupport-drop-choose">' . Html::esc(self::COPY['dropChoose']) . '</span></span>'
            . '<span class="csupport-drop-touch">' . Html::esc(self::COPY['dropTap']) . '</span></button>'
            . '<ul class="csupport-chosen" data-chosen="" aria-label="Files to send" hidden></ul>'
            . '<p class="csaid csupport-drop-said" role="status" aria-live="polite" data-drop-said=""></p>'
            . "</div>\n"
            . '      <div class="csupport-row"><label class="csupport-attach"><span>Add pictures or a text file</span>'
            . '<input type="file" name="files[]" multiple accept="' . self::ACCEPT . '" /></label>'
            . '<button class="cbutton" type="submit">' . Html::esc($send) . "</button></div>\n"
            . '      <p class="chint">' . Html::esc(self::COPY['uploads']) . "</p>\n";
    }

    private static function saidLine(string $error): string
    {
        return '      <p class="csaid" role="status" aria-live="polite"' . ($error === '' ? '' : ' data-tone="bad"') . '>' . Html::esc($error) . "</p>\n";
    }

    /** @param array<string, mixed> $viewer */
    private static function nonceField(array $viewer): string
    {
        return '      <input type="hidden" name="_wpnonce" value="' . Html::attr((string) $viewer['nonce']) . "\" />\n";
    }

    /** The line after a form came back (?said=). */
    private static function said(Request $request): string
    {
        $key = match ($request->query('said')) {
            'sent' => 'sent',
            'replied' => 'replied',
            'closed' => 'closedDone',
            'reopened' => 'reopened',
            default => null,
        };
        return $key === null ? '' : '<p class="csupport-said" role="status">' . Html::esc(self::COPY[$key]) . '</p>';
    }

    /** What a refused form says: the refusal's own words, and to add the files again when some came with it. */
    private static function refusal(RestError $e, Request $request): string
    {
        $words = match ($e->errorCode) {
            'groddle_community_signed_out' => self::COPY['signedOut'],
            'groddle_community_nonce' => CommunityText::said('groddle_community_nonce'),
            'groddle_support_too_big' => self::COPY['tooBig'],
            default => $e->getMessage(),
        };
        return $words . (self::sentFiles($request) ? ' ' . self::COPY['again'] : '');
    }

    /** Whether the request carried any file at all. */
    private static function sentFiles(Request $request): bool
    {
        foreach (self::uploadList($request->files) as $u) {
            if ($u['error'] !== UPLOAD_ERR_NO_FILE) {
                return true;
            }
        }
        return false;
    }

    /**
     * The signed-in player behind a form, its nonce checked (CommunityPages::nonceRefusal, the
     * boards' rule), else a RestError. A form too large for the server arrives with no fields at all.
     *
     * @param array<string, mixed> $form
     */
    private function writer(Request $request, array $form): UserRecord
    {
        if ($form === [] && $request->files === [] && (int) ($request->header('content-length') ?? '0') > 0) {
            throw new RestError('groddle_support_too_big', self::COPY['tooBig'], 413);
        }
        $user = $this->minn->user();
        $viewer = (new Community($this->minn))->viewer();
        $refusal = (new CommunityPages($this->minn))->nonceRefusal($form, $viewer);
        if ($user === null || $refusal !== null) {
            throw new RestError($refusal ?? 'groddle_community_signed_out', CommunityText::said($refusal ?? 'groddle_community_signed_out'), $user === null ? 401 : 403);
        }
        return $user;
    }

    private function mine(UserRecord $me, int $id): array
    {
        return (new TicketStore($this->minn->db))->mine($me->id, $id) ?? throw new RestError('groddle_ticket_missing', 'That report is not one of yours.', 404);
    }

    /**
     * The form's files as two lazy lists for Tickets (read and checked only after the message's
     * rules and limits passed): [pictures, text files]. How many of each is checked from the names
     * before any is read.
     *
     * @return array{0: \Closure(): list<array{bytes: string, width: int, height: int}>, 1: \Closure(): list<array{name: string, text: string, bytes: int}>}
     */
    private function uploads(Request $request): array
    {
        $uploads = array_values(array_filter(self::uploadList($request->files), static fn (array $u): bool => $u['error'] !== UPLOAD_ERR_NO_FILE));
        $sorted = null;
        $sort = static function () use (&$sorted, $uploads): array {
            return $sorted ??= self::sortUploads($uploads);
        };
        return [static fn (): array => $sort()[0], static fn (): array => $sort()[1]];
    }

    /**
     * The uploads sorted and checked: pictures (by the name's ending; ReportPicture::acceptUpload
     * reads the bytes) and text files (ReportFile::accept); anything else refused. The counts are
     * checked first, from the names.
     *
     * @param list<array{name: string, tmp: string, size: int, error: int}> $uploads
     * @return array{0: list<array{bytes: string, width: int, height: int}>, 1: list<array{name: string, text: string, bytes: int}>}
     */
    public static function sortUploads(array $uploads): array
    {
        $pictures = $files = [];
        foreach ($uploads as $u) {
            $ext = strtolower(pathinfo($u['name'], PATHINFO_EXTENSION));
            if (in_array($ext, self::PICTURE_EXTENSIONS, true)) {
                $pictures[] = $u;
            } elseif (ReportFile::isTextName($u['name'])) {
                $files[] = $u;
            } else {
                throw new RestError(ReportFile::CODE . '_type', 'Only pictures (JPEG, PNG or WebP) and text files (.txt or .log) can be added.', 415);
            }
        }
        if (count($pictures) > ReportPicture::MAX_PER_MESSAGE) {
            throw new RestError(ReportPicture::CODE . '_count', 'A message carries at most ' . ReportPicture::MAX_PER_MESSAGE . ' pictures.', 400);
        }
        if (count($files) > ReportFile::MAX_PER_MESSAGE) {
            throw new RestError(ReportFile::CODE . '_count', 'A message carries at most ' . ReportFile::MAX_PER_MESSAGE . ' text files.', 400);
        }
        return [
            array_map(static fn (array $u): array => ReportPicture::acceptUpload(self::read($u, ReportPicture::UPLOAD_MAX_BYTES, ReportPicture::CODE, 'That picture is larger than 10 MB.')), $pictures),
            array_map(static fn (array $u): array => ReportFile::accept($u['name'], self::read($u, ReportFile::MAX_BYTES, ReportFile::CODE, 'That text file is larger than 256 KB. Send the part around the problem.')), $files),
        ];
    }

    /** One upload's bytes, refused unread when it is larger than $max or did not arrive whole. @param array{name: string, tmp: string, size: int, error: int} $u */
    private static function read(array $u, int $max, string $code, string $tooLarge): string
    {
        if ($u['error'] === UPLOAD_ERR_INI_SIZE || $u['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new RestError($code . '_too_large', $tooLarge, 413);
        }
        // A form upload is PHP's own file; the command line (the tests) has none, so it is taken as given there.
        if ($u['error'] !== UPLOAD_ERR_OK || $u['tmp'] === '' || !is_file($u['tmp']) || (PHP_SAPI !== 'cli' && !is_uploaded_file($u['tmp']))) {
            throw new RestError($code, 'A file did not arrive whole. Try again.', 400);
        }
        $size = (int) filesize($u['tmp']);
        if ($size > $max) {
            throw new RestError($code . '_too_large', $tooLarge, 413);
        }
        return (string) file_get_contents($u['tmp']);
    }

    /**
     * PURE: the file field's uploads ($_FILES' files[], or a single file) as a plain list.
     *
     * @param array<string, mixed> $files the request's files
     * @return list<array{name: string, tmp: string, size: int, error: int}>
     */
    public static function uploadList(array $files): array
    {
        $f = $files['files'] ?? null;
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }
        $out = [];
        $names = (array) $f['name'];
        foreach ($names as $i => $name) {
            $out[] = [
                'name' => is_string($name) ? $name : '',
                'tmp' => is_string(((array) ($f['tmp_name'] ?? []))[$i] ?? null) ? ((array) $f['tmp_name'])[$i] : '',
                'size' => (int) (((array) ($f['size'] ?? []))[$i] ?? 0),
                'error' => (int) (((array) ($f['error'] ?? []))[$i] ?? UPLOAD_ERR_NO_FILE),
            ];
            if (count($out) >= 12) {
                break;
            }
        }
        return $out;
    }

    /** A status the list may filter by (FILTERS' keys); anything else is all. */
    public static function status(mixed $asked): string
    {
        return is_string($asked) && isset(self::FILTERS[$asked]) ? $asked : 'all';
    }

    private static function listQuery(string $status, int $page): string
    {
        $q = array_filter(['status' => $status === 'all' ? null : $status, 'page' => $page > 1 ? $page : null]);
        return $q === [] ? '' : '?' . http_build_query($q);
    }

    private static function query(Request $request): string
    {
        return $request->query === [] ? '' : '?' . http_build_query($request->query);
    }

    /** A signed-out reader's page: what Support is, and the sign-in links. @param array<string, mixed> $viewer */
    private function signedOut(Community $community, array $viewer, string $title): Response
    {
        $main = '<section class="cx-conv csupport csupport-out" aria-labelledby="cx-conv-title">'
            . CommunityPages::head('Support', 'Your reports', 'cx-conv-title', '', true, self::COPY['lead'])
            . '<div class="cx-log"><div class="cpanel csupport-signin"><p>' . Html::esc(self::COPY['signedOut']) . '</p>' . CommunityPages::signInLinks() . '</div></div></section>';
        return $this->page($title . ' – Community', $community, $viewer, $main);
    }

    /** The whole page in the Community app's frame and rail (CommunityPages::appPage), never indexed. @param array<string, mixed> $viewer */
    private function page(string $title, Community $community, array $viewer, string $main, int $status = 200): Response
    {
        return (new CommunityPages($this->minn))->appPage($community, $viewer, self::ACTIVE, $main, $title, self::COPY['lead'], $status);
    }
}
