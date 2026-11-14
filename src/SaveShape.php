<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Json;
use Minn\RestError;

/**
 * The one shape a saved character takes on the server: the game's named
 * save entries (public/js/save-store.js SAVE_KEYS) as the raw strings the
 * game serializes, under a kind that says which game it belongs to. A
 * multiplayer save and a solo backup share the shape and never the kind,
 * so neither route takes the other's. The server checks the shape and the
 * size, nothing more: it does not read inside the entries.
 *
 *   {"kind": "groddle-multiplayer", "format": 1, "build": {"id": "3f2a9c01b7de", "at": 1763100000}, "entries": {"playerState": "{...}", "location": "{...}"}}
 *
 * build (G737, optional): the game build that wrote the save, as the play page is stamped
 * (groddle-afternoon tools/deploy/build-id.mjs: id, and at, the build's commit time). A newer
 * build may carry fields an older one would drop, so the multiplayer save refuses a write from a
 * different, older build than the one stored (olderBuild). A save the server changes itself (a
 * payout, a teleport, a trade caught up) keeps the build it had (with).
 */
final class SaveShape
{
    /** A save on the multiplayer world. */
    public const MULTIPLAYER = 'groddle-multiplayer';

    /** A solo character backed up from a browser. */
    public const SOLO = 'groddle-solo';

    public const FORMAT = 1;

    /** The save entry names (public/js/save-store.js SAVE_ENTRY_NAMES). */
    public const ENTRIES = ['playerState', 'location', 'droppedItems', 'companions', 'lastNewDayTs', 'lastNewDayKey', 'appearance'];

    /** The largest save either table keeps, as stored JSON. */
    public const MAX_BYTES = 4 * 1024 * 1024;

    /** A build stamp: tools/deploy/build-id.mjs ids are 12 hex digits; up to 40 is allowed. */
    private const BUILD_ID = '/^[0-9a-f]{1,40}$/';

    /**
     * A save body checked and made canonical: the kind asked for, format 1,
     * only known entries, each a string, and the build that wrote it when it
     * names one in the build shape (anything else is left off). A solo backup
     * must carry the player state (a backup without it would restore nothing).
     *
     * @return array{kind: string, format: int, build?: array{id: string, at: int}, entries: array<string, string>}
     */
    public static function check(mixed $data, string $kind): array
    {
        if (!is_array($data) || ($data['kind'] ?? null) !== $kind) {
            throw new RestError('groddle_save_kind', $kind === self::SOLO ? 'A backup takes a solo save.' : 'The multiplayer save takes a multiplayer save.', 400);
        }
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new RestError('groddle_save_format', 'This save format is not one the server knows.', 400);
        }
        $entries = $data['entries'] ?? null;
        if (!is_array($entries) || ($entries !== [] && array_is_list($entries))) {
            throw new RestError('groddle_save_invalid', 'A save needs its entries as an object.', 400);
        }
        foreach ($entries as $name => $value) {
            if (!in_array($name, self::ENTRIES, true) || !is_string($value)) {
                throw new RestError('groddle_save_invalid', "A save entry is not one the game writes: {$name}.", 400);
            }
        }
        if ($kind === self::SOLO && !isset($entries['playerState'])) {
            throw new RestError('groddle_save_invalid', 'A backup needs the player state.', 400);
        }
        $build = self::build($data['build'] ?? null);
        return ['kind' => $kind, 'format' => self::FORMAT] + ($build === null ? [] : ['build' => $build]) + ['entries' => $entries];
    }

    /** A build stamp as kept: {id, at} or null when it is not one. */
    public static function build(mixed $value): ?array
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value) || !is_string($value['id'] ?? null) || preg_match(self::BUILD_ID, $value['id']) !== 1) {
            return null;
        }
        $at = $value['at'] ?? null;
        return ['id' => $value['id'], 'at' => is_int($at) && $at > 0 ? $at : 0];
    }

    /**
     * The build a stored save names, read from its head without decoding the rest (encode writes
     * build before the entries), or null.
     */
    public static function buildOf(?string $stored): ?array
    {
        if ($stored === null || preg_match('/^\{"kind":"[a-z-]+","format":\d+,"build":\{"id":"([0-9a-f]{1,40})","at":(\d{1,12})\}/', $stored, $m) !== 1) {
            return null;
        }
        return ['id' => $m[1], 'at' => (int) $m[2]];
    }

    /**
     * Whether a write from build $incoming must not go over a save stored by build $stored: the
     * stored save names a build, the write names another one (or none: a page from before builds
     * were named), and that one is not newer. The same build always writes, and so does a newer one
     * (the first save after a release).
     */
    public static function olderBuild(?array $stored, ?array $incoming): bool
    {
        if ($stored === null || ($stored['at'] ?? 0) <= 0) {
            return false;
        }
        if ($incoming !== null && $incoming['id'] === $stored['id']) {
            return false;
        }
        return ($incoming['at'] ?? 0) < $stored['at'];
    }

    /** A stored save (decode) with new entries, its kind, format and build kept. */
    public static function with(object $save, array $entries): array
    {
        $build = self::build($save->build ?? null);
        return ['kind' => $save->kind, 'format' => $save->format] + ($build === null ? [] : ['build' => $build]) + ['entries' => $entries];
    }

    /** The save as stored: compact JSON, entries always an object; a save over the limit is refused. */
    public static function encode(array $save): string
    {
        $build = self::build($save['build'] ?? null);
        $json = Json::encode(['kind' => $save['kind'], 'format' => $save['format']] + ($build === null ? [] : ['build' => $build]) + ['entries' => (object) $save['entries']]);
        if (strlen($json) > self::MAX_BYTES) {
            throw new RestError('groddle_save_too_large', 'This save is larger than the server keeps.', 413, ['max' => self::MAX_BYTES]);
        }
        return $json;
    }

    /** A stored save as an answer: entries an object, so an empty save reads as {} in the browser. */
    public static function decode(string $stored): ?object
    {
        $save = Json::decode($stored, or: null);
        if (!is_array($save)) {
            return null;
        }
        $save['entries'] = (object) (is_array($save['entries'] ?? null) ? $save['entries'] : []);
        return (object) $save;
    }

    /** A stored UTC time as the ISO 8601 the browser reads. */
    public static function time(?string $stored): ?string
    {
        return $stored === null || $stored === '' ? null : str_replace(' ', 'T', $stored) . 'Z';
    }
}
