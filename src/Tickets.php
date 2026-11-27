<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Content\UserRecord;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * groddle/v1/tickets: a signed-in player's bug reports as threads
 * (TicketStore), the game's Reports sheet (the header's bug icon). A player
 * only ever reaches their own tickets: every route that names one reads it
 * through TicketStore::mine, and anyone else's answers 404 as a missing one
 * would.
 *
 *   GET  tickets?offset=n        {"tickets":[Summary],"unread":n,"more":bool}   yours, the newest news first: a page of
 *                                TicketStore::LIST_MAX from offset (0 when left out; digits only, else 400
 *                                groddle_ticket_offset), the same order on every page; more: a later page holds
 *                                some (#744, the game's Older reports); unread: every report's, not the page's
 *   GET  tickets/unread          {"unread":n}                          the bug icon's badge (polled)
 *   GET  tickets/{id}            {"ticket":Summary,"messages":[Message],"picture":{"url","width","height"}|null,"editable":bool}  and marks it read
 *   POST tickets                 {"message","context","pictures"?,"picture"?} 201 {"status":"sent","id","ticket"}   a new report;
 *                                pictures: up to ReportPicture::MAX_PER_MESSAGE base64 JPEGs (the first is the report's
 *                                own picture, the rest its first message's); "picture" alone is one (older games);
 *                                any refused, none is kept: a groddle_report_picture* code
 *   POST tickets/{id}/messages   {"message","pictures"?,"picture"?}  {"ticket","messages"}    a reply; opens a closed ticket again;
 *                                pictures (G791, several since 2026-11-15): as a new report's, all the reply's; refused
 *                                with a groddle_report_picture* code, so the game sends the words again without them
 *   POST tickets/{id}/close      {"ticket","messages"}               withdrawn: closed_by player (the console's Row closedBy)
 *   POST tickets/{id}/edit       {"message"}  {"ticket","messages"}    new words for the report's first message, until an
 *                                administrator has read it (TicketStore::editable); after that 409 groddle_ticket_read
 *   POST tickets/{id}/unread     {"ticket","unread":n}               the new reply dot back (the game's Reports list menu,
 *                                2026-11-12): only on a report an administrator wrote in last, else nothing changes
 *   POST tickets/{id}/messages/{message}/reactions  {"e","on"}  {"message","reactions"}   one of the chat's reactions on
 *                                (or off) any message of your own report (TicketReactions, Austin 2026-11-18); no email,
 *                                the report's status, read marks and lastMessageAt stay as they are
 *   Summary: {"id","status":"open"|"answered"|"working"|"closed","subject","createdAt","updatedAt","lastMessageAt","lastAuthor":"player"|"admin","unread":bool}
 *            adminReplied: an administrator wrote in it at some point, kept after it closes (#225)
 *            readByTeamAt: time|null, when an administrator first opened it after the player's latest
 *            message (the console's GET admin/tickets/{id}, also how /groddle-check reads one); null
 *            while the player wrote last and no administrator has opened it since, and whenever an
 *            administrator wrote last (Read by the team, Austin 2026-11-12; TicketStore::readByTeamAt)
 *   Message: {"id","author":"player"|"admin","body","at","edited":time|null,"pictures":[{"url","width","height"}],
 *             "picture":{"url","width","height"}|null}
 *            who wrote is a role, never a name; pictures: the message's own, in order (/report-pictures/{ticket}/
 *            {message}/{n}; a new report's own picture is the thread's "picture", not in its first message's list);
 *            picture: the first of them again for older readers (G791, /report-pictures/{ticket}/{message})
 *            reactions: [{"e","by":[{"id","name","you"}],"mine"}] (TicketReactions::out), the player's by character
 *            name and an administrator's by display name
 *   editable: whether the first message can still be edited (every thread answer carries it)
 *
 * A new report keeps the old one's rules (BugReports: the message required
 * and at most MAX_MESSAGE characters, the context's known keys cut short,
 * PER_WINDOW in WINDOW seconds and PER_DAY a day; a new report's picture
 * comes with it, so those are its limits too). Replies: REPLY_PER_WINDOW
 * in WINDOW seconds and REPLY_PER_DAY a day, the same length. Every other
 * picture (a reply's, G791, and a new report's after its first) counts against
 * PICTURES_PER_WINDOW and PICTURES_PER_DAY, and has a new report's picture's
 * size and type. An edit has
 * the length limit and cleaning of a new message (ticket 49, 2026-11-02).
 */
final readonly class Tickets
{
    public const REPLY_PER_WINDOW = 10;
    public const REPLY_PER_DAY = 60;
    /**
     * Pictures kept with messages (a reply's, a new report's after its first), in WINDOW seconds and a
     * day; each is up to ReportPicture::MAX_BYTES. G791's 5 and 30 for one a reply, scaled for four a
     * message (Austin 2026-11-15): at most 6 MB in ten minutes, 18 MB a day, from one player.
     */
    public const PICTURES_PER_WINDOW = 20;
    public const PICTURES_PER_DAY = 60;

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/tickets', policy: new Policy(Access::SignedIn))]
    public function index(Request $request): Response
    {
        $offset = (string) ($request->query('offset') ?? '0');
        if (!ctype_digit($offset) || strlen($offset) > 9) {
            throw new RestError('groddle_ticket_offset', 'The offset is a whole number, 0 or more.', 400);
        }
        $me = $this->me();
        $store = $this->store();
        $page = $store->listFor($me->id, (int) $offset);
        $list = array_map(static fn (array $r): array => TicketStore::summary($r, TicketStore::PLAYER), $page['rows']);
        return Response::json(['tickets' => $list, 'unread' => $store->unreadCount($me->id), 'more' => $page['more']])->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Get, '/groddle/v1/tickets/unread', policy: new Policy(Access::SignedIn))]
    public function unread(Request $request): Response
    {
        return Response::json(['unread' => $this->store()->unreadCount($this->me()->id)])->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Get, '/groddle/v1/tickets/{id:\d+}', policy: new Policy(Access::SignedIn))]
    public function show(Request $request, string $id): Response
    {
        $me = $this->me();
        $row = $this->mine($me, (int) $id);
        $this->store()->markRead((int) $row['id'], TicketStore::PLAYER, time());
        return $this->thread((int) $row['id'], $me->id);
    }

    #[Route(Method::Post, '/groddle/v1/tickets', policy: new Policy(Access::SignedIn))]
    public function create(Request $request): Response
    {
        $me = $this->me();
        $body = $request->json();
        $message = BugReports::message($body['message'] ?? null, BugReports::MAX_MESSAGE, 'groddle_feedback_long');
        if ($message === '') {
            throw new RestError('groddle_feedback_empty', 'Please describe the problem you are having.', 400);
        }
        $store = $this->store();
        $now = time();
        if ($store->openedSince($me->id, BugReports::WINDOW, $now) >= BugReports::PER_WINDOW || $store->openedSince($me->id, 86400, $now) >= BugReports::PER_DAY) {
            throw new RestError('groddle_feedback_slow', 'Thanks! You have sent a few reports just now. Try again in a little while.', 429);
        }
        $context = BugReports::context(is_array($body['context'] ?? null) ? $body['context'] : []);
        // The pictures (the game's own and any the player added): checked after the limits, the first the report's own.
        $pictures = $this->pictures($store, $me->id, $body, $now, 1);
        $id = $store->open($me->id, $message, $context, $now, null, $pictures[0] ?? null, array_slice($pictures, 1));
        if ($id <= 0) {
            throw new RestError('groddle_feedback_failed', 'That didn\'t send. Try again in a moment.', 500);
        }
        return Response::json(['status' => 'sent', 'id' => $id, 'ticket' => TicketStore::summary((array) $store->find($id), TicketStore::PLAYER)], 201);
    }

    #[Route(Method::Post, '/groddle/v1/tickets/{id:\d+}/messages', policy: new Policy(Access::SignedIn))]
    public function reply(Request $request, string $id): Response
    {
        $me = $this->me();
        $row = $this->mine($me, (int) $id);
        $body = $request->json();
        $message = BugReports::message($body['message'] ?? null, TicketStore::MAX_MESSAGE, 'groddle_ticket_long');
        if ($message === '') {
            throw new RestError('groddle_ticket_empty', 'Write your reply first.', 400);
        }
        $store = $this->store();
        $now = time();
        if ($store->writtenSince($me->id, BugReports::WINDOW, $now) >= self::REPLY_PER_WINDOW || $store->writtenSince($me->id, 86400, $now) >= self::REPLY_PER_DAY) {
            throw new RestError('groddle_ticket_slow', 'You have sent a lot of replies just now. Try again in a little while.', 429);
        }
        // G791: pictures with the reply, checked after the reply's limits, with a limit of their own.
        $pictures = $this->pictures($store, $me->id, $body, $now, 0);
        $store->add((int) $row['id'], $me->id, TicketStore::PLAYER, $message, $now, $pictures);
        return $this->thread((int) $row['id'], $me->id);
    }

    #[Route(Method::Post, '/groddle/v1/tickets/{id:\d+}/close', policy: new Policy(Access::SignedIn))]
    public function close(Request $request, string $id): Response
    {
        $me = $this->me();
        $row = $this->mine($me, (int) $id);
        $store = $this->store();
        $store->setStatus((int) $row['id'], TicketStore::CLOSED, time(), TicketStore::PLAYER);
        $store->markRead((int) $row['id'], TicketStore::PLAYER, time());
        return $this->thread((int) $row['id'], $me->id);
    }

    /** New words for the report's first message, until an administrator has read it (ticket 49). */
    #[Route(Method::Post, '/groddle/v1/tickets/{id:\d+}/edit', policy: new Policy(Access::SignedIn))]
    public function edit(Request $request, string $id): Response
    {
        $me = $this->me();
        $row = $this->mine($me, (int) $id);
        $body = $request->json();
        $message = BugReports::message($body['message'] ?? null, BugReports::MAX_MESSAGE, 'groddle_ticket_long');
        if ($message === '') {
            throw new RestError('groddle_ticket_empty', 'Write your report first.', 400);
        }
        if (!$this->store()->editFirst((int) $row['id'], $me->id, $message, time())) {
            throw new RestError('groddle_ticket_read', 'An administrator has read this report, so it can no longer be changed. Write a reply to add to it.', 409);
        }
        return $this->thread((int) $row['id'], $me->id);
    }

    /**
     * Mark as unread (the Reports list's row menu, Austin 2026-11-12): the player's own read mark
     * goes back to just before the newest message, so the reply they already read shows as new
     * again. Only the player's mark is touched (the administrators' decides Edit until read), and
     * only when an administrator wrote last; anything else is left as it is.
     */
    #[Route(Method::Post, '/groddle/v1/tickets/{id:\d+}/unread', policy: new Policy(Access::SignedIn))]
    public function markUnread(Request $request, string $id): Response
    {
        $me = $this->me();
        $row = $this->mine($me, (int) $id);
        $store = $this->store();
        $store->markUnreadForPlayer((int) $row['id']);
        return Response::json([
            'ticket' => TicketStore::summary((array) $store->find((int) $row['id']), TicketStore::PLAYER),
            'unread' => $store->unreadCount($me->id),
        ])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * A reaction on (or off) a message of the caller's own report (TicketReactions). Not a reply:
     * no email, no status, read mark or lastMessageAt change, so it is no news for anyone.
     */
    #[Route(Method::Post, '/groddle/v1/tickets/{id:\d+}/messages/{message:\d+}/reactions', policy: new Policy(Access::SignedIn))]
    public function react(Request $request, string $id, string $message): Response
    {
        $me = $this->me();
        $row = $this->mine($me, (int) $id);
        $body = $request->json();
        $reactions = new TicketReactions($this->minn->db);
        $reactions->toggle((int) $row['id'], (int) $message, $me->id, TicketStore::PLAYER, $body['e'] ?? null, ($body['on'] ?? true) !== false, time());
        return Response::json(['message' => (int) $message, 'reactions' => $reactions->ofMessage((int) $row['id'], (int) $message, $me->id)])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * A message's pictures (ReportPicture::fromBody): none kept unless every one passes. The ones the
     * picture limit counts ($free: a new report's own picture has the report's limits) must fit in
     * what PICTURES_PER_WINDOW and PICTURES_PER_DAY leave.
     *
     * @return list<array{bytes: string, width: int, height: int}>
     */
    private function pictures(TicketStore $store, int $player, array $body, int $now, int $free): array
    {
        $pictures = ReportPicture::fromBody($body);
        $counted = max(0, count($pictures) - $free);
        if ($counted > 0 && !self::picturesFit($counted, $store->picturesSince($player, BugReports::WINDOW, $now), $store->picturesSince($player, 86400, $now))) {
            throw new RestError(ReportPicture::CODE . '_slow', 'You have sent a lot of pictures just now. Try again in a little while.', 429);
        }
        return $pictures;
    }

    /** PURE: whether $adding more pictures fit the limits, with $window sent in WINDOW seconds and $day in a day. */
    public static function picturesFit(int $adding, int $window, int $day): bool
    {
        return $window + $adding <= self::PICTURES_PER_WINDOW && $day + $adding <= self::PICTURES_PER_DAY;
    }

    private function thread(int $id, int $player): Response
    {
        $store = $this->store();
        $row = (array) $store->find($id);
        return Response::json([
            'ticket' => TicketStore::summary($row, TicketStore::PLAYER),
            'messages' => (new TicketReactions($this->minn->db))->attach($id, TicketStore::messagesOut($store->messages($id), null, $store->messagePictures($id)), $player),
            'picture' => $store->pictureOut($id),
            'editable' => TicketStore::editable($row, $store->first($id), $player),
        ])->withHeader('Cache-Control', 'no-store');
    }

    /** One of the caller's tickets; anyone else's is missing to them. */
    private function mine(UserRecord $me, int $id): array
    {
        $row = $this->store()->mine($me->id, $id);
        if ($row === null) {
            throw new RestError('groddle_ticket_missing', 'That report is not one of yours.', 404);
        }
        return $row;
    }

    private function me(): UserRecord
    {
        $user = $this->minn->user();
        if ($user === null) {
            throw new RestError('groddle_feedback_signed_out', 'Sign in to send us feedback.', 401);
        }
        return $user;
    }

    private function store(): TicketStore
    {
        return new TicketStore($this->minn->db);
    }
}
