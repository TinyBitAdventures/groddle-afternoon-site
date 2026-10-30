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
 *   GET  tickets                 {"tickets":[Summary],"unread":n}      yours, the newest news first
 *   GET  tickets/unread          {"unread":n}                          the bug icon's badge (polled)
 *   GET  tickets/{id}            {"ticket":Summary,"messages":[Message],"picture":{"url","width","height"}|null}  and marks it read
 *   POST tickets                 {"message","context","picture"?} 201 {"status":"sent","id","ticket"}   a new report;
 *                                picture: a base64 JPEG of the game (ReportPicture), refused with a groddle_report_picture* code
 *   POST tickets/{id}/messages   {"message"}  {"ticket","messages"}    a reply; opens a closed ticket again
 *   POST tickets/{id}/close      {"ticket","messages"}
 *   Summary: {"id","status":"open"|"answered"|"closed","subject","createdAt","updatedAt","lastMessageAt","lastAuthor":"player"|"admin","unread":bool}
 *   Message: {"id","author":"player"|"admin","body","at"}   who wrote is a role, never a name
 *
 * A new report keeps the old one's rules (BugReports: the message required
 * and at most MAX_MESSAGE characters, the context's known keys cut short,
 * PER_WINDOW in WINDOW seconds and PER_DAY a day; a picture comes only with
 * a new report, so those are its limits too). Replies: REPLY_PER_WINDOW
 * in WINDOW seconds and REPLY_PER_DAY a day, the same length.
 */
final readonly class Tickets
{
    public const REPLY_PER_WINDOW = 10;
    public const REPLY_PER_DAY = 60;

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/tickets', policy: new Policy(Access::SignedIn))]
    public function index(Request $request): Response
    {
        $me = $this->me();
        $store = $this->store();
        $list = array_map(static fn (array $r): array => TicketStore::summary($r, TicketStore::PLAYER), $store->listFor($me->id));
        $unread = count(array_filter($list, static fn (array $t): bool => $t['unread']));
        return Response::json(['tickets' => $list, 'unread' => $unread])->withHeader('Cache-Control', 'no-store');
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
        return $this->thread((int) $row['id']);
    }

    #[Route(Method::Post, '/groddle/v1/tickets', policy: new Policy(Access::SignedIn))]
    public function create(Request $request): Response
    {
        $me = $this->me();
        $body = $request->json();
        $message = BugReports::clean((string) (is_scalar($body['message'] ?? null) ? $body['message'] : ''), BugReports::MAX_MESSAGE, true);
        if ($message === '') {
            throw new RestError('groddle_feedback_empty', 'Please describe the problem you are having.', 400);
        }
        $store = $this->store();
        $now = time();
        if ($store->openedSince($me->id, BugReports::WINDOW, $now) >= BugReports::PER_WINDOW || $store->openedSince($me->id, 86400, $now) >= BugReports::PER_DAY) {
            throw new RestError('groddle_feedback_slow', 'Thanks! You have sent a few reports just now. Try again in a little while.', 429);
        }
        $context = BugReports::context(is_array($body['context'] ?? null) ? $body['context'] : []);
        // The picture of the game, when the player left Attach a picture ticked: checked after the limits.
        $raw = $body['picture'] ?? null;
        $picture = $raw === null || $raw === '' || $raw === false ? null : ReportPicture::accept($raw);
        $id = $store->open($me->id, $message, $context, $now, null, $picture);
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
        $message = BugReports::clean((string) (is_scalar($body['message'] ?? null) ? $body['message'] : ''), TicketStore::MAX_MESSAGE, true);
        if ($message === '') {
            throw new RestError('groddle_ticket_empty', 'Write your reply first.', 400);
        }
        $store = $this->store();
        $now = time();
        if ($store->writtenSince($me->id, BugReports::WINDOW, $now) >= self::REPLY_PER_WINDOW || $store->writtenSince($me->id, 86400, $now) >= self::REPLY_PER_DAY) {
            throw new RestError('groddle_ticket_slow', 'You have sent a lot of replies just now. Try again in a little while.', 429);
        }
        $store->add((int) $row['id'], $me->id, TicketStore::PLAYER, $message, $now);
        return $this->thread((int) $row['id']);
    }

    #[Route(Method::Post, '/groddle/v1/tickets/{id:\d+}/close', policy: new Policy(Access::SignedIn))]
    public function close(Request $request, string $id): Response
    {
        $me = $this->me();
        $row = $this->mine($me, (int) $id);
        $store = $this->store();
        $store->setStatus((int) $row['id'], TicketStore::CLOSED, time());
        $store->markRead((int) $row['id'], TicketStore::PLAYER, time());
        return $this->thread((int) $row['id']);
    }

    private function thread(int $id): Response
    {
        $store = $this->store();
        $row = (array) $store->find($id);
        return Response::json(['ticket' => TicketStore::summary($row, TicketStore::PLAYER), 'messages' => TicketStore::messagesOut($store->messages($id)), 'picture' => $store->pictureOut($id)])->withHeader('Cache-Control', 'no-store');
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
