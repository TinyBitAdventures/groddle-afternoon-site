<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\RestError;

/**
 * A picture a player's game uploads for their profile (Profiles): a PNG and
 * nothing else. The bytes are read chunk by chunk before anything else
 * touches them: the PNG signature, an IHDR first (its CRC checked, its size
 * within the kind's limits), every chunk well formed, IDAT present, IEND
 * last. When GD is there the picture is then decoded and written again, so
 * what is stored is a PNG GD made (any extra chunks dropped); a picture GD
 * cannot read is refused.
 */
final class ProfilePng
{
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * What each kind may be: [min width, min height, max width, max height,
     * max bytes]. The avatar is the original's _172 still (172x248, PNGUtil.as
     * saveSinglesFromAvatarMc 87-88) drawn at up to 2x; the tile its _100
     * (100x144) at up to 2x; the home the street as the player saw it.
     */
    public const KINDS = [
        'avatar' => [86, 124, 344, 496, 300 * 1024],
        'tile' => [50, 72, 200, 288, 160 * 1024],
        'home' => [240, 80, 1920, 816, 600 * 1024],
    ];

    /**
     * The PNG checked (and re-encoded with GD when GD is there): the bytes
     * to store, with their width and height.
     *
     * @return array{bytes: string, width: int, height: int}
     */
    public static function accept(string $kind, mixed $value): array
    {
        if (!isset(self::KINDS[$kind])) {
            throw new RestError('groddle_profile_kind', 'That is not a picture a profile keeps.', 400);
        }
        [$minW, $minH, $maxW, $maxH, $maxBytes] = self::KINDS[$kind];
        if (!is_string($value) || $value === '') {
            throw new RestError('groddle_profile_image', 'Send the picture as a base64 PNG.', 400);
        }
        // A data URL is fine; the base64 after it is what counts.
        if (str_starts_with($value, 'data:')) {
            if (!str_starts_with($value, 'data:image/png;base64,')) {
                throw new RestError('groddle_profile_image', 'Only PNG pictures are kept.', 415);
            }
            $value = substr($value, strlen('data:image/png;base64,'));
        }
        if (strlen($value) > intdiv($maxBytes * 4, 3) + 8) {
            throw new RestError('groddle_profile_too_large', 'That picture is larger than a profile keeps.', 413, ['max' => $maxBytes]);
        }
        $bytes = base64_decode($value, true);
        if (!is_string($bytes) || $bytes === '') {
            throw new RestError('groddle_profile_image', 'Send the picture as a base64 PNG.', 400);
        }
        if (strlen($bytes) > $maxBytes) {
            throw new RestError('groddle_profile_too_large', 'That picture is larger than a profile keeps.', 413, ['max' => $maxBytes]);
        }
        [$width, $height] = self::read($bytes);
        if ($width < $minW || $height < $minH || $width > $maxW || $height > $maxH) {
            throw new RestError('groddle_profile_size', "That picture is {$width}x{$height}; a profile {$kind} is {$minW}x{$minH} to {$maxW}x{$maxH}.", 400);
        }
        $bytes = self::reencode($bytes, $width, $height);
        if (strlen($bytes) > $maxBytes) {
            throw new RestError('groddle_profile_too_large', 'That picture is larger than a profile keeps.', 413, ['max' => $maxBytes]);
        }
        return ['bytes' => $bytes, 'width' => $width, 'height' => $height];
    }

    /**
     * The width and height of a well formed PNG; anything else throws.
     *
     * @return array{0: int, 1: int}
     */
    public static function read(string $bytes): array
    {
        $length = strlen($bytes);
        if ($length < 8 + 25 + 12 || !str_starts_with($bytes, self::SIGNATURE)) {
            throw new RestError('groddle_profile_image', 'Only PNG pictures are kept.', 415);
        }
        $at = 8;
        $width = $height = 0;
        $first = true;
        $idat = false;
        for (;;) {
            if ($at + 12 > $length) {
                throw new RestError('groddle_profile_image', 'That PNG is cut short.', 400);
            }
            $size = unpack('N', substr($bytes, $at, 4))[1];
            $type = substr($bytes, $at + 4, 4);
            if ($size > $length - $at - 12 || preg_match('/^[A-Za-z]{4}$/', $type) !== 1) {
                throw new RestError('groddle_profile_image', 'That PNG is not well formed.', 400);
            }
            $data = substr($bytes, $at + 8, $size);
            $crc = unpack('N', substr($bytes, $at + 8 + $size, 4))[1];
            if ($first) {
                if ($type !== 'IHDR' || $size !== 13 || crc32($type . $data) !== $crc) {
                    throw new RestError('groddle_profile_image', 'That PNG has no proper header.', 400);
                }
                $h = unpack('Nw/Nh/Cdepth/Ccolour/Ccompression/Cfilter/Cinterlace', $data);
                $width = (int) $h['w'];
                $height = (int) $h['h'];
                $depthOk = in_array($h['depth'], match ($h['colour']) {
                    0 => [1, 2, 4, 8, 16],
                    3 => [1, 2, 4, 8],
                    2, 4, 6 => [8, 16],
                    default => [],
                }, true);
                if ($width < 1 || $height < 1 || !$depthOk || $h['compression'] !== 0 || $h['filter'] !== 0 || $h['interlace'] > 1) {
                    throw new RestError('groddle_profile_image', 'That PNG has no proper header.', 400);
                }
                $first = false;
            } elseif ($type === 'IHDR') {
                throw new RestError('groddle_profile_image', 'That PNG is not well formed.', 400);
            }
            $idat = $idat || $type === 'IDAT';
            $at += 12 + $size;
            if ($type === 'IEND') {
                if (!$idat || $at !== $length) {
                    throw new RestError('groddle_profile_image', 'That PNG is not well formed.', 400);
                }
                return [$width, $height];
            }
        }
    }

    /** The picture decoded and written again by GD, when GD is there; GD failing to read it refuses it. */
    private static function reencode(string $bytes, int $width, int $height): string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
            return $bytes;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false || imagesx($image) !== $width || imagesy($image) !== $height) {
            throw new RestError('groddle_profile_image', 'That PNG could not be read.', 400);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        $ok = imagepng($image, null, 9);
        $out = (string) ob_get_clean();
        return $ok && $out !== '' ? $out : $bytes;
    }
}
