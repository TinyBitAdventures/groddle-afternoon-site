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
use Minn\Json;
use Minn\RestError;

/**
 * groddle/v1/profile: a multiplayer character's public profile (register
 * G338), drawn at /players/<name>/ (PlayerPages). In Glitch every player had
 * one on the website, fed by the game server's adminGetInfo (GameServerJS
 * players/inc_admin.js 238-290: achievements_get_profile, the avatar hash,
 * profile_get_houses / profile_get_home_street from players/inc_profile.js),
 * and the client drew the avatar's pictures itself and posted them to the
 * site (glitch-client AvatarSheetMaker.as save_all_singles,
 * ss_singles_post_url; PNGUtil.as saveSinglesFromAvatarMc made image.png
 * 172x248 and image_100.png). The same here: the game renders the avatar
 * and the home street and uploads them (ProfilePng checks every byte), and
 * the player writes the description (the website's profile-bio) in the game.
 * Level and badges are read from the character's own save; the email and
 * the account's made-up username are never shown or sent.
 *
 * Only signed-in players with a character (Characters' table) have one,
 * and each call writes the caller's own row only: the user is never read
 * from the request. Pictures are stored as blobs (table groddle_profiles),
 * written through FROM_BASE64 since the engine's database door only takes
 * text.
 */
final readonly class Profiles
{
    public const TABLE = 'groddle_profiles';

    /** The description's cap in characters (the source keeps no limit for profile-bio; ours). */
    public const ABOUT_MAX = 500;

    /** Seconds between two uploads of the same picture (the game throttles itself; this stops a loop). */
    public const UPLOAD_GAP = 15;

    /**
     * The level a character must reach before the Players page lists it and
     * its page and pictures are public (Austin 2026-10-26: keeps fresh spam
     * accounts off the site). Below it only the owner sees their page.
     */
    public const LISTED_LEVEL = 5;

    /** SQL for a save row's level (s.data: entries.playerState is JSON inside JSON). */
    public static function levelSql(string $alias): string
    {
        return "CAST(JSON_EXTRACT(JSON_UNQUOTE(JSON_EXTRACT({$alias}.data, '$.entries.playerState')), '$.player.level') AS UNSIGNED)";
    }

    public function __construct(private Seams $minn)
    {
    }

    /** The caller's profile as the Account panel shows it. */
    #[Route(Method::Get, '/groddle/v1/profile', policy: new Policy(Access::SignedIn))]
    public function read(Request $request): Response
    {
        return Response::json($this->answer($this->character()));
    }

    /** Saves the description. */
    #[Route(Method::Put, '/groddle/v1/profile', policy: new Policy(Access::SignedIn))]
    public function write(Request $request): Response
    {
        $character = $this->character();
        $about = self::about($request->json()['about'] ?? null);
        $this->ensureRow($character['id']);
        $db = $this->minn->db;
        $db->execute('UPDATE ' . $db->table(self::TABLE) . ' SET about = ?, updated_at = ? WHERE user_id = ?', [$about, gmdate('Y-m-d H:i:s'), $character['id']]);
        return Response::json($this->answer($character));
    }

    /** The avatar's two pictures: the profile still and the grid tile, with the look's key. */
    #[Route(Method::Put, '/groddle/v1/profile/avatar', policy: new Policy(Access::SignedIn))]
    public function avatar(Request $request): Response
    {
        $character = $this->character();
        $body = $request->json();
        $key = self::key($body['key'] ?? null);
        $this->throttle($character['id'], 'avatar_at');
        $avatar = ProfilePng::accept('avatar', $body['avatar'] ?? null);
        $tile = ProfilePng::accept('tile', $body['tile'] ?? null);
        $this->ensureRow($character['id']);
        $db = $this->minn->db;
        $now = gmdate('Y-m-d H:i:s');
        $db->execute('UPDATE ' . $db->table(self::TABLE) . ' SET avatar = FROM_BASE64(?), tile = FROM_BASE64(?), avatar_key = ?, avatar_at = ?, updated_at = ? WHERE user_id = ?', [base64_encode($avatar['bytes']), base64_encode($tile['bytes']), $key, $now, $now, $character['id']]);
        return Response::json($this->answer($character));
    }

    /** The home street as the player saw it, with the home's key. */
    #[Route(Method::Put, '/groddle/v1/profile/home', policy: new Policy(Access::SignedIn))]
    public function home(Request $request): Response
    {
        $character = $this->character();
        $body = $request->json();
        $key = self::key($body['key'] ?? null);
        $this->throttle($character['id'], 'home_at');
        $home = ProfilePng::accept('home', $body['home'] ?? null);
        $this->ensureRow($character['id']);
        $db = $this->minn->db;
        $now = gmdate('Y-m-d H:i:s');
        $db->execute('UPDATE ' . $db->table(self::TABLE) . ' SET home = FROM_BASE64(?), home_key = ?, home_at = ?, updated_at = ? WHERE user_id = ?', [base64_encode($home['bytes']), $key, $now, $now, $character['id']]);
        return Response::json($this->answer($character));
    }

    /**
     * The caller's character: id and name. An account with no character
     * (the site's own people) has no profile.
     *
     * @return array{id: int, name: string}
     */
    private function character(): array
    {
        $id = $this->minn->reader->userId;
        $db = $this->minn->db;
        // The Account panel can ask before the first realtime ticket: claim the display name here too.
        $user = $this->minn->user();
        if ($user !== null) {
            (new Characters($db))->claimOwnName($user);
        }
        $name = $db->value('SELECT name FROM ' . $db->table(Characters::TABLE) . ' WHERE user_id = ?', [$id]);
        if (!is_string($name) || $name === '') {
            throw new RestError('groddle_profile_none', 'Only multiplayer characters have a profile.', 403);
        }
        return ['id' => $id, 'name' => $name];
    }

    private function ensureRow(int $id): void
    {
        $db = $this->minn->db;
        $db->execute('INSERT IGNORE INTO ' . $db->table(self::TABLE) . ' (user_id, about, updated_at) VALUES (?, \'\', ?)', [$id, gmdate('Y-m-d H:i:s')]);
    }

    /** A second upload of one picture within UPLOAD_GAP seconds is refused. */
    private function throttle(int $id, string $column): void
    {
        $db = $this->minn->db;
        $at = $db->value("SELECT {$column} FROM " . $db->table(self::TABLE) . ' WHERE user_id = ?', [$id]);
        if (is_string($at) && $at !== '' && time() - (int) strtotime($at . ' UTC') < self::UPLOAD_GAP) {
            throw new RestError('groddle_profile_wait', 'That picture was just saved. Try again in a moment.', 429);
        }
    }

    /** The answer for the caller: what the Account panel and the game's uploader need. */
    private function answer(array $character): array
    {
        $db = $this->minn->db;
        $row = $db->row('SELECT about, avatar_key, avatar_at, home_key, home_at FROM ' . $db->table(self::TABLE) . ' WHERE user_id = ?', [$character['id']]);
        $slug = self::slugs($db)[$character['id']] ?? self::slug($character['name']);
        return [
            'id' => $character['id'],
            'name' => $character['name'],
            'url' => '/players/' . $slug . '/',
            'about' => (string) ($row['about'] ?? ''),
            'aboutMax' => self::ABOUT_MAX,
            'avatar' => self::picture($character['id'], 'avatar', $row['avatar_key'] ?? null, $row['avatar_at'] ?? null),
            'home' => self::picture($character['id'], 'home', $row['home_key'] ?? null, $row['home_at'] ?? null),
        ];
    }

    private static function picture(int $id, string $kind, mixed $key, mixed $at): ?array
    {
        if (!is_string($at) || $at === '') {
            return null;
        }
        return ['key' => (string) $key, 'at' => SaveShape::time($at), 'url' => self::imageUrl($id, $kind, $at)];
    }

    /** Where a picture is served (PlayerPages::image), versioned by when it was saved. */
    public static function imageUrl(int $id, string $kind, string $at): string
    {
        return "/players/{$id}/{$kind}.png?v=" . self::stamp($at);
    }

    /** A stored UTC time as the version a picture's URL carries. */
    public static function stamp(string $at): string
    {
        return (string) (int) strtotime($at . ' UTC');
    }

    /** The description as kept: text, line breaks as \n, no control characters, trimmed, at most ABOUT_MAX characters. */
    public static function about(mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new RestError('groddle_profile_about', 'The description is text.', 400);
        }
        $text = str_replace(["\r\n", "\r"], "\n", $value);
        $text = (string) preg_replace('/[^\P{C}\n]/u', '', $text);
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
        if (mb_strlen($text) > self::ABOUT_MAX) {
            throw new RestError('groddle_profile_about', 'Descriptions are at most ' . self::ABOUT_MAX . ' characters.', 400, ['max' => self::ABOUT_MAX]);
        }
        return $text;
    }

    /** The key the game sends with a picture (what it was made from, so it knows when to make a new one). */
    private static function key(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9:._-]{1,64}$/', $value) !== 1) {
            throw new RestError('groddle_profile_key', 'A picture names the key it was made from.', 400);
        }
        return $value;
    }

    /** A character name as a path: lower case, spaces, - and _ as one -. */
    public static function slug(string $name): string
    {
        return trim((string) preg_replace('/[ _-]+/', '-', strtolower($name)), '-');
    }

    /**
     * Every character's path, id => slug. Two names can make one slug
     * ("Bo Peep", "bo-peep"): the first made keeps it, a later one adds its
     * id after a dot (bo-peep.57), which no name can hold.
     *
     * @return array<int, string>
     */
    public static function slugs(Db $db): array
    {
        $rows = $db->rows('SELECT c.user_id, c.name FROM ' . $db->table(Characters::TABLE) . ' c JOIN ' . $db->table('users') . ' u ON u.ID = c.user_id ORDER BY c.created_at ASC, c.user_id ASC');
        $owner = [];
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['user_id'];
            $slug = self::slug((string) $row['name']);
            if ($slug === '') {
                continue;
            }
            if (!isset($owner[$slug])) {
                $owner[$slug] = $id;
                $out[$id] = $slug;
            } else {
                $out[$id] = "{$slug}.{$id}";
            }
        }
        return $out;
    }

    /**
     * What a character's save says about them for the profile: the level,
     * the badges unlocked and the skills learned, each newest first (the
     * save keeps them in the order they came; it keeps no times).
     *
     * @return array{level: int, badges: list<string>, skills: list<string>, saved: bool}
     */
    public static function fromSave(Db $db, int $id): array
    {
        $out = ['level' => 1, 'badges' => [], 'skills' => [], 'saved' => false];
        $stored = $db->value('SELECT data FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ?', [$id]);
        if (!is_string($stored) || $stored === '') {
            return $out;
        }
        $save = Json::decode($stored, or: null);
        $state = is_array($save) && is_string($save['entries']['playerState'] ?? null) ? Json::decode($save['entries']['playerState'], or: null) : null;
        if (!is_array($state)) {
            return $out;
        }
        $out['saved'] = true;
        $level = $state['player']['level'] ?? null;
        if (is_int($level) || (is_numeric($level) && (int) $level == $level)) {
            $out['level'] = max(1, min(999, (int) $level));
        }
        $out['badges'] = self::ids($state['systems']['achievements']['unlocked'] ?? []);
        $out['skills'] = self::ids($state['systems']['skills']['learned'] ?? []);
        return $out;
    }

    /**
     * A save's list of class ids, newest first, each once; anything else in
     * it is dropped.
     *
     * @return list<string>
     */
    public static function ids(mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $ids = [];
        foreach ($list as $id) {
            if (is_string($id) && preg_match('/^[a-z0-9_]{1,80}$/', $id) === 1) {
                $ids[$id] = true;
            }
        }
        return array_reverse(array_keys($ids));
    }
}
