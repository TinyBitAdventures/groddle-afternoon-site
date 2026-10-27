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
use Minn\Json;
use Minn\RestError;

/**
 * groddle/v1/groups: player groups in multiplayer (register G326), the
 * rules of GameServerJS groups/group.js and players/inc_groups.js, and the
 * signed list the realtime server lets a player open group chat and the
 * game-wide channels with (groddle-afternoon-server channels.go).
 *
 * A group has a name, a description and a mode (group.js:24-35 doCreate:
 * public, or private; the source's third mode, public_apply, where an
 * administrator approves each request, is left out). Its creator is its
 * administrator (group.js:76-80, level 1); everyone joining is a member
 * (group.js:150-202). Anyone may find and join a public group; a private
 * one takes an invitation from a member (group.js:1203-1235 invite: the
 * inviter must be in the group; 1237-1260 accepting joins; 1262-1292
 * declining). A player is in at most MAX_GROUPS groups (config_prod.js:39
 * max_groups; inc_groups.js:261 and group.js:267 report it). Leaving is
 * leaving (group.js:886-939); when the administrator leaves, the member who
 * joined first takes over (get_promote_candidate 826-884), and when the
 * last member leaves the group is gone (group.js:931-936 doDelete). Here the
 * new administrator is worked out when the list is read, so only the
 * caller's own membership row is ever written. Roles beyond the two, kicks,
 * bans, applications, forums and group halls are not here.
 *
 * Each answer about the caller carries a watch token: their group ids and
 * the channels they may use, signed with the realtime secret
 * (RealtimeTicket::sign), which the game hands the realtime server
 * (groups_watch). The channels (config_prod.js:1290-1294): Global Chat for
 * everyone; Live Help, which is the newbie channel under level 11
 * (main.js:579-585), read from the multiplayer save; the Trade Channel with
 * the Trade Channelling upgrade (player.js:3404 canJoinTradeChat). The
 * cones of silence (player.js:2908-2933, main.js:5809-5833) are the user
 * meta groddle_cone_of_silence: "chat", "help" or "chat,help", set by the
 * site's people (wp user meta update <id> groddle_cone_of_silence chat).
 *
 * REST rules: signed in, the cookie with its nonce; the user is the caller,
 * never read from the request.
 */
final readonly class Groups
{
    public const TABLE = 'groddle_groups';

    public const MEMBERS = 'groddle_group_members';

    public const INVITES = 'groddle_group_invites';

    /** config_prod.js:39 max_groups. */
    public const MAX_GROUPS = 20;

    /** main.js:580 stats_get_level() < 11: the newbie Live Help. */
    public const NEWBIE_BELOW = 11;

    /** The imagination upgrade the Trade Channel needs (player.js:3404). */
    public const TRADE_UPGRADE = 'trade_channel';

    /** A group's name: a character name's letters (PlayerAccount::NAME), up to 32 of them. */
    public const NAME = '/^[A-Za-z0-9](?:[A-Za-z0-9 _-]{1,30})[A-Za-z0-9]$/';

    public const DESCRIPTION_MAX = 300;

    public const MODES = ['public', 'private'];

    /** Seconds a watch token is good for (the game asks for a fresh one well before). */
    public const WATCH_TTL = 3600;

    /** Groups one search answers. */
    public const SEARCH_LIMIT = 30;

    public const SILENCE_META = 'groddle_cone_of_silence';

    public function __construct(private Seams $minn)
    {
    }

    /** The caller's groups, invitations, channels and the watch token. */
    #[Route(Method::Get, '/groddle/v1/groups', policy: new Policy(Access::SignedIn))]
    public function mine(Request $request): Response
    {
        return Response::json($this->answer());
    }

    /** Public groups by name (or the biggest, with no words), for Find a group. */
    #[Route(Method::Get, '/groddle/v1/groups/search', policy: new Policy(Access::SignedIn))]
    public function search(Request $request): Response
    {
        $q = trim(preg_replace('/\s+/', ' ', (string) $request->query('q', '')) ?? '');
        $q = mb_substr($q, 0, 32);
        $db = $this->minn->db;
        $groups = $db->table(self::TABLE);
        $members = $db->table(self::MEMBERS);
        $where = "g.mode = 'public'";
        $params = [];
        if ($q !== '') {
            $where .= ' AND g.name_key LIKE ?';
            $params[] = '%' . addcslashes(strtolower($q), '%_\\') . '%';
        }
        $rows = $db->rows("SELECT g.id, g.name, g.description, g.mode, g.created_at, (SELECT COUNT(*) FROM {$members} m WHERE m.group_id = g.id) AS members,
            (SELECT 1 FROM {$members} m WHERE m.group_id = g.id AND m.user_id = ?) AS mine
            FROM {$groups} g WHERE {$where} ORDER BY members DESC, g.created_at DESC, g.id DESC LIMIT " . self::SEARCH_LIMIT, [$this->me(), ...$params]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::card($r) + ['member' => $r['mine'] !== null];
        }
        return Response::json(['q' => $q, 'groups' => $out]);
    }

    /** One group: what it is and who is in it (public groups to anyone, private ones to members and invitees). */
    #[Route(Method::Get, '/groddle/v1/groups/{id:\d+}', policy: new Policy(Access::SignedIn))]
    public function show(Request $request, string $id): Response
    {
        $group = $this->group((int) $id);
        $me = $this->me();
        $member = $this->memberRole($group, $me) !== null;
        $invite = $this->invite((int) $group['id'], $me);
        if ($group['mode'] !== 'public' && !$member && $invite === null) {
            throw self::missing();
        }
        $people = $this->members((int) $group['id']);
        $names = (new Characters($this->minn->db))->namesOf(array_map(static fn (array $m): int => $m['id'], $people));
        $list = [];
        foreach ($people as $m) {
            if (isset($names[$m['id']])) {
                $list[] = ['id' => $m['id'], 'name' => $names[$m['id']], 'role' => $m['role'], 'joinedAt' => SaveShape::time($m['joined_at'])];
            }
        }
        return Response::json(self::card($group + ['members' => count($people)]) + ['member' => $member, 'invited' => $invite !== null, 'people' => $list]);
    }

    /** Makes a group with the caller as its administrator (inc_groups.js:38-73 groups_create, group.js:24-88 doCreate). */
    #[Route(Method::Post, '/groddle/v1/groups', policy: new Policy(Access::SignedIn))]
    public function create(Request $request): Response
    {
        $body = $request->json();
        $name = trim(preg_replace('/\s+/', ' ', (string) ($body['name'] ?? '')) ?? '');
        $description = self::description($body['description'] ?? '');
        $mode = (string) ($body['mode'] ?? 'public');
        $fields = [];
        if (preg_match(self::NAME, $name) !== 1) {
            $fields['name'] = 'Group names are 3 to 32 letters, numbers, spaces, - or _, starting and ending with a letter or number.';
        }
        if (!in_array($mode, self::MODES, true)) {
            $fields['mode'] = 'A group is public or private.';
        }
        if ($description === null) {
            $fields['description'] = 'Descriptions are up to ' . self::DESCRIPTION_MAX . ' characters.';
        }
        if ($fields !== []) {
            throw new RestError('groddle_group_invalid', (string) reset($fields), 400, ['fields' => $fields]);
        }
        $me = $this->me();
        $this->underLimit($me);
        $db = $this->minn->db;
        $groups = $db->table(self::TABLE);
        $key = strtolower($name);
        if ($db->value("SELECT 1 FROM {$groups} WHERE name_key = ?", [$key]) !== null) {
            throw new RestError('groddle_group_taken', 'There is already a group with that name. Try another.', 409, ['fields' => ['name' => 'There is already a group with that name. Try another.']]);
        }
        $now = gmdate('Y-m-d H:i:s');
        $db->execute("INSERT IGNORE INTO {$groups} (name, name_key, description, mode, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)", [$name, $key, $description, $mode, $me, $now]);
        $id = $db->insertId();
        if ($id <= 0 || (int) $db->value("SELECT id FROM {$groups} WHERE name_key = ?", [$key]) !== $id) {
            // Two players racing for one name: the first keeps it.
            throw new RestError('groddle_group_taken', 'There is already a group with that name. Try another.', 409, ['fields' => ['name' => 'There is already a group with that name. Try another.']]);
        }
        $db->execute('INSERT IGNORE INTO ' . $db->table(self::MEMBERS) . ' (group_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)', [$id, $me, 'admin', $now]);
        return Response::json($this->answer() + ['created' => $id], 201);
    }

    /** Joins a group: a public one, or a private one the caller is invited to (group.js:150-202 join, 1237-1260). */
    #[Route(Method::Post, '/groddle/v1/groups/{id:\d+}/members', policy: new Policy(Access::SignedIn))]
    public function join(Request $request, string $id): Response
    {
        $me = $this->me();
        $group = $this->group((int) $id);
        $gid = (int) $group['id'];
        if ($this->memberRole($group, $me) !== null) {
            // group.js:154 already in: ok.
            return Response::json($this->answer() + ['joined' => $gid]);
        }
        $invite = $this->invite($gid, $me);
        if ($group['mode'] !== 'public' && $invite === null) {
            throw new RestError('groddle_group_private', 'That group is invite only.', 403);
        }
        $this->underLimit($me);
        $db = $this->minn->db;
        $db->execute('INSERT IGNORE INTO ' . $db->table(self::MEMBERS) . ' (group_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)', [$gid, $me, 'member', gmdate('Y-m-d H:i:s')]);
        // group.js:189-193: joining takes up the invitation.
        $db->execute('DELETE FROM ' . $db->table(self::INVITES) . ' WHERE group_id = ? AND user_id = ?', [$gid, $me]);
        return Response::json($this->answer() + ['joined' => $gid]);
    }

    /** Leaves a group (inc_groups.js:163-197 groups_leave, group.js:886-939). */
    #[Route(Method::Delete, '/groddle/v1/groups/{id:\d+}/members', policy: new Policy(Access::SignedIn))]
    public function leave(Request $request, string $id): Response
    {
        $gid = (int) $id;
        $db = $this->minn->db;
        $members = $db->table(self::MEMBERS);
        if ($db->execute("DELETE FROM {$members} WHERE group_id = ? AND user_id = ?", [$gid, $this->me()]) === 0) {
            throw new RestError('groddle_group_not_member', 'You are not in that group.', 404);
        }
        // group.js:931-936: the last member leaving ends the group.
        if ((int) $db->value("SELECT COUNT(*) FROM {$members} WHERE group_id = ?", [$gid]) === 0) {
            $db->execute('DELETE FROM ' . $db->table(self::INVITES) . ' WHERE group_id = ?', [$gid]);
            $db->execute('DELETE FROM ' . $db->table(self::TABLE) . ' WHERE id = ?', [$gid]);
        }
        return Response::json($this->answer() + ['left' => $gid]);
    }

    /** A member invites another player (group.js:1180-1235 admin_invite, invite). */
    #[Route(Method::Post, '/groddle/v1/groups/{id:\d+}/invites', policy: new Policy(Access::SignedIn))]
    public function inviteSomeone(Request $request, string $id): Response
    {
        $me = $this->me();
        $group = $this->group((int) $id);
        $gid = (int) $group['id'];
        if ($this->memberRole($group, $me) === null) {
            throw new RestError('groddle_group_not_member', 'You are not in that group.', 403); // invite(): not_in_group
        }
        $who = $request->json()['id'] ?? null;
        if (!is_int($who) && !(is_string($who) && preg_match('/^[1-9]\d{0,18}$/', $who) === 1)) {
            throw new RestError('groddle_group_invitee', 'Name a player by their id.', 400);
        }
        $who = (int) $who;
        if ($who <= 0 || (new Users($this->minn->db))->find($who) === null) {
            throw new RestError('groddle_group_invitee', 'There is no player with that id.', 404); // bad_invitee
        }
        if ($who === $me || $this->memberRole($group, $who) !== null) {
            throw new RestError('groddle_group_member', 'They are already in the group.', 409); // already_member
        }
        if ($this->invite($gid, $who) !== null) {
            throw new RestError('groddle_group_invited', 'They are invited already.', 409); // already_invited
        }
        $ignores = $this->minn->db->table(Buddies::IGNORES);
        if ($this->minn->db->value("SELECT 1 FROM {$ignores} WHERE (user_id = ? AND ignored_id = ?) OR (user_id = ? AND ignored_id = ?)", [$who, $me, $me, $who]) !== null) {
            // inc_trading.js:125-130's words for a block either way.
            throw new RestError('groddle_group_blocked', 'You are blocking or blocked by that player.', 403);
        }
        $db = $this->minn->db;
        $db->execute('INSERT IGNORE INTO ' . $db->table(self::INVITES) . ' (group_id, user_id, invited_by, invited_at) VALUES (?, ?, ?, ?)', [$gid, $who, $me, gmdate('Y-m-d H:i:s')]);
        return Response::json($this->answer() + ['invited' => $who, 'group' => $gid], 201);
    }

    /** Declines the caller's own invitation (group.js:1262-1292 admin_decline_invite). */
    #[Route(Method::Delete, '/groddle/v1/groups/{id:\d+}/invites', policy: new Policy(Access::SignedIn))]
    public function decline(Request $request, string $id): Response
    {
        $db = $this->minn->db;
        if ($db->execute('DELETE FROM ' . $db->table(self::INVITES) . ' WHERE group_id = ? AND user_id = ?', [(int) $id, $this->me()]) === 0) {
            throw new RestError('groddle_group_not_invited', 'You have no invitation to that group.', 404); // not_invited
        }
        return Response::json($this->answer() + ['declined' => (int) $id]);
    }

    private function me(): int
    {
        return $this->minn->reader->userId;
    }

    private static function missing(): RestError
    {
        // inc_groups.js:329 sendActivity("Group not found").
        return new RestError('groddle_group_missing', 'Group not found', 404);
    }

    /** @return array<string, mixed> */
    private function group(int $id): array
    {
        $row = $id > 0 ? $this->minn->db->row('SELECT id, name, description, mode, created_by, created_at FROM ' . $this->minn->db->table(self::TABLE) . ' WHERE id = ?', [$id]) : null;
        if ($row === null) {
            throw self::missing();
        }
        return $row;
    }

    /** A description: one paragraph of plain text, or null when too long. */
    private static function description(mixed $value): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', (string) (is_scalar($value) ? $value : '')) ?? '') ?? '');
        return mb_strlen($text) > self::DESCRIPTION_MAX ? null : $text;
    }

    private function underLimit(int $me): void
    {
        $count = (int) $this->minn->db->value('SELECT COUNT(*) FROM ' . $this->minn->db->table(self::MEMBERS) . ' WHERE user_id = ?', [$me]);
        if ($count >= self::MAX_GROUPS) {
            throw new RestError('groddle_group_limit', 'You are in ' . self::MAX_GROUPS . ' groups, the most you can be in. Leave one to join another.', 400, ['max' => self::MAX_GROUPS]);
        }
    }

    /** @return array<string, mixed>|null */
    private function invite(int $gid, int $userId): ?array
    {
        return $this->minn->db->row('SELECT invited_by, invited_at FROM ' . $this->minn->db->table(self::INVITES) . ' WHERE group_id = ? AND user_id = ?', [$gid, $userId]);
    }

    /**
     * A group's members, the administrators first then by joining, with the
     * administrator worked out as get_promote_candidate does when none is
     * left (group.js:826-884: the member who joined first).
     *
     * @return list<array{id: int, role: string, joined_at: string}>
     */
    private function members(int $gid): array
    {
        $rows = $this->minn->db->rows('SELECT user_id, role, joined_at FROM ' . $this->minn->db->table(self::MEMBERS) . ' WHERE group_id = ? ORDER BY joined_at ASC, user_id ASC', [$gid]);
        $out = array_map(static fn (array $r): array => ['id' => (int) $r['user_id'], 'role' => $r['role'] === 'admin' ? 'admin' : 'member', 'joined_at' => (string) $r['joined_at']], $rows);
        if ($out !== [] && !in_array('admin', array_column($out, 'role'), true)) {
            $out[0]['role'] = 'admin';
        }
        usort($out, static fn (array $a, array $b): int => [$a['role'] !== 'admin', $a['joined_at'], $a['id']] <=> [$b['role'] !== 'admin', $b['joined_at'], $b['id']]);
        return $out;
    }

    private function memberRole(array $group, int $userId): ?string
    {
        foreach ($this->members((int) $group['id']) as $m) {
            if ($m['id'] === $userId) {
                return $m['role'];
            }
        }
        return null;
    }

    /** What a list shows of a group (group.js:473-483 get_basic_info). */
    private static function card(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'mode' => $row['mode'] === 'private' ? 'private' : 'public',
            'members' => (int) ($row['members'] ?? 0),
        ];
    }

    /** The caller's groups, invitations and channels, and the watch token over them. */
    private function answer(): array
    {
        $db = $this->minn->db;
        $me = $this->me();
        $groups = $db->table(self::TABLE);
        $members = $db->table(self::MEMBERS);
        $invites = $db->table(self::INVITES);
        $count = static fn (string $alias): string => "(SELECT COUNT(*) FROM {$members} c WHERE c.group_id = {$alias}.id)";
        $mine = $db->rows("SELECT g.id, g.name, g.description, g.mode, m.joined_at, {$count('g')} AS members FROM {$members} m JOIN {$groups} g ON g.id = m.group_id
            WHERE m.user_id = ? ORDER BY g.name_key ASC", [$me]);
        $asked = $db->rows("SELECT g.id, g.name, g.description, g.mode, i.invited_by, i.invited_at, {$count('g')} AS members FROM {$invites} i JOIN {$groups} g ON g.id = i.group_id
            WHERE i.user_id = ? AND NOT EXISTS (SELECT 1 FROM {$members} x WHERE x.group_id = i.group_id AND x.user_id = i.user_id) ORDER BY i.invited_at DESC", [$me]);
        $names = (new Characters($db))->namesOf(array_map(static fn (array $r): int => (int) $r['invited_by'], $asked));
        $out = ['max' => self::MAX_GROUPS, 'groups' => [], 'invites' => []];
        foreach ($mine as $r) {
            $out['groups'][] = self::card($r) + ['role' => $this->memberRole($r, $me) ?? 'member', 'joinedAt' => SaveShape::time((string) $r['joined_at'])];
        }
        foreach ($asked as $r) {
            $by = (int) $r['invited_by'];
            $out['invites'][] = self::card($r) + ['invitedBy' => ['id' => $by, 'name' => $names[$by] ?? 'A Glitch'], 'invitedAt' => SaveShape::time((string) $r['invited_at'])];
        }
        $out['count'] = count($out['groups']);
        $out['channels'] = $this->channels($me);
        $out['watch'] = $this->watch(array_map(static fn (array $g): int => $g['id'], $out['groups']), $out['channels']);
        return $out;
    }

    /**
     * The channels this player may use, from their multiplayer save: Live
     * Help is the newbie channel under level 11 (main.js:579-585), the Trade
     * Channel needs the upgrade (player.js:3404).
     *
     * @return array{global: bool, help: string, trade: bool, silenced: list<string>}
     */
    private function channels(int $me): array
    {
        $level = Profiles::fromSave($this->minn->db, $me)['level'];
        $silence = [];
        $meta = (string) ((new Users($this->minn->db))->meta($me, self::SILENCE_META) ?? '');
        foreach (['chat', 'help'] as $cone) {
            if (in_array($cone, array_map('trim', explode(',', strtolower($meta))), true)) {
                $silence[] = $cone;
            }
        }
        return ['global' => true, 'help' => $level < self::NEWBIE_BELOW ? 'newbie' : 'help', 'trade' => $this->hasUpgrade($me, self::TRADE_UPGRADE), 'silenced' => $silence];
    }

    private function hasUpgrade(int $me, string $upgrade): bool
    {
        $db = $this->minn->db;
        $stored = $db->value('SELECT data FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ?', [$me]);
        if (!is_string($stored) || $stored === '') {
            return false;
        }
        $save = Json::decode($stored, or: null);
        $state = is_array($save) && is_string($save['entries']['playerState'] ?? null) ? Json::decode($save['entries']['playerState'], or: null) : null;
        $owned = is_array($state) ? ($state['player']['imaginationUpgrades'] ?? []) : [];
        return is_array($owned) && in_array($upgrade, $owned, true);
    }

    /**
     * The signed list the realtime server reads (channels.go): {k: "groups",
     * sub, g: the group ids (Buddies::ids), c: the channel rooms, s: the
     * cones, iat, exp}.
     *
     * @param list<int> $ids
     * @return array{token: string, expiresIn: int}|null null when the site has no realtime secret
     */
    private function watch(array $ids, array $channels): ?array
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null) {
            return null;
        }
        $rooms = ['global', $channels['help']];
        if ($channels['trade']) {
            $rooms[] = 'trade';
        }
        $now = time();
        $payload = ['k' => 'groups', 'sub' => $this->me(), 'g' => Buddies::ids($ids), 'c' => implode(',', $rooms), 's' => implode(',', $channels['silenced']), 'iat' => $now, 'exp' => $now + self::WATCH_TTL];
        return ['token' => RealtimeTicket::sign($payload, $secret), 'expiresIn' => self::WATCH_TTL];
    }
}
