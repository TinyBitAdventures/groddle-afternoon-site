<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\RestError;

/**
 * A plain-text file a player adds to a bug report or a reply from the website
 * (Support, /chat/support/, Austin 2026-11-27: "photo/file uploads"): a .txt or
 * a .log, nothing else, at most MAX_BYTES, at most MAX_PER_MESSAGE a message.
 *
 * The bytes are untrusted. The name decides only whether it may be a text file
 * at all (.txt or .log; anything else, .html included, is refused before the
 * bytes are read); the bytes must then be text: UTF-8 (a byte order mark is
 * dropped), no NUL and no control characters but tab, newline, carriage
 * return, form feed and escape (a log's colours). A picture or a program named
 * .txt is refused. It is kept as text in groddle_ticket_files
 * (TicketStore::FILES), never under uploads, and served only to its player and
 * the administrators as text/plain, an attachment, never sniffed (TicketFiles).
 * The name kept is cut to letters, digits, dot, dash, underscore and space.
 */
final class ReportFile
{
    /** The most bytes a text file may be (256 KiB). */
    public const MAX_BYTES = 262_144;

    /** The most text files one message carries. */
    public const MAX_PER_MESSAGE = 2;

    /** The only endings a text file may have. */
    public const EXTENSIONS = ['txt', 'log'];

    /** The longest name kept. */
    public const NAME_MAX = 80;

    /** Every refusal's code starts with this. */
    public const CODE = 'groddle_report_file';

    /** PURE: whether a file's name ends in one of EXTENSIONS (letter case aside). */
    public static function isTextName(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * The file checked: its name made safe and its words as kept.
     *
     * @return array{name: string, text: string, bytes: int}
     */
    public static function accept(string $name, string $bytes): array
    {
        if (!self::isTextName($name)) {
            throw new RestError(self::CODE . '_type', 'Only pictures (JPEG, PNG or WebP) and text files (.txt or .log) can be added.', 415);
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RestError(self::CODE . '_too_large', 'That text file is larger than 256 KB. Send the part around the problem.', 413);
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        if (trim($bytes) === '') {
            throw new RestError(self::CODE . '_empty', 'That text file is empty.', 400);
        }
        if (!mb_check_encoding($bytes, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0E-\x1A\x1C-\x1F\x7F]/', $bytes) === 1) {
            throw new RestError(self::CODE . '_type', 'That file is not plain text. Add a .txt or .log file of words.', 415);
        }
        return ['name' => self::safeName($name), 'text' => $bytes, 'bytes' => strlen($bytes)];
    }

    /** PURE: a name safe to keep and to send back in a header: its own letters cut to a plain set, its ending kept. */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $ext = in_array($ext, self::EXTENSIONS, true) ? $ext : 'txt';
        $base = (string) pathinfo($name, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '-', $base), " .-");
        $base = (string) preg_replace('/-{2,}/', '-', $base);
        $base = mb_substr($base === '' ? 'file' : $base, 0, self::NAME_MAX - strlen($ext) - 1);
        return rtrim($base, ' .') . '.' . $ext;
    }

    /** PURE: a size as a player reads it: 12 KB, 1 KB for anything under one. */
    public static function size(int $bytes): string
    {
        return max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
