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
 *   {"kind": "groddle-multiplayer", "format": 1, "entries": {"playerState": "{...}", "location": "{...}"}}
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

    /**
     * A save body checked and made canonical: the kind asked for, format 1,
     * only known entries, each a string. A solo backup must carry the
     * player state (a backup without it would restore nothing).
     *
     * @return array{kind: string, format: int, entries: array<string, string>}
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
        return ['kind' => $kind, 'format' => self::FORMAT, 'entries' => $entries];
    }

    /** The save as stored: compact JSON, entries always an object; a save over the limit is refused. */
    public static function encode(array $save): string
    {
        $json = Json::encode(['kind' => $save['kind'], 'format' => $save['format'], 'entries' => (object) $save['entries']]);
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
