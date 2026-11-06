<?php

declare(strict_types=1);

namespace Groddle\Minn;

/**
 * Content keys on the stylesheets and scripts the theme links itself (report 95, 2026-11-06):
 * `?v=` and the first 8 hex of the file's sha256, the key AdminBuilderPage::key puts on the level
 * builder's files, so a deploy that changes a file changes its address and a browser never keeps
 * the old copy. The game's own pages are keyed by its build (tools/module-keys); the report's data
 * by DataKeys.
 *
 * Plain PHP with no engine classes, so tests run it with php -r.
 */
final class AssetKeys
{
    /** @var array<string, string> each path's key, worked out once a request */
    private static array $keys = [];

    /** A file's key: the first 8 hex of its bytes' sha256, or '' when it is not there. */
    public static function key(string $dir, string $file): string
    {
        $path = rtrim($dir, '/') . '/' . ltrim($file, '/');
        if (!isset(self::$keys[$path])) {
            self::$keys[$path] = $dir !== '' && $file !== '' && !str_contains($file, '..') && is_file($path)
                ? substr((string) hash_file('sha256', $path), 0, 8)
                : '';
        }
        return self::$keys[$path];
    }

    /** `$href` with `?v=<key>` of the file `$file` under `$dir`; as it is when the file is not there. */
    public static function url(string $href, string $dir, string $file): string
    {
        $key = self::key($dir, $file);
        return $key === '' ? $href : $href . (str_contains($href, '?') ? '&' : '?') . 'v=' . $key;
    }
}
