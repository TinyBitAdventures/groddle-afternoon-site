<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Auth\Capabilities;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * The console's Community tab (Austin 2026-11-04, moderation from day one
 * for the boards, Community): posts, reported ones first; Remove and
 * Restore; Pin and Lock on a thread; stopping a player from posting for a
 * while, and the list of who is stopped. Administrators only (manage_options).
 *
 *   GET  admin/community_posts?show=reported|recent|removed
 *        {"posts":[Row],"counts":{"reported":n,"removed":n},"stopped":[Stop]}
 *   POST admin/community_remove  {"post":id}   the post (and a thread's opening post: the thread) off the boards
 *   POST admin/community_restore {"post":id}
 *   POST admin/community_thread  {"thread":id,"pinned"?:bool,"locked"?:bool}
 *   POST admin/community_stop    {"player":id,"days":1|3|7|30|0,"reason"}   0: until lifted
 *   POST admin/community_unstop  {"player":id}
 *
 *   Row: {"id","thread":{id,title,url,board,pinned,locked,status},"author":{id,name},"body","status",
 *         "reports","first":bool,"at"}
 *   Stop: {"player":{id,name},"until"(unix|0),"reason","by":{id,name},"at"}
 *
 * A removal hides the post's words for everyone but administrators (its
 * place in the thread says a moderator removed it); removing a thread's
 * opening post takes the whole thread off its board. Restore undoes either.
 * Nothing is deleted. A stop lets the player read but not post, react or edit;
 * a suspended account (AdminConsole ban) can't post either. Administrators
 * are never stopped. Each action writes one groddle_admin_actions line
 * (kinds comm_remove, comm_restore, comm_pin, comm_unpin, comm_lock,
 * comm_unlock, comm_stop, comm_unstop).
 */
final readonly class AdminCommunity
{
    public const SHOWS = ['reported', 'recent', 'removed'];
    public const LIST_MAX = 200;
    /** The lengths a stop can be, in days (0: until lifted). */
    public const STOP_DAYS = [1, 3, 7, 30, 0];

    private const ADMIN = 'manage_options';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/admin/community_posts', policy: new Policy(Access::Cap, self::ADMIN))]
    public function index(Request $request): Response
    {
        $show = $request->query('show');
        $show = in_array($show, self::SHOWS, true) ? $show : 'reported';
        $db = $this->minn->db;
        $p = $db->table(Community::POSTS);
        $t = $db->table(Community::THREADS);
        $where = match ($show) {
            'reported' => "p.reports > 0 AND p.status = 'shown'",
            'removed' => "p.status = 'removed'",
            default => '1 = 1',
        };
        $rows = $db->rows("SELECT p.id, p.thread_id, p.author_id, p.author_name, p.body, p.status, p.reports, p.created_at, t.board, t.title, t.pinned, t.locked, t.status AS thread_status, t.first_post_id
            FROM {$p} p JOIN {$t} t ON t.id = p.thread_id WHERE {$where} ORDER BY " . ($show === 'reported' ? 'p.reports DESC, ' : '') . 'p.id DESC LIMIT ' . self::LIST_MAX);
        $names = (new Characters($db))->namesOf(array_values(array_unique(array_map(static fn (array $r): int => (int) $r['author_id'], $rows))));
        $out = array_map(static fn (array $r): array => self::row($r, $names), $rows);
        $counts = [
            'reported' => (int) $db->value("SELECT COUNT(*) FROM {$p} WHERE reports > 0 AND status = 'shown'"),
            'removed' => (int) $db->value("SELECT COUNT(*) FROM {$p} WHERE status = 'removed'"),
        ];
        return Response::json(['posts' => $out, 'counts' => $counts, 'stopped' => $this->stops()])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * One post as the console lists it.
     *
     * @param array<string, mixed> $r
     * @param array<int, string> $names
     */
    public static function row(array $r, array $names): array
    {
        $author = (int) $r['author_id'];
        return [
            'id' => (int) $r['id'],
            'thread' => [
                'id' => (int) $r['thread_id'],
                'title' => (string) $r['title'],
                'url' => CommunityText::threadPath((string) $r['board'], (int) $r['thread_id'], (string) $r['title']),
                'board' => CommunityText::BOARDS[$r['board']][0] ?? (string) $r['board'],
                'pinned' => (int) $r['pinned'] === 1,
                'locked' => (int) $r['locked'] === 1,
                'status' => (string) $r['thread_status'],
            ],
            'author' => ['id' => $author, 'name' => $names[$author] ?? (string) $r['author_name']],
            'body' => (string) $r['body'],
            'status' => (string) $r['status'],
            'reports' => max(0, (int) $r['reports']),
            'first' => (int) $r['first_post_id'] === (int) $r['id'],
            'at' => Community::unix((string) $r['created_at']),
        ];
    }

    #[Route(Method::Post, '/groddle/v1/admin/community_remove', policy: new Policy(Access::Cap, self::ADMIN))]
    public function remove(Request $request): Response
    {
        return $this->moderate($request, true);
    }

    #[Route(Method::Post, '/groddle/v1/admin/community_restore', policy: new Policy(Access::Cap, self::ADMIN))]
    public function restore(Request $request): Response
    {
        return $this->moderate($request, false);
    }

    private function moderate(Request $request, bool $remove): Response
    {
        $admin = $this->admin();
        $id = $request->json()['post'] ?? null;
        $db = $this->minn->db;
        $community = new Community($this->minn);
        $post = is_int($id) ? $community->post($id) : null;
        if ($post === null) {
            throw new RestError('groddle_admin_no_post', 'There is no post with that id.', 404);
        }
        $want = $remove ? 'shown' : 'removed';
        if ($post['status'] !== $want) {
            throw new RestError('groddle_admin_post_state', $remove ? 'That post is not showing.' : 'That post was not removed.', 409);
        }
        $t = $db->table(Community::THREADS);
        $thread = $db->row("SELECT id, board, title, first_post_id, status FROM {$t} WHERE id = ?", [$post['thread_id']]);
        $first = $thread !== null && (int) $thread['first_post_id'] === (int) $post['id'];
        $now = time();
        $db->execute('UPDATE ' . $db->table(Community::POSTS) . ' SET status = ?, removed_by = ?, removed_at = ?, changed_ms = ? WHERE id = ?', [$remove ? 'removed' : 'shown', $remove ? $admin : 0, $remove ? gmdate('Y-m-d H:i:s', $now) : null, Community::ms(), $post['id']]);
        if ($first) {
            $db->execute("UPDATE {$t} SET status = ? WHERE id = ?", [$remove ? 'removed' : 'open', $thread['id']]);
        }
        $community->refreshThread((int) $post['thread_id']);
        $name = (new Characters($db))->namesOf([(int) $post['author_id']])[(int) $post['author_id']] ?? (string) $post['author_name'];
        $title = (string) ($thread['title'] ?? '');
        AdminConsole::recordAction($db, $admin, (int) $post['author_id'], $remove ? 'comm_remove' : 'comm_restore', ['post' => (int) $post['id'], 'thread' => (int) $post['thread_id'], 'title' => mb_substr($title, 0, 80), 'first' => $first, 'text' => CommunityText::snippet((string) $post['body'], 200)], 'stored', $now);
        $text = $remove
            ? ($first ? "Removed {$name}'s thread “{$title}” from its board." : "Removed {$name}'s post in “{$title}”.")
            : ($first ? "Restored {$name}'s thread “{$title}”." : "Restored {$name}'s post in “{$title}”.");
        return Response::json(['post' => (int) $post['id'], 'thread' => (int) $post['thread_id'], 'first' => $first, 'text' => $text]);
    }

    #[Route(Method::Post, '/groddle/v1/admin/community_thread', policy: new Policy(Access::Cap, self::ADMIN))]
    public function thread(Request $request): Response
    {
        $admin = $this->admin();
        $body = $request->json();
        $db = $this->minn->db;
        $t = $db->table(Community::THREADS);
        $id = $body['thread'] ?? null;
        $row = is_int($id) ? $db->row("SELECT id, author_id, title, pinned, locked FROM {$t} WHERE id = ?", [$id]) : null;
        if ($row === null) {
            throw new RestError('groddle_admin_no_thread', 'There is no thread with that id.', 404);
        }
        $said = [];
        foreach (['pinned' => ['comm_pin', 'comm_unpin', 'Pinned', 'Unpinned'], 'locked' => ['comm_lock', 'comm_unlock', 'Locked', 'Unlocked']] as $col => [$on, $off, $onWord, $offWord]) {
            if (!is_bool($body[$col] ?? null) || (int) $row[$col] === (int) $body[$col]) {
                continue;
            }
            $db->execute("UPDATE {$t} SET {$col} = ? WHERE id = ?", [$body[$col] ? 1 : 0, $row['id']]);
            AdminConsole::recordAction($db, $admin, (int) $row['author_id'], $body[$col] ? $on : $off, ['thread' => (int) $row['id'], 'title' => mb_substr((string) $row['title'], 0, 80)], 'stored');
            $said[] = $body[$col] ? $onWord : $offWord;
        }
        // The thread's posts redraw for readers (its locked reply box) at their next update.
        $db->execute('UPDATE ' . $db->table(Community::POSTS) . ' SET changed_ms = ? WHERE id = (SELECT first_post_id FROM ' . $t . ' WHERE id = ?)', [Community::ms(), $row['id']]);
        $after = $db->row("SELECT pinned, locked FROM {$t} WHERE id = ?", [$row['id']]);
        return Response::json([
            'thread' => (int) $row['id'],
            'pinned' => (int) $after['pinned'] === 1,
            'locked' => (int) $after['locked'] === 1,
            'text' => $said === [] ? 'Nothing changed.' : implode(' and ', $said) . " “{$row['title']}”.",
        ]);
    }

    #[Route(Method::Post, '/groddle/v1/admin/community_stop', policy: new Policy(Access::Cap, self::ADMIN))]
    public function stop(Request $request): Response
    {
        $admin = $this->admin();
        $body = $request->json();
        [$id, $name] = $this->player($body['player'] ?? null);
        if ($id === $admin) {
            throw new RestError('groddle_admin_self', 'You cannot stop yourself.', 409);
        }
        if (Capabilities::fromDb($this->minn->db)->can($id, self::ADMIN)) {
            throw new RestError('groddle_admin_ban_admin', "{$name} is an administrator and cannot be stopped.", 409);
        }
        $days = $body['days'] ?? null;
        if (!is_int($days) || !in_array($days, self::STOP_DAYS, true)) {
            throw new RestError('groddle_admin_days', 'Choose how long: 1, 3, 7 or 30 days, or until lifted.', 400);
        }
        $reason = AdminConsole::reasonText($body['reason'] ?? '');
        $now = time();
        $until = $days === 0 ? null : gmdate('Y-m-d H:i:s', $now + $days * 86400);
        $db = $this->minn->db;
        $db->execute('INSERT INTO ' . $db->table(Community::BANS) . ' (user_id, until, reason, admin_user_id, created_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE until = VALUES(until), reason = VALUES(reason), admin_user_id = VALUES(admin_user_id), created_at = VALUES(created_at)', [$id, $until, $reason, $admin, gmdate('Y-m-d H:i:s', $now)]);
        AdminConsole::recordAction($db, $admin, $id, 'comm_stop', ['days' => $days, 'reason' => $reason], 'stored', $now);
        return Response::json(['player' => ['id' => $id, 'name' => $name], 'text' => self::stopText($name, $days), 'stopped' => $this->stops()]);
    }

    #[Route(Method::Post, '/groddle/v1/admin/community_unstop', policy: new Policy(Access::Cap, self::ADMIN))]
    public function unstop(Request $request): Response
    {
        $admin = $this->admin();
        [$id, $name] = $this->player($request->json()['player'] ?? null);
        $db = $this->minn->db;
        $db->execute('DELETE FROM ' . $db->table(Community::BANS) . ' WHERE user_id = ?', [$id]);
        AdminConsole::recordAction($db, $admin, $id, 'comm_unstop', [], 'stored');
        return Response::json(['player' => ['id' => $id, 'name' => $name], 'text' => "{$name} can post on the boards again.", 'stopped' => $this->stops()]);
    }

    /** What the console says after a stop. */
    public static function stopText(string $name, int $days): string
    {
        return match ($days) {
            0 => "{$name} can't post on the boards until you lift it.",
            1 => "{$name} can't post on the boards for a day.",
            default => "{$name} can't post on the boards for {$days} days.",
        };
    }

    /** Who is stopped now, newest first. @return list<array<string, mixed>> */
    private function stops(): array
    {
        $db = $this->minn->db;
        $rows = $db->rows('SELECT user_id, until, reason, admin_user_id, created_at FROM ' . $db->table(Community::BANS) . ' WHERE until IS NULL OR until > ? ORDER BY created_at DESC LIMIT 100', [gmdate('Y-m-d H:i:s')]);
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['user_id'];
            $ids[] = (int) $r['admin_user_id'];
        }
        $names = (new Characters($db))->namesOf(array_values(array_unique(array_filter($ids))));
        return array_map(static fn (array $r): array => [
            'player' => ['id' => (int) $r['user_id'], 'name' => $names[(int) $r['user_id']] ?? '#' . $r['user_id']],
            'until' => $r['until'] === null ? 0 : Community::unix((string) $r['until']),
            'reason' => (string) $r['reason'],
            'by' => ['id' => (int) $r['admin_user_id'], 'name' => $names[(int) $r['admin_user_id']] ?? ''],
            'at' => Community::unix((string) $r['created_at']),
        ], $rows);
    }

    private function admin(): int
    {
        $admin = $this->minn->user();
        if ($admin === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        return $admin->id;
    }

    /** @return array{0: int, 1: string} */
    private function player(mixed $id): array
    {
        $name = is_int($id) && $id > 0 ? ((new Characters($this->minn->db))->namesOf([$id])[$id] ?? null) : null;
        if ($name === null) {
            throw new RestError('groddle_admin_no_player', 'No player has that id.', 404);
        }
        return [$id, $name];
    }
}
