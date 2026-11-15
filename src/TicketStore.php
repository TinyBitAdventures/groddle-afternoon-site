<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;

/**
 * Bug reports as threaded tickets (Austin 2026-10-30): one groddle_tickets
 * row per report, its messages in groddle_ticket_messages, oldest first. The
 * player's routes are Tickets, the administrators' AdminTickets, the one-time
 * move of the old groddle_feedback posts TicketMigration; all of them read
 * and write through here.
 *
 * Status: open (the player wrote last, waiting for an administrator),
 * answered (an administrator wrote last), working (an administrator marked it
 * Working on it, ticket 49, 2026-11-02), closed (either side closed it). A
 * new message from either side opens a closed ticket again: the player's as
 * open, an administrator's as answered. Working stays working whoever writes
 * (statusAfter): it is the administrators' mark, taken off with Not working
 * on it or Close, and the read marks below still say who has news.
 *
 * Who closed it (Austin 2026-11-11): closed_by is player (they withdrew it,
 * Tickets close) or admin (the console's Close), closed_at when; both NULL
 * while a ticket is not closed, and cleared when it opens again (a message
 * from either side, Reopen, Working on it). Tickets closed before the
 * columns stay NULL: who closed them is unknown (closedBy null).
 *
 * Unread: a side has unread news when the other side wrote last
 * (last_author_role) after it last read the thread (player_read_at,
 * admin_read_at; NULL is never). Writing counts as reading.
 *
 * A new report may carry a picture of the game (ReportPicture): kept with
 * the ticket in PICTURES, written in the same transaction as the ticket.
 * A player's reply may carry one too (G791, #216 and #281: a bug gone before
 * the report window opened, a picture taken on purpose afterwards): kept per
 * message in MESSAGE_PICTURES, written with the message, the same checks and
 * limits (ReportPicture), and shown to the same two people (TicketPictures).
 * Since 2026-11-15 (Austin: any picture, several a message) a message carries
 * up to ReportPicture::MAX_PER_MESSAGE: a reply's all in MESSAGE_PICTURES, a
 * new report's first in PICTURES (the report's own, as before) and the rest in
 * MESSAGE_PICTURES under its first message. Each row has its place in its
 * message (position, from 0), and one address (/report-pictures/<ticket>/
 * <message>/<position + 1>). G791's table held one a message
 * (OLD_MESSAGE_PICTURES, never released); adoptOldPictures() copies its rows.
 *
 * Read by the team (Austin 2026-11-12): the player's list and thread say
 * when an administrator first opened the thread after the player's latest
 * message (readByTeamAt). admin_read_at is that mark: opening it again while
 * nothing new came keeps the first time (markRead), and a new message from
 * the player leaves it older than last_message_at, so the mark goes until an
 * administrator opens the thread again. Unread and editable read the same
 * column and are unchanged by keeping the earlier time.
 *
 * Edit until read (ticket 49): the player may change the words of a report's
 * first message until an administrator has read the thread (editable: admin_read_at
 * NULL, or older than the message as it now stands). The edit is stored in
 * place, the message marked edited_at, the subject taken again from it.
 *
 * Bodies are plain text (BugReports::clean: no control characters, newlines
 * kept, at most MAX_MESSAGE characters). They are stored as text and set as
 * text by every reader (the game, the console), and escaped in the email.
 */
final readonly class TicketStore
{
    public const TABLE = 'groddle_tickets';
    public const MESSAGES = 'groddle_ticket_messages';
    /** Every earlier text of an edited message (Austin 2026-11-02: an edit's whole history, for administrators). */
    public const REVISIONS = 'groddle_ticket_message_revisions';
    /** A report's picture of the game, one row a ticket (ReportPicture, served by TicketPictures). */
    public const PICTURES = 'groddle_ticket_pictures';
    /** A message's pictures, up to ReportPicture::MAX_PER_MESSAGE, by position (ReportPicture, served by TicketPictures). */
    public const MESSAGE_PICTURES = 'groddle_message_pictures';
    /** G791's one picture a reply (2026-11-15, never released): its rows are copied by adoptOldPictures(). */
    public const OLD_MESSAGE_PICTURES = 'groddle_ticket_message_pictures';

    public const OPEN = 'open';
    public const ANSWERED = 'answered';
    public const CLOSED = 'closed';
    /** Working on it: an administrator's mark (ticket 49); fits status varchar(8). */
    public const WORKING = 'working';
    public const STATUSES = [self::OPEN, self::ANSWERED, self::WORKING, self::CLOSED];

    public const PLAYER = 'player';
    public const ADMIN = 'admin';
    /** Who can close a ticket (closed_by; fits varchar(8)). */
    public const CLOSERS = [self::PLAYER, self::ADMIN];

    public const MAX_MESSAGE = BugReports::MAX_MESSAGE;
    /** An administrator's reply: long combined answers to a batch of reports (console/console.js TICKET_MAX). */
    public const MAX_ADMIN_MESSAGE = 10000;
    /** The subject: the first line, cut to this many characters with an ellipsis. */
    public const SUBJECT_MAX = 60;
    /** Tickets an administrator's list carries at once. */
    public const LIST_MAX = 200;
    /** Messages a thread carries at once (the newest). */
    public const THREAD_MAX = 200;

    public function __construct(private Db $db)
    {
    }

    /** The subject a message gives: its first line, at most SUBJECT_MAX characters. */
    public static function subject(string $message): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', (string) (strtok(trim($message), "\n") ?: $message)));
        return mb_strlen($line) > self::SUBJECT_MAX ? rtrim(mb_substr($line, 0, self::SUBJECT_MAX - 1)) . '…' : $line;
    }

    /** The status a ticket takes when $role writes in it: a working one stays working. */
    public static function statusAfter(string $role, string $current = ''): string
    {
        if ($current === self::WORKING) {
            return self::WORKING;
        }
        return $role === self::ADMIN ? self::ANSWERED : self::OPEN;
    }

    /** The status Working on it sets ($on), or the one taking it off gives back (open or answered, by who wrote last). */
    public static function workingStatus(bool $on, string $lastAuthor): string
    {
        return $on ? self::WORKING : self::statusAfter($lastAuthor);
    }

    /**
     * Whether $player may change the words of a ticket's first message
     * $first (ticket 49): it is their ticket and their message, and no
     * administrator has read the thread since the message was written or
     * last edited (admin_read_at NULL, or older). A reply by an administrator
     * counts as reading, so it ends the edit too.
     */
    public static function editable(array $ticket, ?array $first, int $player): bool
    {
        if ($first === null || $player <= 0 || (int) ($ticket['player_user_id'] ?? 0) !== $player) {
            return false;
        }
        if ((string) ($first['author_role'] ?? '') !== self::PLAYER || (int) ($first['author_user_id'] ?? 0) !== $player || (int) ($first['ticket_id'] ?? $ticket['id'] ?? 0) !== (int) ($ticket['id'] ?? -1)) {
            return false;
        }
        $read = $ticket['admin_read_at'] ?? null;
        $at = (string) (($first['edited_at'] ?? null) ?: ($first['created_at'] ?? ''));
        return $read === null || (string) $read < $at;
    }

    /** Who closed a ticket row: player, admin, or null (not closed, or closed before closed_by was kept). */
    public static function closedBy(array $row): ?string
    {
        $by = $row['closed_by'] ?? null;
        return ($row['status'] ?? '') === self::CLOSED && in_array($by, self::CLOSERS, true) ? $by : null;
    }

    /** Whether $role has news in a ticket row it has not read. */
    public static function unreadFor(array $row, string $role): bool
    {
        if (($row['last_author_role'] ?? '') === $role) {
            return false;
        }
        $read = $row[$role === self::ADMIN ? 'admin_read_at' : 'player_read_at'] ?? null;
        return $read === null || (string) $read < (string) $row['last_message_at'];
    }

    /**
     * When the team read the player's latest message (Read by the team, Austin 2026-11-12): the
     * administrators' read mark while the player wrote last and an administrator has opened the
     * thread since; null otherwise. A thread an administrator wrote in last has none: their reply
     * is its own news (Answered), and writing counts as reading.
     */
    public static function readByTeamAt(array $row): ?string
    {
        $read = $row['admin_read_at'] ?? null;
        if (($row['last_author_role'] ?? '') !== self::PLAYER || $read === null || $read === '') {
            return null;
        }
        return (string) $read >= (string) ($row['last_message_at'] ?? '') ? SaveShape::time((string) $read) : null;
    }

    // —— Writing ——

    /**
     * A new ticket with its first message, by the player: the new id (0 when
     * the database refused the text).
     *
     * @param array<string, mixed> $context BugReports::context
     * @param array{bytes: string, width: int, height: int}|null $picture ReportPicture::accept, the report's own
     * @param list<array{bytes: string, width: int, height: int}> $more the report's other pictures (its first message's)
     */
    public function open(int $player, string $message, array $context, int $now, ?int $legacyPost = null, ?array $picture = null, array $more = []): int
    {
        return Ledger::atomically($this->db, function () use ($player, $message, $context, $now, $legacyPost, $picture, $more): int {
            $at = self::stamp($now);
            $sql = 'INSERT ' . ($legacyPost !== null ? 'IGNORE ' : '') . 'INTO ' . $this->db->table(self::TABLE)
                . ' (player_user_id, status, subject, created_at, updated_at, last_message_at, last_author_role, player_read_at, admin_read_at, context, legacy_post_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)';
            $made = $this->db->execute($sql, [$player, self::OPEN, self::subject($message), $at, $at, $at, self::PLAYER, $at, self::contextJson($context), $legacyPost]);
            $id = $made > 0 ? $this->db->insertId() : 0;
            if ($id <= 0) {
                return 0;
            }
            $this->db->execute('INSERT INTO ' . $this->db->table(self::MESSAGES) . ' (ticket_id, author_user_id, author_role, body, created_at) VALUES (?, ?, ?, ?, ?)', [$id, $player, self::PLAYER, $message, $at]);
            $first = $this->db->insertId();
            if ($first > 0) {
                $this->writePictures($id, $first, $player, $more, $at);
            }
            if ($picture !== null) {
                // Binary goes in as base64 and FROM_BASE64, as Profiles writes its pictures (raw bytes are not a valid string to bind).
                $this->db->execute('INSERT INTO ' . $this->db->table(self::PICTURES) . ' (ticket_id, player_user_id, picture, width, height, bytes, created_at) VALUES (?, ?, FROM_BASE64(?), ?, ?, ?, ?)', [$id, $player, base64_encode($picture['bytes']), $picture['width'], $picture['height'], strlen($picture['bytes']), $at]);
            }
            return $id;
        });
    }

    /**
     * A message in a ticket from $role: the ticket opens again if it was closed.
     * A player's reply may carry pictures (G791; several since 2026-11-15), written with the message.
     *
     * @param list<array{bytes: string, width: int, height: int}> $pictures ReportPicture::fromBody
     */
    public function add(int $ticket, int $author, string $role, string $body, int $now, array $pictures = []): int
    {
        return Ledger::atomically($this->db, function () use ($ticket, $author, $role, $body, $now, $pictures): int {
            $at = self::stamp($now);
            $this->db->execute('INSERT INTO ' . $this->db->table(self::MESSAGES) . ' (ticket_id, author_user_id, author_role, body, created_at) VALUES (?, ?, ?, ?, ?)', [$ticket, $author, $role, $body, $at]);
            $id = $this->db->insertId();
            if ($id > 0) {
                $this->writePictures($ticket, $id, $author, $pictures, $at);
            }
            $read = $role === self::ADMIN ? 'admin_read_at' : 'player_read_at';
            $current = (string) ($this->db->value('SELECT status FROM ' . $this->db->table(self::TABLE) . ' WHERE id = ? FOR UPDATE', [$ticket]) ?? '');
            $this->db->execute('UPDATE ' . $this->db->table(self::TABLE) . " SET status = ?, updated_at = ?, last_message_at = ?, last_author_role = ?, {$read} = ?, closed_by = NULL, closed_at = NULL WHERE id = ?", [self::statusAfter($role, $current), $at, $at, $role, $at, $ticket]);
            return $id;
        });
    }

    /**
     * A ticket's new status. Closing records who closed it ($by: PLAYER or
     * ADMIN) and when; any other status clears both.
     */
    public function setStatus(int $ticket, string $status, int $now, ?string $by = null): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            return;
        }
        $closed = $status === self::CLOSED;
        $at = self::stamp($now);
        $this->db->execute('UPDATE ' . $this->db->table(self::TABLE) . ' SET status = ?, updated_at = ?, closed_by = ?, closed_at = ? WHERE id = ?', [$status, $at, $closed && in_array($by, self::CLOSERS, true) ? $by : null, $closed ? $at : null, $ticket]);
    }

    /**
     * The player's new words for their report's first message (ticket 49),
     * in place: true when written, false when it could no longer be edited
     * (an administrator read it meanwhile). The ticket row is locked, so an
     * administrator opening the thread (markRead) waits for the edit or
     * comes first and ends it. The subject follows the new first line.
     */
    public function editFirst(int $ticket, int $player, string $body, int $now): bool
    {
        return Ledger::atomically($this->db, function () use ($ticket, $player, $body, $now): bool {
            $row = $this->db->row('SELECT * FROM ' . $this->db->table(self::TABLE) . ' WHERE id = ? AND player_user_id = ? FOR UPDATE', [$ticket, $player]);
            $first = $row === null ? null : $this->first($ticket);
            if ($row === null || !self::editable($row, $first, $player)) {
                return false;
            }
            $at = self::stamp($now);
            // The text it replaces is kept, with when it was written and when it was replaced.
            $this->db->execute('INSERT INTO ' . $this->db->table(self::REVISIONS) . ' (message_id, ticket_id, player_user_id, body, written_at, replaced_at) VALUES (?, ?, ?, ?, ?, ?)', [(int) $first['id'], $ticket, $player, (string) $first['body'], (string) (($first['edited_at'] ?? null) ?: $first['created_at']), $at]);
            $this->db->execute('UPDATE ' . $this->db->table(self::MESSAGES) . ' SET body = ?, edited_at = ? WHERE id = ? AND ticket_id = ?', [$body, $at, (int) $first['id'], $ticket]);
            $this->db->execute('UPDATE ' . $this->db->table(self::TABLE) . ' SET subject = ?, updated_at = ? WHERE id = ?', [self::subject($body), $at, $ticket]);
            return true;
        });
    }

    /**
     * $role has read the ticket now. An administrator's mark keeps its time while it already covers
     * the newest message (Read by the team: when they first read it), so opening the thread again
     * changes nothing the player sees.
     */
    public function markRead(int $ticket, string $role, int $now): void
    {
        if ($role === self::ADMIN) {
            $this->db->execute('UPDATE ' . $this->db->table(self::TABLE) . ' SET admin_read_at = IF(admin_read_at IS NOT NULL AND admin_read_at >= last_message_at, admin_read_at, ?) WHERE id = ?', [self::stamp($now), $ticket]);
            return;
        }
        $this->db->execute('UPDATE ' . $this->db->table(self::TABLE) . ' SET player_read_at = ? WHERE id = ?', [self::stamp($now), $ticket]);
    }

    /**
     * The player's reply dot back on a ticket (Tickets::markUnread): their read mark one second
     * before the newest message, only while an administrator wrote last (a player's own message is
     * never news to them). A mark, not NULL, so AdminTickets::mailWaiting still counts only the
     * mailed replies since then.
     */
    public function markUnreadForPlayer(int $ticket): void
    {
        $this->db->execute('UPDATE ' . $this->db->table(self::TABLE) . " SET player_read_at = DATE_SUB(last_message_at, INTERVAL 1 SECOND) WHERE id = ? AND last_author_role = 'admin'", [$ticket]);
    }

    // —— Reading ——

    /** One ticket row (with admin_replied, adminRepliedSql), or null. */
    public function find(int $ticket): ?array
    {
        return $ticket > 0 ? $this->db->row('SELECT *, ' . $this->adminRepliedSql() . ' AS admin_replied FROM ' . $this->db->table(self::TABLE) . ' WHERE id = ?', [$ticket]) : null;
    }

    /**
     * Whether an administrator ever wrote in the ticket, as a column of a ticket row
     * (#225, Friendly 2026-11-12): the player's list marks a report that got a reply, so
     * one closed after an answer reads apart from one closed with none.
     */
    private function adminRepliedSql(): string
    {
        return 'EXISTS (SELECT 1 FROM ' . $this->db->table(self::MESSAGES) . ' m WHERE m.ticket_id = ' . $this->db->table(self::TABLE) . ".id AND m.author_role = 'admin')";
    }

    /** One of this player's tickets, or null: the only way a player's route reads a ticket. */
    public function mine(int $player, int $ticket): ?array
    {
        return $player > 0 && $ticket > 0 ? $this->db->row('SELECT * FROM ' . $this->db->table(self::TABLE) . ' WHERE id = ? AND player_user_id = ?', [$ticket, $player]) : null;
    }

    /** A ticket's picture row (with its bytes and its player), or null. */
    public function picture(int $ticket): ?array
    {
        return $ticket > 0 ? $this->db->row('SELECT ticket_id, player_user_id, picture, width, height FROM ' . $this->db->table(self::PICTURES) . ' WHERE ticket_id = ?', [$ticket]) : null;
    }

    /** A thread's picture as either side reads it: where it is shown and its size, or null. @return array{url: string, width: int, height: int}|null */
    public function pictureOut(int $ticket): ?array
    {
        $row = $ticket > 0 ? $this->db->row('SELECT width, height FROM ' . $this->db->table(self::PICTURES) . ' WHERE ticket_id = ?', [$ticket]) : null;
        return $row === null ? null : ['url' => TicketPictures::url($ticket), 'width' => (int) $row['width'], 'height' => (int) $row['height']];
    }

    /**
     * A message's pictures, in order from position 0, written in the message's transaction (open, add).
     * As open() writes a report's picture: base64 through FROM_BASE64.
     *
     * @param list<array{bytes: string, width: int, height: int}> $pictures
     */
    private function writePictures(int $ticket, int $message, int $player, array $pictures, string $at): void
    {
        foreach (array_values($pictures) as $i => $p) {
            if ($i >= ReportPicture::MAX_PER_MESSAGE) {
                break;
            }
            $this->db->execute('INSERT INTO ' . $this->db->table(self::MESSAGE_PICTURES) . ' (message_id, position, ticket_id, player_user_id, picture, width, height, bytes, created_at) VALUES (?, ?, ?, ?, FROM_BASE64(?), ?, ?, ?, ?)', [$message, $i, $ticket, $player, base64_encode($p['bytes']), $p['width'], $p['height'], strlen($p['bytes']), $at]);
        }
    }

    /**
     * One of a message's pictures (with its bytes and its player), or null: only when the message is
     * in that ticket. $n counts from 1 (its address); G791's /<ticket>/<message> is $n 1.
     */
    public function messagePicture(int $ticket, int $message, int $n = 1): ?array
    {
        if ($ticket <= 0 || $message <= 0 || $n < 1 || $n > ReportPicture::MAX_PER_MESSAGE) {
            return null;
        }
        return $this->db->row('SELECT message_id, position, ticket_id, player_user_id, picture, width, height FROM ' . $this->db->table(self::MESSAGE_PICTURES) . ' WHERE message_id = ? AND position = ? AND ticket_id = ?', [$message, $n - 1, $ticket]);
    }

    /**
     * A thread's message pictures by message id, in order, as either side reads them: where each
     * is shown and its size (picturesOut). The report's own picture is pictureOut().
     * @return array<int, list<array{url: string, width: int, height: int}>>
     */
    public function messagePictures(int $ticket): array
    {
        if ($ticket <= 0) {
            return [];
        }
        return self::picturesOut($ticket, $this->db->rows('SELECT message_id, position, width, height FROM ' . $this->db->table(self::MESSAGE_PICTURES) . ' WHERE ticket_id = ? ORDER BY message_id ASC, position ASC', [$ticket]));
    }

    /**
     * PURE: picture rows (message_id, position, width, height) as each message's list, in order:
     * {url, width, height}, the url /report-pictures/<ticket>/<message>/<n> (n = position + 1).
     * @return array<int, list<array{url: string, width: int, height: int}>>
     */
    public static function picturesOut(int $ticket, array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $n = (int) ($r['position'] ?? 0) + 1;
            if ($n < 1 || $n > ReportPicture::MAX_PER_MESSAGE) {
                continue;
            }
            $out[(int) $r['message_id']][] = ['url' => TicketPictures::url($ticket, (int) $r['message_id'], $n), 'width' => (int) $r['width'], 'height' => (int) $r['height']];
        }
        return $out;
    }

    /**
     * G791's rows (one picture a reply, OLD_MESSAGE_PICTURES) copied into MESSAGE_PICTURES at
     * position 0; safe to run again (a message's position 0 is kept once). Returns the rows copied,
     * 0 when the old table is not there (bin/message-pictures.php runs it).
     */
    public function adoptOldPictures(): int
    {
        $old = $this->db->table(self::OLD_MESSAGE_PICTURES);
        if ((int) $this->db->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$old]) !== 1) {
            return 0;
        }
        return $this->db->execute('INSERT IGNORE INTO ' . $this->db->table(self::MESSAGE_PICTURES) . " (message_id, position, ticket_id, player_user_id, picture, width, height, bytes, created_at) SELECT message_id, 0, ticket_id, player_user_id, picture, width, height, bytes, created_at FROM {$old}");
    }

    /** Pictures a player sent with messages in the last $seconds (the picture limit; a new report's own picture has the report's). */
    public function picturesSince(int $player, int $seconds, int $now): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM ' . $this->db->table(self::MESSAGE_PICTURES) . ' WHERE player_user_id = ? AND created_at >= ?', [$player, self::stamp($now - $seconds)]);
    }

    /** A ticket's first message (the report itself), or null. */
    public function first(int $ticket): ?array
    {
        return $ticket > 0 ? $this->db->row('SELECT id, ticket_id, author_user_id, author_role, body, created_at, edited_at FROM ' . $this->db->table(self::MESSAGES) . ' WHERE ticket_id = ? ORDER BY id ASC LIMIT 1', [$ticket]) : null;
    }

    /** A thread's messages, oldest first (the newest THREAD_MAX). @return list<array<string, mixed>> */
    public function messages(int $ticket): array
    {
        $rows = $this->db->rows('SELECT id, author_user_id, author_role, body, created_at, edited_at FROM ' . $this->db->table(self::MESSAGES) . ' WHERE ticket_id = ? ORDER BY id DESC LIMIT ' . self::THREAD_MAX, [$ticket]);
        return array_reverse($rows);
    }

    /**
     * A thread's earlier texts by message id, oldest first (administrators only: the player sees "edited").
     * @return array<int, list<array<string, mixed>>>
     */
    public function revisions(int $ticket): array
    {
        $out = [];
        foreach ($this->db->rows('SELECT message_id, body, written_at, replaced_at FROM ' . $this->db->table(self::REVISIONS) . ' WHERE ticket_id = ? ORDER BY id ASC', [$ticket]) as $r) {
            $out[(int) $r['message_id']][] = $r;
        }
        return $out;
    }

    /**
     * A player's tickets as their Reports list orders them (ticket 115, Friendly 2026-11-06), the
     * administrators' list turned to the player's side (listAll): a reply they have not read first,
     * then answered, open, working, closed, the newest news first in each. The game sorts the same
     * way (js/reports.js sortReports), so the LIST_MAX cut keeps the ones that need them.
     * @return list<array<string, mixed>>
     */
    public function listFor(int $player): array
    {
        $unread = "(last_author_role = 'admin' AND (player_read_at IS NULL OR player_read_at < last_message_at))";
        return $this->db->rows('SELECT *, ' . $unread . ' AS player_unread, ' . $this->adminRepliedSql() . ' AS admin_replied FROM ' . $this->db->table(self::TABLE) . " WHERE player_user_id = ? ORDER BY player_unread DESC, FIELD(status, 'answered', 'open', 'working', 'closed'), updated_at DESC, id DESC LIMIT " . self::LIST_MAX, [$player]);
    }

    /** How many of a player's tickets have an administrator's reply they have not read. */
    public function unreadCount(int $player): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM ' . $this->db->table(self::TABLE) . " WHERE player_user_id = ? AND last_author_role = 'admin' AND (player_read_at IS NULL OR player_read_at < last_message_at)", [$player]);
    }

    /**
     * Every ticket for the administrators: unread first, then open, working,
     * answered, closed, the newest news first in each. $status narrows it to one.
     * A page of LIST_MAX from $offset (the console's Show older asks with the
     * rows it has); more: a later page holds some; total: all for $status.
     *
     * @return array{rows: list<array<string, mixed>>, more: bool, total: int}
     */
    public function listAll(?string $status, int $offset = 0): array
    {
        $offset = max(0, $offset);
        $where = $status !== null && in_array($status, self::STATUSES, true) ? 'WHERE status = ?' : '';
        $args = $where === '' ? [] : [$status];
        $t = $this->db->table(self::TABLE);
        $unread = "(last_author_role = 'player' AND (admin_read_at IS NULL OR admin_read_at < last_message_at))";
        $rows = $this->db->rows('SELECT *, ' . $unread . " AS admin_unread FROM {$t} {$where} ORDER BY admin_unread DESC, FIELD(status, 'open', 'working', 'answered', 'closed'), last_message_at DESC, id DESC LIMIT " . (self::LIST_MAX + 1) . ' OFFSET ' . $offset, $args);
        return [
            'rows' => array_slice($rows, 0, self::LIST_MAX),
            'more' => count($rows) > self::LIST_MAX,
            'total' => (int) $this->db->value("SELECT COUNT(*) FROM {$t} {$where}", $args),
        ];
    }

    /** Tickets by status, and how many hold a player's message no administrator has read. @return array<string, int> */
    public function counts(): array
    {
        $out = [self::OPEN => 0, self::ANSWERED => 0, self::WORKING => 0, self::CLOSED => 0, 'unread' => 0];
        $t = $this->db->table(self::TABLE);
        foreach ($this->db->rows("SELECT status, COUNT(*) AS n FROM {$t} GROUP BY status") as $r) {
            if (isset($out[(string) $r['status']])) {
                $out[(string) $r['status']] = (int) $r['n'];
            }
        }
        $out['unread'] = (int) $this->db->value("SELECT COUNT(*) FROM {$t} WHERE last_author_role = 'player' AND (admin_read_at IS NULL OR admin_read_at < last_message_at)");
        return $out;
    }

    /** Tickets a player opened in the last $seconds (the new-report limit, as BugReports counted its posts). */
    public function openedSince(int $player, int $seconds, int $now): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM ' . $this->db->table(self::TABLE) . ' WHERE player_user_id = ? AND created_at >= ?', [$player, self::stamp($now - $seconds)]);
    }

    /** Messages an account wrote in the last $seconds (the reply limit). */
    public function writtenSince(int $author, int $seconds, int $now): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM ' . $this->db->table(self::MESSAGES) . ' WHERE author_user_id = ? AND created_at >= ?', [$author, self::stamp($now - $seconds)]);
    }

    // —— Shapes ——

    /** A ticket as a list shows it, for $role. */
    public static function summary(array $row, string $role): array
    {
        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'subject' => (string) $row['subject'],
            'createdAt' => SaveShape::time((string) $row['created_at']),
            'updatedAt' => SaveShape::time((string) $row['updated_at']),
            'lastMessageAt' => SaveShape::time((string) $row['last_message_at']),
            'lastAuthor' => (string) $row['last_author_role'],
            'unread' => self::unreadFor($row, $role),
            // #225: an administrator wrote in it at some point (rows from find and listFor carry it).
            'adminReplied' => !empty($row['admin_replied']),
            // Read by the team: when an administrator first opened it after the player's latest message, or null.
            'readByTeamAt' => self::readByTeamAt($row),
        ];
    }

    /**
     * A thread's messages as either side reads them: who wrote is a role, never a username.
     * $pictures: messagePictures(); each message carries its pictures (a list, maybe empty) and,
     * for older readers (G791's games, the console before 2026-11-15), "picture": the first of
     * them at G791's address /report-pictures/<ticket>/<message>, or null.
     * @return list<array<string, mixed>>
     */
    public static function messagesOut(array $rows, ?array $revisions = null, array $pictures = []): array
    {
        $out = [];
        foreach ($rows as $m) {
            $row = [
                'id' => (int) $m['id'],
                'author' => (string) $m['author_role'] === self::ADMIN ? self::ADMIN : self::PLAYER,
                'body' => (string) $m['body'],
                'at' => SaveShape::time((string) $m['created_at']),
                // When the player last changed its words (ticket 49), or null.
                'edited' => ($m['edited_at'] ?? null) ? SaveShape::time((string) $m['edited_at']) : null,
                // Up to four pictures ({url, width, height}) in order; the first again as "picture" (G791), or null.
                'pictures' => $pictures[(int) $m['id']] ?? [],
                'picture' => self::firstPicture($pictures[(int) $m['id']] ?? []),
            ];
            // Administrators: each earlier text, oldest first ($revisions from revisions(); null for the player).
            if ($revisions !== null) {
                $row['revisions'] = array_map(static fn (array $r): array => [
                    'body' => (string) $r['body'],
                    'at' => SaveShape::time((string) $r['written_at']),
                    'replaced' => SaveShape::time((string) $r['replaced_at']),
                ], $revisions[(int) $m['id']] ?? []);
            }
            $out[] = $row;
        }
        return $out;
    }

    /** A message's first picture at G791's address (/report-pictures/<ticket>/<message>, which serves it too), or null. */
    private static function firstPicture(array $list): ?array
    {
        $p = $list[0] ?? null;
        if (!is_array($p)) {
            return null;
        }
        return ['url' => (string) preg_replace('#/1$#', '', (string) $p['url'])] + $p;
    }

    /** The stored context, decoded (BugReports::context's keys). @return array<string, mixed> */
    public static function contextOf(array $row): array
    {
        $ctx = json_decode((string) ($row['context'] ?? ''), true);
        return is_array($ctx) ? $ctx : [];
    }

    public static function contextJson(array $context): string
    {
        return (string) json_encode($context === [] ? new \stdClass() : $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function stamp(int $at): string
    {
        return gmdate('Y-m-d H:i:s', $at);
    }
}
