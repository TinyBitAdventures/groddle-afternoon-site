<?php

declare(strict_types=1);

namespace Groddle\Minn;

/**
 * The game's content keys on art the theme draws (2026-10-29). The game
 * build writes js/data-versions.js (tools/data-versions/build.mjs): a short
 * hash for every data or art file changed since its epoch commit, the epoch's
 * key for the rest. The game's pages and its hook (js/data-urls.js) put
 * `?v=<key>` on every such URL; this does the same for the <img> src/srcset
 * and style url() the report's sections draw in PHP (the diorama's sprites,
 * the seasons' icons), so a deploy that changes a sprite never leaves the old
 * one cached. Only paths from the site root ("/assets/...") are keyed, the way
 * the sections write them. Without the map every URL is left as it is.
 *
 * Plain PHP with no engine classes, so tests run it with php -r.
 */
final class DataKeys
{
    /** Versioned extensions (tools/data-versions/build.mjs EXT). */
    private const EXT = '/\.(?:json|png|webp|jpe?g|gif|svg|mp3|ogg|wav|m4a|atlas|xml)$/i';

    /** @var array<string, array{epoch: string, files: array<string, string>}> */
    private static array $maps = [];

    /**
     * The map the game build wrote in `$dir` (the static root): its epoch key and its files.
     *
     * @return array{epoch: string, files: array<string, string>}
     */
    public static function map(string $dir): array
    {
        if (isset(self::$maps[$dir])) {
            return self::$maps[$dir];
        }
        $source = is_file($dir . '/js/data-versions.js') ? (string) file_get_contents($dir . '/js/data-versions.js') : '';
        $files = [];
        preg_match_all('/^\s+("(?:[^"\\\\]|\\\\.)*"): "([0-9a-f]{8})",$/m', $source, $rows, PREG_SET_ORDER);
        foreach ($rows as [, $path, $key]) {
            $files[(string) json_decode($path)] = $key;
        }
        $epoch = preg_match("/epoch: '([0-9a-f]{8})'/", $source, $m) === 1 ? $m[1] : '';
        return self::$maps[$dir] = ['epoch' => $epoch, 'files' => $files];
    }

    /** `$url` with its content key (`?v=`, replacing one it had), when it is a versioned file from the site root. */
    public static function url(string $url, array $map): string
    {
        if ($map['epoch'] === '' || preg_match('#^/(?!/)([^?\#\s"\'<>]*)([?][^\#]*)?(\#.*)?$#', $url, $m) !== 1) {
            return $url;
        }
        $path = rawurldecode($m[1]);
        if (preg_match(self::EXT, $path) !== 1) {
            return $url;
        }
        $key = $map['files'][$path] ?? $map['epoch'];
        $params = array_values(array_filter(explode('&', ltrim($m[2] ?? '', '?')), static fn (string $p): bool => $p !== '' && preg_match('/^v(?:=|$)/', $p) !== 1));
        return '/' . $m[1] . '?' . implode('&', ['v=' . $key, ...$params]) . ($m[3] ?? '');
    }

    /** Markup with each src, srcset and style url() keyed (attribute values in double quotes, as the sections write them). */
    public static function html(string $html, array $map): string
    {
        if ($map['epoch'] === '') {
            return $html;
        }
        return (string) preg_replace_callback('/(\s(src|srcset|style)=")([^"]*)"/i', static function (array $m) use ($map): string {
            $name = strtolower($m[2]);
            $value = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5);
            if ($name === 'src') {
                $next = self::url($value, $map);
            } elseif ($name === 'srcset') {
                $next = implode(',', array_map(static fn (string $part): string => (string) preg_replace_callback('/^(\s*)(\S+)/', static fn (array $p): string => $p[1] . self::url($p[2], $map), $part), explode(',', $value)));
            } else {
                $next = (string) preg_replace_callback('/(url\(\s*)(["\']?)([^"\'()\s]+)\2(\s*\))/i', static fn (array $u): string => $u[1] . $u[2] . self::url($u[3], $map) . $u[2] . $u[4], $value);
            }
            return $next === $value ? $m[0] : $m[1] . htmlspecialchars($next, ENT_COMPAT | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '"';
        }, $html);
    }
}
