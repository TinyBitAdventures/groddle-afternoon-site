<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\RestError;

/**
 * The picture of a player's home street their game uploads for their
 * profile (Profiles::home): untrusted bytes, as strict as ProfilePng. The
 * type is read from the bytes alone (a PNG's signature, a JPEG's SOI, a
 * WebP's RIFF/WEBP header), never from a data URL. The structure is walked
 * before anything decodes it (ProfilePng::read for a PNG; SnapImage::readJpeg
 * for a JPEG; for a WebP every RIFF chunk, one still image, no animation),
 * and the size read from the headers checked against the limits, so nothing
 * large is ever decoded. GD then decodes it (the size must match the
 * headers) and writes it again, so what is stored is GD's own file: WebP
 * when GD writes WebP (Kinsta's and Cove's GD both do, 2026-10-30), else a
 * JPEG on the game's sky colour; no metadata or extra chunks survive. GD
 * also writes a SMALL_W copy for phones (PlayerPages srcset). Without GD the
 * checked bytes are kept as they came, with no small copy.
 *
 * The game draws the view at 2x, up to 1920 wide (js/profiles.js HOME_*):
 * the profile shows it up to 1096 CSS px wide, so a 960 picture was blurry
 * on a sharp screen (Austin, 2026-10-30). The view's shape follows the
 * window (game.js syncLocationViewHeight: about 1.9:1 at 1280 x 800, 2.3:1
 * at 1920 x 1080), so a 1920 wide picture is 830-1010 tall: MAX_H is 1080
 * (816 would have held a 1280 x 800 window's picture to 1560 wide).
 */
final class HomePicture
{
    public const MIN_W = 240;
    public const MIN_H = 80;
    public const MAX_W = 1920;
    public const MAX_H = 1080;

    /** The most bytes a home picture may arrive as, and be kept as. */
    public const MAX_BYTES = 600 * 1024;

    /** The phone copy's width: a 3x phone's column (358 CSS px x 3 = 1074). */
    public const SMALL_W = 1080;

    /** The quality GD writes WebP and JPEG at; a second try at RETRY_QUALITY if the first is over MAX_BYTES. */
    public const QUALITY = 85;
    public const RETRY_QUALITY = 70;

    /** The types a home picture may be stored and served as. */
    public const TYPES = ['png' => 'image/png', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];

    /** The game's sky (game.js app.init background), under anything clear when GD writes a JPEG. */
    private const BACKDROP = [0x8e, 0xc8, 0xe8];

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * The picture checked and written again: the bytes to keep, their type,
     * size, and the phone copy (null when the picture is no wider than
     * SMALL_W, or without GD).
     *
     * @return array{bytes: string, type: string, width: int, height: int, small: ?string, smallWidth: int, smallHeight: int}
     */
    public static function accept(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            throw new RestError('groddle_profile_image', 'Send the picture as a base64 WebP, JPEG or PNG.', 400);
        }
        // A data URL's own type is not trusted: whatever it says, the bytes decide.
        if (str_starts_with($value, 'data:')) {
            if (preg_match('#^data:image/(?:webp|jpeg|png);base64,#', $value, $m) !== 1) {
                throw new RestError('groddle_profile_image', 'Home pictures are WebP, JPEG or PNG.', 415);
            }
            $value = substr($value, strlen($m[0]));
        }
        if (strlen($value) > intdiv(self::MAX_BYTES * 4, 3) + 8) {
            throw new RestError('groddle_profile_too_large', 'That picture is larger than a profile keeps.', 413, ['max' => self::MAX_BYTES]);
        }
        $bytes = base64_decode($value, true);
        if (!is_string($bytes) || $bytes === '') {
            throw new RestError('groddle_profile_image', 'Send the picture as a base64 WebP, JPEG or PNG.', 400);
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RestError('groddle_profile_too_large', 'That picture is larger than a profile keeps.', 413, ['max' => self::MAX_BYTES]);
        }
        [$format, $width, $height] = self::read($bytes);
        if ($width < self::MIN_W || $height < self::MIN_H || $width > self::MAX_W || $height > self::MAX_H) {
            throw new RestError('groddle_profile_size', "That picture is {$width}x{$height}; a profile home is " . self::MIN_W . 'x' . self::MIN_H . ' to ' . self::MAX_W . 'x' . self::MAX_H . '.', 400);
        }
        if (!self::hasGd()) {
            return ['bytes' => $bytes, 'type' => self::TYPES[$format], 'width' => $width, 'height' => $height, 'small' => null, 'smallWidth' => 0, 'smallHeight' => 0];
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false || imagesx($image) !== $width || imagesy($image) !== $height) {
            throw new RestError('groddle_profile_image', 'That picture could not be read.', 400);
        }
        $out = self::encode($image, self::QUALITY);
        if (strlen($out['bytes']) > self::MAX_BYTES) {
            $out = self::encode($image, self::RETRY_QUALITY);
        }
        if (strlen($out['bytes']) > self::MAX_BYTES) {
            throw new RestError('groddle_profile_too_large', 'That picture is larger than a profile keeps.', 413, ['max' => self::MAX_BYTES]);
        }
        $small = null;
        $smallWidth = $smallHeight = 0;
        if ($width > self::SMALL_W) {
            $smallWidth = self::SMALL_W;
            $smallHeight = max(1, (int) round($height * self::SMALL_W / $width));
            $copy = imagecreatetruecolor($smallWidth, $smallHeight);
            if ($copy !== false) {
                imagealphablending($copy, false);
                imagesavealpha($copy, true);
                imagecopyresampled($copy, $image, 0, 0, 0, 0, $smallWidth, $smallHeight, $width, $height);
                $small = self::encode($copy, self::QUALITY)['bytes'];
            }
            if ($small === null || $small === '') {
                $small = null;
                $smallWidth = $smallHeight = 0;
            }
        }
        return ['bytes' => $out['bytes'], 'type' => $out['type'], 'width' => $width, 'height' => $height, 'small' => $small, 'smallWidth' => $smallWidth, 'smallHeight' => $smallHeight];
    }

    /**
     * The format ('png', 'jpeg' or 'webp') and size of well formed picture
     * bytes, read from the headers; anything else throws.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    public static function read(string $bytes): array
    {
        if (str_starts_with($bytes, self::PNG_SIGNATURE)) {
            [$w, $h] = ProfilePng::read($bytes);
            return ['png', $w, $h];
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            try {
                [$w, $h] = SnapImage::readJpeg($bytes);
            } catch (RestError) {
                throw new RestError('groddle_profile_image', 'That JPEG is not well formed.', 400);
            }
            return ['jpeg', $w, $h];
        }
        if (strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            [$w, $h] = self::readWebp($bytes);
            return ['webp', $w, $h];
        }
        throw new RestError('groddle_profile_image', 'Home pictures are WebP, JPEG or PNG.', 415);
    }

    /**
     * A still WebP's width and height, after walking every chunk: the RIFF
     * size is the file's, each chunk inside it (padded to even), exactly one
     * image (VP8 key frame or VP8L), VP8X only first and never animated,
     * ALPH only with VP8X before a VP8, ICCP/EXIF/XMP allowed (GD drops
     * them), anything else refused. A VP8X canvas must be the image's size.
     *
     * @return array{0: int, 1: int}
     */
    public static function readWebp(string $bytes): array
    {
        $length = strlen($bytes);
        $bad = static fn (string $why = 'That WebP is not well formed.'): RestError => new RestError('groddle_profile_image', $why, 400);
        if ($length < 30 || !str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') {
            throw $bad();
        }
        if (unpack('V', substr($bytes, 4, 4))[1] !== $length - 8) {
            throw $bad();
        }
        $at = 12;
        $width = $height = 0;
        $canvas = null;
        $image = false;
        $alpha = false;
        for ($chunks = 0; $at < $length; $chunks++) {
            if ($chunks >= 32 || $at + 8 > $length) {
                throw $bad();
            }
            $type = substr($bytes, $at, 4);
            $size = unpack('V', substr($bytes, $at + 4, 4))[1];
            $padded = $size + ($size & 1);
            if ($padded > $length - $at - 8) {
                throw $bad();
            }
            $data = substr($bytes, $at + 8, $size);
            switch ($type) {
                case 'VP8X':
                    if ($at !== 12 || $size !== 10) {
                        throw $bad();
                    }
                    $flags = ord($data[0]);
                    if (($flags & 0x02) !== 0) {
                        throw $bad('Home pictures are still pictures.');
                    }
                    $canvas = [
                        1 + (ord($data[4]) | (ord($data[5]) << 8) | (ord($data[6]) << 16)),
                        1 + (ord($data[7]) | (ord($data[8]) << 8) | (ord($data[9]) << 16)),
                    ];
                    break;
                case 'VP8 ':
                    // A key frame (bit 0 of the frame tag clear), the start code, then 14-bit width and height.
                    if ($image || $size < 10 || (ord($data[0]) & 0x01) !== 0 || substr($data, 3, 3) !== "\x9d\x01\x2a") {
                        throw $bad();
                    }
                    $width = unpack('v', substr($data, 6, 2))[1] & 0x3fff;
                    $height = unpack('v', substr($data, 8, 2))[1] & 0x3fff;
                    $image = true;
                    break;
                case 'VP8L':
                    // The signature byte, then 14-bit width-1 and height-1, an alpha bit and a 3-bit version (0).
                    if ($image || $size < 5 || $data[0] !== "\x2f") {
                        throw $bad();
                    }
                    $bits = unpack('V', substr($data, 1, 4))[1];
                    if (($bits >> 29) !== 0) {
                        throw $bad();
                    }
                    $width = ($bits & 0x3fff) + 1;
                    $height = (($bits >> 14) & 0x3fff) + 1;
                    $image = true;
                    break;
                case 'ALPH':
                    if ($canvas === null || $image || $alpha) {
                        throw $bad();
                    }
                    $alpha = true;
                    break;
                case 'ICCP':
                case 'EXIF':
                case 'XMP ':
                    if ($canvas === null) {
                        throw $bad();
                    }
                    break;
                case 'ANIM':
                case 'ANMF':
                    throw $bad('Home pictures are still pictures.');
                default:
                    throw $bad();
            }
            $at += 8 + $padded;
        }
        if (!$image || $at !== $length || $width < 1 || $height < 1) {
            throw $bad();
        }
        if ($canvas !== null && $canvas !== [$width, $height]) {
            throw $bad();
        }
        return [$width, $height];
    }

    /** Whether GD can decode and write pictures here. */
    private static function hasGd(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor') && function_exists('imagejpeg');
    }

    /** Whether GD writes WebP here. */
    public static function gdWritesWebp(): bool
    {
        return function_exists('imagewebp') && function_exists('gd_info') && !empty(gd_info()['WebP Support']);
    }

    /**
     * An image written by GD: WebP when GD writes it (alpha kept), else a
     * JPEG on the game's sky.
     *
     * @return array{bytes: string, type: string}
     */
    private static function encode(\GdImage $image, int $quality): array
    {
        if (self::gdWritesWebp()) {
            imagesavealpha($image, true);
            ob_start();
            $ok = imagewebp($image, null, $quality);
            $out = (string) ob_get_clean();
            if ($ok && str_starts_with($out, 'RIFF') && substr($out, 8, 4) === 'WEBP') {
                return ['bytes' => $out, 'type' => self::TYPES['webp']];
            }
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $flat = imagecreatetruecolor($w, $h);
        if ($flat === false) {
            throw new RestError('groddle_profile_image', 'That picture could not be read.', 400);
        }
        [$r, $g, $b] = self::BACKDROP;
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, $r, $g, $b));
        imagealphablending($flat, true);
        imagecopy($flat, $image, 0, 0, 0, 0, $w, $h);
        imageinterlace($flat, false);
        ob_start();
        $ok = imagejpeg($flat, null, $quality);
        $out = (string) ob_get_clean();
        if (!$ok || !str_starts_with($out, "\xFF\xD8\xFF")) {
            throw new RestError('groddle_profile_image', 'That picture could not be read.', 400);
        }
        return ['bytes' => $out, 'type' => self::TYPES['jpeg']];
    }
}
