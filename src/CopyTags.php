<?php

declare(strict_types=1);

namespace Groddle\Minn;

/**
 * A copy's own words on the way between players (2026-11-04): a Cubimal's
 * name, a Draught of Giant Amicability's Giant, a Still's hopper and Hooch, a
 * lit pumpkin's phrases, a Teleportation Script's destination. The game keeps them beside each copy (js/tools.js
 * row.tags) and sends them as an item's "t", beside its wear "r", on a
 * one-copy stack: mail and auctions straight here, gifts and shared ground
 * drops through the realtime server (which checks them too, copytags.go).
 * Each is checked here before it is stored or handed on, by the rules the game
 * writes into data/economy.json ("tags", and each class's "tag" kind), the
 * same as the game's js/copy-tags.js (the three agree on the game repo's
 * tests/fixtures/copy-tag-vectors.json):
 *
 *   - name: the characters a Cubimal name keeps (letters, digits, spaces and
 *     the word filter's symbols), at most max of them, the word filter,
 *     spaces trimmed (npc_cubimal_chick.js onInputBoxResponse 41). A figure
 *     that has won a battle (G40) keeps {name, level, xp} (its instance props
 *     level and xp): the name as above ('' for none), level a whole number
 *     from 1 to levelMax, xp from 0 to xpMax; at level 1 with no xp it is
 *     just the name again.
 *   - phrases: at most max; each one line with no control characters, angle
 *     brackets or U+FFFD, spaces trimmed, at most len characters, the word
 *     filter, trimmed; empty ones dropped (lit_pumpkin_base teach).
 *   - giant: one of the giants (potion_favor.js config.giants).
 *   - still: {hopper, hooch, workingSince} whole numbers, at most hopper and
 *     hooch, and a start no later than now (still.js 30-31).
 *   - script: a Teleportation Script's {tsid, x, y, label, hubId, hubName,
 *     imbued} (teleportation_script.js 298-319): tsid and hubId letters, digits,
 *     _ and -, at most id; x and y whole numbers within coord (null when not
 *     known); label and hubName as one phrase of len. No tsid is no words.
 *   - plop: a Piggy Plop's seeds {seed, n} (plop.js seed_class, seed_count):
 *     seed one of the crop seed classes (seeds), n a whole number from 1 to max
 *     (G605: a shared Plop's seeds, groddle-afternoon-server objects_pigs_plops.go).
 *
 * Words a class does not keep, words on a stack of more than one, or words
 * that come out empty are none (null): the copy goes without them. Only the
 * side giving a copy sends its words; whoever takes it gets the stored ones.
 * Plain PHP, no engine calls.
 */
final class CopyTags
{
    /** The most bytes one copy's stored words may take. */
    public const MAX = 2048;

    /**
     * The checked words of one stack as stored JSON, or null for none.
     */
    public static function json(Economy $economy, string $class, int $n, mixed $value, ?int $nowMs = null): ?string
    {
        if ($value === null || $n !== 1) {
            return null;
        }
        $kind = $economy->tagKind($class);
        if ($kind === '') {
            return null;
        }
        $out = self::clean($economy->tagRules(), $kind, $value, $nowMs ?? (int) floor(microtime(true) * 1000));
        if ($out === null) {
            return null;
        }
        $json = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) && strlen($json) <= self::MAX ? $json : null;
    }

    /** Stored words as they go back out on an item (null: none). */
    public static function decode(mixed $json): mixed
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $v = json_decode($json, true);
        return is_string($v) || is_array($v) ? $v : null;
    }

    /** The checked words of kind (a string, a list or a Still's map), or null. */
    public static function clean(array $rules, string $kind, mixed $value, int $nowMs): string|array|null
    {
        switch ($kind) {
            case 'name':
                $max = (int) ($rules['name']['max'] ?? 0);
                if (is_string($value)) {
                    $name = self::name($value, $max);
                    return $name === '' ? null : $name;
                }
                if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                    return null;
                }
                $name = is_string($value['name'] ?? null) ? self::name($value['name'], $max) : '';
                $level = max(1, self::whole($value['level'] ?? null, (int) ($rules['name']['levelMax'] ?? 1)));
                $xp = self::whole($value['xp'] ?? null, (int) ($rules['name']['xpMax'] ?? 0));
                if ($level <= 1 && $xp <= 0) {
                    return $name === '' ? null : $name;
                }
                return ['name' => $name, 'level' => $level, 'xp' => $xp];
            case 'phrases':
                if (!is_array($value) || !array_is_list($value)) {
                    return null;
                }
                $out = [];
                foreach (array_slice($value, 0, (int) ($rules['phrases']['max'] ?? 0)) as $p) {
                    $s = is_string($p) ? self::phrase($p, (int) ($rules['phrases']['len'] ?? 0)) : '';
                    if ($s !== '') {
                        $out[] = $s;
                    }
                }
                return $out === [] ? null : $out;
            case 'giant':
                return is_string($value) && in_array($value, (array) ($rules['giant']['giants'] ?? []), true) ? $value : null;
            case 'still':
                if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                    return null;
                }
                $v = [
                    'hopper' => self::whole($value['hopper'] ?? null, (int) ($rules['still']['hopper'] ?? 0)),
                    'hooch' => self::whole($value['hooch'] ?? null, (int) ($rules['still']['hooch'] ?? 0)),
                    'workingSince' => self::whole($value['workingSince'] ?? null, $nowMs),
                ];
                return $v['hopper'] > 0 || $v['hooch'] > 0 || $v['workingSince'] > 0 ? $v : null;
            case 'plop':
                // G605: a Piggy Plop's seeds (plop.js seed_class, seed_count; npc_animal doPlop 1315-1350).
                if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                    return null;
                }
                $seed = $value['seed'] ?? null;
                if (!is_string($seed) || !in_array($seed, (array) ($rules['plop']['seeds'] ?? []), true)) {
                    return null;
                }
                $n = self::whole($value['n'] ?? null, (int) ($rules['plop']['max'] ?? 0));
                return $n >= 1 ? ['seed' => $seed, 'n' => $n] : null;
            case 'script':
                if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                    return null;
                }
                $id = (int) ($rules['script']['id'] ?? 0);
                $tsid = self::scriptId($value['tsid'] ?? null, $id);
                if ($tsid === '') {
                    return null;
                }
                $len = (int) ($rules['script']['len'] ?? 0);
                $coord = (int) ($rules['script']['coord'] ?? 0);
                return [
                    'tsid' => $tsid,
                    'x' => self::coord($value['x'] ?? null, $coord),
                    'y' => self::coord($value['y'] ?? null, $coord),
                    'label' => is_string($value['label'] ?? null) ? self::phrase($value['label'], $len) : '',
                    'hubId' => self::scriptId($value['hubId'] ?? null, $id),
                    'hubName' => is_string($value['hubName'] ?? null) ? self::phrase($value['hubName'], $len) : '',
                    'imbued' => ($value['imbued'] ?? null) === true,
                ];
        }
        return null;
    }

    /** A Cubimal name: its characters, at most $max, the word filter, trimmed ('' for none). */
    public static function name(string $s, int $max): string
    {
        $s = (string) preg_replace('/[^A-Za-z0-9 #!@$%&*]/', '', $s);
        return trim(WordFilter::text(substr($s, 0, max(0, $max))), ' ');
    }

    /** One phrase: one line, no control characters, angle brackets or U+FFFD, at most $len characters ('' for none). */
    public static function phrase(string $s, int $len): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            return '';
        }
        $s = (string) preg_replace('/[<>\x{FFFD}]/u', '', $s);
        $s = (string) preg_replace('/[\n\r\t]/', ' ', $s);
        $s = (string) preg_replace('/[\x{00}-\x{1F}\x{7F}-\x{9F}\x{2028}\x{2029}]/u', '', $s);
        $s = mb_substr(trim($s, ' '), 0, max(0, $len), 'UTF-8');
        return trim(WordFilter::text($s), ' ');
    }

    /** Letters, digits, _ and -, at most $max ('' for anything else). */
    private static function scriptId(mixed $v, int $max): string
    {
        return is_string($v) && strlen($v) <= $max && preg_match('/^[A-Za-z0-9_-]+$/D', $v) === 1 ? $v : '';
    }

    /** A JSON number as a whole number within $max, toward zero (anything else, a numeric string too, is null). */
    private static function coord(mixed $v, int $max): ?int
    {
        if (!(is_int($v) || (is_float($v) && is_finite($v)))) {
            return null;
        }
        return $v >= $max ? $max : ($v <= -$max ? -$max : (int) $v);
    }

    /** A JSON number as a whole number from 0 to $max (anything else, a numeric string too, is 0). */
    private static function whole(mixed $v, int $max): int
    {
        if (!(is_int($v) || (is_float($v) && is_finite($v))) || $v <= 0) {
            return 0;
        }
        return $v >= $max ? $max : (int) floor($v);
    }
}
