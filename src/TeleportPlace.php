<?php

declare(strict_types=1);

namespace Groddle\Minn;

/**
 * The pure half of the admin teleport (AdminTeleport): which street a tsid
 * names in the game's street index, where on a street a moved player
 * stands, the save's location entry, and where the realtime server takes
 * the site's calls. No database and no network, so tests run it through
 * the php CLI (spike tests/g406-admin-teleport.test.js).
 *
 * Coordinates are the game's: from the street's top-left corner, y down
 * (js/street-model.js worldToScreen: x - bounds.l, y - bounds.t), the way
 * a save's location and a signpost's arrive point are written.
 */
final class TeleportPlace
{
    /** Where the realtime server listens when nothing says otherwise (its -addr default). */
    public const LOCAL_SERVICE_URL = 'http://127.0.0.1:8793/ws';

    /**
     * The index key a tsid names, trying the G and L forms as the game does
     * (js/street-loader.js resolveIndexTsid), or null.
     *
     * @param array<string, mixed> $streets the index's streets map
     */
    public static function streetKey(array $streets, string $tsid): ?string
    {
        $tsid = trim($tsid);
        if ($tsid === '' || preg_match('/^[A-Za-z0-9_-]{1,32}$/', $tsid) !== 1) {
            return null;
        }
        $tries = [$tsid];
        if (str_starts_with($tsid, 'L')) {
            $tries[] = 'G' . substr($tsid, 1);
        }
        if (str_starts_with($tsid, 'G')) {
            $tries[] = 'L' . substr($tsid, 1);
        }
        foreach ($tries as $key) {
            if (isset($streets[$key]) && is_array($streets[$key])) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Where a moved player stands: at $x when given (on the lowest floor
     * under it), else at the signpost nearest the street's middle, else in
     * the middle on its lowest floor. Rounded, inside the street.
     *
     * @param array<string, mixed> $street the street's JSON (assets/streets/<tsid>.json)
     * @return array{x: int, y: int}
     */
    public static function spot(array $street, ?float $x = null, ?float $y = null): array
    {
        $b = is_array($street['bounds'] ?? null) ? $street['bounds'] : [];
        $l = (float) ($b['l'] ?? -1000);
        $r = (float) ($b['r'] ?? 1000);
        $t = (float) ($b['t'] ?? -1000);
        $bottom = (float) ($b['b'] ?? 0);
        $w = max(80.0, $r - $l);
        $h = max(80.0, $bottom - $t);
        $clamp = static fn (float $sx, float $sy): array => [
            'x' => (int) round(max(40.0, min($w - 40.0, $sx))),
            'y' => (int) round(max(40.0, min($h - 20.0, $sy))),
        ];
        if ($x !== null && $y !== null) {
            return $clamp($x, $y);
        }
        if ($x === null) {
            $best = null;
            foreach (is_array($street['signposts'] ?? null) ? $street['signposts'] : [] as $sign) {
                if (!is_array($sign) || !is_numeric($sign['x'] ?? null) || !is_numeric($sign['y'] ?? null)) {
                    continue;
                }
                $d = abs((float) $sign['x'] - ($l + $r) / 2);
                if ($best === null || $d < $best[0]) {
                    $best = [$d, (float) $sign['x'] - $l, (float) $sign['y'] - $t];
                }
            }
            if ($best !== null) {
                return $clamp($best[1], $best[2]);
            }
            $x = $w / 2;
        }
        return $clamp($x, self::floorAt($street, $x + $l) - $t);
    }

    /** The lowest platform's height (world y) at world x, or a little above the street's bottom. */
    private static function floorAt(array $street, float $wx): float
    {
        $floor = null;
        foreach (is_array($street['platforms'] ?? null) ? $street['platforms'] : [] as $p) {
            if (!is_array($p) || !is_numeric($p['x1'] ?? null) || !is_numeric($p['x2'] ?? null) || !is_numeric($p['y1'] ?? null) || !is_numeric($p['y2'] ?? null)) {
                continue;
            }
            [$x1, $y1, $x2, $y2] = [(float) $p['x1'], (float) $p['y1'], (float) $p['x2'], (float) $p['y2']];
            if ($x1 > $x2) {
                [$x1, $y1, $x2, $y2] = [$x2, $y2, $x1, $y1];
            }
            if ($wx < $x1 || $wx > $x2) {
                continue;
            }
            $py = $x2 === $x1 ? max($y1, $y2) : $y1 + ($y2 - $y1) * ($wx - $x1) / ($x2 - $x1);
            $floor = $floor === null ? $py : max($floor, $py);
        }
        return $floor ?? ((float) ($street['bounds']['b'] ?? 0) - 60.0);
    }

    /** The save's location entry as the game writes it (game.js savePlayerLocation). */
    public static function locationEntry(string $tsid, int $x, int $y, int $savedAtMs): string
    {
        return json_encode(['tsid' => $tsid, 'x' => $x, 'y' => $y, 'savedAt' => $savedAtMs], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Where the site posts to the realtime server: the named URL (option
     * groddle_realtime_service_url, an http or https address), else the
     * socket the game opens (GRODDLE_REALTIME_URL) as http or https, else
     * the server's own default on this host.
     */
    public static function serviceUrl(?string $named, ?string $socket): string
    {
        $named = trim((string) $named);
        if ($named !== '' && preg_match('~^https?://[^/?#@\s]+(/[^?#\s]*)?$~i', $named) === 1) {
            return $named;
        }
        $socket = trim((string) $socket);
        if (preg_match('~^(wss?)://([^/?#@\s]+)(/[^?#\s]*)?$~i', $socket, $m) === 1) {
            return (strtolower($m[1]) === 'wss' ? 'https' : 'http') . '://' . $m[2] . (($m[3] ?? '') !== '' ? $m[3] : '/ws');
        }
        return self::LOCAL_SERVICE_URL;
    }

    /** A loopback host the call may reach (Minn\Http refuses private addresses unless named). */
    public static function loopbackHost(string $url): ?string
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true) ? $host : null;
    }
}
