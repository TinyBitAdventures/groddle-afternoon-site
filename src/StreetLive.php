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

/**
 * What stands on a street now, for its Encyclopedia page (register G1277, report #686). A player
 * found no Wood Trees on Thicket where the page lists 3: all three had been felled, and a felled
 * Wood Tree is a Patch, as in Glitch. Austin 2026-11-25: "Maybe the live count could say last check
 * within last 24 hours, so a cached lookup rather then a realtime check."
 *
 *   GET groddle/v1/streets/live?street=GLI23Q4NDHD11EK
 *       {"checked":1764050000,"street":{"trees":{"wood_tree":[0,3]},"patches":{"empty":5,"seedlings":1,
 *        "dead":1,"grown":{"trant_spice":1}}}}
 *       "street" null: the street has no trees and no shared Patches. {"checked":null}: no count
 *       younger than KEEP (the realtime server has not answered for a day); the page then shows its
 *       starting counts only.
 *
 * The counts come from the realtime server's signed call street_counts (groddle-afternoon-server
 * streetcounts.go: per street, each tree class [standing, gone] and the shared Patch plots by what
 * is in them; object counts only, no player). The site keeps the last answer in the option
 * groddle_street_counts and asks again on the first request after REFRESH, at most once every RETRY
 * while the server does not answer (groddle_street_counts_tried). The site has no scheduled job of
 * its own (Minn gives an extension no cron seam), so the request that finds the count stale asks.
 * Public, cached by browsers for MAX_AGE.
 */
final readonly class StreetLive
{
    public const OPTION = 'groddle_street_counts';
    public const TRIED_OPTION = 'groddle_street_counts_tried';

    /** How old a count gets before the next request asks the realtime server again. */
    public const REFRESH = 3600;

    /** How long the site waits after an ask the server did not answer. */
    public const RETRY = 300;

    /** The oldest count a page is shown (Austin: "last check within last 24 hours"). */
    public const KEEP = 86400;

    /** How long a browser keeps an answer. */
    public const MAX_AGE = 600;

    /** The most of the server's answer the site reads (about 40 KB for every street). */
    public const MAX_BYTES = 1048576;

    public const STREET = '/^[A-Z][A-Z0-9]{1,31}$/';

    private const CLASS_NAME = '/^[a-z][a-z0-9_]{0,63}$/';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/streets/live', policy: new Policy(Access::Public))]
    public function live(Request $request): Response
    {
        $street = (string) ($request->query('street') ?? '');
        if (preg_match(self::STREET, $street) !== 1) {
            return Response::json(['code' => 'bad_street', 'message' => 'Name a street by its id.', 'data' => ['status' => 400]], 400)
                ->withHeader('Cache-Control', 'public, max-age=' . self::MAX_AGE);
        }
        $now = time();
        $site = $this->minn->site;
        $snap = self::read($site->option(self::OPTION));
        if (self::due($snap, (int) ($site->option(self::TRIED_OPTION) ?? 0), $now)) {
            $site->setOption(self::TRIED_OPTION, (string) $now, 'off');
            $fresh = self::snapshot((new RealtimeCall($this->minn))->post('street_counts', [], RealtimeCall::TIMEOUT, self::MAX_BYTES), $now);
            if ($fresh !== null) {
                $site->setOption(self::OPTION, (string) json_encode($fresh, JSON_UNESCAPED_SLASHES), 'off');
                $snap = $fresh;
            }
        }
        $out = self::answer($snap, $street, $now);
        return Response::json($out)->withHeader('Cache-Control', 'public, max-age=' . ($out['checked'] === null ? 60 : self::MAX_AGE));
    }

    /** Whether to ask the server: no count or one older than REFRESH, and no ask in the last RETRY. */
    public static function due(?array $snap, int $tried, int $now): bool
    {
        $stale = $snap === null || $snap['checked'] <= $now - self::REFRESH;
        return $stale && $tried <= $now - self::RETRY;
    }

    /**
     * The page's answer: the count's time and the street's row (null when it has nothing counted),
     * or checked null when there is no count younger than KEEP.
     *
     * @return array{checked: ?int, street?: ?object}
     */
    public static function answer(?array $snap, string $street, int $now): array
    {
        if ($snap === null || $snap['checked'] < $now - self::KEEP || $snap['checked'] > $now + 300) {
            return ['checked' => null];
        }
        $row = $snap['streets'][$street] ?? null;
        return ['checked' => $snap['checked'], 'street' => $row === null ? null : self::rowObject($row)];
    }

    /**
     * The server's answer as the site keeps it, every value checked: ["checked" => $now, "streets" =>
     * [tsid => ["trees" => [class => [standing, gone]], "patches" => [...]]]], or null when it is not
     * a good answer.
     *
     * @return array{checked: int, streets: array<string, array>}|null
     */
    public static function snapshot(mixed $answer, int $now): ?array
    {
        if (!is_array($answer) || ($answer['status'] ?? null) !== 'ok' || ($answer['http'] ?? 200) !== 200 || !is_array($answer['streets'] ?? null)) {
            return null;
        }
        $streets = [];
        foreach ($answer['streets'] as $tsid => $row) {
            if (!is_string($tsid) || preg_match(self::STREET, $tsid) !== 1 || !is_array($row)) {
                continue;
            }
            $clean = [];
            $trees = [];
            foreach (is_array($row['trees'] ?? null) ? $row['trees'] : [] as $class => $t) {
                if (is_string($class) && preg_match(self::CLASS_NAME, $class) === 1 && is_array($t) && count($t) === 2 && self::count($t[0] ?? null) !== null && self::count($t[1] ?? null) !== null) {
                    $trees[$class] = [self::count($t[0]), self::count($t[1])];
                }
            }
            if ($trees !== []) {
                ksort($trees);
                $clean['trees'] = $trees;
            }
            $p = is_array($row['patches'] ?? null) ? $row['patches'] : [];
            $patches = [];
            foreach (['empty', 'seedlings', 'dead'] as $k) {
                if (($n = self::count($p[$k] ?? null)) > 0) {
                    $patches[$k] = $n;
                }
            }
            $grown = [];
            foreach (is_array($p['grown'] ?? null) ? $p['grown'] : [] as $class => $n) {
                if (is_string($class) && preg_match(self::CLASS_NAME, $class) === 1 && ($n = self::count($n)) > 0) {
                    $grown[$class] = $n;
                }
            }
            if ($grown !== []) {
                ksort($grown);
                $patches['grown'] = $grown;
            }
            if ($patches !== []) {
                $clean['patches'] = $patches;
            }
            if ($clean !== []) {
                $streets[$tsid] = $clean;
            }
        }
        ksort($streets);
        return ['checked' => $now, 'streets' => $streets];
    }

    /** The kept count back from its option, or null when there is none or it does not read. */
    public static function read(?string $json): ?array
    {
        $data = json_decode((string) $json, true);
        if (!is_array($data) || !is_int($data['checked'] ?? null) || !is_array($data['streets'] ?? null)) {
            return null;
        }
        return $data;
    }

    /** A street's row with its maps as JSON objects. */
    private static function rowObject(array $row): object
    {
        $out = [];
        if (isset($row['trees'])) {
            $out['trees'] = (object) $row['trees'];
        }
        if (isset($row['patches'])) {
            $p = $row['patches'];
            if (isset($p['grown'])) {
                $p['grown'] = (object) $p['grown'];
            }
            $out['patches'] = (object) $p;
        }
        return (object) $out;
    }

    /** A count from the server (a whole number, 0 to 10,000), or null. */
    private static function count(mixed $n): ?int
    {
        return is_int($n) && $n >= 0 && $n <= 10000 ? $n : null;
    }
}
