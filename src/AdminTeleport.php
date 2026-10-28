<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * groddle/v1/admin/teleport (v0.2.2): an administrator frees a stuck
 * player, from the game's chat (/tp, js/admin-teleport.js) or by hand.
 * Administrators only (manage_options); players are subscribers and are
 * refused (401 signed out, 403 signed in).
 *
 *   GET  admin/teleport   {"recent": [line…]}: the last 20 moves, newest first
 *   POST admin/teleport   {"player": "<character name>"?, "tsid": "GA5…", "x"?: n, "y"?: n}
 *        200 {"player": {id, name}, "street": {tsid, label}, "from": {tsid, x, y}|null,
 *             "at": {x, y}, "spot": bool, "delivery": "self"|"live"|"saved", "text": "…"}
 *
 * No player (or the caller's own name) moves the caller: the game enters
 * the street itself and saves. Another player is moved live when the
 * realtime server has them connected (a call signed as the server signs
 * its ledger calls, ServiceCall, to its POST /ws?call=teleport,
 * groddle-afternoon-server teleport.go): they leave their street, their game
 * enters the new one and saves. Otherwise (offline, or the server not
 * answering) their stored save's location entry is rewritten here with the
 * version bumped, so an open tab that is not connected conflicts instead
 * of writing the old street back, and they start on the new street next
 * time. Either way an audit line goes in groddle_admin_teleports.
 *
 * The street must be in the game's street index (assets/streets/index.json
 * under the built game, StaticRoot). "spot" says whether a place on the
 * street was named (x, or x and y); without one a live move lands where
 * the game puts an arrival, and a saved one at TeleportPlace::spot.
 */
final readonly class AdminTeleport
{
    public const TABLE = 'groddle_admin_teleports';

    /** How many lines GET shows. */
    public const RECENT = 20;

    /** How long the site waits for the realtime server. */
    public const SERVICE_TIMEOUT = 3.0;

    public function __construct(private Seams $minn)
    {
    }

    /** The last moves, for an administrator (and the game's check that /tp is theirs to use). */
    #[Route(Method::Get, '/groddle/v1/admin/teleport', policy: new Policy(Access::Cap, 'manage_options'))]
    public function recent(Request $request): Response
    {
        $db = $this->minn->db;
        $rows = $db->rows('SELECT * FROM ' . $db->table(self::TABLE) . ' ORDER BY id DESC LIMIT ' . self::RECENT);
        $names = (new Characters($db))->namesOf(array_merge(array_map(static fn (array $r): int => (int) $r['admin_user_id'], $rows), array_map(static fn (array $r): int => (int) $r['player_user_id'], $rows)));
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = [
                'id' => (int) $r['id'],
                'at' => SaveShape::time((string) $r['at']),
                'by' => ['id' => (int) $r['admin_user_id'], 'name' => $names[(int) $r['admin_user_id']] ?? ''],
                'player' => ['id' => (int) $r['player_user_id'], 'name' => $names[(int) $r['player_user_id']] ?? ''],
                'from' => $r['from_tsid'] === null ? null : ['tsid' => (string) $r['from_tsid'], 'x' => (int) $r['from_x'], 'y' => (int) $r['from_y']],
                'to' => ['tsid' => (string) $r['to_tsid'], 'x' => (int) $r['to_x'], 'y' => (int) $r['to_y']],
                'delivery' => (string) $r['delivery'],
            ];
        }
        return Response::json(['recent' => $lines]);
    }

    /** Moves a player (or the caller) to a street. */
    #[Route(Method::Post, '/groddle/v1/admin/teleport', policy: new Policy(Access::Cap, 'manage_options'))]
    public function teleport(Request $request): Response
    {
        $admin = $this->minn->user();
        if ($admin === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        $body = $request->json();
        $db = $this->minn->db;
        $characters = new Characters($db);
        $asked = trim(preg_replace('/\s+/', ' ', (string) ($body['player'] ?? '')) ?? '');
        $player = $asked === '' ? ['id' => $admin->id, 'name' => $characters->nameOf($admin)] : $this->findPlayer($asked);
        $self = $player['id'] === $admin->id;

        $index = $this->json('assets/streets/index.json');
        $streets = is_array($index['streets'] ?? null) ? $index['streets'] : [];
        $tsid = TeleportPlace::streetKey($streets, (string) ($body['tsid'] ?? ''));
        if ($tsid === null) {
            throw new RestError('groddle_tp_street', 'No street has that id.', 404);
        }
        $label = (string) ($streets[$tsid]['label'] ?? $tsid);
        $x = self::number($body['x'] ?? null);
        $y = $x === null ? null : self::number($body['y'] ?? null);
        $spot = $x !== null;
        $file = (string) ($streets[$tsid]['file'] ?? "assets/streets/{$tsid}.json");
        $at = TeleportPlace::spot($this->json($file) ?? [], $x, $y);

        $from = $this->location($player['id']);
        $delivery = 'self';
        if (!$self) {
            $delivery = $this->tellServer($player['id'], $tsid, $spot ? $at : null) ? 'live' : 'saved';
            if ($delivery === 'saved' && !$this->rewriteSave($player['id'], $tsid, $at)) {
                throw new RestError('groddle_tp_no_save', "{$player['name']} is not online and has no saved game to move yet.", 409);
            }
        }
        $db->execute('INSERT INTO ' . $db->table(self::TABLE) . ' (at, admin_user_id, player_user_id, from_tsid, from_x, from_y, to_tsid, to_x, to_y, delivery) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            gmdate('Y-m-d H:i:s'), $admin->id, $player['id'], $from['tsid'] ?? null, $from['x'] ?? null, $from['y'] ?? null, $tsid, $at['x'], $at['y'], $delivery,
        ]);
        $text = match ($delivery) {
            'self' => "Moving you to {$label}.",
            'live' => "Moved {$player['name']} to {$label}.",
            default => "{$player['name']} is not online. They will start on {$label} next time.",
        };
        return Response::json([
            'player' => $player,
            'street' => ['tsid' => $tsid, 'label' => $label],
            'from' => $from,
            'at' => $at,
            'spot' => $spot,
            'delivery' => $delivery,
            'text' => $text,
        ]);
    }

    /**
     * A character by name: the exact name (letter case aside), else the one
     * name that starts with it; several are named back so the admin can say which.
     *
     * @return array{id: int, name: string}
     */
    private function findPlayer(string $asked): array
    {
        $db = $this->minn->db;
        $table = $db->table(Characters::TABLE);
        $key = strtolower($asked);
        $row = $db->row("SELECT user_id, name FROM {$table} WHERE name_key = ? LIMIT 1", [$key]);
        if ($row === null) {
            $rows = $db->rows("SELECT user_id, name FROM {$table} WHERE name_key LIKE ? ORDER BY name_key LIMIT 7", [addcslashes($key, '%_\\') . '%']);
            if (count($rows) > 1) {
                $names = array_map(static fn (array $r): string => (string) $r['name'], array_slice($rows, 0, 6));
                throw new RestError('groddle_tp_which_player', 'Which player? ' . implode(', ', $names) . (count($rows) > 6 ? ', and more' : '') . '.', 409, ['players' => $names]);
            }
            $row = $rows[0] ?? null;
        }
        if ($row === null) {
            throw new RestError('groddle_tp_no_player', "No player is called {$asked}.", 404);
        }
        return ['id' => (int) $row['user_id'], 'name' => (string) $row['name']];
    }

    /** Where the player's stored save says they are, or null. */
    private function location(int $user): ?array
    {
        $db = $this->minn->db;
        $data = $db->value('SELECT data FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ?', [$user]);
        $save = is_string($data) ? SaveShape::decode($data) : null;
        $entry = $save === null ? null : (((array) $save->entries)['location'] ?? null);
        $loc = is_string($entry) ? json_decode($entry, true) : null;
        if (!is_array($loc) || !is_string($loc['tsid'] ?? null)) {
            return null;
        }
        return ['tsid' => substr($loc['tsid'], 0, 32), 'x' => (int) round((float) ($loc['x'] ?? 0)), 'y' => (int) round((float) ($loc['y'] ?? 0))];
    }

    /** Rewrites the stored save's location and bumps its version; false when there is no save. */
    private function rewriteSave(int $user, string $tsid, array $at): bool
    {
        $db = $this->minn->db;
        $table = $db->table(MultiplayerSave::TABLE);
        return (bool) Ledger::atomically($db, function () use ($db, $table, $user, $tsid, $at): bool {
            $row = $db->row("SELECT data, version FROM {$table} WHERE user_id = ? FOR UPDATE", [$user]);
            $save = $row === null ? null : SaveShape::decode((string) $row['data']);
            if ($save === null) {
                return false;
            }
            $entries = array_filter((array) $save->entries, 'is_string');
            $entries['location'] = TeleportPlace::locationEntry($tsid, $at['x'], $at['y'], (int) floor(microtime(true) * 1000));
            $json = SaveShape::encode(['kind' => $save->kind, 'format' => $save->format, 'entries' => $entries]);
            $db->execute("UPDATE {$table} SET data = ?, version = version + 1, updated_at = ? WHERE user_id = ? AND version = ?", [$json, gmdate('Y-m-d H:i:s'), $user, (int) $row['version']]);
            return true;
        });
    }

    /** Asks the realtime server to move a connected player; true when it did. */
    private function tellServer(int $user, string $tsid, ?array $at): bool
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null) {
            return false;
        }
        $call = ['player' => $user, 'street' => $tsid];
        if ($at !== null) {
            $call['x'] = $at['x'];
            $call['y'] = $at['y'];
        }
        $body = json_encode($call, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $named = $this->minn->site->option('groddle_realtime_service_url');
        $url = TeleportPlace::serviceUrl(is_string($named) ? $named : null, RealtimeTicket::socketUrl());
        $loopback = TeleportPlace::loopbackHost($url);
        try {
            $reply = $this->minn->http->post($url, body: $body, query: ['call' => 'teleport'], headers: [
                'Content-Type' => 'application/json',
                'X-Groddle-Service' => ServiceCall::sign('POST', '/realtime/v1/teleport', time(), bin2hex(random_bytes(8)), $body, $secret),
            ], timeout: self::SERVICE_TIMEOUT, redirects: 0, private: $loopback === null ? [] : [$loopback], maxBytes: 4096);
        } catch (\Throwable) {
            return false;
        }
        $answer = $reply->ok() ? json_decode($reply->body, true) : null;
        return is_array($answer) && ($answer['status'] ?? null) === 'sent';
    }

    /** A JSON file of the built game, or null. */
    private function json(string $path): ?array
    {
        if (preg_match('~^assets/streets/[A-Za-z0-9_.-]+\.json$~', $path) !== 1) {
            return null;
        }
        $file = StaticRoot::fromSite($this->minn)->dir . '/' . $path;
        $text = is_file($file) ? file_get_contents($file) : false;
        $data = is_string($text) ? json_decode($text, true) : null;
        return is_array($data) ? $data : null;
    }

    private static function number(mixed $v): ?float
    {
        return (is_int($v) || is_float($v)) && is_finite((float) $v) ? (float) $v : null;
    }
}
