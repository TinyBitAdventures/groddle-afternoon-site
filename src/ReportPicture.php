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
 * (TicketPictures serves it to those two). A new report is the only way in,
 * so the report's own limits (BugReports PER_WINDOW, PER_DAY) are its rate.
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
