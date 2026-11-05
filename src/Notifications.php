<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Content\Users;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * The notifications on /chat/ (Austin 2026-11-04: a bell in the chat app's
 * header, the same count on the Community menu link): what happened to you
 * while you weren't looking, newest first, gathered when asked from where
 * each thing is already kept, so nothing new is written when it happens:
 *
 *   reply, quote  a reply to your topic or a quote of you on the boards (Community's notices)
 *   thread        replies under your line in a channel (ChatHistory::threadNotices)
 *   im            IMs you have not answered (ChatHistory::imNotices: only your own conversations)
 *   buddy         a player added you as a buddy (groddle_buddies)
 *   ticket        the team answered your report (groddle_tickets: last_author_role admin)
 *
 *   GET  groddle/v1/notifications                    {"unread":n,"items":[Item],"now":ms}
 *   POST groddle/v1/notifications/read {"key"}       one read; {} reads them all   {"unread":n}
 *   Item: {"key","kind","at":ms,"unread":bool,"from":{id,name}|null,"count","text","snippet","url","open"?}
 *         open: what the chat app opens in place ({"conv":"chan:global","thread":lid} | {"conv":"im:9"} | {"ticket":5})
 *
 * Read state: a board notice keeps its own (read_at, as opening the topic
 * does, and the game's Activity is untouched); the rest is user meta
 * groddle_notif_read, {"all": ms of the last Mark all read, "k": {key: ms}}
 * (KEYS_KEPT kept). An item is unread when its source says so (a notice not
 * read, a report not read in the game) and it is newer than both marks. An IM
 * is gone once you answer; a thread reply is read once you reply under it.
 * The last DAYS days, at most SHOWN items. Signed-in players only; private,
 * no-store; only ever the caller's own.
 */
final readonly class Notifications
{
    public const META = 'groddle_notif_read';
    public const DAYS = 14;
    public const SHOWN = 30;
    public const KEYS_KEPT = 120;
    public const SNIPPET = 90;

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/notifications', policy: new Policy(Access::SignedIn))]
    public function list(Request $request): Response
    {
        $items = $this->items($this->me());
        return self::private(Response::json(['unread' => self::count($items), 'items' => $items, 'now' => Community::ms()]));
    }

    #[Route(Method::Post, '/groddle/v1/notifications/read', policy: new Policy(Access::SignedIn))]
    public function read(Request $request): Response
    {
        $me = $this->me();
        $key = $request->json()['key'] ?? null;
        if ($key !== null && (!is_string($key) || preg_match(self::KEY, $key) !== 1)) {
            throw new RestError('groddle_notif_key', 'Not a notification.', 400);
        }
        $this->mark($me, $key);
        return self::private(Response::json(['unread' => $this->unread($me)]));
    }

    /** How many of the player's notifications are unread (the bell, and the Community link's count in SiteNav). */
    public function unread(int $me): int
    {
        return $me > 0 ? self::count($this->items($me)) : 0;
    }

    /** The keys an item may have. */
    public const KEY = '/^(?:n:\d{1,19}|th:(?:global|help|trade):\d{1,19}|im:\d{1,19}|bud:\d{1,19}|tk:\d{1,19})$/';

    /**
     * Marks one item read, or all of them (null). A board notice is read in its own row; the rest
     * by the marks in user meta.
     */
    public function mark(int $me, ?string $key): void
    {
        $users = new Users($this->minn->db);
        $marks = self::marks($users->meta($me, self::META));
        $now = Community::ms();
        if ($key === null) {
            (new Community($this->minn))->readNotices($me);
        } elseif (str_starts_with($key, 'n:')) {
            (new Community($this->minn))->readNotices($me, (int) substr($key, 2));
            return;
        }
        $users->setMeta($me, self::META, (string) json_encode(self::marked($marks, $key, $now, self::KEYS_KEPT)));
    }

    /**
     * The player's notifications, newest first, each marked read or unread.
     *
     * @return list<array<string, mixed>>
     */
    public function items(int $me): array
    {
        $db = $this->minn->db;
        $since = time() - self::DAYS * 86400;
        $sinceMs = $since * 1000;
        $items = [];
        foreach ((new Community($this->minn))->recentNotices($me, $since) as $x) {
            $title = '“' . $x['title'] . '”';
            $text = $x['kind'] === 'quote'
                ? "{$x['from']['name']} quoted you in {$title}"
                : ($x['count'] > 1 ? "{$x['count']} new replies to your topic {$title}" : "{$x['from']['name']} replied to your topic {$title}");
            $items[] = ['key' => 'n:' . $x['id'], 'kind' => $x['kind'] === 'quote' ? 'quote' : 'reply', 'at' => $x['at'] * 1000, 'unread' => !$x['read'], 'from' => $x['from'], 'count' => $x['count'], 'text' => $text, 'snippet' => '', 'url' => $x['url']];
        }
        $chat = new ChatHistory($this->minn);
        foreach ($chat->threadNotices($me, $sinceMs) as $x) {
            $others = $x['people'] - 1;
            $who = $x['last']['name'] . ($others > 0 ? ' and ' . CommunityText::count($others, 'other', 'others') : '');
            $items[] = ['key' => "th:{$x['key']}:{$x['lid']}", 'kind' => 'thread', 'at' => $x['at'], 'unread' => $x['mine'] < $x['at'], 'from' => $x['last'], 'count' => $x['n'], 'text' => "{$who} replied to your line in {$x['label']}", 'snippet' => CommunityText::snippet($x['text'], self::SNIPPET), 'url' => "/chat/?c={$x['key']}#t{$x['lid']}", 'open' => ['conv' => "chan:{$x['key']}", 'thread' => $x['lid']]];
        }
        foreach ($chat->imNotices($me, $sinceMs) as $x) {
            $text = $x['n'] > 1 ? "{$x['with']['name']} sent you {$x['n']} IMs" : "{$x['with']['name']} sent you an IM";
            $items[] = ['key' => "im:{$x['with']['id']}", 'kind' => 'im', 'at' => $x['at'], 'from' => $x['with'], 'count' => $x['n'], 'text' => $text, 'snippet' => CommunityText::snippet($x['text'], self::SNIPPET), 'url' => "/chat/?im={$x['with']['id']}" . ($x['parent'] > 0 ? "#t{$x['parent']}" : ''), 'open' => ['conv' => "im:{$x['with']['id']}"] + ($x['parent'] > 0 ? ['thread' => $x['parent']] : [])];
        }
        $items = [...$items, ...$this->buddyItems($me, $since), ...$this->ticketItems($me, $since)];
        return self::merge($items, self::marks((new Users($db))->meta($me, self::META)), self::SHOWN);
    }

    /** Players who added the reader as a buddy since $since (not one either of them blocks). @return list<array<string, mixed>> */
    private function buddyItems(int $me, int $since): array
    {
        $db = $this->minn->db;
        $b = $db->table(Buddies::TABLE);
        $i = $db->table(Buddies::IGNORES);
        $rows = $db->rows("SELECT x.user_id, x.added_at, (SELECT 1 FROM {$b} y WHERE y.user_id = ? AND y.buddy_id = x.user_id) AS held FROM {$b} x WHERE x.buddy_id = ? AND x.added_at >= ? AND NOT EXISTS (SELECT 1 FROM {$i} g WHERE (g.user_id = ? AND g.ignored_id = x.user_id) OR (g.user_id = x.user_id AND g.ignored_id = ?)) ORDER BY x.added_at DESC LIMIT 20", [$me, $me, gmdate('Y-m-d H:i:s', $since), $me, $me]);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['user_id'], $rows);
        $names = (new Characters($db))->namesOf($ids);
        $authors = (new Community($this->minn))->authors($ids);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['user_id'];
            if (!isset($names[$id])) {
                continue;
            }
            $out[] = ['key' => "bud:{$id}", 'kind' => 'buddy', 'at' => Community::unix((string) $r['added_at']) * 1000, 'from' => ['id' => $id, 'name' => $names[$id]], 'count' => 1, 'text' => "Added you: {$names[$id]}", 'snippet' => $r['held'] !== null ? 'You are buddies now.' : 'Add them back from their menu.', 'url' => $authors[$id]['profile'] ?? "/chat/?im={$id}"];
        }
        return $out;
    }

    /** Reports the team answered since $since; unread while the answer is not read in the game. @return list<array<string, mixed>> */
    private function ticketItems(int $me, int $since): array
    {
        $db = $this->minn->db;
        $rows = $db->rows('SELECT * FROM ' . $db->table(TicketStore::TABLE) . " WHERE player_user_id = ? AND last_author_role = 'admin' AND last_message_at >= ? ORDER BY last_message_at DESC LIMIT 10", [$me, gmdate('Y-m-d H:i:s', $since)]);
        $out = [];
        foreach ($rows as $r) {
            $subject = trim((string) $r['subject']);
            $out[] = ['key' => 'tk:' . (int) $r['id'], 'kind' => 'ticket', 'at' => Community::unix((string) $r['last_message_at']) * 1000, 'unread' => TicketStore::unreadFor($r, TicketStore::PLAYER), 'from' => null, 'count' => 1, 'text' => 'The team replied to your report' . ($subject === '' ? '' : ' “' . $subject . '”'), 'snippet' => '', 'url' => '/play/online/', 'open' => ['ticket' => (int) $r['id']]];
        }
        return $out;
    }

    // —— Pure ——

    /**
     * PURE: the items newest first (then by key), at most $limit, each with its final unread: what
     * its source says (true when it says nothing) and newer than both marks.
     *
     * @param list<array<string, mixed>> $items
     * @param array{all: int, k: array<string, int>} $marks
     * @return list<array<string, mixed>>
     */
    public static function merge(array $items, array $marks, int $limit): array
    {
        usort($items, static fn (array $a, array $b): int => [(int) $b['at'], (string) $a['key']] <=> [(int) $a['at'], (string) $b['key']]);
        $out = [];
        foreach (array_slice($items, 0, max(0, $limit)) as $item) {
            $seen = max((int) $marks['all'], (int) ($marks['k'][(string) $item['key']] ?? 0));
            $item['unread'] = ($item['unread'] ?? true) === true && (int) $item['at'] > $seen;
            $out[] = $item;
        }
        return $out;
    }

    /** PURE: how many are unread. @param list<array<string, mixed>> $items */
    public static function count(array $items): int
    {
        return count(array_filter($items, static fn (array $i): bool => ($i['unread'] ?? false) === true));
    }

    /**
     * PURE: the marks as kept in user meta, anything else read as none.
     *
     * @return array{all: int, k: array<string, int>}
     */
    public static function marks(?string $json): array
    {
        $raw = json_decode((string) $json, true);
        $out = ['all' => 0, 'k' => []];
        if (!is_array($raw)) {
            return $out;
        }
        $out['all'] = is_int($raw['all'] ?? null) && $raw['all'] > 0 ? $raw['all'] : 0;
        foreach (is_array($raw['k'] ?? null) ? $raw['k'] : [] as $k => $at) {
            if (is_string($k) && preg_match(self::KEY, $k) === 1 && is_int($at) && $at > $out['all']) {
                $out['k'][$k] = $at;
            }
        }
        return $out;
    }

    /**
     * PURE: the marks after reading one key at $now (null: all of them, which clears the keys), the
     * newest $keep keys kept.
     *
     * @param array{all: int, k: array<string, int>} $marks
     * @return array{all: int, k: array<string, int>}
     */
    public static function marked(array $marks, ?string $key, int $now, int $keep): array
    {
        if ($key === null) {
            return ['all' => $now, 'k' => []];
        }
        $k = $marks['k'];
        unset($k[$key]);
        $k[$key] = $now;
        arsort($k);
        return ['all' => $marks['all'], 'k' => array_slice($k, 0, max(1, $keep), true)];
    }

    private function me(): int
    {
        $user = $this->minn->user();
        if ($user === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        return $user->id;
    }

    private static function private(Response $r): Response
    {
        return $r->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Robots-Tag', 'noindex');
    }
}
