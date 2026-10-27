<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\RestError;

/**
 * A Snap's picture as a player's game sends it (Snaps): untrusted bytes. The
 * type is read from the bytes alone (a JPEG's SOI, a PNG's signature), never
 * from the data URL or anything else the client says. The structure is
 * walked before anything decodes it (ProfilePng::read for a PNG; for a JPEG
 * every marker segment up to the first scan, a frame header with the size,
 * EOI last), and the size checked against the limits, so nothing large is
 * ever decoded. GD then decodes it and writes a new JPEG, which is all that
 * is stored: no metadata, no extra chunks, nothing the client wrote beyond
 * the pixels. Without GD nothing is kept.
 */
final class SnapImage
{
    /** The most bytes a Snap may arrive as, and be kept as. */
    public const MAX_BYTES = 1_000_000;

    /**
     * Sizes a Snap may be. The game's location view is 960 wide and 400-600
     * tall (game.js VIEW_W, LayoutModel.as 10-13); the original sent the
     * viewport (CameraMan.as snap, gameRenderer.getSnapshot). Up to 2x.
     */
    public const MIN_W = 320;
    public const MIN_H = 160;
    public const MAX_W = 1920;
    public const MAX_H = 1200;

    /** The JPEG quality GD writes the kept picture at. */
    public const QUALITY = 85;

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * The picture checked and written again as a JPEG: the bytes to keep,
     * with their width and height.
     *
     * @return array{bytes: string, width: int, height: int}
     */
    public static function accept(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            throw new RestError('groddle_snap_image', 'Send the snap as a base64 JPEG or PNG.', 400);
        }
        // A data URL's own type is not trusted: whatever it says, the bytes decide.
        if (str_starts_with($value, 'data:')) {
            $comma = strpos($value, ',');
            if ($comma === false || $comma > 64 || !str_ends_with(substr($value, 0, $comma), ';base64')) {
                throw new RestError('groddle_snap_image', 'Send the snap as a base64 JPEG or PNG.', 400);
            }
            $value = substr($value, $comma + 1);
        }
        if (strlen($value) > intdiv(self::MAX_BYTES * 4, 3) + 8) {
            throw new RestError('groddle_snap_too_large', 'That snap is larger than the site keeps.', 413, ['max' => self::MAX_BYTES]);
        }
        $bytes = base64_decode($value, true);
        if (!is_string($bytes) || $bytes === '') {
            throw new RestError('groddle_snap_image', 'Send the snap as a base64 JPEG or PNG.', 400);
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RestError('groddle_snap_too_large', 'That snap is larger than the site keeps.', 413, ['max' => self::MAX_BYTES]);
        }
        [, $width, $height] = self::read($bytes);
        if ($width < self::MIN_W || $height < self::MIN_H || $width > self::MAX_W || $height > self::MAX_H) {
            throw new RestError('groddle_snap_size', "That snap is {$width}x{$height}; a snap is " . self::MIN_W . 'x' . self::MIN_H . ' to ' . self::MAX_W . 'x' . self::MAX_H . '.', 400);
        }
        $out = self::reencode($bytes, $width, $height);
        if (strlen($out) > self::MAX_BYTES) {
            throw new RestError('groddle_snap_too_large', 'That snap is larger than the site keeps.', 413, ['max' => self::MAX_BYTES]);
        }
        return ['bytes' => $out, 'width' => $width, 'height' => $height];
    }

    /**
     * The type ('jpeg' or 'png') and size of well formed picture bytes;
     * anything else throws.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    public static function read(string $bytes): array
    {
        if (str_starts_with($bytes, self::PNG_SIGNATURE)) {
            try {
                [$w, $h] = ProfilePng::read($bytes);
            } catch (RestError) {
                throw new RestError('groddle_snap_image', 'That PNG is not well formed.', 400);
            }
            return ['png', $w, $h];
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            [$w, $h] = self::readJpeg($bytes);
            return ['jpeg', $w, $h];
        }
        throw new RestError('groddle_snap_type', 'Snaps are JPEG or PNG pictures.', 415);
    }

    /**
     * A JPEG's width and height from its frame header, after walking every
     * marker segment from SOI to the first scan (each length inside the
     * file); the file must end with EOI.
     *
     * @return array{0: int, 1: int}
     */
    public static function readJpeg(string $bytes): array
    {
        $length = strlen($bytes);
        $bad = static fn (): RestError => new RestError('groddle_snap_image', 'That JPEG is not well formed.', 400);
        if ($length < 128 || substr($bytes, 0, 2) !== "\xFF\xD8" || substr($bytes, -2) !== "\xFF\xD9") {
            throw $bad();
        }
        $at = 2;
        $width = $height = 0;
        for ($segments = 0; $segments < 200; $segments++) {
            if ($at + 4 > $length || $bytes[$at] !== "\xFF") {
                throw $bad();
            }
            // Fill bytes (0xFF runs) before a marker are allowed.
            while ($at < $length && $bytes[$at] === "\xFF") {
                $at++;
            }
            if ($at >= $length) {
                throw $bad();
            }
            $marker = ord($bytes[$at]);
            $at++;
            if ($marker === 0x00 || $marker === 0xD8 || $marker === 0xD9 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                // No SOI again, no EOI or restart marker before the scan, no stuffed zero here.
                throw $bad();
            }
            if ($at + 2 > $length) {
                throw $bad();
            }
            $size = unpack('n', substr($bytes, $at, 2))[1];
            if ($size < 2 || $at + $size > $length) {
                throw $bad();
            }
            // SOF0-3, 5-7, 9-11, 13-15: precision, height, width, components.
            if (in_array($marker, [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true)) {
                if ($size < 8 || $width !== 0) {
                    throw $bad();
                }
                $f = unpack('Cprecision/nheight/nwidth/Ccomponents', substr($bytes, $at + 2, 6));
                $width = (int) $f['width'];
                $height = (int) $f['height'];
                if ($width < 1 || $height < 1 || !in_array($f['components'], [1, 3], true) || $f['precision'] !== 8) {
                    throw $bad();
                }
            }
            if ($marker === 0xDA) {
                // The first scan: the frame header must have come first.
                if ($width === 0) {
                    throw $bad();
                }
                return [$width, $height];
            }
            $at += $size;
        }
        throw $bad();
    }

    /** The picture decoded by GD and written as a new JPEG (PNG transparency on white). */
    private static function reencode(string $bytes, int $width, int $height): string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg') || !function_exists('imagecreatetruecolor')) {
            throw new RestError('groddle_snap_unavailable', 'Snaps cannot be saved on this site right now.', 503);
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false || imagesx($image) !== $width || imagesy($image) !== $height) {
            throw new RestError('groddle_snap_image', 'That snap could not be read.', 400);
        }
        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            throw new RestError('groddle_snap_image', 'That snap could not be read.', 400);
        }
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        imageinterlace($canvas, false);
        ob_start();
        $ok = imagejpeg($canvas, null, self::QUALITY);
        $out = (string) ob_get_clean();
        if (!$ok || $out === '' || !str_starts_with($out, "\xFF\xD8\xFF")) {
            throw new RestError('groddle_snap_image', 'That snap could not be read.', 400);
        }
        return $out;
    }
}
