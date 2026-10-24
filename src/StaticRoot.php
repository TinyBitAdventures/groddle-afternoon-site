<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;

/**
 * Where the built game lives: the groddle_static_root option (absolute, or
 * relative to the webroot, e.g. ../game beside it), else the webroot itself.
 */
final readonly class StaticRoot
{
    private function __construct(public string $dir)
    {
    }

    /** The root this site names, made absolute and without a trailing slash. */
    public static function fromSite(Seams $minn): self
    {
        $named = trim((string) ($minn->site->option('groddle_static_root') ?? ''));
        $webroot = defined('ABSPATH') ? rtrim(ABSPATH, '/') : '';
        $dir = $named === '' ? $webroot : (str_starts_with($named, '/') ? $named : "{$webroot}/{$named}");
        return new self(rtrim((string) (realpath($dir) ?: $dir), '/'));
    }

    /**
     * The index.html a path's folder holds, or null. The path is matched
     * against the route's own pattern first, so it never climbs out of the
     * root; realpath is checked again after symlinks resolve.
     */
    public function indexFor(string $folder): ?string
    {
        $file = $this->dir . ($folder === '' ? '' : '/' . $folder) . '/index.html';
        $real = realpath($file);
        if ($real === false || !is_file($real)) {
            return null;
        }
        return $real;
    }
}
