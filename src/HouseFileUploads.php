<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Auth\Can;
use Minn\Runtime\Runtime;

/**
 * House files (G1285): an administrator may upload a .json file to the media library, so a whole
 * house can be shared as a play link (/play/?autostart=1&fresh=1&housefile=/wp-content/uploads/...).
 * The game reads it as data only (js/house-file.js: same origin, under /wp-content/uploads/, size
 * capped, schema checked, solo only). Everyone else keeps the engine's own list of upload types.
 */
final class HouseFileUploads
{
    public const EXT = 'json';
    public const TYPE = 'application/json';

    public static function register(): void
    {
        Runtime::hooks()->add('upload_mimes', [self::class, 'allow'], 10, 2);
    }

    /**
     * upload_mimes: the types given, plus json for a user who can manage_options.
     *
     * @param array<string, string> $types
     * @return array<string, string>
     */
    public static function allow(mixed $types = [], mixed $user = null): array
    {
        $types = is_array($types) ? $types : [];
        $admin = $user === null ? Can::current('manage_options') : Can::user($user, 'manage_options');
        if ($admin) {
            $types[self::EXT] = self::TYPE;
        }
        return $types;
    }
}
