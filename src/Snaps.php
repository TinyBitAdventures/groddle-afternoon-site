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
 * Camera another player's public Snap on a public street other than yours
 * (none from a player you block or who blocks you). The caller is always
 * the signed-in user, never read from the request.
 *
 * Public or private (G1240, Austin 2026-11-24): every Snap starts private,
 * its owner's alone; POST snaps/<id>/public makes one public or private
 * again. A public Snap shows on its owner's profile (PlayerPages: the Snaps
 * section and tab, and a page of its own at /players/<slug>/snaps/<id>/,
 * the link the game copies, as Glitch's saved dialog gave the photo's page
 * and a short URL: GlitchrSavedDialog.as 160-225) and may come up on
 * another player's Camera. Private means private: the Camera never shows it.
 *
 * The picture is untrusted: SnapImage reads its type from the bytes, walks
 * its structure, keeps it within size limits and has GD write a new JPEG
 * (and a SMALL_W copy for the cards); only those are kept, as files under
 * wp-content/uploads/groddle-snaps/ with random names. The row keeps the
 * file's name, never a path the client sent. The name never leaves the
 * site: every picture is served by PlayerPages through
 * /players/<owner id>/snaps/<id>/picture (small, download), which checks
 * who may see it (viewable). Snaps saved before G1240 were linked by their
 * file's address, so sealOld gives each of those files a new random name
 * once: an old address that was ever seen stops loading. The folder has an
 * empty index.html so the web server lists nothing.
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

    /**
     * A kept file's name: random hex, .jpg (nothing else is ever written or
     * deleted). 32 hex digits since G1240 (never sent to a client); 24 for a
     * file saved before, whose address the game was given (sealOld renames it).
     */
    private const FILE = '/^[0-9a-f]{24}(?:[0-9a-f]{8})?\.jpg$/';

    /** A file saved before G1240, whose address went to the game. */
    private const OLD_FILE = '/^[0-9a-f]{24}\.jpg$/';

    /** A kept file's small copy (G1240). */
    private const SMALL = '/^[0-9a-f]{24}(?:[0-9a-f]{8})?-s\.jpg$/';

    /** The option set once sealOld finds no old file left. */
    public const SEALED_OPTION = 'groddle_snaps_sealed';

    /** Old files sealOld renames in one request. */
    private const SEAL_BATCH = 100;

    public function __construct(private Seams $minn)
    {
    }

    /** Your Snaps, newest first. */
    #[Route(Method::Get, '/groddle/v1/snaps', policy: new Policy(Access::SignedIn))]
    public function mine(Request $request): Response
    {
        $me = $this->character()['id'];
        self::sealOld($this->minn->db, $this->minn->site);
        return Response::json($this->album($me));
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
        $file = self::newName();
        if (!self::write($dir, $file, $image['bytes'])) {
            throw new RestError('groddle_snap_unavailable', 'Snaps cannot be saved on this site right now.', 503);
        }
        // The cards' copy; without it the cards show the picture itself.
        if (is_string($image['small'] ?? null) && $image['small'] !== '') {
            self::write($dir, self::smallName($file), $image['small']);
        }

        $db = $this->minn->db;
        // Private: `public` is left to its default, 0 (Austin 2026-11-24).
        $db->execute(
            'INSERT INTO ' . $db->table(self::TABLE) . ' (user_id, file, street, x, y, caption, width, height, bytes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$me, $file, $street, $x, $y, $caption, $image['width'], $image['height'], strlen($image['bytes']), gmdate('Y-m-d H:i:s')]
        );
        $id = $db->insertId();
        return Response::json($this->withSnap($me, $id), 201);
    }

    /**
     * Makes one of your Snaps public ({"public": true}: on your profile, a
     * page of its own, other players' Cameras) or private again (false: off
     * all of them at once; its pictures answer only you).
     */
    #[Route(Method::Post, '/groddle/v1/snaps/{id:\d+}/public', policy: new Policy(Access::SignedIn))]
    public function share(Request $request, string $id): Response
    {
        $me = $this->character()['id'];
        $on = $request->json()['public'] ?? null;
        if (!is_bool($on)) {
            throw new RestError('groddle_snap_public', 'Say whether the snap is public (true or false).', 400);
        }
        $db = $this->minn->db;
        $table = $db->table(self::TABLE);
        if ($db->value("SELECT 1 FROM {$table} WHERE id = ? AND user_id = ?", [(int) $id, $me]) === null) {
            throw new RestError('groddle_snap_missing', 'That is not one of your snaps.', 404);
        }
        $db->execute("UPDATE {$table} SET public = ? WHERE id = ? AND user_id = ?", [$on ? 1 : 0, (int) $id, $me]);
        return Response::json($this->withSnap($me, (int) $id));
    }

    /** The album with one Snap of it picked out as `snap` (null when it is gone). */
    private function withSnap(int $me, int $id): array
    {
        $album = $this->album($me);
        $snap = null;
        foreach ($album['snaps'] as $row) {
            if ($row['id'] === $id) {
                $snap = $row;
            }
        }
        return ['snap' => $snap] + $album;
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
        self::unlinkFile(self::smallName($file));
        return Response::json(['deleted' => (int) $id] + $this->album($me));
    }

    /**
     * The Camera's random Snap (snaps.randomForCamera): another character's
     * public one (private means private, G1240), on a street that is not
     * `exclude`, from nobody you block or who blocks you; {snap: null} when
     * there is none.
     */
    #[Route(Method::Get, '/groddle/v1/snaps/random', policy: new Policy(Access::SignedIn))]
    public function random(Request $request): Response
    {
        $me = $this->character()['id'];
        self::sealOld($this->minn->db, $this->minn->site);
        $exclude = (string) $request->query('exclude', '');
        $exclude = preg_match(self::STREET, $exclude) === 1 ? $exclude : '';
        $db = $this->minn->db;
        $snaps = $db->table(self::TABLE);
        $players = $db->table(Characters::TABLE);
        $ignores = $db->table(Buddies::IGNORES);
        $where = "s.public = 1 AND s.user_id <> ? AND s.street <> '' AND s.street <> ?"
            . " AND NOT EXISTS (SELECT 1 FROM {$ignores} i WHERE (i.user_id = ? AND i.ignored_id = s.user_id) OR (i.user_id = s.user_id AND i.ignored_id = ?))"
            // Never a suspended player's Snap (a ban on any character of the login).
            . ' AND NOT ' . AdminConsole::bannedSql($db, 's.user_id');
        $params = [$me, $exclude, $me, $me];
        $count = (int) $db->value("SELECT COUNT(*) FROM {$snaps} s JOIN {$players} p ON p.user_id = s.user_id WHERE {$where}", $params);
        if ($count < 1) {
            return Response::json(['snap' => null]);
        }
        $offset = random_int(0, $count - 1);
        $row = $db->row(
            "SELECT s.id, s.user_id, s.file, s.street, s.x, s.y, s.caption, s.width, s.height, s.created_at, s.public, p.name FROM {$snaps} s JOIN {$players} p ON p.user_id = s.user_id WHERE {$where} ORDER BY s.id LIMIT 1 OFFSET {$offset}",
            $params
        );
        if (!is_array($row)) {
            return Response::json(['snap' => null]);
        }
        $snap = self::shape($row, Profiles::slugs($db)[(int) $row['user_id']] ?? null);
        $snap['owner'] = ['id' => (int) $row['user_id'], 'name' => (string) $row['name']];
        return Response::json(['snap' => $snap]);
    }

    /** Your album: the Snaps, newest first, the limits, and your profile's address (null without one). */
    private function album(int $me): array
    {
        $db = $this->minn->db;
        $rows = $db->rows('SELECT id, user_id, file, street, x, y, caption, width, height, created_at, public FROM ' . $db->table(self::TABLE) . ' WHERE user_id = ? ORDER BY id DESC', [$me]);
        $slug = Profiles::slugs($db)[$me] ?? null;
        return [
            'snaps' => array_map(static fn (array $row): array => self::shape($row, $slug), $rows),
            'max' => self::KEEP_MAX,
            'captionMax' => self::CAPTION_MAX,
            'profile' => $slug === null ? null : "/players/{$slug}/",
        ];
    }

    /**
     * One Snap as the game reads it: its pictures' addresses (never the
     * file's), whether it is public, and its page when it is (the link the
     * game copies). `slug` is the owner's profile path.
     */
    private static function shape(array $row, ?string $slug = null): array
    {
        $owner = (int) $row['user_id'];
        $id = (int) $row['id'];
        $public = (int) ($row['public'] ?? 0) === 1;
        return [
            'id' => $id,
            'url' => self::url($owner, $id),
            'small' => is_file(self::dir() . '/' . self::smallName((string) $row['file'])) ? self::url($owner, $id, 'small') : self::url($owner, $id),
            'download' => self::url($owner, $id, 'download'),
            'street' => (string) $row['street'],
            'x' => (int) $row['x'],
            'y' => (int) $row['y'],
            'caption' => (string) $row['caption'],
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'at' => SaveShape::time((string) $row['created_at']),
            'public' => $public,
            'page' => $public && $slug !== null ? self::page($slug, $id) : null,
        ];
    }

    /** Where a Snap's picture is served (PlayerPages::snapPicture): 'picture', 'small' or 'download'. */
    public static function url(int $owner, int $id, string $kind = 'picture'): string
    {
        return "/players/{$owner}/snaps/{$id}/{$kind}";
    }

    /** A public Snap's page on its owner's profile. */
    public static function page(string $slug, int $id): string
    {
        return "/players/{$slug}/snaps/{$id}/";
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
        if (preg_match(self::FILE, $file) === 1 || preg_match(self::SMALL, $file) === 1) {
            @unlink(self::dir() . '/' . $file);
        }
    }

    /** The small copy's name beside a kept file ('' for a name Snaps did not write). */
    public static function smallName(string $file): string
    {
        return preg_match(self::FILE, $file) === 1 ? substr($file, 0, -4) . '-s.jpg' : '';
    }

    /** A new kept file's name: 32 random hex digits. */
    private static function newName(): string
    {
        return bin2hex(random_bytes(16)) . '.jpg';
    }

    /** Writes a kept file whole (a .part first, then renamed in). */
    private static function write(string $dir, string $file, string $bytes): bool
    {
        $tmp = "{$dir}/.{$file}.part";
        if (@file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes) || !@rename($tmp, "{$dir}/{$file}")) {
            @unlink($tmp);
            return false;
        }
        @chmod("{$dir}/{$file}", 0644);
        return true;
    }

    /**
     * Gives every file saved before G1240 (a 24-digit name, whose address the
     * game was sent: the owner's album, and any player's Camera) a new random
     * name, SEAL_BATCH at a time, so an address once seen stops loading; the
     * row follows. Once none is left the option says so and this costs one
     * option read. Two requests at once: only one rename of a file succeeds,
     * and the row moves only with it.
     */
    public static function sealOld(\Minn\Db $db, \Minn\Content\Site $site): int
    {
        if ($site->option(self::SEALED_OPTION) === '1') {
            return 0;
        }
        $table = $db->table(self::TABLE);
        $rows = $db->rows("SELECT id, file FROM {$table} WHERE CHAR_LENGTH(file) = 28 ORDER BY id LIMIT " . self::SEAL_BATCH);
        $sealed = 0;
        foreach ($rows as $row) {
            $old = (string) $row['file'];
            if (preg_match(self::OLD_FILE, $old) !== 1) {
                continue;
            }
            $new = self::newName();
            $from = self::dir() . "/{$old}";
            // A missing file has nothing to hide: the row moves on so the pass ends.
            if (is_file($from) && !@rename($from, self::dir() . "/{$new}")) {
                continue;
            }
            $sealed += $db->execute("UPDATE {$table} SET file = ? WHERE id = ? AND file = ?", [$new, (int) $row['id'], $old]);
        }
        if (count($rows) < self::SEAL_BATCH) {
            $left = (int) $db->value("SELECT COUNT(*) FROM {$table} WHERE CHAR_LENGTH(file) = 28");
            if ($left === 0) {
                $site->setOption(self::SEALED_OPTION, '1', 'yes');
            }
        }
        return $sealed;
    }

    /**
     * A Snap's row with its owner, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function find(\Minn\Db $db, int $owner, int $id): ?array
    {
        return $db->row('SELECT id, user_id, file, street, x, y, caption, width, height, created_at, public FROM ' . $db->table(self::TABLE) . ' WHERE id = ? AND user_id = ?', [$id, $owner]);
    }

    /**
     * Whether `reader` may see a Snap: its owner always; anyone else only a
     * public one whose owner has a public profile (a character at
     * Profiles::LISTED_LEVEL, not retired, not suspended), with no block
     * between them either way (the Camera's rule). Everything else answers
     * as if there were no such Snap.
     *
     * @param array<string, mixed> $row
     */
    public static function viewable(\Minn\Db $db, int $reader, array $row): bool
    {
        $owner = (int) $row['user_id'];
        if ($reader > 0 && $reader === $owner) {
            return true;
        }
        return (int) ($row['public'] ?? 0) === 1 && self::shown($db, $owner) && !self::blocked($db, $reader, $owner);
    }

    /** Whether a character's public Snaps may show: a listed profile, not retired, not suspended. */
    public static function shown(\Minn\Db $db, int $owner): bool
    {
        return isset(Profiles::slugs($db)[$owner])
            && Profiles::fromSave($db, $owner)['level'] >= Profiles::LISTED_LEVEL
            && AdminConsole::banned($db, $owner) === null;
    }

    /** Whether either of two players blocks the other (Buddies' ignores); never for a signed-out reader. */
    public static function blocked(\Minn\Db $db, int $reader, int $owner): bool
    {
        if ($reader <= 0 || $reader === $owner) {
            return false;
        }
        return $db->value('SELECT 1 FROM ' . $db->table(Buddies::IGNORES) . ' WHERE (user_id = ? AND ignored_id = ?) OR (user_id = ? AND ignored_id = ?) LIMIT 1', [$reader, $owner, $owner, $reader]) !== null;
    }

    /**
     * A character's public Snaps, newest first, as rows (PlayerPages: the
     * profile's Snaps section and tab); none for a reader blocked either way.
     *
     * @return list<array<string, mixed>>
     */
    public static function publicOf(\Minn\Db $db, int $owner, int $reader): array
    {
        if (self::blocked($db, $reader, $owner)) {
            return [];
        }
        return $db->rows('SELECT id, user_id, file, street, x, y, caption, width, height, created_at, public FROM ' . $db->table(self::TABLE) . ' WHERE user_id = ? AND public = 1 ORDER BY id DESC', [$owner]);
    }

    /**
     * The kept bytes of a Snap's picture ('small': the cards' copy, else the
     * picture itself), or null when the file is gone.
     *
     * @param array<string, mixed> $row
     */
    public static function bytes(array $row, string $kind): ?string
    {
        $file = (string) $row['file'];
        if (preg_match(self::FILE, $file) !== 1) {
            return null;
        }
        $dir = self::dir();
        $small = self::smallName($file);
        $path = $kind === 'small' && is_file("{$dir}/{$small}") ? "{$dir}/{$small}" : "{$dir}/{$file}";
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /**
     * A downloaded Snap's file name: the street and the date it was taken
     * ("Snap of Hechey Track 2026-11-24.jpg"), plain letters only.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $index the game's street index (tsid => row)
     */
    public static function fileName(array $row, array $index): string
    {
        $where = BuddiesOnline::where((string) $row['street'], $index)['street'];
        $where = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Za-z0-9 \'().,-]+/', ' ', $where)));
        $at = strtotime(((string) $row['created_at']) . ' UTC');
        $date = $at ? gmdate('Y-m-d', $at) : '';
        return trim('Snap' . ($where === '' ? '' : " of {$where}") . ($date === '' ? '' : " {$date}")) . '.jpg';
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
        return WordFilter::text($text); // the remake's own word filter
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
