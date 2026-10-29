<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Content\Users;
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
 * Visiting real players' homes and house keys in multiplayer (register
 * G328), the rules of GameServerJS players/inc_houses.js (houses_visit
 * 961-1035 and its errors in admin_houses_visit 933-959),
 * locations/inc_pols.js (pols_canEnter 278-532: a home street is public
 * unless its owner blocks you; the house needs a key, or the owner online
 * and letting you in), players/inc_visiting.js (opting in for random
 * visitors, visiting_can_opt_in 1-37; a random visit, visiting_visit_random
 * 89-129) and players/inc_acl_keys.js with locations/inc_acl_keys.js (keys:
 * acl_keys_grant 7-42 and its limits, config_prod.js:48-49; revoking,
 * acl_keys_remove 67-81; a block takes keys back both ways,
 * acl_keys_handle_ignore 93-117).
 *
 * - groddle/v1/homes/{id}: someone's home as they keep it, read from their
 *   own save here (systems.home and the homes feature's slice: the house,
 *   furniture, yard, cultivations and styles), never sent by the visitor;
 *   and a pass signed with the realtime secret ({k: home, sub: visitor, o:
 *   owner, name, key}) the game hands the realtime server (homes.go home_pass),
 *   which only lets a holder of a pass onto HOME_<owner>, and into
 *   HOUSE_<owner> with a key (or once the owner lets them in).
 * - groddle/v1/homes/random: a random home among the players who opted in
 *   (callbacks/player_random_go.php is not in the dump: here, a few
 *   opted-in players in random order, the first one houses_visit allows).
 * - groddle/v1/homes/visitors: opting in and out (table groddle_home_visitors,
 *   pc.home_allow_visits), checked against the caller's save.
 * - groddle/v1/keys: keys given and received (table groddle_house_keys, one
 *   row per key); giving or revoking one answers a proof signed with the
 *   realtime secret ({k: house_key, sub: owner, to: holder, on}) so the
 *   realtime server tells the holder (the source's prompt "<b>X</b> sent you
 *   a key to their house!") and, on a revoke, walks them out of the house
 *   (pols_kickPlayer).
 *
 * REST rules: signed in, the cookie with its nonce; the caller is the
 * signed-in user, never read from the request.
 */
final readonly class Neighbours
{
    public const KEYS = 'groddle_house_keys';

    public const VISITORS = 'groddle_home_visitors';

    /** config_prod.js:48 acl_keys_per_house_limit. */
    public const HOUSE_LIMIT = 100;

    /** config_prod.js:49 acl_keys_per_player_limit. */
    public const PLAYER_LIMIT = 100;

    /** visiting_can_opt_in: level 4 (inc_visiting.js:3). */
    public const OPT_IN_LEVEL = 4;

    /** visiting_can_opt_in: three cultivated items on the home street (inc_visiting.js:19). */
    public const OPT_IN_CULTIVATIONS = 3;

    /** Seconds a visit pass is good for (the game asks for a fresh one on every visit). */
    public const PASS_TTL = 1800;

    /** Seconds a key proof is good for. */
    public const TELL_TTL = 120;

    /** Opted-in players tried for one random visit. */
    public const RANDOM_TRIES = 8;

    /** The most of a homes slice sent to a visitor. */
    public const HOME_BUDGET = 400000;

    // The source's words (admin_houses_visit 937-956, visiting_stone.js 136-138, inc_acl_keys.js 9-35, inc_visiting.js 41-46, 128).
    public const TEXT_NOT_FOUND = 'Could not find that player.';
    public const TEXT_BLOCKED = 'You are blocked by that player.';
    public const TEXT_NO_HOUSE = 'Could not find their house.';
    public const TEXT_NEWXP = "That player has not finished the tutorial, and can't be visited until they do.";
    public const TEXT_NEWXP_US = "You can't do that until you finish the tutorial.";
    public const TEXT_RANDOM_NONE = 'Hmm, something went wrong :(';
    /** The remake's own line (the source has none): nobody else is opted in for random visitors at all. */
    public const TEXT_RANDOM_EMPTY = "Nobody's taking visitors right now. Try again later.";
    public const TEXT_LOW_LEVEL = 'You need to reach level 4 first.';
    public const TEXT_NO_CULTIVATION = 'You need to cultivate 3 items.';
    public const TEXT_NO_BUTLER = "You haven't yet unboxed your butler in your front yard.";
    public const TEXT_NO_HOME = 'You need a home street first.';
    public const TEXT_OPTED_IN = "You've been opted in - you will recieve random visitors to your street.";
    public const TEXT_OPTED_OUT = "You've been opted out - you will no longer receive random visitors to your street.";
    public const TEXT_KEY_THEY_BLOCK = "You can't give them a key.";
    public const TEXT_KEY_YOU_BLOCK = "You can't give that player a key because you're blocking them.";
    public const TEXT_KEY_NO_HOUSE = "You can't give out keys because you don't have a house!";
    public const TEXT_KEY_HOUSE_FULL = "You've already given out the maximum number of keys for your house.";
    public const TEXT_KEY_RING_FULL = 'They already have too many keys on their keyring!';

    public function __construct(private Seams $minn)
    {
    }

    /** Someone's home for a visit (houses_visit), with the pass the realtime server lets you in with. */
    #[Route(Method::Get, '/groddle/v1/homes/{id:\d+}', policy: new Policy(Access::SignedIn))]
    public function home(Request $request, string $id): Response
    {
        $owner = (int) $id;
        if ($owner <= 0 || (new Users($this->minn->db))->find($owner) === null) {
            throw new RestError('groddle_home_player', self::TEXT_NOT_FOUND, 404);
        }
        $me = $this->me();
        $view = $this->visit($me, $owner);
        if (is_string($view)) {
            throw self::refusal($view);
        }
        return Response::json($view);
    }

    /** A random opted-in player's home (visiting_visit_random, player_random_go.php). */
    #[Route(Method::Post, '/groddle/v1/homes/random', policy: new Policy(Access::SignedIn))]
    public function random(Request $request): Response
    {
        $me = $this->me();
        if (self::inTutorial(self::state($this->minn->db, $me))) {
            throw self::refusal('newxp_us');
        }
        $db = $this->minn->db;
        $visitors = $db->table(self::VISITORS);
        $ignores = $db->table(Buddies::IGNORES);
        // Opted in, not me, and not blocking me (houses_visit 'blocked'): the stone's targets.
        $ids = $db->rows("SELECT v.user_id FROM {$visitors} v WHERE v.user_id <> ?
            AND NOT EXISTS (SELECT 1 FROM {$ignores} i WHERE i.user_id = v.user_id AND i.ignored_id = ?)
            ORDER BY RAND() LIMIT " . self::RANDOM_TRIES, [$me, $me]);
        if ($ids === []) {
            throw new RestError('groddle_home_random_empty', self::TEXT_RANDOM_EMPTY, 404);
        }
        foreach ($ids as $row) {
            $view = $this->visit($me, (int) $row['user_id']);
            if (is_array($view)) {
                return Response::json($view + ['random' => true]);
            }
        }
        throw new RestError('groddle_home_random', self::TEXT_RANDOM_NONE, 404);
    }

    /** Whether the caller gets random visitors, and whether they could opt in now. */
    #[Route(Method::Get, '/groddle/v1/homes/visitors', policy: new Policy(Access::SignedIn))]
    public function visitors(Request $request): Response
    {
        return Response::json($this->visitorsAnswer());
    }

    /** Opts in for random visitors (visiting_opt_in), if visiting_can_opt_in allows. */
    #[Route(Method::Post, '/groddle/v1/homes/visitors', policy: new Policy(Access::SignedIn))]
    public function optIn(Request $request): Response
    {
        $me = $this->me();
        $can = self::canOptIn(self::state($this->minn->db, $me));
        if (!$this->optedIn($me) && !$can['ok']) {
            throw new RestError('groddle_home_' . $can['error'], $can['reason'], 400);
        }
        $db = $this->minn->db;
        $db->execute('INSERT IGNORE INTO ' . $db->table(self::VISITORS) . ' (user_id, opted_at) VALUES (?, ?)', [$me, gmdate('Y-m-d H:i:s')]);
        return Response::json($this->visitorsAnswer() + ['text' => self::TEXT_OPTED_IN]);
    }

    /** Opts out (visiting_opt_out). */
    #[Route(Method::Delete, '/groddle/v1/homes/visitors', policy: new Policy(Access::SignedIn))]
    public function optOut(Request $request): Response
    {
        $db = $this->minn->db;
        $db->execute('DELETE FROM ' . $db->table(self::VISITORS) . ' WHERE user_id = ?', [$this->me()]);
        return Response::json($this->visitorsAnswer() + ['text' => self::TEXT_OPTED_OUT]);
    }

    /** Keys given out and received (acl_keys_build_client_msg). */
    #[Route(Method::Get, '/groddle/v1/keys', policy: new Policy(Access::SignedIn))]
    public function keys(Request $request): Response
    {
        return Response::json($this->keysAnswer());
    }

    /** Gives a player a key to your house (acl_keys_grant). */
    #[Route(Method::Post, '/groddle/v1/keys', policy: new Policy(Access::SignedIn))]
    public function grant(Request $request): Response
    {
        $me = $this->me();
        $to = $this->target($request->json()['id'] ?? null);
        $db = $this->minn->db;
        if ($this->ignoring($to, $me)) {
            throw new RestError('groddle_key_blocked', self::TEXT_KEY_THEY_BLOCK, 403);
        }
        if ($this->ignoring($me, $to)) {
            throw new RestError('groddle_key_blocking', self::TEXT_KEY_YOU_BLOCK, 403);
        }
        if (!self::hasHome(self::state($db, $me))) {
            throw new RestError('groddle_key_no_house', self::TEXT_KEY_NO_HOUSE, 400);
        }
        $keys = $db->table(self::KEYS);
        $held = $db->value("SELECT 1 FROM {$keys} WHERE owner_id = ? AND holder_id = ?", [$me, $to]) !== null;
        if (!$held) {
            if ((int) $db->value("SELECT COUNT(*) FROM {$keys} WHERE owner_id = ?", [$me]) >= self::HOUSE_LIMIT) {
                throw new RestError('groddle_key_house_full', self::TEXT_KEY_HOUSE_FULL, 400, ['limit' => self::HOUSE_LIMIT]);
            }
            if ((int) $db->value("SELECT COUNT(*) FROM {$keys} WHERE holder_id = ?", [$to]) >= self::PLAYER_LIMIT) {
                throw new RestError('groddle_key_ring_full', self::TEXT_KEY_RING_FULL, 400, ['limit' => self::PLAYER_LIMIT]);
            }
            $db->execute("INSERT IGNORE INTO {$keys} (owner_id, holder_id, granted_at) VALUES (?, ?, ?)", [$me, $to, gmdate('Y-m-d H:i:s')]);
        }
        return Response::json($this->keysAnswer() + ['given' => $to, 'told' => $this->tell($me, $to, true)], $held ? 200 : 201);
    }

    /** Takes a key back (acl_keys_remove): the holder leaves the house if they are in it. */
    #[Route(Method::Delete, '/groddle/v1/keys/{id:\d+}', policy: new Policy(Access::SignedIn))]
    public function revoke(Request $request, string $id): Response
    {
        $me = $this->me();
        $db = $this->minn->db;
        $keys = $db->table(self::KEYS);
        if ($db->execute("DELETE FROM {$keys} WHERE owner_id = ? AND holder_id = ?", [$me, (int) $id]) === 0) {
            throw new RestError('groddle_key_missing', 'They do not have a key to your house.', 404);
        }
        return Response::json($this->keysAnswer() + ['revoked' => (int) $id, 'told' => $this->tell($me, (int) $id, false)]);
    }

    private function me(): int
    {
        return $this->minn->reader->userId;
    }

    /**
     * The visit view, or the houses_visit error: player_not_found, blocked,
     * house_not_found, newxp, newxp_us.
     *
     * @return array<string, mixed>|string
     */
    private function visit(int $me, int $owner): array|string
    {
        $db = $this->minn->db;
        if ($owner !== $me && $this->ignoring($owner, $me)) {
            return 'blocked';
        }
        $theirs = self::state($db, $owner);
        if (!self::hasHome($theirs)) {
            return 'house_not_found';
        }
        if ($owner !== $me) {
            if (self::inTutorial($theirs)) {
                return 'newxp';
            }
            if (self::inTutorial(self::state($db, $me))) {
                return 'newxp_us';
            }
        }
        $homes = self::homesSlice($theirs);
        if ($homes === null) {
            return 'house_not_found';
        }
        $name = (new Characters($db))->namesOf([$owner])[$owner] ?? null;
        if ($name === null) {
            return 'player_not_found';
        }
        $key = $owner === $me || $this->holdsKey($owner, $me);
        return [
            'owner' => ['id' => $owner, 'name' => $name],
            'home' => ['owned' => true, 'style' => self::homeStyle($theirs)],
            // Slice 2: the gardens with the key-holders' waiting acts on them (HomeTending), and the Butler as it looks.
            'homes' => HomeTending::overlay($homes, HomeTending::rowsFor($db, $owner)),
            'butler' => self::butlerView($theirs),
            'key' => $key,
            'pass' => $this->pass($me, $owner, $name, $key),
        ];
    }

    private static function refusal(string $error): RestError
    {
        return match ($error) {
            'blocked' => new RestError('groddle_home_blocked', self::TEXT_BLOCKED, 403),
            'house_not_found' => new RestError('groddle_home_none', self::TEXT_NO_HOUSE, 404),
            'newxp' => new RestError('groddle_home_newxp', self::TEXT_NEWXP, 403),
            'newxp_us' => new RestError('groddle_home_newxp_us', self::TEXT_NEWXP_US, 403),
            default => new RestError('groddle_home_player', self::TEXT_NOT_FOUND, 404),
        };
    }

    /** @return array{token: string, expiresIn: int}|null */
    private function pass(int $me, int $owner, string $name, bool $key): ?array
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null) {
            return null;
        }
        $now = time();
        $payload = ['k' => 'home', 'sub' => $me, 'o' => $owner, 'name' => $name, 'key' => $key ? 1 : 0, 'iat' => $now, 'exp' => $now + self::PASS_TTL];
        return ['token' => RealtimeTicket::sign($payload, $secret), 'expiresIn' => self::PASS_TTL];
    }

    /** The proof of a key given (on) or taken back, for the realtime server. */
    private function tell(int $owner, int $holder, bool $on): ?string
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null) {
            return null;
        }
        $now = time();
        return RealtimeTicket::sign(['k' => 'house_key', 'sub' => $owner, 'to' => $holder, 'on' => $on ? 1 : 0, 'iat' => $now, 'exp' => $now + self::TELL_TTL, 'n' => bin2hex(random_bytes(8))], $secret);
    }

    /** A player id from the body: a number, not the caller, an account that exists. */
    private function target(mixed $id): int
    {
        if (!is_int($id) && !(is_string($id) && preg_match('/^[1-9]\d{0,18}$/', $id) === 1)) {
            throw new RestError('groddle_key_id', 'Name a player by their id.', 400);
        }
        $id = (int) $id;
        if ($id <= 0) {
            throw new RestError('groddle_key_id', 'Name a player by their id.', 400);
        }
        if ($id === $this->me()) {
            throw new RestError('groddle_key_self', 'You already have a key to your own house.', 400);
        }
        if ((new Users($this->minn->db))->find($id) === null) {
            throw new RestError('groddle_key_unknown', self::TEXT_NOT_FOUND, 404);
        }
        return $id;
    }

    private function ignoring(int $ignorer, int $other): bool
    {
        $ignores = $this->minn->db->table(Buddies::IGNORES);
        return $this->minn->db->value("SELECT 1 FROM {$ignores} WHERE user_id = ? AND ignored_id = ?", [$ignorer, $other]) !== null;
    }

    /** Whether holder has a key to owner's house (HomeTending: a key-holder tends the gardens). */
    public function holdsKeyTo(int $owner, int $holder): bool
    {
        return $owner > 0 && $holder > 0 && $owner !== $holder && $this->holdsKey($owner, $holder);
    }

    /** Whether holder has a key to owner's house; a block either way takes it back (acl_keys_handle_ignore). */
    private function holdsKey(int $owner, int $holder): bool
    {
        $this->dropBlockedKeys($holder);
        $keys = $this->minn->db->table(self::KEYS);
        return $this->minn->db->value("SELECT 1 FROM {$keys} WHERE owner_id = ? AND holder_id = ?", [$owner, $holder]) !== null;
    }

    /** acl_keys_handle_ignore 93-117: keys between two players where one blocks the other go, for every key of id's. */
    private function dropBlockedKeys(int $id): void
    {
        $db = $this->minn->db;
        $keys = $db->table(self::KEYS);
        $ignores = $db->table(Buddies::IGNORES);
        $db->execute("DELETE k FROM {$keys} k JOIN {$ignores} i
            ON (i.user_id = k.owner_id AND i.ignored_id = k.holder_id) OR (i.user_id = k.holder_id AND i.ignored_id = k.owner_id)
            WHERE k.owner_id = ? OR k.holder_id = ?", [$id, $id]);
    }

    private function optedIn(int $id): bool
    {
        $db = $this->minn->db;
        return $db->value('SELECT 1 FROM ' . $db->table(self::VISITORS) . ' WHERE user_id = ?', [$id]) !== null;
    }

    /** @return array{allow: bool, can: array{ok: bool, error?: string, reason?: string}} */
    private function visitorsAnswer(): array
    {
        $me = $this->me();
        return ['allow' => $this->optedIn($me), 'can' => self::canOptIn(self::state($this->minn->db, $me))];
    }

    /** acl_keys_build_client_msg: keys_given and keys_received, newest first, with names. */
    private function keysAnswer(): array
    {
        $db = $this->minn->db;
        $me = $this->me();
        $this->dropBlockedKeys($me);
        $keys = $db->table(self::KEYS);
        $given = $db->rows("SELECT holder_id AS id, granted_at FROM {$keys} WHERE owner_id = ? ORDER BY granted_at DESC, holder_id DESC", [$me]);
        $received = $db->rows("SELECT owner_id AS id, granted_at FROM {$keys} WHERE holder_id = ? ORDER BY granted_at DESC, owner_id DESC", [$me]);
        $names = (new Characters($db))->namesOf(array_merge(
            array_map(static fn (array $r): int => (int) $r['id'], $given),
            array_map(static fn (array $r): int => (int) $r['id'], $received),
        ));
        $rows = static function (array $list) use ($names): array {
            $out = [];
            foreach ($list as $r) {
                $id = (int) $r['id'];
                if (isset($names[$id])) {
                    $out[] = ['id' => $id, 'name' => $names[$id], 'at' => SaveShape::time((string) $r['granted_at'])];
                }
            }
            return $out;
        };
        return [
            'given' => $rows($given),
            'received' => $rows($received),
            'houseLimit' => self::HOUSE_LIMIT,
            'playerLimit' => self::PLAYER_LIMIT,
            'hasHouse' => self::hasHome(self::state($db, $me)),
        ];
    }

    // —— Reading a save ——

    /** A character's playerState from their multiplayer save, as objects (empty objects stay objects), or null. */
    public static function state(Db $db, int $id): ?object
    {
        $stored = $db->value('SELECT data FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ?', [$id]);
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        $save = json_decode($stored, false, 64);
        $raw = is_object($save) && is_object($save->entries ?? null) ? ($save->entries->playerState ?? null) : null;
        if (!is_string($raw)) {
            return null;
        }
        $state = json_decode($raw, false, 128);
        return is_object($state) ? $state : null;
    }

    private static function feature(?object $state, string $id): ?object
    {
        $features = $state?->systems?->features ?? null;
        $slice = is_object($features) ? ($features->{$id} ?? null) : null;
        return is_object($slice) ? $slice : null;
    }

    /** The home is made (js/homes.js: systems.home.owned). */
    public static function hasHome(?object $state): bool
    {
        return ($state?->systems?->home?->owned ?? false) === true;
    }

    /** systems.home.style, one of the three. */
    public static function homeStyle(?object $state): string
    {
        $style = $state?->systems?->home?->style ?? 'meadow';
        return in_array($style, ['meadow', 'firebog', 'uralia'], true) ? $style : 'meadow';
    }

    /** Still on Gentle Island (the newxp feature 'active': has_done_intro unset, leave_gentle_island todo). */
    public static function inTutorial(?object $state): bool
    {
        return (self::feature($state, 'newxp')?->status ?? '') === 'active';
    }

    public static function level(?object $state): int
    {
        $level = $state?->player?->level ?? 1;
        return is_numeric($level) ? max(1, min(999, (int) $level)) : 1;
    }

    /** visiting_can_opt_in 1-37: level 4, a home, 3 cultivated items, an unboxed Butler. */
    public static function canOptIn(?object $state): array
    {
        if (self::level($state) < self::OPT_IN_LEVEL) {
            return ['ok' => false, 'error' => 'low_level', 'reason' => self::TEXT_LOW_LEVEL];
        }
        if (!self::hasHome($state)) {
            return ['ok' => false, 'error' => 'no_home', 'reason' => self::TEXT_NO_HOME];
        }
        $items = self::feature($state, 'homes')?->cult?->items ?? [];
        $jobs = is_array($items) ? count($items) : 0;
        if ($jobs < self::OPT_IN_CULTIVATIONS) {
            return ['ok' => false, 'error' => 'no_cultivation', 'reason' => self::TEXT_NO_CULTIVATION, 'jobs' => $jobs];
        }
        if ((self::feature($state, 'butler')?->owned ?? false) !== true) {
            return ['ok' => false, 'error' => 'no_butler', 'reason' => self::TEXT_NO_BUTLER];
        }
        return ['ok' => true];
    }

    /**
     * The owner's Butler as a visitor sees it (G328 slice 2: bag_butler.js
     * stands at its post on the home street): whether it is there, its name,
     * its eight parts (the customizer's, js/butler.js normalizeButlerParts
     * checks them) and its post relative to the house. Not what it holds.
     *
     * @return array{owned: bool, name?: string, parts?: object, post?: int|null}
     */
    public static function butlerView(?object $state): array
    {
        $b = self::feature($state, 'butler');
        if ($b === null || ($b->owned ?? false) !== true) {
            return ['owned' => false];
        }
        $name = is_string($b->name ?? null) ? mb_substr(trim(strip_tags($b->name)), 0, 19) : '';
        $parts = (object) [];
        foreach (is_object($b->parts ?? null) ? get_object_vars($b->parts) : [] as $k => $v) {
            if (is_string($k) && preg_match('/^[a-zA-Z]{1,16}$/', $k) === 1 && is_string($v) && preg_match('/^[a-z0-9_]{1,32}$/', $v) === 1) {
                $parts->{$k} = $v;
            }
        }
        $post = is_numeric($b->post ?? null) ? (int) round((float) $b->post) : null;
        return ['owned' => true, 'name' => $name, 'parts' => $parts, 'post' => $post];
    }

    /**
     * What a visitor sees of the homes feature's slice: the house's decor,
     * the street's and back yard's cultivations and their styles, and where
     * the house stands. Not the Magic Rock's flags, the house visit counts or
     * the Tower floors.
     */
    public static function homesSlice(?object $state): ?object
    {
        $homes = self::feature($state, 'homes');
        if ($homes === null) {
            return (object) [];
        }
        $out = (object) [];
        foreach (['decor', 'cult', 'backYard'] as $key) {
            if (is_object($homes->{$key} ?? null)) {
                $out->{$key} = $homes->{$key};
            }
        }
        foreach (['streetStyle', 'yardStyle'] as $key) {
            $value = $homes->{$key} ?? null;
            if (is_string($value) && preg_match('/^[a-z0-9_]{1,64}$/', $value) === 1) {
                $out->{$key} = $value;
            }
        }
        $json = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > self::HOME_BUDGET) {
            return null;
        }
        return $out;
    }
}
