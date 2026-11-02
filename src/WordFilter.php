<?php

declare(strict_types=1);

namespace Groddle\Minn;

/**
 * The word filter (the remake's own addition, Austin 2026-11-02: "include a
 * list of bad words to exclude from chat and things like bag renames"; the
 * original Glitch had no swear filter in its server or client code). A bad
 * word in something a player writes becomes a grawlix of the same length,
 * "fuck" -> "#!@$", everything around it kept. A character name with one is
 * refused instead (PlayerAccount, GoogleSignIn: NAME_REFUSED).
 *
 * The list is the game repo's public/js/word-filter-list.js, written here as
 * data/word-filter.json by its tools/build-word-filter.mjs (do not edit it by
 * hand). The rule is the game's js/word-filter.js step for step, with a twin
 * in the realtime server (wordfilter.go); the game's tests run this class
 * through the shared vectors (tests/fixtures/word-filter-vectors.json). Only
 * ASCII bytes are looked at (any other byte breaks a word), so the byte
 * offsets here give what the game's UTF-16 offsets give.
 *
 * Where it applies: profile descriptions (Profiles), letters (LedgerMail),
 * gift notes (LedgerGift), Butler messages (ButlerParcels), group names and
 * descriptions (Groups), snap captions (Snaps), and the names and notes a
 * neighbour's save shows visitors (Neighbours, HomeStreet). Not bug reports
 * or anything the administrators write.
 */
final class WordFilter
{
    /** The refusal for a character name with a bad word (the game's js/word-filter.js NAME_REFUSED). */
    public const NAME_REFUSED = 'Please pick a different name.';

    /** The grawlix's symbols, taken in turn from the first. */
    public const SYMBOLS = '#!@$%&*';

    /** The longest piece a spelled-out word ("f.u.c.k") is joined from. */
    private const JOIN_MAX = 2;

    private const JOINERS = ".-_*'`";

    private const SWAPS = ['0' => 'o', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i', 'v' => 'u'];

    /** @var array{whole: array<string, list<list<int>>>, inside: list<array{0: string, 1: list<int>}>, exceptions: array<string, true>}|null */
    private static ?array $rules = null;

    /** The text with every bad word turned into a grawlix; anything else as it was. */
    public static function text(string $s): string
    {
        $spans = self::spans($s);
        if ($spans === []) {
            return $s;
        }
        $out = '';
        $at = 0;
        foreach ($spans as [$a, $b]) {
            $out .= substr($s, $at, $a - $at) . self::grawlix($b - $a);
            $at = $b;
        }
        return $out . substr($s, $at);
    }

    /** Whether the text has a bad word. */
    public static function has(string $s): bool
    {
        return self::spans($s) !== [];
    }

    /** A grawlix n characters long. */
    public static function grawlix(int $n): string
    {
        $out = '';
        for ($k = 0; $k < $n; $k++) {
            $out .= self::SYMBOLS[$k % strlen(self::SYMBOLS)];
        }
        return $out;
    }

    /**
     * [start, end) byte offsets of every bad word in s, in order (the rule's steps 1-3).
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function spans(string $s): array
    {
        $spans = [];
        $n = strlen($s);
        $i = 0;
        while ($i < $n) {
            if (!self::isWordChar($s[$i])) {
                $i++;
                continue;
            }
            $pieces = [];
            $j = $i;
            while (true) {
                $start = $j;
                while ($j < $n && self::isWordChar($s[$j])) {
                    $j++;
                }
                $pieces[] = [$start, $j];
                if ($j + 1 < $n && str_contains(self::JOINERS, $s[$j]) && self::isWordChar($s[$j + 1])) {
                    $j++;
                } else {
                    break;
                }
            }
            $joined = null;
            if (count($pieces) > 1) {
                $short = true;
                $idx = [];
                foreach ($pieces as [$a, $b]) {
                    if ($b - $a > self::JOIN_MAX) {
                        $short = false;
                    }
                    for ($k = $a; $k < $b; $k++) {
                        $idx[] = $k;
                    }
                }
                if ($short) {
                    $joined = self::trimmed($s, $idx);
                }
            }
            if ($joined !== null) {
                $spans[] = $joined;
            } else {
                foreach ($pieces as [$a, $b]) {
                    $one = self::trimmed($s, range($a, $b - 1));
                    if ($one !== null) {
                        $spans[] = $one;
                    }
                }
            }
            $i = $j;
        }
        return $spans;
    }

    /** Steps 3-5 for one word (its ! already trimmed). */
    public static function isBadWord(string $w): bool
    {
        $letters = 0;
        $digits = 0;
        for ($k = 0, $n = strlen($w); $k < $n; $k++) {
            if (self::isLetter($w[$k])) {
                $letters++;
            } elseif ($w[$k] >= '0' && $w[$k] <= '9') {
                $digits++;
            }
        }
        if ($letters <= $digits) {
            return false;
        }
        $rules = self::rules();
        $forms = self::forms(strtolower($w));
        foreach ($forms as $f) {
            if (isset($rules['exceptions'][$f])) {
                return false;
            }
        }
        foreach ($forms as $f) {
            if (self::badForm($rules, $f)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The word's ! trimmed off both ends, and its span when what is left is bad.
     *
     * @param list<int> $idx
     * @return array{0: int, 1: int}|null
     */
    private static function trimmed(string $s, array $idx): ?array
    {
        $a = 0;
        $b = count($idx);
        while ($a < $b && $s[$idx[$a]] === '!') {
            $a++;
        }
        while ($b > $a && $s[$idx[$b - 1]] === '!') {
            $b--;
        }
        if ($a >= $b) {
            return null;
        }
        $word = '';
        for ($k = $a; $k < $b; $k++) {
            $word .= $s[$idx[$k]];
        }
        return self::isBadWord($word) ? [$idx[$a], $idx[$b - 1] + 1] : null;
    }

    private static function isLetter(string $c): bool
    {
        return ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z');
    }

    private static function isWordChar(string $c): bool
    {
        return self::isLetter($c) || ($c >= '0' && $c <= '9') || $c === '@' || $c === '$' || $c === '!';
    }

    /**
     * Step 4: the swaps, with 1 once as i and once as l (the word in lower case).
     *
     * @return list<string>
     */
    private static function forms(string $w): array
    {
        $mapped = static function (string $one) use ($w): string {
            $out = '';
            for ($k = 0, $n = strlen($w); $k < $n; $k++) {
                $c = $w[$k];
                $out .= $c === '1' ? $one : (self::SWAPS[$c] ?? $c);
            }
            return $out;
        };
        return str_contains($w, '1') ? [$mapped('i'), $mapped('l')] : [$mapped('i')];
    }

    /**
     * Step 5's runs of one letter: [letters, counts].
     *
     * @return array{0: string, 1: list<int>}
     */
    private static function runs(string $w): array
    {
        $letters = '';
        $counts = [];
        for ($k = 0, $n = strlen($w); $k < $n; $k++) {
            $c = $w[$k];
            $last = strlen($letters) - 1;
            if ($last >= 0 && $letters[$last] === $c) {
                $counts[$last]++;
            } else {
                $letters .= $c;
                $counts[] = 1;
            }
        }
        return [$letters, $counts];
    }

    /**
     * @param list<int> $have
     * @param list<int> $need
     */
    private static function atLeast(array $have, array $need, int $at): bool
    {
        foreach ($need as $k => $n) {
            if ($have[$at + $k] < $n) {
                return false;
            }
        }
        return true;
    }

    /** @param array{whole: array<string, list<list<int>>>, inside: list<array{0: string, 1: list<int>}>, exceptions: array<string, true>} $rules */
    private static function badForm(array $rules, string $f): bool
    {
        [$letters, $counts] = self::runs($f);
        foreach ($rules['whole'][$letters] ?? [] as $need) {
            if (self::atLeast($counts, $need, 0)) {
                return true;
            }
        }
        foreach ($rules['inside'] as [$inLetters, $inCounts]) {
            for ($at = strpos($letters, $inLetters); $at !== false; $at = strpos($letters, $inLetters, $at + 1)) {
                if (self::atLeast($counts, $inCounts, $at)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array{whole: array<string, list<list<int>>>, inside: list<array{0: string, 1: list<int>}>, exceptions: array<string, true>} */
    private static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }
        $file = dirname(__DIR__) . '/data/word-filter.json';
        $list = json_decode(is_file($file) ? (string) file_get_contents($file) : '', true);
        if (!is_array($list)) {
            throw new \RuntimeException('data/word-filter.json is missing: run the game repo\'s tools/build-word-filter.mjs');
        }
        $norm = static fn (string $w): string => self::forms(strtolower($w))[0];
        $whole = [];
        $add = static function (string $w) use (&$whole, $norm): void {
            [$letters, $counts] = self::runs($norm($w));
            $whole[$letters][] = $counts;
        };
        foreach ($list['stems'] ?? [] as $stem) {
            foreach ($list['suffixes'] ?? [] as $suffix) {
                $add($stem . $suffix);
            }
        }
        foreach ($list['exact'] ?? [] as $w) {
            $add($w);
        }
        $inside = [];
        foreach ($list['inside'] ?? [] as $w) {
            $inside[] = self::runs($norm($w));
        }
        $exceptions = [];
        foreach ($list['exceptions'] ?? [] as $w) {
            $exceptions[$norm($w)] = true;
        }
        return self::$rules = ['whole' => $whole, 'inside' => $inside, 'exceptions' => $exceptions];
    }
}
