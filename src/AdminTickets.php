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
 * The console's Reports tab (AdminConsolePage, console/console.js): every
 * player's bug report tickets (TicketStore), manage_options only like the
 * console's other routes, each action a line in groddle_admin_actions
 * (AdminConsole::recordAction: reply, close, reopen, migrate).
 *
 *   GET  admin/tickets?status=open|answered|closed|all   {"tickets":[Row],"counts":{open,answered,closed,unread},"more":bool,"legacy":n}
 *                                  unread first, then open, answered, closed, the newest news first; legacy: old report posts not brought in yet
 *   GET  admin/tickets/{id}        {"ticket":Row,"messages":[Message],"context":{..}}   and marks it read
 *   POST admin/tickets/{id}/reply  {"message","email"?}  the thread + {"delivery","text"}; emails the player (below)
 *                                  unless "email" is false: a note (delivery note), for closing many related reports
 *   POST admin/tickets/{id}/close  the thread
 *   POST admin/tickets/{id}/reopen the thread (open, or answered when an administrator wrote last)
 *   POST admin/tickets/migrate     {"made","already","posts","text"}   TicketMigration, once (safe again)
 *   Row: Tickets' Summary (unread for the administrators) + {"player":{"id","name"}}
 *
 * A reply emails the player (PlayerMail::ticketReply, in the site's look,
 * by their character name, never the username), unless the administrator
 * ticked Don't email (a note: the player still sees it in the game, with the
 * bug icon's badge), the thread already held an emailed reply they had not
 * read (one email until they look; a note does not count), or the site is at
 * its hourly mail ceiling (MailLimits; each reply mail is a 'reply' row
 * there). The delivery says which: mailed, note, unread (not mailed: they
 * have an unread emailed reply already), busy, failed.
 */
final readonly class AdminTickets
{
    private const ADMIN = 'manage_options';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/admin/tickets', policy: new Policy(Access::Cap, self::ADMIN))]
    public function index(Request $request): Response
    {
        $status = (string) ($request->query('status') ?? 'all');
        $store = $this->store();
        $list = $store->listAll(in_array($status, TicketStore::STATUSES, true) ? $status : null);
        $names = (new Characters($this->minn->db))->namesOf(array_map(static fn (array $r): int => (int) $r['player_user_id'], $list['rows']));
        $out = [];
        foreach ($list['rows'] as $r) {
            $out[] = $this->row($r, $names);
        }
        return Response::json([
            'tickets' => $out,
            'counts' => $store->counts(),
            'more' => $list['more'],
            'legacy' => (new TicketMigration($this->minn->db))->pending(),
        ])->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Get, '/groddle/v1/admin/tickets/{id:\d+}', policy: new Policy(Access::Cap, self::ADMIN))]
    public function show(Request $request, string $id): Response
    {
        $row = $this->ticket((int) $id);
        $this->store()->markRead((int) $row['id'], TicketStore::ADMIN, time());
        return $this->thread((int) $row['id']);
    }

    #[Route(Method::Post, '/groddle/v1/admin/tickets/{id:\d+}/reply', policy: new Policy(Access::Cap, self::ADMIN))]
    public function reply(Request $request, string $id): Response
    {
        $admin = $this->admin();
        $row = $this->ticket((int) $id);
        $body = $request->json();
        $message = BugReports::clean((string) (is_scalar($body['message'] ?? null) ? $body['message'] : ''), TicketStore::MAX_MESSAGE, true);
        if ($message === '') {
            throw new RestError('groddle_ticket_empty', 'Write your reply first.', 400);
        }
        $now = time();
        // Don't email: the console's checkbox sends "email": false.
        $email = ($body['email'] ?? true) !== false;
        $waiting = $email && $this->mailWaiting($row);
        $this->store()->add((int) $row['id'], $admin->id, TicketStore::ADMIN, $message, $now);
        $delivery = !$email ? 'note' : ($waiting ? 'unread' : $this->mail((int) $row['player_user_id'], (int) $row['id'], (string) $row['subject'], $message, $now));
        $player = (int) $row['player_user_id'];
        AdminConsole::recordAction($this->minn->db, $admin->id, $player, 'reply', ['ticket' => (int) $row['id'], 'subject' => (string) $row['subject'], 'text' => $message], $delivery, $now);
        $name = $this->nameOf($player);
        $text = match ($delivery) {
            'mailed' => "Replied. {$name} has an email about it.",
            'note' => "Note added. {$name} sees it in the game; no email went.",
            'unread' => "Replied. {$name} has not read your last reply yet, so no new email went.",
            'busy' => "Replied. The site is at its hourly email limit, so {$name} was not emailed; they see it in the game.",
            default => "Replied, but the email to {$name} did not send; they see it in the game.",
        };
        return $this->thread((int) $row['id'], ['delivery' => $delivery, 'text' => $text]);
    }

    #[Route(Method::Post, '/groddle/v1/admin/tickets/{id:\d+}/close', policy: new Policy(Access::Cap, self::ADMIN))]
    public function close(Request $request, string $id): Response
    {
        $admin = $this->admin();
        $row = $this->ticket((int) $id);
        $this->store()->setStatus((int) $row['id'], TicketStore::CLOSED, time());
        AdminConsole::recordAction($this->minn->db, $admin->id, (int) $row['player_user_id'], 'close', ['ticket' => (int) $row['id'], 'subject' => (string) $row['subject']], 'stored');
        return $this->thread((int) $row['id'], ['text' => 'Closed.']);
    }

    #[Route(Method::Post, '/groddle/v1/admin/tickets/{id:\d+}/reopen', policy: new Policy(Access::Cap, self::ADMIN))]
    public function reopen(Request $request, string $id): Response
    {
        $admin = $this->admin();
        $row = $this->ticket((int) $id);
        $this->store()->setStatus((int) $row['id'], TicketStore::statusAfter((string) $row['last_author_role']), time());
        AdminConsole::recordAction($this->minn->db, $admin->id, (int) $row['player_user_id'], 'reopen', ['ticket' => (int) $row['id'], 'subject' => (string) $row['subject']], 'stored');
        return $this->thread((int) $row['id'], ['text' => 'Open again.']);
    }

    #[Route(Method::Post, '/groddle/v1/admin/tickets/migrate', policy: new Policy(Access::Cap, self::ADMIN))]
    public function migrate(Request $request): Response
    {
        $admin = $this->admin();
        $r = (new TicketMigration($this->minn->db))->run();
        AdminConsole::recordAction($this->minn->db, $admin->id, 0, 'migrate', ['made' => $r['made'], 'already' => $r['already']], 'stored');
        $text = $r['made'] === 0
            ? 'Every old report is already a ticket.'
            : ($r['made'] === 1 ? 'Brought in 1 old report as a ticket.' : "Brought in {$r['made']} old reports as tickets.");
        return Response::json(['made' => $r['made'], 'already' => $r['already'], 'posts' => $r['posts'], 'text' => $text]);
    }

    /**
     * Whether the player has an emailed reply in this ticket they have not
     * read yet: news they have not read, and a 'mailed' reply logged for it
     * since they last read it. A note (delivery note) never counts, so a
     * reply after a note still emails.
     */
    private function mailWaiting(array $row): bool
    {
        if (!TicketStore::unreadFor($row, TicketStore::PLAYER)) {
            return false;
        }
        $db = $this->minn->db;
        $read = $row['player_read_at'] ?? null;
        $sql = 'SELECT COUNT(*) FROM ' . $db->table(AdminConsole::ACTIONS) . " WHERE player_user_id = ? AND kind = 'reply' AND delivery = 'mailed' AND detail LIKE ?";
        $args = [(int) $row['player_user_id'], '{"ticket":' . (int) $row['id'] . ',%'];
        if ($read !== null) {
            $sql .= ' AND at > ?';
            $args[] = (string) $read;
        }
        return (int) $db->value($sql, $args) > 0;
    }

    /** Mails the player that an administrator replied: mailed | busy | failed. */
    private function mail(int $player, int $ticket, string $subject, string $message, int $now): string
    {
        $db = $this->minn->db;
        $email = (string) ($db->value('SELECT user_email FROM ' . $db->table('users') . ' WHERE ID = ?', [$player]) ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'failed';
        }
        $limits = new MailLimits($db, $this->minn->site);
        if ($limits->overHourCap($now, 'report reply')) {
            return 'busy';
        }
        $home = rtrim((string) ($this->minn->site->option('home') ?? ''), '/');
        $from = (string) ($this->minn->site->option('groddle_mail_from') ?? '');
        // The character's name, else the display name: never the username underneath.
        $name = (string) ($db->value('SELECT name FROM ' . $db->table(Characters::TABLE) . ' WHERE user_id = ?', [$player]) ?? '');
        $name = $name !== '' ? $name : (string) ($db->value('SELECT display_name FROM ' . $db->table('users') . ' WHERE ID = ?', [$player]) ?? '');
        [$mailSubject, $html, $headers] = PlayerMail::ticketReply($home, $from, $name, $subject, $message, $home . '/play/online/#report=' . $ticket);
        $sent = $this->minn->accounts()->mail($email, $mailSubject, $html, $headers);
        $limits->record(MailLimits::REPLY, $email, '', $sent, $now);
        return $sent ? 'mailed' : 'failed';
    }

    private function thread(int $id, array $extra = []): Response
    {
        $store = $this->store();
        $row = (array) $store->find($id);
        $names = (new Characters($this->minn->db))->namesOf([(int) $row['player_user_id']]);
        return Response::json($extra + [
            'ticket' => $this->row($row, $names),
            'messages' => TicketStore::messagesOut($store->messages($id)),
            'context' => (object) TicketStore::contextOf($row),
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function row(array $r, array $names): array
    {
        $player = (int) $r['player_user_id'];
        return TicketStore::summary($r, TicketStore::ADMIN) + ['player' => ['id' => $player, 'name' => (string) ($names[$player] ?? "#{$player}")]];
    }

    private function ticket(int $id): array
    {
        $row = $this->store()->find($id);
        if ($row === null) {
            throw new RestError('groddle_ticket_missing', 'No report has that id.', 404);
        }
        return $row;
    }

    private function nameOf(int $player): string
    {
        return (string) ((new Characters($this->minn->db))->namesOf([$player])[$player] ?? 'there');
    }

    private function admin(): UserRecord
    {
        $admin = $this->minn->user();
        if ($admin === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        return $admin;
    }

    private function store(): TicketStore
    {
        return new TicketStore($this->minn->db);
    }
}
