<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\RestError;

/**
 * The picture of the game a bug report may carry (Austin 2026-10-30): the
 * game's own canvas as a JPEG, taken when the player opens New report
 * (game js/features/bug-report-feature.js capturePicture: the Pixi stage
 * through the renderer's extract, at most 1280 wide, quality about 0.8). It
 * holds only what the canvas draws: the street, the player, other players'
 * avatars and the name plates and speech bubbles drawn over them. The HUD,
 * the chat dock and every window are page elements, so they are never in it.
 *
 * The bytes are untrusted, as a Snap's are (SnapImage): the type is read from
 * the bytes alone (a JPEG's SOI; anything else is refused, PNG too), the
 * structure walked before anything decodes it (SnapImage::readJpeg), the size
 * checked against the limits, and GD writes a new JPEG, which is all that is
 * kept: no metadata, nothing the client wrote beyond the pixels.
 *
 * Kept in groddle_ticket_pictures (TicketStore::PICTURES, one row a ticket),
 * not under uploads: an upload is served to anyone by the web server, and a
 * report's picture is for the administrators and the player who sent it
 * (TicketPictures serves it to those two). A new report's picture has the
 * report's own limits (BugReports PER_WINDOW, PER_DAY) as its rate; a reply's
 * (G791, TicketStore::MESSAGE_PICTURES) has Tickets PICTURES_PER_WINDOW
 * and PICTURES_PER_DAY, and the same checks here.
 *
 * Several a message (Austin 2026-11-15): a new report or a reply carries up to
 * MAX_PER_MESSAGE pictures ("pictures", a list; "picture" alone is one, as
 * before). The game turns any picture the browser can open (a PNG, a phone
 * photo, a screenshot pasted or dropped in) into a JPEG within these limits
 * before it sends it, so every one arrives as the game's own picture does and
 * meets the same checks here, each written again by GD. A new report's first
 * picture is the report's own (TicketStore::PICTURES); the rest, and every
 * picture with a reply, are the message's (TicketStore::MESSAGE_PICTURES).
 */
final class ReportPicture
{
    /** The most bytes a picture may arrive as, and be kept as (300 KiB). */
    public const MAX_BYTES = 307_200;

    /** Sizes a picture may be: the game downscales to at most 1280 wide. */
    public const MIN_SIDE = 64;
    public const MAX_W = 1280;
    public const MAX_H = 1280;

    /** The JPEG qualities GD tries, best first, until the kept picture fits MAX_BYTES. */
    public const QUALITIES = [80, 70, 60];

    /** Every refusal's code starts with this, so the game can send the report again without it. */
    public const CODE = 'groddle_report_picture';

    /** The most pictures one message carries (a new report counts its own picture among them). */
    public const MAX_PER_MESSAGE = 4;

    /**
     * The pictures a request body carries, each checked and written again (accept), in the
     * order sent: "pictures" (a list of at most MAX_PER_MESSAGE) when it is there, else
     * "picture" (one, the older games' field), else none. Empty entries are skipped.
     *
     * @param array<string, mixed> $body
     * @return list<array{bytes: string, width: int, height: int}>
     */
    public static function fromBody(array $body): array
    {
        $raw = array_key_exists('pictures', $body) && $body['pictures'] !== null ? $body['pictures'] : [$body['picture'] ?? null];
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new RestError(self::CODE, 'Send the pictures as a list of base64 JPEGs.', 400);
        }
        $raw = array_values(array_filter($raw, static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== false));
        if (count($raw) > self::MAX_PER_MESSAGE) {
            throw new RestError(self::CODE . '_count', 'A message carries at most ' . self::MAX_PER_MESSAGE . ' pictures.', 400);
        }
        return array_map(static fn (mixed $v): array => self::accept($v), $raw);
    }

    /**
     * The picture checked and written again as a JPEG.
     *
     * @return array{bytes: string, width: int, height: int}
     */
    public static function accept(mixed $value): array
    {
        $bad = static fn (string $message, int $status = 400, string $code = self::CODE): RestError => new RestError($code, $message, $status);
        if (!is_string($value) || $value === '') {
            throw $bad('Send the picture as a base64 JPEG.');
        }
        // A data URL's own type is not trusted: the bytes decide.
        if (str_starts_with($value, 'data:')) {
            $comma = strpos($value, ',');
            if ($comma === false || $comma > 64 || !str_ends_with(substr($value, 0, $comma), ';base64')) {
                throw $bad('Send the picture as a base64 JPEG.');
            }
            $value = substr($value, $comma + 1);
        }
        if (strlen($value) > intdiv(self::MAX_BYTES * 4, 3) + 8) {
            throw $bad('That picture is larger than a report keeps.', 413, self::CODE . '_too_large');
        }
        $bytes = base64_decode($value, true);
        if (!is_string($bytes) || $bytes === '') {
            throw $bad('Send the picture as a base64 JPEG.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw $bad('That picture is larger than a report keeps.', 413, self::CODE . '_too_large');
        }
        if (!str_starts_with($bytes, "\xFF\xD8\xFF")) {
            throw $bad('A report\'s picture is a JPEG.', 415, self::CODE . '_type');
        }
        try {
            [$width, $height] = SnapImage::readJpeg($bytes);
        } catch (RestError) {
            throw $bad('That picture is not a well formed JPEG.');
        }
        if ($width < self::MIN_SIDE || $height < self::MIN_SIDE || $width > self::MAX_W || $height > self::MAX_H) {
            throw $bad("That picture is {$width}x{$height}; a report's picture is at most " . self::MAX_W . 'x' . self::MAX_H . '.', 400, self::CODE . '_size');
        }
        return ['bytes' => self::reencode($bytes, $width, $height), 'width' => $width, 'height' => $height];
    }

    /**
     * A picture a player uploads on the website (Support, /chat/support/, Austin 2026-11-27): a
     * JPEG, PNG or WebP file as the browser sent it, before anything made it smaller. The game
     * shrinks a picture to these limits before it sends one; a form upload comes whole (a phone's
     * photo is several megabytes), so it may arrive as large as UPLOAD_MAX_BYTES and
     * UPLOAD_MAX_PIXELS, and is made to fit here instead: GD draws it again at most
     * MAX_W x MAX_H (the shape kept, a phone photo turned upright by its EXIF orientation, PNG
     * transparency on white) and writes a new JPEG within MAX_BYTES, as the game's own picture is
     * kept. Nothing of the file but its pixels is kept: no EXIF, no location, no comments.
     *
     * The type is read from the bytes alone (the name and the browser's type are not trusted):
     * JPEG's SOI, PNG's signature or a RIFF WEBP header, and the header's own size parse must agree.
     *
     * @return array{bytes: string, width: int, height: int}
     */
    public static function acceptUpload(string $bytes): array
    {
        if (strlen($bytes) > self::UPLOAD_MAX_BYTES) {
            throw new RestError(self::CODE . '_too_large', 'That picture is larger than 10 MB.', 413);
        }
        $kind = self::uploadKind($bytes);
        if ($kind === null) {
            throw new RestError(self::CODE . '_type', 'Pictures are JPEG, PNG or WebP files.', 415);
        }
        $info = @getimagesizefromstring($bytes);
        $expected = ['jpeg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP][$kind];
        if (!is_array($info) || (int) ($info[2] ?? 0) !== $expected) {
            throw new RestError(self::CODE, 'That picture could not be read.', 400);
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w < self::MIN_SIDE || $h < self::MIN_SIDE) {
            throw new RestError(self::CODE . '_size', "That picture is {$w}x{$h}; a picture is at least " . self::MIN_SIDE . ' pixels on each side.', 400);
        }
        if ($w > self::UPLOAD_MAX_SIDE || $h > self::UPLOAD_MAX_SIDE || $w * $h > self::UPLOAD_MAX_PIXELS) {
            throw new RestError(self::CODE . '_size', "That picture is {$w}x{$h}, too large to read. Send one under 24 megapixels.", 400);
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg') || ($kind === 'webp' && !function_exists('imagecreatefromwebp'))) {
            throw new RestError(self::CODE . '_unavailable', 'This site cannot keep pictures right now.', 503);
        }
        self::roomFor($w * $h);
        $image = @imagecreatefromstring($bytes);
        if ($image === false || imagesx($image) !== $w || imagesy($image) !== $h) {
            throw new RestError(self::CODE, 'That picture could not be read.', 400);
        }
        $turn = $kind === 'jpeg' ? self::orientation($bytes) : 1;
        if ($turn !== 1) {
            $image = self::upright($image, $turn);
            [$w, $h] = [imagesx($image), imagesy($image)];
        }
        foreach (self::UPLOAD_SIDES as $side) {
            [$tw, $th] = self::fit($w, $h, min($side, self::MAX_W), min($side, self::MAX_H));
            $canvas = imagecreatetruecolor($tw, $th);
            if ($canvas === false) {
                break;
            }
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
            imagealphablending($canvas, true);
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $tw, $th, $w, $h);
            imageinterlace($canvas, false);
            foreach (self::QUALITIES as $quality) {
                ob_start();
                $ok = imagejpeg($canvas, null, $quality);
                $out = (string) ob_get_clean();
                if ($ok && str_starts_with($out, "\xFF\xD8\xFF") && strlen($out) <= self::MAX_BYTES) {
                    return ['bytes' => $out, 'width' => $tw, 'height' => $th];
                }
            }
        }
        throw new RestError(self::CODE . '_too_large', 'That picture is larger than a report keeps.', 413);
    }

    /** An upload as large as this many bytes is read (10 MiB). */
    public const UPLOAD_MAX_BYTES = 10_485_760;
    /** Nor a side longer than this. */
    public const UPLOAD_MAX_SIDE = 8192;
    /** Nor more pixels than this: a 24 megapixel phone photo (5712 x 4284) is the largest read. */
    public const UPLOAD_MAX_PIXELS = 24_500_000;
    /** The longest side an upload is drawn at, then smaller ones, until the JPEG fits MAX_BYTES. */
    public const UPLOAD_SIDES = [1280, 1024, 800];

    /** PURE: the kind of picture the bytes start as (jpeg, png, webp), or null. */
    public static function uploadKind(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'png',
            strlen($bytes) >= 16 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => 'webp',
            default => null,
        };
    }

    /** PURE: $w x $h made to fit $maxW x $maxH, the shape kept and never made larger: [w, h]. @return array{0: int, 1: int} */
    public static function fit(int $w, int $h, int $maxW, int $maxH): array
    {
        $s = min(1.0, $maxW / max(1, $w), $maxH / max(1, $h));
        return [max(1, (int) round($w * $s)), max(1, (int) round($h * $s))];
    }

    /**
     * PURE: a JPEG's EXIF orientation (1-8; 1 when it has none or it cannot be read), read from its
     * APP1 Exif segment by hand (the exif extension is not everywhere): the TIFF header's byte
     * order, IFD0's entries, tag 0x0112. Every offset is checked against the segment.
     */
    public static function orientation(string $bytes): int
    {
        $len = strlen($bytes);
        $at = 2;
        for ($n = 0; $n < 64 && $at + 4 <= $len; $n++) {
            if ($bytes[$at] !== "\xFF") {
                return 1;
            }
            $marker = ord($bytes[$at + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                return 1;
            }
            $size = unpack('n', substr($bytes, $at + 2, 2))[1];
            if ($size < 2 || $at + 2 + $size > $len) {
                return 1;
            }
            if ($marker === 0xE1 && substr($bytes, $at + 4, 6) === "Exif\0\0") {
                $tiff = substr($bytes, $at + 10, $size - 8);
                $t = strlen($tiff);
                if ($t < 8) {
                    return 1;
                }
                $le = substr($tiff, 0, 2) === 'II';
                if (!$le && substr($tiff, 0, 2) !== 'MM') {
                    return 1;
                }
                $u16 = static fn (int $o): int => unpack($le ? 'v' : 'n', substr($tiff, $o, 2))[1];
                $u32 = static fn (int $o): int => unpack($le ? 'V' : 'N', substr($tiff, $o, 4))[1];
                $ifd = $u32(4);
                if ($ifd + 2 > $t) {
                    return 1;
                }
                $count = $u16($ifd);
                for ($i = 0; $i < $count && $i < 256; $i++) {
                    $e = $ifd + 2 + $i * 12;
                    if ($e + 12 > $t) {
                        return 1;
                    }
                    if ($u16($e) === 0x0112) {
                        $v = $u16($e + 8);
                        return $v >= 1 && $v <= 8 ? $v : 1;
                    }
                }
                return 1;
            }
            $at += 2 + $size;
        }
        return 1;
    }

    /** The image turned as its EXIF orientation says, so it reads upright. */
    private static function upright(\GdImage $image, int $o): \GdImage
    {
        if (in_array($o, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($o) {
            // Flipped first (2, 4, 5, 7), so 5 turns as 8 does and 7 as 6.
            3, 4 => 180,
            6, 7 => 270,
            5, 8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $turned = imagerotate($image, $angle, 0);
        return $turned === false ? $image : $turned;
    }

    /**
     * Enough memory to draw a picture of $pixels (GD keeps four bytes a pixel, then the copy): the
     * limit raised to 256 MB for this request only when it is lower and the picture needs it.
     */
    private static function roomFor(int $pixels): void
    {
        $limit = (string) ini_get('memory_limit');
        if ($limit === '-1' || $pixels < 4_000_000) {
            return;
        }
        $n = (int) $limit;
        $bytes = match (strtolower(substr($limit, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
        if ($bytes < 256 * 1024 ** 2) {
            @ini_set('memory_limit', '256M');
        }
    }

    /** The picture decoded by GD and written as a new JPEG that fits MAX_BYTES. */
    private static function reencode(string $bytes, int $width, int $height): string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            throw new RestError(self::CODE . '_unavailable', 'This site cannot keep pictures right now.', 503);
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false || imagesx($image) !== $width || imagesy($image) !== $height) {
            throw new RestError(self::CODE, 'That picture could not be read.', 400);
        }
        imageinterlace($image, false);
        foreach (self::QUALITIES as $quality) {
            ob_start();
            $ok = imagejpeg($image, null, $quality);
            $out = (string) ob_get_clean();
            if ($ok && str_starts_with($out, "\xFF\xD8\xFF") && strlen($out) <= self::MAX_BYTES) {
                return $out;
            }
        }
        throw new RestError(self::CODE . '_too_large', 'That picture is larger than a report keeps.', 413);
    }
}
