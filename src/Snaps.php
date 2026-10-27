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
use Minn\Json;
use Minn\RestError;

/**
 * groddle/v1/snaps: a multiplayer character's Snaps (register G332). In
 * Glitch a player with the Snapshottery card pressed Enter in Camera Mode
 * and the client posted the picture to the web API's snaps.upload with a
 * caption, the street and the spot (glitch-client debug/API.as 348-381,
 * GlitchrSnapshotView.as 281-310); the Camera item's take_picture then
 * showed a random Snap by another player and offered to go there (GSJS
 * items/camera.js 93-119, glitch-client SnapTravelView.as, APICall.as
 * 593-596 snaps.randomForCamera?exclude_tsid=<this street>).
 *
 * The same here: POST saves one of your Snaps, GET lists yours (the
 * original's snaps/manage), DELETE removes one, GET snaps/random gives the
 * Camera another player's Snap on a public street other than yours (none
 * from a player you block or who blocks you). The caller is always the
 * signed-in user, never read from the request.
 *
 * The picture is untrusted: SnapImage reads its type from the bytes, walks
 * its structure, keeps it within size limits and has GD write a new JPEG;
 * only that is kept, as a file under wp-content/uploads/groddle-snaps/ with
 * a random name, served by the web server like any upload. The row keeps
 * the file's name, never a path the client sent.
 *
 * Who may snap is the source's: the Snapshottery card (players/player.js
 * 2148-2173 getCameraAbilities), which needs level 5
 * (inc_data_imagination.js 6990-7010), both read from the character's save.
 * Ours: one Snap every SNAP_GAP seconds, DAY_MAX a day, KEEP_MAX kept.
 */
final readonly class Snaps
{
    public const TABLE = 'groddle_snaps';

    /** The folder under wp-content/uploads the pictures live in. */
    public const FOLDER = 'groddle-snaps';

    /** The upgrade a Snap needs (getCameraAbilities: "Get the Snapshottery upgrade first."). */
    public const UPGRADE = 'snapshotting';

    /** The Snapshottery card's own condition (inc_data_imagination.js 6998-7004). */
    public const LEVEL = 5;

    /** The caption's cap in characters (GlitchrSnapshotView.as 153 input_submit.maxChars). */
    public const CAPTION_MAX = 500;

    /** Ours: Snaps kept per character; a full album refuses the next until one is deleted. */
    public const KEEP_MAX = 60;

    /** Ours: Snaps saved in any 24 hours. */
    public const DAY_MAX = 40;

    /** Ours: seconds between two Snaps. */
    public const SNAP_GAP = 10;

    /** A street a Snap can lead to: an ordinary street's tsid (assets/streets/index.json), never a private place (an "_" in it). */
    private const STREET = '/^[GL][A-Z0-9]{8,20}$/';

    /** A kept file's name: random hex, .jpg (nothing else is ever written or deleted). */
    private const FILE = '/^[0-9a-f]{24}\.jpg$/';

    public function __construct(private Seams $minn)
    {
    }

    /** Your Snaps, newest first. */
    #[Route(Method::Get, '/groddle/v1/snaps', policy: new Policy(Access::SignedIn))]
    public function mine(Request $request): Response
    {
        return Response::json($this->album($this->character()['id']));
    }

    /** Saves a Snap: {image, caption, street, x, y}. */
    #[Route(Method::Post, '/groddle/v1/snaps', policy: new Policy(Access::SignedIn))]
    public function save(Request $request): Response
    {
        $character = $this->character();
        $me = $character['id'];
        $body = $request->json();
        $caption = self::caption($body['caption'] ?? '');
        $street = self::street($body['street'] ?? '');
        $x = self::coordinate($body['x'] ?? 0);
        $y = self::coordinate($body['y'] ?? 0);
        $this->mayStart($me);
        $this->withinLimits($me);
        $image = SnapImage::accept($body['image'] ?? null);

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RestError('groddle_snap_unavailable', 'Snaps cannot be saved on this site right now.', 503);
        }
        if (!is_file("{$dir}/index.html")) {
            @file_put_contents("{$dir}/index.html", '');
        }
        $file = bin2hex(random_bytes(12)) . '.jpg';
        $tmp = "{$dir}/.{$file}.part";
        if (@file_put_contents($tmp, $image['bytes'], LOCK_EX) !== strlen($image['bytes']) || !@rename($tmp, "{$dir}/{$file}")) {
            @unlink($tmp);
            throw new RestError('groddle_snap_unavailable', 'Snaps cannot be saved on this site right now.', 503);
        }
        @chmod("{$dir}/{$file}", 0644);

        $db = $this->minn->db;
        $db->execute(
            'INSERT INTO ' . $db->table(self::TABLE) . ' (user_id, file, street, x, y, caption, width, height, bytes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$me, $file, $street, $x, $y, $caption, $image['width'], $image['height'], strlen($image['bytes']), gmdate('Y-m-d H:i:s')]
        );
        $id = $db->insertId();
        $album = $this->album($me);
        $snap = null;
        foreach ($album['snaps'] as $row) {
            if ($row['id'] === $id) {
                $snap = $row;
            }
        }
        return Response::json(['snap' => $snap] + $album, 201);
    }

    /** Deletes one of your Snaps, its file too. */
    #[Route(Method::Delete, '/groddle/v1/snaps/{id:\d+}', policy: new Policy(Access::SignedIn))]
    public function remove(Request $request, string $id): Response
    {
        $me = $this->character()['id'];
        $db = $this->minn->db;
        $table = $db->table(self::TABLE);
        $file = $db->value("SELECT file FROM {$table} WHERE id = ? AND user_id = ?", [(int) $id, $me]);
        if (!is_string($file)) {
            throw new RestError('groddle_snap_missing', 'That is not one of your snaps.', 404);
        }
        $db->execute("DELETE FROM {$table} WHERE id = ? AND user_id = ?", [(int) $id, $me]);
        self::unlinkFile($file);
        return Response::json(['deleted' => (int) $id] + $this->album($me));
    }

    /**
     * The Camera's random Snap (snaps.randomForCamera): another character's,
     * on a street that is not `exclude`, from nobody you block or who blocks
     * you; {snap: null} when there is none.
     */
    #[Route(Method::Get, '/groddle/v1/snaps/random', policy: new Policy(Access::SignedIn))]
    public function random(Request $request): Response
    {
        $me = $this->character()['id'];
        $exclude = (string) $request->query('exclude', '');
        $exclude = preg_match(self::STREET, $exclude) === 1 ? $exclude : '';
        $db = $this->minn->db;
        $snaps = $db->table(self::TABLE);
        $players = $db->table(Characters::TABLE);
        $ignores = $db->table(Buddies::IGNORES);
        $where = "s.user_id <> ? AND s.street <> '' AND s.street <> ?"
            . " AND NOT EXISTS (SELECT 1 FROM {$ignores} i WHERE (i.user_id = ? AND i.ignored_id = s.user_id) OR (i.user_id = s.user_id AND i.ignored_id = ?))";
        $params = [$me, $exclude, $me, $me];
        $count = (int) $db->value("SELECT COUNT(*) FROM {$snaps} s JOIN {$players} p ON p.user_id = s.user_id WHERE {$where}", $params);
        if ($count < 1) {
            return Response::json(['snap' => null]);
        }
        $offset = random_int(0, $count - 1);
        $row = $db->row(
            "SELECT s.id, s.user_id, s.file, s.street, s.x, s.y, s.caption, s.width, s.height, s.created_at, p.name FROM {$snaps} s JOIN {$players} p ON p.user_id = s.user_id WHERE {$where} ORDER BY s.id LIMIT 1 OFFSET {$offset}",
            $params
        );
        if (!is_array($row)) {
            return Response::json(['snap' => null]);
        }
        $snap = self::shape($row);
        $snap['owner'] = ['id' => (int) $row['user_id'], 'name' => (string) $row['name']];
        return Response::json(['snap' => $snap]);
    }

    /** Your album: the Snaps, newest first, and the limits. */
    private function album(int $me): array
    {
        $db = $this->minn->db;
        $rows = $db->rows('SELECT id, file, street, x, y, caption, width, height, created_at FROM ' . $db->table(self::TABLE) . ' WHERE user_id = ? ORDER BY id DESC', [$me]);
        return [
            'snaps' => array_map(self::shape(...), $rows),
            'max' => self::KEEP_MAX,
            'captionMax' => self::CAPTION_MAX,
        ];
    }

    /** One Snap as the game reads it. */
    private static function shape(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'url' => self::url((string) $row['file']),
            'street' => (string) $row['street'],
            'x' => (int) $row['x'],
            'y' => (int) $row['y'],
            'caption' => (string) $row['caption'],
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'at' => SaveShape::time((string) $row['created_at']),
        ];
    }

    /** Where a kept file is served. */
    public static function url(string $file): string
    {
        return '/wp-content/uploads/' . self::FOLDER . '/' . $file;
    }

    /** The folder on disk. */
    public static function dir(): string
    {
        $root = defined('ABSPATH') ? rtrim((string) ABSPATH, '/') : '';
        return "{$root}/wp-content/uploads/" . self::FOLDER;
    }

    /** Removes a kept file by its name (only names Snaps writes). */
    public static function unlinkFile(string $file): void
    {
        if (preg_match(self::FILE, $file) === 1) {
            @unlink(self::dir() . '/' . $file);
        }
    }

    /**
     * The caller's character: id and name. Snaps are a multiplayer
     * character's; the site's own people have none.
     *
     * @return array{id: int, name: string}
     */
    private function character(): array
    {
        $id = $this->minn->reader->userId;
        $db = $this->minn->db;
        $name = $db->value('SELECT name FROM ' . $db->table(Characters::TABLE) . ' WHERE user_id = ?', [$id]);
        if (!is_string($name) || $name === '') {
            throw new RestError('groddle_snap_none', 'Only multiplayer characters take snaps.', 403);
        }
        return ['id' => $id, 'name' => $name];
    }

    /** The source's conditions, read from the save: the Snapshottery card (and level 5, which the card needs). */
    private function mayStart(int $me): void
    {
        $db = $this->minn->db;
        $stored = $db->value('SELECT data FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ?', [$me]);
        $save = is_string($stored) && $stored !== '' ? Json::decode($stored, or: null) : null;
        $state = is_array($save) && is_string($save['entries']['playerState'] ?? null) ? Json::decode($save['entries']['playerState'], or: null) : null;
        $player = is_array($state) && is_array($state['player'] ?? null) ? $state['player'] : [];
        $owned = is_array($player['imaginationUpgrades'] ?? null) ? $player['imaginationUpgrades'] : [];
        if (!in_array(self::UPGRADE, $owned, true)) {
            throw new RestError('groddle_snap_upgrade', 'Get the Snapshottery upgrade first.', 403);
        }
        $level = $player['level'] ?? 0;
        if (!is_numeric($level) || (int) $level < self::LEVEL) {
            throw new RestError('groddle_snap_upgrade', 'Get the Snapshottery upgrade first.', 403);
        }
    }

    /** One every SNAP_GAP seconds, DAY_MAX a day, KEEP_MAX kept. */
    private function withinLimits(int $me): void
    {
        $db = $this->minn->db;
        $table = $db->table(self::TABLE);
        $row = $db->row("SELECT COUNT(*) AS kept, MAX(created_at) AS last, SUM(created_at > ?) AS today FROM {$table} WHERE user_id = ?", [gmdate('Y-m-d H:i:s', time() - 86400), $me]);
        $kept = (int) ($row['kept'] ?? 0);
        $last = is_string($row['last'] ?? null) ? (int) strtotime($row['last'] . ' UTC') : 0;
        if ($last && time() - $last < self::SNAP_GAP) {
            throw new RestError('groddle_snap_wait', 'That snap was just saved. Try again in a moment.', 429);
        }
        if ((int) ($row['today'] ?? 0) >= self::DAY_MAX) {
            throw new RestError('groddle_snap_day', 'That is all the snaps you can save today. Try again tomorrow.', 429, ['max' => self::DAY_MAX]);
        }
        if ($kept >= self::KEEP_MAX) {
            throw new RestError('groddle_snap_full', 'You have ' . self::KEEP_MAX . ' snaps. Delete one to save another.', 409, ['max' => self::KEEP_MAX]);
        }
    }

    /** A caption as kept: one line of text, no control characters, trimmed, at most CAPTION_MAX characters. */
    public static function caption(mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new RestError('groddle_snap_caption', 'A caption is text.', 400);
        }
        $text = (string) preg_replace('/\s+/u', ' ', $value);
        $text = trim((string) preg_replace('/\p{C}/u', '', $text));
        if (mb_strlen($text) > self::CAPTION_MAX) {
            throw new RestError('groddle_snap_caption', 'Captions are at most ' . self::CAPTION_MAX . ' characters.', 400, ['max' => self::CAPTION_MAX]);
        }
        return $text;
    }

    /** The street a Snap leads to: an ordinary street's tsid, or '' (a private place: kept, never offered to the Camera). */
    public static function street(mixed $value): string
    {
        if ($value === '' || $value === null) {
            return '';
        }
        if (!is_string($value) || preg_match(self::STREET, $value) !== 1) {
            throw new RestError('groddle_snap_street', 'That is not a street a snap can lead to.', 400);
        }
        return $value;
    }

    /** A spot on a street, in the street's own pixels. */
    private static function coordinate(mixed $value): int
    {
        if (!is_int($value) && !(is_float($value) && is_finite($value))) {
            throw new RestError('groddle_snap_spot', 'A snap names the spot it was taken.', 400);
        }
        return max(-100000, min(100000, (int) round($value)));
    }
}
