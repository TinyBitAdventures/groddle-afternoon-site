<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * Notice Boards shared by everyone on a street (Austin 2026-11-04): in the
 * original a board was one bag in the location, so every player there read
 * the same ten notes (GameServerJS items/bag_notice_board.js: capacity 10 in
 * onCreate 155, addNote 127-148, takeNote 169-184, updateStatus 186-221 with
 * each note's last editor). The realtime server (groddle-afternoon-server
 * boards.go) decides who is on the street and who asked first; this keeps the
 * notes and moves the Note through the ledger (Ledger, G335), as ground drops
 * do (LedgerDrop):
 *
 *   POST groddle/v1/ledger/board/pin   (the realtime server, signed: ServiceCall)
 *        {"op":"board-<16 hex>","id":7,"street":"GA5…","board":0,"title":"…","body":"…","author":9,"remake":false,"capacity":10}
 *        200 {"status":"settled","note":Note} | 409 {"status":"refused","why":"full|short","text":"…"}
 *
 *   The board's row (groddle_boards) is locked and its pinned notes counted,
 *   so two pins at once never make eleven; the Note leaves the poster's
 *   ledger, recorded as a settled trade with nobody (b_id 0, as LedgerDrop),
 *   and the note waits in groddle_board_notes, in nobody's ledger. Asking
 *   again for the same op answers as recorded.
 *
 *   POST groddle/v1/ledger/board/take  (signed) {"op":"board-…","id":9}
 *        200 {"status":"settled","op":"board-…-t","note":Note} | 409 {"status":"refused","why":"gone","by":9}
 *
 *   The first take has it: the Note goes into the taker's ledger (a settled
 *   credit with nobody, <op>-t). Asking again for the same taker answers as
 *   recorded; anyone else hears it is gone (by 0: an administrator removed it).
 *
 *   POST groddle/v1/ledger/boards      (signed) {"seeds":{"GA5…":[{"title","body"}]}}  → {"notes":[Note + street, board]}
 *        every note on every board, for a realtime server that has just started. A board
 *        not seeded yet gets its opening notices first, once (the remake's own words, from
 *        the game's BOARD_PINS through the server's data/boards.json, author nobody,
 *        remake 1); after that they are anyone's to take.
 *
 *   GET  groddle/v1/boards/mine        the signed-in player's pins and takes their save has not applied
 *        {"boards":[{"op","side":"pinned"|"took","id","note":Note}]}
 *
 *   POST groddle/v1/boards/notes/{id}/report  {"why":"…"}  (signed in)
 *        201 {"status":"reported","ticket":id} | 200 {"status":"already"}
 *
 *   A player's report of a note on a board is a ticket like any bug report
 *   (TicketStore, the game's Reports sheet; the console's Reports tab), with
 *   the note's words in it, under the same limits as a new report; each
 *   player reports a note once (groddle_board_reports). The console's Boards
 *   tab (AdminBoards) lists notes, reported first, and removes one.
 *
 *   Note: {"id","title","body","author":{"id","name"}|null,"remake":bool,"at"(ms)}
 *
 * A note's writer (note.js last_editor, which a board keeps): the poster,
 * unless the realtime server says the game names another player and that
 * player's very words reached the poster unchanged, from a take off a board or
 * a gift (authorOf). Words: LedgerGift::text (no tags or control characters,
 * 150 / 1000 characters, the word filter), after the server's own filter.
 *
 * Each save applies a pin or a take through the trade slice's applied list
 * (features/boards-mp-feature.js), and a load applies a missed one to the
 * stored save (Ledger::catchUp → catchUp here): the note in or out of the
 * street-furniture slice's carried notes, with its words.
 */
final readonly class LedgerBoard
{
    public const TABLE = 'groddle_board_notes';
    public const BOARDS = 'groddle_boards';
    public const REPORTS = 'groddle_board_reports';

    public const PIN = '/groddle/v1/ledger/board/pin';
    public const TAKE = '/groddle/v1/ledger/board/take';
    public const LOAD = '/groddle/v1/ledger/boards';

    /** bag_notice_board.js onCreate 155. */
    public const CAPACITY = 10;

    /** note.js 246-247. */
    public const TITLE = 150;
    public const BODY = 1000;

    public const NOTE = 'note';

    /** Notes one load carries (8 boards of 10 today). */
    public const LOAD_MAX = 2000;

    /** A report's own words, at most. */
    public const WHY_MAX = 500;

    public const TEXT_FULL = "The notice board is already full! You'll have to remove a note before you can add another."; // bag_notice_board.js:130
    public const TEXT_SHORT = 'You have no notes to post!'; // bag_notice_board.js:114

    private const NONE = '{"items":[],"currants":0}';
    private const ONE = '{"items":[{"c":"note","n":1}],"currants":0}';

    public function __construct(private Seams $minn)
    {
    }

    // —— The realtime server ——

    #[Route(Method::Post, self::PIN, policy: new Policy(Access::Public))]
    public function pinRoute(Request $request): Response
    {
        $this->signed($request, self::PIN);
        $body = json_decode($request->body, true);
        $pin = is_array($body) ? self::call($body) : null;
        if ($pin === null) {
            throw new RestError('groddle_ledger_invalid', 'Not a pin.', 400);
        }
        $out = Ledger::atomically($this->minn->db, fn () => $this->pin($pin, time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    #[Route(Method::Post, self::TAKE, policy: new Policy(Access::Public))]
    public function takeRoute(Request $request): Response
    {
        $this->signed($request, self::TAKE);
        $body = json_decode($request->body, true);
        $op = is_array($body) ? ($body['op'] ?? null) : null;
        $id = is_array($body) ? ($body['id'] ?? null) : null;
        if (!self::noteId($op) || !is_int($id) || $id <= 0) {
            throw new RestError('groddle_ledger_invalid', 'Not a take.', 400);
        }
        $out = Ledger::atomically($this->minn->db, fn () => $this->take($op, $id, time()));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    #[Route(Method::Post, self::LOAD, policy: new Policy(Access::Public))]
    public function loadRoute(Request $request): Response
    {
        $this->signed($request, self::LOAD);
        $body = json_decode($request->body, true);
        $seeds = self::seedsOf(is_array($body) ? ($body['seeds'] ?? null) : null);
        $now = time();
        foreach ($seeds as $street => $list) {
            Ledger::atomically($this->minn->db, fn () => $this->seed($street, $list, $now));
        }
        $db = $this->minn->db;
        $rows = $db->rows('SELECT id, street, board, author_id, remake, title, body, created_at FROM ' . $db->table(self::TABLE) . " WHERE status = 'pinned' ORDER BY created_at, id LIMIT " . self::LOAD_MAX);
        $names = $this->names($rows);
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::loadRow($r, $names);
        }
        return Response::json(['notes' => $out]);
    }

    /**
     * A pin as the realtime server sends it, checked: null when it is not one.
     *
     * @return array{op: string, id: int, street: string, board: int, title: string, body: string, author: int, remake: bool, capacity: int}|null
     */
    public static function call(array $body): ?array
    {
        $op = $body['op'] ?? null;
        $id = $body['id'] ?? null;
        $street = $body['street'] ?? null;
        $board = $body['board'] ?? 0;
        $author = $body['author'] ?? 0;
        $capacity = $body['capacity'] ?? self::CAPACITY;
        if (!self::opId($op) || !is_int($id) || $id <= 0 || !is_string($street) || preg_match(AdminConsole::STREET, $street) !== 1 || str_contains($street, '_')
            || $board !== 0 || !is_int($author) || $author < 0 || !is_int($capacity) || !is_string($body['title'] ?? '') || !is_string($body['body'] ?? '')) {
            return null;
        }
        return [
            'op' => $op, 'id' => $id, 'street' => $street, 'board' => 0,
            'title' => LedgerGift::text($body['title'] ?? '', self::TITLE, false),
            'body' => LedgerGift::text($body['body'] ?? '', self::BODY, true),
            'author' => $author === $id ? 0 : $author,
            'remake' => ($body['remake'] ?? false) === true,
            // The server's ten, never more than the source's.
            'capacity' => max(1, min(self::CAPACITY, $capacity)),
        ];
    }

    /** Whether a board holding $count notes has room for one more. */
    public static function hasRoom(int $count, int $capacity = self::CAPACITY): bool
    {
        return $count < max(1, min(self::CAPACITY, $capacity));
    }

    /**
     * Who wrote a pinned note: nobody for the remake's own words; the claimed writer
     * when that player's words reached the poster unchanged ($reached: a take or a gift
     * was found); else the poster.
     */
    public static function writer(int $poster, int $claim, bool $remake, bool $reached): int
    {
        if ($remake) {
            return 0;
        }
        return $claim > 0 && $claim !== $poster && $reached ? $claim : $poster;
    }

    /** @return array{status: string, why?: string, text?: string, note?: array} */
    private function pin(array $pin, int $now): array
    {
        $db = $this->minn->db;
        $notes = $db->table(self::TABLE);
        $t = $db->table(Ledger::TRADES);
        $had = $db->row("SELECT id, street, board, author_id, remake, title, body, created_at FROM {$notes} WHERE id = ?", [$pin['op']]);
        if ($had !== null) {
            return ['status' => 'settled', 'note' => self::loadRow($had, $this->names([$had]))];
        }
        $held = $db->row("SELECT status FROM {$t} WHERE trade_id = ? FOR UPDATE", [$pin['op']]);
        if ($held !== null) {
            return ['status' => 'refused', 'why' => 'short', 'text' => self::TEXT_SHORT];
        }
        $ledger = Ledger::fromSeams($this->minn);
        // The board's own row is the lock: two pins at once count one after the other.
        $boards = $db->table(self::BOARDS);
        $db->execute("INSERT IGNORE INTO {$boards} (street, board, seeded_at) VALUES (?, ?, NULL)", [$pin['street'], $pin['board']]);
        $db->row("SELECT street FROM {$boards} WHERE street = ? AND board = ? FOR UPDATE", [$pin['street'], $pin['board']]);
        $count = (int) $db->value("SELECT COUNT(*) FROM {$notes} WHERE street = ? AND board = ? AND status = 'pinned'", [$pin['street'], $pin['board']]);
        if (!self::hasRoom($count, $pin['capacity'])) {
            $ledger->log($pin['id'], 'refuse', ['board' => $pin['op'], 'why' => 'full', 'street' => $pin['street']], $now);
            return ['status' => 'refused', 'why' => 'full', 'text' => self::TEXT_FULL];
        }
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $state = $ledger->lock($pin['id'], $now);
        if (($state['items'][self::NOTE] ?? 0) < 1) {
            // Recorded void, so asking again answers the same.
            $db->execute("INSERT INTO {$t} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'void', ?, 0, ?, ?, NULL, ?)", [$pin['op'], $pin['id'], self::ONE, self::NONE, $stamp]);
            $ledger->log($pin['id'], 'refuse', ['board' => $pin['op'], 'why' => 'short'], $now);
            return ['status' => 'refused', 'why' => 'short', 'text' => self::TEXT_SHORT];
        }
        self::move($db, $pin['id'], $state, -1, $now);
        $db->execute("INSERT INTO {$t} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$pin['op'], $pin['id'], self::ONE, self::NONE, $stamp, $stamp]);
        $author = self::writer($pin['id'], $pin['author'], $pin['remake'], $pin['author'] > 0 && $this->reached($pin['id'], $pin['author'], $pin['title'], $pin['body']));
        $db->execute("INSERT INTO {$notes} (id, street, board, poster_id, author_id, remake, title, body, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pinned', ?)", [
            $pin['op'], $pin['street'], $pin['board'], $pin['id'], $author, $pin['remake'] ? 1 : 0, $pin['title'], $pin['body'], $stamp,
        ]);
        $ledger->log($pin['id'], 'board_pin', ['board' => $pin['op'], 'street' => $pin['street'], 'author' => $author, 'gave' => [self::NOTE => 1]], $now);
        $row = ['id' => $pin['op'], 'street' => $pin['street'], 'board' => $pin['board'], 'author_id' => $author, 'remake' => $pin['remake'] ? 1 : 0, 'title' => $pin['title'], 'body' => $pin['body'], 'created_at' => $stamp];
        return ['status' => 'settled', 'note' => self::loadRow($row, $this->names([$row]))];
    }

    /** Whether $writer's very words reached $poster: a take of their note off a board, or their gift. */
    private function reached(int $poster, int $writer, string $title, string $body): bool
    {
        $db = $this->minn->db;
        $took = $db->value('SELECT 1 FROM ' . $db->table(self::TABLE) . " WHERE taker_id = ? AND author_id = ? AND status = 'taken' AND title = ? AND body = ? LIMIT 1", [$poster, $writer, $title, $body]);
        if ($took !== null) {
            return true;
        }
        return $db->value('SELECT 1 FROM ' . $db->table(LedgerGift::TABLE) . ' WHERE to_id = ? AND from_id = ? AND note_title = ? AND note_body = ? LIMIT 1', [$poster, $writer, $title, $body]) !== null;
    }

    /** @return array{status: string, op?: string, note?: array, why?: string, by?: int} */
    private function take(string $id, int $taker, int $now): array
    {
        $db = $this->minn->db;
        $notes = $db->table(self::TABLE);
        $row = $db->row("SELECT id, street, board, author_id, remake, title, body, status, taker_id, created_at FROM {$notes} WHERE id = ? FOR UPDATE", [$id]);
        if ($row === null) {
            return ['status' => 'refused', 'why' => 'gone', 'by' => 0];
        }
        $credit = $id . '-t';
        $note = self::loadRow($row, $this->names([$row]));
        if ($row['status'] !== 'pinned') {
            return (int) $row['taker_id'] === $taker && $row['status'] === 'taken'
                ? ['status' => 'settled', 'op' => $credit, 'note' => $note]
                : ['status' => 'refused', 'why' => 'gone', 'by' => (int) $row['taker_id']];
        }
        $ledger = Ledger::fromSeams($this->minn);
        $state = $ledger->lock($taker, $now);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        self::move($db, $taker, $state, 1, $now);
        $db->execute('INSERT IGNORE INTO ' . $db->table(Ledger::TRADES) . " (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$credit, $taker, self::NONE, self::ONE, $stamp, $stamp]);
        $db->execute("UPDATE {$notes} SET status = 'taken', taker_id = ?, taken_at = ? WHERE id = ?", [$taker, $stamp, $id]);
        $ledger->log($taker, 'board_take', ['board' => $id, 'street' => (string) $row['street'], 'author' => (int) $row['author_id'], 'took' => [self::NOTE => 1]], $now);
        return ['status' => 'settled', 'op' => $credit, 'note' => $note];
    }

    /** The opening notices of one board, once. */
    private function seed(string $street, array $list, int $now): void
    {
        $db = $this->minn->db;
        $boards = $db->table(self::BOARDS);
        $db->execute("INSERT IGNORE INTO {$boards} (street, board, seeded_at) VALUES (?, 0, NULL)", [$street]);
        $row = $db->row("SELECT seeded_at FROM {$boards} WHERE street = ? AND board = 0 FOR UPDATE", [$street]);
        if ($row === null || $row['seeded_at'] !== null) {
            return;
        }
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $notes = $db->table(self::TABLE);
        foreach ($list as $i => $n) {
            $db->execute("INSERT IGNORE INTO {$notes} (id, street, board, poster_id, author_id, remake, title, body, status, created_at) VALUES (?, ?, 0, 0, 0, 1, ?, ?, 'pinned', ?)", [self::seedId($street, $i), $street, $n['title'], $n['body'], $stamp]);
        }
        $db->execute("UPDATE {$boards} SET seeded_at = ? WHERE street = ? AND board = 0", [$stamp, $street]);
    }

    /**
     * The server's opening notices, checked: street => [{title, body}], at most CAPACITY each.
     *
     * @return array<string, list<array{title: string, body: string}>>
     */
    public static function seedsOf(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $street => $list) {
            if (!is_string($street) || preg_match('/^[A-Z0-9]{1,32}$/', $street) !== 1 || !is_array($list) || !array_is_list($list)) {
                continue;
            }
            $notes = [];
            foreach (array_slice($list, 0, self::CAPACITY) as $n) {
                if (is_array($n)) {
                    $notes[] = ['title' => LedgerGift::text($n['title'] ?? '', self::TITLE, false), 'body' => LedgerGift::text($n['body'] ?? '', self::BODY, true)];
                }
            }
            $out[$street] = $notes;
        }
        ksort($out);
        return $out;
    }

    /** An opening notice's id (boards.go boardSeedRe). */
    public static function seedId(string $street, int $i): string
    {
        return "board-seed-{$street}-{$i}";
    }

    // —— The player ——

    /** The signed-in player's pins and takes their save has not applied, oldest first (a missed answer). */
    #[Route(Method::Get, '/groddle/v1/boards/mine', policy: new Policy(Access::SignedIn))]
    public function mine(Request $request): Response
    {
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        $since = gmdate('Y-m-d H:i:s', time() - Ledger::PENDING_FOR);
        $rows = $db->rows('SELECT t.trade_id, n.id, n.street, n.board, n.author_id, n.remake, n.title, n.body, n.created_at FROM ' . $db->table(Ledger::TRADES) . ' t JOIN ' . $db->table(self::TABLE) . " n ON (t.trade_id = n.id OR t.trade_id = CONCAT(n.id, '-t'))
            WHERE t.status = 'settled' AND t.a_id = ? AND t.a_applied_at IS NULL AND t.created_at >= ? AND t.trade_id LIKE 'board-%'
            ORDER BY t.created_at, t.trade_id LIMIT 40", [$me, $since]);
        $names = $this->names($rows);
        $out = [];
        foreach ($rows as $r) {
            $op = (string) $r['trade_id'];
            $out[] = ['op' => $op, 'side' => str_ends_with($op, '-t') ? 'took' : 'pinned', 'id' => (string) $r['id'], 'note' => self::noteOut($r, $names)];
        }
        return Response::json(['boards' => $out])->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Post, '/groddle/v1/boards/notes/{id:[A-Za-z0-9-]+}/report', policy: new Policy(Access::SignedIn))]
    public function report(Request $request, string $id): Response
    {
        $user = $this->minn->user();
        if ($user === null) {
            throw new RestError('groddle_feedback_signed_out', 'Sign in to send us feedback.', 401);
        }
        if (!self::noteId($id)) {
            throw new RestError('groddle_board_no_note', 'That note is no longer on the board.', 404);
        }
        $db = $this->minn->db;
        $row = $db->row('SELECT id, street, board, poster_id, author_id, remake, title, body, status, created_at FROM ' . $db->table(self::TABLE) . ' WHERE id = ?', [$id]);
        if ($row === null || $row['status'] === 'removed') {
            throw new RestError('groddle_board_no_note', 'That note is no longer on the board.', 404);
        }
        $reports = $db->table(self::REPORTS);
        if ($db->value("SELECT 1 FROM {$reports} WHERE note_id = ? AND reporter_id = ?", [$id, $user->id]) !== null) {
            return Response::json(['status' => 'already']);
        }
        $store = new TicketStore($db);
        $now = time();
        if ($store->openedSince($user->id, BugReports::WINDOW, $now) >= BugReports::PER_WINDOW || $store->openedSince($user->id, 86400, $now) >= BugReports::PER_DAY) {
            throw new RestError('groddle_feedback_slow', 'Thanks! You have sent a few reports just now. Try again in a little while.', 429);
        }
        $body = $request->json();
        $why = BugReports::clean(is_string($body['why'] ?? null) ? $body['why'] : '', self::WHY_MAX, true);
        $street = (string) $row['street'];
        $label = (string) ($this->streetIndex()[$street]['label'] ?? $street);
        $names = $this->names([$row]);
        $author = (int) $row['author_id'];
        $by = (int) $row['remake'] === 1 ? 'the remake' : ($names[$author] ?? 'Someone');
        $message = self::reportMessage($label, $id, (string) $row['title'], (string) $row['body'], $by, $why);
        $context = BugReports::context(['street' => $street, 'streetName' => $label, 'page' => "board-note:{$id}"]);
        $ticket = $store->open($user->id, $message, $context, $now);
        if ($ticket <= 0) {
            throw new RestError('groddle_feedback_failed', 'That didn\'t send. Try again in a moment.', 500);
        }
        $made = $db->execute("INSERT IGNORE INTO {$reports} (note_id, reporter_id, ticket_id, created_at) VALUES (?, ?, ?, ?)", [$id, $user->id, $ticket, gmdate('Y-m-d H:i:s', $now)]);
        if ($made > 0) {
            $db->execute('UPDATE ' . $db->table(self::TABLE) . ' SET reports = reports + 1 WHERE id = ?', [$id]);
        }
        return Response::json(['status' => 'reported', 'ticket' => $ticket], 201);
    }

    /** A report's first message: where, which note, its words, and why (the player's own, when given). */
    public static function reportMessage(string $label, string $id, string $title, string $body, string $by, string $why): string
    {
        $lines = ["I am reporting a note on the notice board in {$label}.", '', 'Title: ' . ($title === '' ? '(none)' : $title), "Written by: {$by}", '', $body === '' ? '(The note is blank.)' : $body, ''];
        $lines[] = $why === '' ? 'Why: (no reason given)' : "Why: {$why}";
        $lines[] = "Note: {$id}";
        return mb_substr(implode("\n", $lines), 0, BugReports::MAX_MESSAGE);
    }

    // —— A load (Ledger::catchUp) ——

    /** A pin or a take of a board note (boards.go board-), as Ledger::catchUp sees its trade id. */
    public static function isBoard(string $trade): bool
    {
        return preg_match('/^board-([0-9a-f]{16}|seed-[A-Z0-9]{1,32}-[0-9]{1,2})(-t)?$/', $trade) === 1;
    }

    /** Applies one pending pin or take to a stored save: the note out of, or into, the carried notes. */
    public static function catchUp(Db $db, array $entries, int $user, array $p): array
    {
        $trade = $p['trade'];
        $took = str_ends_with($trade, '-t');
        $id = $took ? substr($trade, 0, -2) : $trade;
        $r = $db->row('SELECT id, street, board, author_id, remake, title, body, created_at FROM ' . $db->table(self::TABLE) . ' WHERE id = ?', [$id]);
        if ($r === null) {
            return LedgerMath::applyTrade($entries, $trade, $p['gave'], $p['got']);
        }
        $names = (new Characters($db))->namesOf(array_values(array_filter([(int) $r['author_id']])));
        return self::apply($entries, $trade, $took ? 'took' : 'pinned', self::noteOut($r, $names), $user);
    }

    /**
     * The game's own apply (features/boards-mp-feature.js), on a stored save: a pin takes the
     * carried note with those words out of the street-furniture slice (or a blank one) and
     * one Note out of the pack; a take puts the note in with its words and its writer.
     *
     * @param array{id: string, title: string, body: string, author: ?array{id: int, name: string}, remake: bool, at: int} $note
     */
    public static function apply(array $entries, string $op, string $side, array $note, int $user): array
    {
        $none = ['items' => [], 'currants' => 0];
        $raw = $entries['playerState'] ?? null;
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, false) : null;
        if (!$state instanceof \stdClass || in_array($op, LedgerMath::applied($entries), true)) {
            return $entries;
        }
        foreach (['inventory', 'systems'] as $k) {
            if (!($state->{$k} ?? null) instanceof \stdClass) {
                $state->{$k} = new \stdClass();
            }
        }
        if (!($state->systems->features ?? null) instanceof \stdClass) {
            $state->systems->features = new \stdClass();
        }
        $features = $state->systems->features;
        if (!($features->{LedgerGift::NOTES_SLICE} ?? null) instanceof \stdClass) {
            $features->{LedgerGift::NOTES_SLICE} = new \stdClass();
        }
        $slice = $features->{LedgerGift::NOTES_SLICE};
        $carried = is_array($slice->carried ?? null) ? $slice->carried : [];
        $inv = $state->inventory;
        $had = LedgerMath::count($inv->{self::NOTE} ?? 0);
        if ($side === 'pinned') {
            $at = null;
            foreach ($carried as $i => $n) {
                if ($at === null && is_object($n) && self::shows($n, $note['title'], $note['body'])) {
                    $at = $i;
                }
            }
            if ($at !== null) {
                array_splice($carried, $at, 1);
            }
            $inv->{self::NOTE} = max(0, $had - 1);
        } else {
            $next = max(1, (int) ($slice->nextNoteId ?? 0), count($carried) + 1);
            $carried[] = (object) self::carriedNote($note, 'n' . $next, $user);
            $slice->nextNoteId = $next + 1;
            $inv->{self::NOTE} = $had + 1;
        }
        $slice->carried = array_values($carried);
        $entries['playerState'] = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // Remembered as applied in the trade slice (the counts already moved above).
        return LedgerMath::applyTrade($entries, $op, $none, $none);
    }

    /**
     * A note taken off a board as the game carries it (js/boards-mp.js carriedFromBoard): the
     * remake's own words as its initial text, marked; anyone else's with their name.
     *
     * @return array<string, mixed>
     */
    public static function carriedNote(array $note, string $id, int $user): array
    {
        if ($note['remake']) {
            return ['id' => $id, 'initialTitle' => $note['title'], 'initialText' => $note['body'], 'title' => '', 'body' => '', 'remakeText' => true, 'lastEditor' => null, 'lastEdited' => 0, 'history' => []];
        }
        $author = $note['author'];
        $mine = $author === null || $author['id'] === $user;
        $out = ['id' => $id, 'initialTitle' => '', 'initialText' => '', 'title' => $note['title'], 'body' => $note['body'], 'lastEditor' => $mine ? 'player' : 'player:' . $author['id'], 'lastEdited' => intdiv($note['at'], 1000), 'history' => []];
        if (!$mine) {
            $out['authorName'] = $author['name'];
        }
        return $out;
    }

    /** Whether a carried note shows these words (js/street-furniture.js noteContent: initial text wins). */
    private static function shows(object $n, string $title, string $body): bool
    {
        $t = (string) (($n->initialTitle ?? '') !== '' ? $n->initialTitle : ($n->title ?? ''));
        $b = (string) (($n->initialText ?? '') !== '' ? $n->initialText : ($n->body ?? ''));
        return $t === $title && $b === $body;
    }

    // —— Helpers ——

    /**
     * A note row as the realtime server reads it (boards.go boardRow): the writer as an id and a
     * name (0 and "" for nobody), when in unix seconds.
     *
     * @param array<string, mixed> $r
     * @param array<int, string> $names
     * @return array{id: string, street: string, board: int, title: string, body: string, author: int, name: string, remake: bool, at: int}
     */
    public static function loadRow(array $r, array $names): array
    {
        $n = self::noteOut($r, $names);
        return ['id' => $n['id'], 'street' => (string) $r['street'], 'board' => (int) $r['board'], 'title' => $n['title'], 'body' => $n['body'],
            'author' => $n['author']['id'] ?? 0, 'name' => $n['author']['name'] ?? '', 'remake' => $n['remake'], 'at' => intdiv($n['at'], 1000)];
    }

    /**
     * A note row as the game reads it (js/boards-mp.js normalizeBoardNote).
     *
     * @param array<string, mixed> $r
     * @param array<int, string> $names
     * @return array{id: string, title: string, body: string, author: ?array{id: int, name: string}, remake: bool, at: int}
     */
    public static function noteOut(array $r, array $names): array
    {
        $author = (int) ($r['author_id'] ?? 0);
        $remake = (int) ($r['remake'] ?? 0) === 1;
        return [
            'id' => (string) $r['id'], 'title' => (string) $r['title'], 'body' => (string) $r['body'],
            'author' => $author > 0 && !$remake ? ['id' => $author, 'name' => $names[$author] ?? 'Someone'] : null,
            'remake' => $remake,
            'at' => (int) (strtotime((string) $r['created_at'] . ' UTC') * 1000),
        ];
    }

    /** boards.go: board- and 16 hex. */
    public static function opId(mixed $op): bool
    {
        return is_string($op) && preg_match('/^board-[0-9a-f]{16}$/', $op) === 1;
    }

    /** A note on a board: a player's pin or an opening notice. */
    public static function noteId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^board-([0-9a-f]{16}|seed-[A-Z0-9]{1,32}-[0-9]{1,2})$/', $id) === 1;
    }

    /** @param list<array<string, mixed>> $rows @return array<int, string> */
    private function names(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) ($r['author_id'] ?? 0), $rows))));
        return $ids === [] ? [] : (new Characters($this->minn->db))->namesOf($ids);
    }

    /** Moves the Note in a character's ledger by $by (the row is locked: Ledger::lock). */
    private static function move(Db $db, int $user, array $state, int $by, int $now): void
    {
        $count = ($state['items'][self::NOTE] ?? 0) + $by;
        $items = $db->table(Ledger::ITEMS);
        $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET updated_at = ? WHERE user_id = ?', [gmdate('Y-m-d H:i:s', $now), $user]);
        if ($count <= 0) {
            $db->execute("DELETE FROM {$items} WHERE user_id = ? AND class_tsid = ?", [$user, self::NOTE]);
        } else {
            $db->execute("INSERT INTO {$items} (user_id, class_tsid, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)", [$user, self::NOTE, $count]);
        }
    }

    /** The game's street index (tsid => row), or empty. @return array<string, array<string, mixed>> */
    public function streetIndex(): array
    {
        $file = StaticRoot::fromSite($this->minn)->dir . '/assets/streets/index.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data['streets'] ?? null) ? $data['streets'] : [];
    }

    private function signed(Request $request, string $route): void
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', $route, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
    }
}
