<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Auth\Capabilities;
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
 * The administrators' console's data (the page is AdminConsolePage, /console/):
 * groddle/v1/admin/*, every route manage_options only (players are
 * subscribers: 401 signed out, 403 signed in). What reaches a player live
 * goes through the realtime server's signed calls (RealtimeCall,
 * groddle-afternoon-server admin.go); every action writes one line in
 * groddle_admin_actions (the teleport too, AdminTeleport).
 *
 *   GET  admin/online           {"server":"up"|"down","players":[Row + {"since":unix}]}   who is connected now
 *                               ?sync=1 first sends the server the whole ban list (admin.go "bans")
 *   GET  admin/players?q=…      {"players":[Row]}  characters by name (the start of it), online or not
 *   GET  admin/bans             {"bans":[{"player":{id,name},"reason","by":{id,name},"at"}]}
 *   GET  admin/log?before=<id>  {"lines":[{"id","at","kind","by":{id,name},"player":{id,name},"detail","delivery"}],"more":bool}
 *   GET  admin/catalog          {"items":[{"c","name","plural"}]}   what a give may carry (the ledger's tradeable classes)
 *   GET  admin/streets          {"streets":[{"tsid","label","hub"}]}  the game's street index, for teleport
 *   POST admin/message {"player":id,"text":"…"}               delivery live | queued (offline: at their next connect) | down
 *   POST admin/boot    {"player":id,"reason":"…"}             delivery live | offline | down
 *   POST admin/ban     {"player":id,"reason":"…"}             stored; delivery live (signed out now) | stored
 *   POST admin/unban   {"player":id}
 *   POST admin/give    {"player":id,"items":[{"c","n"}],"currants":n}   through the ledger; delivery live | saved
 *   POST admin/refill  {"player":id}                          mood and energy to full; delivery live | offline | down
 *   Row: {"id","name","level","street":{tsid,label}|null,"savedAt","banned":bool,"admin":bool}
 *
 * Bans (groddle_bans, one row per suspended account): a banned account gets
 * no realtime ticket (RealtimeTicket), cannot load or write its multiplayer
 * save (MultiplayerSave), is told so by groddle/v1/me (Player: suspended),
 * and the realtime server refuses it at auth and signs it out if connected.
 * An administrator (manage_options) is never banned, nor is the caller.
 *
 * A give is a settled trade from nobody (groddle_trades admin-give-<16 hex>:
 * a_id the player, a_gave nothing, b_id 0, b_gave the goods, b's side applied),
 * the way starter bags arrive (LedgerStarter): the goods go into the ledger at
 * once, so until the save lists the id as applied a write is checked as if
 * they were not there yet; a connected game applies it once through the trade
 * slice's applied list (the realtime server's admin_give, ledgerReceipt), and
 * otherwise the next load applies it to the stored save (Ledger::catchUp).
 * Only the ledger's tradeable classes and currants: iMG, mood and energy are
 * not in the ledger. Admin gives are never counted applied by age
 * (Ledger::pending), so a player away for weeks still gets them.
 */
final readonly class AdminConsole
{
    public const ACTIONS = 'groddle_admin_actions';
    public const BANS = 'groddle_bans';

    public const GIVE_PREFIX = 'admin-give-';

    /** Characters, reasons and stacks an action may carry. */
    public const TEXT_MAX = 500;
    public const REASON_MAX = 200;
    public const GIVE_STACKS = 10;
    public const GIVE_EACH = 999;
    public const GIVE_CURRANTS = 100000;

    public const LOG_PAGE = 50;
    public const SEARCH_MAX = 25;

    public const SUSPENDED = 'This account is suspended.';

    private const ADMIN = 'manage_options';

    public function __construct(private Seams $minn)
    {
    }

    // —— Reading ——

    #[Route(Method::Get, '/groddle/v1/admin/online', policy: new Policy(Access::Cap, self::ADMIN))]
    public function online(Request $request): Response
    {
        $call = new RealtimeCall($this->minn);
        if ($request->query('sync') === '1') {
            $ids = array_map(static fn (array $r): int => (int) $r['user_id'], $this->minn->db->rows('SELECT user_id FROM ' . $this->minn->db->table(self::BANS)));
            $call->post('bans', ['players' => $ids]);
        }
        $answer = $call->post('online', []);
        if (!is_array($answer['players'] ?? null)) {
            return Response::json(['server' => 'down', 'players' => []]);
        }
        $since = [];
        $streets = [];
        foreach ($answer['players'] as $p) {
            if (is_array($p) && is_int($p['id'] ?? null) && $p['id'] > 0) {
                $since[$p['id']] = (int) ($p['since'] ?? 0);
                $streets[$p['id']] = is_string($p['street'] ?? null) ? $p['street'] : '';
            }
        }
        $rows = $this->rows(array_keys($since));
        $index = $this->streetIndex();
        $out = [];
        foreach ($rows as $row) {
            $live = $streets[$row['id']] ?? '';
            $row['street'] = $live === '' ? null : ['tsid' => $live, 'label' => (string) ($index[$live]['label'] ?? $live)];
            $row['since'] = $since[$row['id']] ?? 0;
            $out[] = $row;
        }
        return Response::json(['server' => 'up', 'players' => $out]);
    }

    #[Route(Method::Get, '/groddle/v1/admin/players', policy: new Policy(Access::Cap, self::ADMIN))]
    public function players(Request $request): Response
    {
        $q = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($request->query('q') ?? '')) ?? ''));
        if ($q === '') {
            return Response::json(['players' => []]);
        }
        $db = $this->minn->db;
        $ids = array_map(static fn (array $r): int => (int) $r['user_id'], $db->rows('SELECT user_id FROM ' . $db->table(Characters::TABLE) . ' WHERE name_key LIKE ? ORDER BY name_key = ? DESC, name_key LIMIT ' . self::SEARCH_MAX, [addcslashes(substr($q, 0, 32), '%_\\') . '%', $q]));
        return Response::json(['players' => $this->rows($ids)]);
    }

    #[Route(Method::Get, '/groddle/v1/admin/bans', policy: new Policy(Access::Cap, self::ADMIN))]
    public function bans(Request $request): Response
    {
        $db = $this->minn->db;
        $rows = $db->rows('SELECT user_id, reason, admin_user_id, created_at FROM ' . $db->table(self::BANS) . ' ORDER BY created_at DESC, user_id');
        $names = (new Characters($db))->namesOf(array_merge(array_map(static fn (array $r): int => (int) $r['user_id'], $rows), array_map(static fn (array $r): int => (int) $r['admin_user_id'], $rows)));
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'player' => ['id' => (int) $r['user_id'], 'name' => $this->nameOr($names, (int) $r['user_id'])],
                'reason' => (string) $r['reason'],
                'by' => ['id' => (int) $r['admin_user_id'], 'name' => $this->nameOr($names, (int) $r['admin_user_id'])],
                'at' => SaveShape::time((string) $r['created_at']),
            ];
        }
        return Response::json(['bans' => $out]);
    }

    #[Route(Method::Get, '/groddle/v1/admin/log', policy: new Policy(Access::Cap, self::ADMIN))]
    public function log(Request $request): Response
    {
        $db = $this->minn->db;
        $before = (int) ($request->query('before') ?? '0');
        $where = $before > 0 ? 'WHERE id < ' . $before : '';
        $rows = $db->rows('SELECT * FROM ' . $db->table(self::ACTIONS) . " {$where} ORDER BY id DESC LIMIT " . (self::LOG_PAGE + 1));
        $more = count($rows) > self::LOG_PAGE;
        $rows = array_slice($rows, 0, self::LOG_PAGE);
        $names = (new Characters($db))->namesOf(array_merge(array_map(static fn (array $r): int => (int) $r['admin_user_id'], $rows), array_map(static fn (array $r): int => (int) $r['player_user_id'], $rows)));
        $lines = [];
        foreach ($rows as $r) {
            $detail = json_decode((string) $r['detail'], true);
            $lines[] = [
                'id' => (int) $r['id'],
                'at' => SaveShape::time((string) $r['at']),
                'kind' => (string) $r['kind'],
                'by' => ['id' => (int) $r['admin_user_id'], 'name' => $this->nameOr($names, (int) $r['admin_user_id'])],
                'player' => ['id' => (int) $r['player_user_id'], 'name' => $this->nameOr($names, (int) $r['player_user_id'])],
                'detail' => is_array($detail) ? $detail : new \stdClass(),
                'delivery' => (string) $r['delivery'],
            ];
        }
        return Response::json(['lines' => $lines, 'more' => $more]);
    }

    #[Route(Method::Get, '/groddle/v1/admin/catalog', policy: new Policy(Access::Cap, self::ADMIN))]
    public function catalog(Request $request): Response
    {
        $file = dirname(__DIR__) . '/data/economy.json';
        $table = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $out = [];
        foreach (is_array($table['items'] ?? null) ? $table['items'] : [] as $c => $row) {
            if (is_string($c) && is_array($row)) {
                $out[] = ['c' => $c, 'name' => (string) ($row['n'] ?? $c), 'plural' => (string) ($row['p'] ?? $row['n'] ?? $c)];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return Response::json(['items' => $out])->withHeader('Cache-Control', 'private, max-age=300');
    }

    #[Route(Method::Get, '/groddle/v1/admin/streets', policy: new Policy(Access::Cap, self::ADMIN))]
    public function streets(Request $request): Response
    {
        $out = [];
        foreach ($this->streetIndex() as $tsid => $s) {
            if (is_string($tsid) && is_array($s)) {
                $out[] = ['tsid' => $tsid, 'label' => (string) ($s['label'] ?? $tsid), 'hub' => (string) ($s['hubName'] ?? '')];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']) ?: strcmp($a['tsid'], $b['tsid']));
        return Response::json(['streets' => $out])->withHeader('Cache-Control', 'private, max-age=300');
    }

    // —— Acting ——

    #[Route(Method::Post, '/groddle/v1/admin/message', policy: new Policy(Access::Cap, self::ADMIN))]
    public function message(Request $request): Response
    {
        [$admin, $player, $body] = $this->target($request);
        $text = self::messageText($body['text'] ?? '');
        if ($text === '') {
            throw new RestError('groddle_admin_empty', 'Write a message first.', 400);
        }
        $id = 'am-' . bin2hex(random_bytes(8));
        $answer = (new RealtimeCall($this->minn))->post('message', ['player' => $player['id'], 'id' => $id, 'text' => $text]);
        $delivery = match ($answer['status'] ?? null) {
            'sent' => 'live',
            'queued' => 'queued',
            default => 'down',
        };
        $this->record($admin, $player['id'], 'message', ['text' => $text], $delivery);
        return $this->done($player, $delivery, match ($delivery) {
            'live' => "{$player['name']} has your message.",
            'queued' => "{$player['name']} is not online. They will see it when they next connect.",
            default => "The realtime server is not answering, so {$player['name']} did not get it.",
        });
    }

    #[Route(Method::Post, '/groddle/v1/admin/boot', policy: new Policy(Access::Cap, self::ADMIN))]
    public function boot(Request $request): Response
    {
        [$admin, $player, $body] = $this->target($request);
        if ($player['id'] === $admin->id) {
            throw new RestError('groddle_admin_self', 'You cannot sign yourself out from here.', 409);
        }
        $reason = self::reasonText($body['reason'] ?? '');
        $answer = (new RealtimeCall($this->minn))->post('boot', ['player' => $player['id'], 'reason' => $reason]);
        $delivery = match ($answer['status'] ?? null) {
            'sent' => 'live',
            'offline' => 'offline',
            default => 'down',
        };
        $this->record($admin, $player['id'], 'boot', ['reason' => $reason], $delivery);
        return $this->done($player, $delivery, match ($delivery) {
            'live' => "Signed {$player['name']} out.",
            'offline' => "{$player['name']} is not online.",
            default => 'The realtime server is not answering.',
        });
    }

    #[Route(Method::Post, '/groddle/v1/admin/ban', policy: new Policy(Access::Cap, self::ADMIN))]
    public function ban(Request $request): Response
    {
        [$admin, $player, $body] = $this->target($request);
        if ($player['id'] === $admin->id) {
            throw new RestError('groddle_admin_self', 'You cannot ban yourself.', 409);
        }
        if (Capabilities::fromDb($this->minn->db)->can($player['id'], self::ADMIN)) {
            throw new RestError('groddle_admin_ban_admin', "{$player['name']} is an administrator and cannot be banned.", 409);
        }
        $reason = self::reasonText($body['reason'] ?? '');
        $db = $this->minn->db;
        $db->execute('INSERT INTO ' . $db->table(self::BANS) . ' (user_id, reason, admin_user_id, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE reason = VALUES(reason), admin_user_id = VALUES(admin_user_id)', [$player['id'], $reason, $admin->id, gmdate('Y-m-d H:i:s')]);
        $answer = (new RealtimeCall($this->minn))->post('ban', ['player' => $player['id'], 'reason' => $reason]);
        $delivery = ($answer['status'] ?? null) === 'sent' ? 'live' : 'stored';
        $this->record($admin, $player['id'], 'ban', ['reason' => $reason], $delivery);
        return $this->done($player, $delivery, $delivery === 'live' ? "Banned {$player['name']} and signed them out." : "Banned {$player['name']}.");
    }

    #[Route(Method::Post, '/groddle/v1/admin/unban', policy: new Policy(Access::Cap, self::ADMIN))]
    public function unban(Request $request): Response
    {
        [$admin, $player] = $this->target($request);
        $db = $this->minn->db;
        $db->execute('DELETE FROM ' . $db->table(self::BANS) . ' WHERE user_id = ?', [$player['id']]);
        $answer = (new RealtimeCall($this->minn))->post('unban', ['player' => $player['id']]);
        $delivery = ($answer['status'] ?? null) === 'unbanned' ? 'live' : 'stored';
        $this->record($admin, $player['id'], 'unban', [], $delivery);
        return $this->done($player, $delivery, "{$player['name']} can play again.");
    }

    #[Route(Method::Post, '/groddle/v1/admin/give', policy: new Policy(Access::Cap, self::ADMIN))]
    public function give(Request $request): Response
    {
        [$admin, $player, $body] = $this->target($request);
        $economy = Economy::shared();
        $goods = self::giveGoods($body, static fn (string $c): bool => $economy->tradeable($c));
        if (is_string($goods)) {
            throw new RestError('groddle_admin_give', $goods, 400);
        }
        $now = time();
        $op = self::GIVE_PREFIX . bin2hex(random_bytes(8));
        Ledger::atomically($this->minn->db, fn () => $this->grant($player['id'], $op, $goods, $now));
        $parts = [];
        foreach ($goods['items'] as $c => $n) {
            $parts[] = $economy->label((string) $c, $n);
        }
        if ($goods['currants'] > 0) {
            $parts[] = number_format($goods['currants']) . ($goods['currants'] === 1 ? ' currant' : ' currants');
        }
        $what = self::listWords($parts);
        $items = [];
        foreach ($goods['items'] as $c => $n) {
            $items[] = ['c' => (string) $c, 'n' => $n];
        }
        $answer = (new RealtimeCall($this->minn))->post('give', ['player' => $player['id'], 'op' => $op, 'items' => $items, 'currants' => $goods['currants'], 'text' => "An administrator gave you {$what}."]);
        $delivery = ($answer['status'] ?? null) === 'sent' ? 'live' : 'saved';
        $this->record($admin, $player['id'], 'give', ['op' => $op, 'items' => (object) $goods['items'], 'currants' => $goods['currants'], 'text' => $what], $delivery);
        return $this->done($player, $delivery, $delivery === 'live' ? "Gave {$player['name']} {$what}." : "Gave {$player['name']} {$what}. They get it when their game next loads.") ->withHeader('X-Groddle-Give', $op);
    }

    #[Route(Method::Post, '/groddle/v1/admin/refill', policy: new Policy(Access::Cap, self::ADMIN))]
    public function refill(Request $request): Response
    {
        [$admin, $player] = $this->target($request);
        $answer = (new RealtimeCall($this->minn))->post('refill', ['player' => $player['id']]);
        $delivery = match ($answer['status'] ?? null) {
            'sent' => 'live',
            'offline' => 'offline',
            default => 'down',
        };
        $this->record($admin, $player['id'], 'refill', [], $delivery);
        return $this->done($player, $delivery, match ($delivery) {
            'live' => "Filled {$player['name']}'s mood and energy.",
            'offline' => "{$player['name']} is not online. Refill works on a connected game.",
            default => 'The realtime server is not answering.',
        });
    }

    // —— Shared ——

    /** Whether an account is suspended, and why: null when it is not. @return array{reason: string}|null */
    public static function banned(Db $db, int $user): ?array
    {
        if ($user <= 0) {
            return null;
        }
        $reason = $db->value('SELECT reason FROM ' . $db->table(self::BANS) . ' WHERE user_id = ?', [$user]);
        return $reason === null ? null : ['reason' => (string) $reason];
    }

    /** One audit line (every console action and the teleport). */
    public static function recordAction(Db $db, int $admin, int $player, string $kind, array $detail, string $delivery, ?int $now = null): void
    {
        $db->execute('INSERT INTO ' . $db->table(self::ACTIONS) . ' (at, admin_user_id, player_user_id, kind, detail, delivery) VALUES (?, ?, ?, ?, ?, ?)', [
            gmdate('Y-m-d H:i:s', $now ?? time()), $admin, $player, substr($kind, 0, 12), self::detailJson($detail), substr($delivery, 0, 8),
        ]);
    }

    /** Whether a trade id is an administrator's give (Ledger::pending never stamps these by age). */
    public static function isGive(string $trade): bool
    {
        return preg_match('/^admin-give-[0-9a-f]{16}$/', $trade) === 1;
    }

    /** A message as players get it: plain text, line breaks kept (at most one blank line), at most TEXT_MAX characters. */
    public static function messageText(mixed $text): string
    {
        $text = is_string($text) ? $text : '';
        $text = (string) preg_replace('/[^\P{C}\n]+/u', '', str_replace(["\r\n", "\r"], "\n", mb_scrub($text, 'UTF-8')));
        $lines = array_map(static fn (string $l): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", $text));
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
        return trim(mb_substr($text, 0, self::TEXT_MAX));
    }

    /** A reason: one plain line of at most REASON_MAX characters. */
    public static function reasonText(mixed $text): string
    {
        $text = is_string($text) ? mb_scrub($text, 'UTF-8') : '';
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', ' ', $text)));
        return trim(mb_substr($text, 0, self::REASON_MAX));
    }

    /**
     * What a give asks for, checked: the goods (class => n, ksorted, and
     * currants), or the words for what is wrong.
     *
     * @param callable(string): bool $tradeable
     * @return array{items: array<string, int>, currants: int}|string
     */
    public static function giveGoods(array $body, callable $tradeable): array|string
    {
        $list = $body['items'] ?? [];
        $currants = $body['currants'] ?? 0;
        if (!is_array($list) || !array_is_list($list) || !is_int($currants)) {
            return 'That is not a give.';
        }
        if ($currants < 0 || $currants > self::GIVE_CURRANTS) {
            return 'Currants go from 0 to ' . number_format(self::GIVE_CURRANTS) . '.';
        }
        if (count($list) > self::GIVE_STACKS) {
            return 'At most ' . self::GIVE_STACKS . ' kinds of thing in one give.';
        }
        $items = [];
        foreach ($list as $it) {
            $c = is_array($it) ? ($it['c'] ?? null) : null;
            $n = is_array($it) ? ($it['n'] ?? null) : null;
            if (!is_string($c) || preg_match('/^[a-z0-9_]{1,64}$/', $c) !== 1 || !is_int($n)) {
                return 'That is not a give.';
            }
            if (!$tradeable($c)) {
                return "{$c} is not something the ledger carries, so it cannot be given.";
            }
            if ($n < 1 || $n > self::GIVE_EACH) {
                return 'Give 1 to ' . self::GIVE_EACH . ' of each thing.';
            }
            if (isset($items[$c])) {
                return 'Name each thing once.';
            }
            $items[$c] = $n;
        }
        if ($items === [] && $currants === 0) {
            return 'Choose something to give.';
        }
        ksort($items);
        return ['items' => $items, 'currants' => $currants];
    }

    /** "a", "a and b", "a, b and c". @param list<string> $parts */
    public static function listWords(array $parts): string
    {
        if (count($parts) < 2) {
            return $parts[0] ?? '';
        }
        $last = array_pop($parts);
        return implode(', ', $parts) . ' and ' . $last;
    }

    /** The goods into the ledger and the settled trade from nobody (see the class comment). */
    private function grant(int $user, string $op, array $goods, int $now): void
    {
        $db = $this->minn->db;
        $ledger = Ledger::fromSeams($this->minn);
        $state = $ledger->lock($user, $now);
        $after = LedgerMath::add(['items' => $state['items'], 'currants' => $state['currants']], ['items' => $goods['items'], 'currants' => $goods['currants']]);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $t = $db->table(Ledger::ITEMS);
        foreach ($goods['items'] as $c => $_) {
            $db->execute("INSERT INTO {$t} (user_id, class_tsid, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)", [$user, (string) $c, (int) $after['items'][$c]]);
        }
        $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET currants = ?, updated_at = ? WHERE user_id = ?', [$after['currants'], $stamp, $user]);
        // Stored as every trade's goods are, a list of {c, n} (LedgerMath::goods).
        $list = [];
        foreach ($goods['items'] as $c => $n) {
            $list[] = ['c' => (string) $c, 'n' => $n];
        }
        $gave = json_encode(['items' => $list, 'currants' => $goods['currants']], JSON_UNESCAPED_SLASHES);
        $db->execute('INSERT INTO ' . $db->table(Ledger::TRADES) . " (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$op, $user, '{"items":[],"currants":0}', $gave, $stamp, $stamp]);
        $ledger->log($user, 'admin', ['trade' => $op, 'by' => $this->minn->user()?->id, 'got' => ['items' => (object) $goods['items'], 'currants' => $goods['currants']]], $now);
    }

    /**
     * The caller and the character a POST names ({"player": id}).
     *
     * @return array{0: \Minn\Content\UserRecord, 1: array{id: int, name: string}, 2: array<string, mixed>}
     */
    private function target(Request $request): array
    {
        $admin = $this->minn->user();
        if ($admin === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        $body = $request->json();
        $id = $body['player'] ?? null;
        $name = is_int($id) && $id > 0 ? $this->minn->db->value('SELECT name FROM ' . $this->minn->db->table(Characters::TABLE) . ' WHERE user_id = ?', [$id]) : null;
        if ($name === null) {
            throw new RestError('groddle_admin_no_player', 'No player has that id.', 404);
        }
        return [$admin, ['id' => (int) $id, 'name' => (string) $name], $body];
    }

    private function record(\Minn\Content\UserRecord $admin, int $player, string $kind, array $detail, string $delivery): void
    {
        self::recordAction($this->minn->db, $admin->id, $player, $kind, $detail, $delivery);
    }

    private function done(array $player, string $delivery, string $text): Response
    {
        return Response::json(['player' => $player, 'delivery' => $delivery, 'text' => $text]);
    }

    /** A detail as JSON that fits the column (a long message is cut, never the JSON). */
    private static function detailJson(array $detail): string
    {
        $json = (string) json_encode($detail === [] ? new \stdClass() : $detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 2000 && is_string($detail['text'] ?? null)) {
            $detail['text'] = mb_substr($detail['text'], 0, 300) . '…';
            $json = (string) json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $json;
    }

    /**
     * Characters as the console lists them, in the order of $ids.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function rows(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $db = $this->minn->db;
        $loc = "JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(JSON_EXTRACT(s.data, '$.entries.location')), '$.tsid'))";
        $found = $db->rows('SELECT c.user_id, c.name, s.updated_at, ' . Profiles::levelSql('s') . " AS level, {$loc} AS tsid, b.user_id AS banned FROM " . $db->table(Characters::TABLE) . ' c LEFT JOIN ' . $db->table(MultiplayerSave::TABLE) . ' s ON s.user_id = c.user_id LEFT JOIN ' . $db->table(self::BANS) . " b ON b.user_id = c.user_id WHERE c.user_id IN (?)", [$ids]);
        $by = [];
        foreach ($found as $r) {
            $by[(int) $r['user_id']] = $r;
        }
        $caps = Capabilities::fromDb($db);
        $index = $this->streetIndex();
        $out = [];
        foreach ($ids as $id) {
            $r = $by[$id] ?? null;
            if ($r === null) {
                continue;
            }
            $tsid = is_string($r['tsid'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $r['tsid']) === 1 ? $r['tsid'] : null;
            $out[] = [
                'id' => $id,
                'name' => (string) $r['name'],
                'level' => max(1, (int) ($r['level'] ?? 1)),
                'street' => $tsid === null ? null : ['tsid' => $tsid, 'label' => (string) ($index[$tsid]['label'] ?? $tsid)],
                'savedAt' => $r['updated_at'] === null ? null : SaveShape::time((string) $r['updated_at']),
                'banned' => $r['banned'] !== null,
                'admin' => $caps->can($id, self::ADMIN),
            ];
        }
        return $out;
    }

    private function nameOr(array $names, int $id): string
    {
        return (string) ($names[$id] ?? "#{$id}");
    }

    /** The game's street index (tsid => row), or empty. @return array<string, array<string, mixed>> */
    private function streetIndex(): array
    {
        $file = StaticRoot::fromSite($this->minn)->dir . '/assets/streets/index.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data['streets'] ?? null) ? $data['streets'] : [];
    }
}
