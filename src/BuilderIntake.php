<?php

declare(strict_types=1);

namespace Groddle\Minn;

/**
 * The level builder's asset intake (the game's docs/level-builder/spec.md 9.1, 10 and 13; phase
 * P5): the checks new art (a gz_ sprite) passes before it is kept, and the one file that is kept.
 * Pure: no database and no site; AdminBuilderAssets gives it the library's names and the style
 * bands, and BuilderAssets keeps what it returns.
 *
 * The bytes are untrusted, as a Snap's are. The type comes from the bytes alone: a PNG or a WebP,
 * walked chunk by chunk before anything decodes them (lengths, CRCs, the header first, the end
 * last), with the size read from the header and checked before GD allocates anything. GD then
 * decodes the pixels and writes a new lossless WebP, which is all that is kept: every chunk but the
 * image data is gone (a PNG's text chunks, a WebP's EXIF and XMP, colour profiles, anything after
 * the end), so a PHP payload in a text chunk comes out as pixels or not at all. The report names
 * what was stripped. The kept file is only ever served as image/webp (AdminBuilderAssets::art).
 *
 *   format        PNG or WebP only (SVG, GIF, JPEG and anything else refused), well formed, not animated   refuse
 *   size          at most 8 MB as sent, 4096 px a side, at least 8 px                                        refuse
 *   scale         a 3x file must be 1.5 times the 2x file, within 1 px                                       refuse
 *   name          gz_ and lowercase words joined by _, not a library deco; gz_ai_ for AI art               refuse
 *   provenance    licence own-work-CC0 or ai-generated; author; tool and prompt for AI art; inputs are
 *                 library decos (deco:<name>) or Austin's own work (own:<what>)                          refuse
 *   transparency  an alpha channel that is used (a sky may be solid: info there); the art's edge more
 *                 than 30% opaque reads as square edges (not for sky and bg); the colour of fully
 *                 transparent pixels is cleared                                                          warn
 *   registration  transparent margins trimmed (the top fully, the sides evenly, so the bottom-centre
 *                 anchor stays where the artist put it); a visible bottom more than 2 px above the
 *                 file's bottom floats over its anchor                                                   warn
 *   style         lightness, saturation and hues (js/builder/colour-stats.js, ported here) against
 *                 the layer role's palette band in style-profiles.json                                   info
 *
 * GD keeps alpha in 7 bits, so a soft edge's opacity moves by at most 1/255 on the way through.
 */
final class BuilderIntake
{
    /** The most bytes one file may arrive as (8 MiB). */
    public const MAX_BYTES = 8_388_608;
    /** The most a kept file may be after re-encoding (a mediumblob holds 16 MiB). */
    public const MAX_KEPT = 15_728_640;
    public const MAX_SIDE = 4096;
    public const MIN_SIDE = 8;
    public const NAME = '/^gz_[a-z0-9]+(?:_[a-z0-9]+)*$/';
    public const NAME_MAX = 96;
    /** Share of the art's edge that may be opaque before it reads as square. */
    public const EDGE_OPAQUE = 0.30;
    /** How far (px) the visible bottom may sit above the file's bottom. */
    public const FLOAT_PX = 2;
    public const ROLES = ['sky', 'bg', 'middleground', 'middleplus', 'foreground'];
    public const HUE_BINS = 12;
    /** Colour is measured on at most about this many pixels (an even grid). */
    public const STYLE_SAMPLES = 40_000;
    public const INPUT = '/^(deco:[A-Za-z0-9_.-]{1,96}|own:.{1,200})$/su';
    public const INPUTS_MAX = 20;

    /** The publish policy's licences: the same as the game's tools/builder/licence-policy.json (a test holds them together). */
    public const LICENCES = [
        'CC0-1.0' => ['origin' => 'tinyspeck-cc0', 'label' => "CC0 1.0, Tiny Speck's library", 'requires' => ['source']],
        'own-work-CC0' => ['origin' => 'new', 'label' => 'Own work, dedicated CC0 1.0', 'requires' => ['author', 'sha256', 'intake'], 'prefix' => 'gz_', 'notPrefix' => 'gz_ai_'],
        'ai-generated' => ['origin' => 'new', 'label' => 'AI-generated, dedicated CC0 1.0', 'requires' => ['author', 'tool', 'prompt', 'sha256', 'intake'], 'prefix' => 'gz_ai_'],
    ];

    /** PNG chunks that carry the picture; every other one is stripped (and named in the report). */
    private const PNG_KEEP = ['IHDR', 'PLTE', 'tRNS', 'IDAT', 'IEND'];

    /**
     * Check one piece of new art and make the file that is kept.
     *
     * $in: name, licence, author, tool, prompt, inputs (list), role (the layer role it is meant
     * for), file2 and file3 (base64; file3 optional). $ctx: library (name => true), palette
     * (role => {l, s, hue}) from style-profiles.json's default profile.
     *
     * @param array<string, mixed> $in
     * @param array{library?: array<string, bool>, palette?: array<string, mixed>} $ctx
     * @return array{ok: bool, report: array<string, mixed>, file2: ?string, file3: ?string, asset: ?array<string, mixed>}
     */
    public static function intake(array $in, array $ctx = []): array
    {
        $findings = [];
        $say = static function (string $check, string $severity, string $code, string $message, array $data = []) use (&$findings): void {
            $findings[] = ['check' => $check, 'severity' => $severity, 'code' => $code, 'message' => $message] + ($data === [] ? [] : ['data' => $data]);
        };
        $name = is_string($in['name'] ?? null) ? $in['name'] : '';
        $role = is_string($in['role'] ?? null) && in_array($in['role'], self::ROLES, true) ? $in['role'] : null;
        $prov = self::provenance($in, $name, $ctx['library'] ?? [], $say);
        self::checkName($name, (string) ($prov['licence'] ?? ''), $ctx['library'] ?? [], $say);

        $two = self::readFile($in['file2'] ?? null, '2x', $say, true);
        $three = array_key_exists('file3', $in) && $in['file3'] !== null && $in['file3'] !== '' ? self::readFile($in['file3'], '3x', $say, false) : null;
        if ($two !== null && $three !== null) {
            $wantW = $two['w'] * 1.5;
            $wantH = $two['h'] * 1.5;
            if (abs($three['w'] - $wantW) > 1 || abs($three['h'] - $wantH) > 1) {
                $say('scale', 'refuse', 'scale_3x', "The 3x file is {$three['w']} by {$three['h']}; for a {$two['w']} by {$two['h']} 2x file it must be " . self::px($wantW) . ' by ' . self::px($wantH) . ' (within 1 px).', ['want' => [$wantW, $wantH], 'got' => [$three['w'], $three['h']]]);
            }
        }
        $refused = static fn (): bool => array_filter($findings, static fn (array $f): bool => $f['severity'] === 'refuse') !== [];
        $report = ['name' => $name, 'role' => $role];
        if ($two !== null) {
            $report['file2'] = ['type' => $two['type'], 'w' => $two['w'], 'h' => $two['h'], 'bytes' => $two['bytes'], 'stripped' => $two['stripped']];
        }
        if ($three !== null) {
            $report['file3'] = ['type' => $three['type'], 'w' => $three['w'], 'h' => $three['h'], 'bytes' => $three['bytes'], 'stripped' => $three['stripped']];
        }
        if ($two === null || $refused()) {
            return self::done(false, $report, $findings);
        }

        // The pixels: decoded by GD, never by anything that reads the file's own idea of its type.
        $img = self::decode($two, $say);
        $img3 = $img !== null && $three !== null ? self::decode($three, $say) : null;
        if ($img === null || ($three !== null && $img3 === null) || $refused()) {
            return self::done(false, $report, $findings);
        }
        $box = self::visibleBox($img);
        if ($box === null) {
            $say('transparency', 'refuse', 'empty', 'Every pixel is transparent: there is nothing to draw.');
            return self::done(false, $report, $findings);
        }
        [$minX, $minY, $maxX, $maxY] = $box;
        $w = $two['w'];
        $h = $two['h'];
        // Trim: the top fully, the sides evenly (the anchor is bottom-centre), never the bottom.
        $side = min($minX, $w - 1 - $maxX);
        $trim = ['l' => $side, 't' => $minY, 'r' => $side, 'b' => 0];
        $kw = $w - 2 * $side;
        $kh = $h - $minY;
        $gap = $h - 1 - $maxY;
        if ($gap > self::FLOAT_PX) {
            $say('registration', 'warn', 'floats', "The art's visible bottom is {$gap} px above the file's bottom, so it floats over its anchor (the game draws decos from the bottom centre). Crop the empty rows below it unless it is meant to hang.", ['gap' => $gap]);
        }
        $report['trim'] = $trim;
        $report['registration'] = ['x' => $kw / 2, 'y' => $kh];

        if (!$two['alpha']) {
            $say('transparency', $role === 'sky' ? 'info' : 'warn', 'no_alpha', 'The file has no transparency, so it draws as a solid rectangle.' . ($role === 'sky' ? ' That can be right for a sky.' : ' Give it a transparent background.'));
        }
        $kept = self::cleared($img, $side, $minY, $kw, $kh);
        $edge = self::edge($kept, $minX - $side, 0, $maxX - $side, $maxY - $minY);
        $opaqueAll = self::allOpaque($img);
        if ($two['alpha'] && $opaqueAll) {
            $say('transparency', $role === 'sky' ? 'info' : 'warn', 'no_alpha', 'Every pixel is solid, so it draws as a rectangle.' . ($role === 'sky' ? ' That can be right for a sky.' : ' Give it a transparent background.'));
        } elseif ($edge['share'] > self::EDGE_OPAQUE && !in_array($role, ['sky', 'bg'], true) && $two['alpha']) {
            $say('transparency', 'warn', 'square_edges', 'Square edges: ' . (int) round($edge['share'] * 100) . '% of the art\'s outline is solid, so it will show as a box. Soften or cut the edges.', $edge);
        }
        $report['edge'] = $edge;

        $stats = self::colourStats($kept);
        $report['colour'] = $stats;
        self::style($stats, $role, $ctx['palette'] ?? [], $say);

        $file2 = self::webp($kept, $say);
        $file3 = null;
        if ($img3 !== null && $three !== null) {
            $k3w = (int) round($kw * 1.5);
            $k3h = (int) round($kh * 1.5);
            $x3 = max(0, min($three['w'] - $k3w, (int) round($side * 1.5)));
            $y3 = max(0, $three['h'] - $k3h);
            $file3 = self::webp(self::cleared($img3, $x3, $y3, min($k3w, $three['w']), min($k3h, $three['h'])), $say);
        }
        if ($file2 === null || ($img3 !== null && $file3 === null) || $refused()) {
            return self::done(false, $report, $findings);
        }
        $sha = hash('sha256', $file2);
        $report['sha256'] = $sha;
        $report['kept'] = ['w' => $kw, 'h' => $kh, 'bytes' => strlen($file2)] + ($file3 !== null ? ['bytes3' => strlen($file3)] : []);
        $report['id'] = 'in-' . substr($sha, 0, 16);
        $asset = ['origin' => 'new', 'licence' => $prov['licence']];
        $asset['source'] = $prov['author'] . (isset($prov['tool']) ? ' with ' . $prov['tool'] : '');
        $asset['author'] = $prov['author'];
        foreach (['tool', 'prompt'] as $k) {
            if (isset($prov[$k])) {
                $asset[$k] = $prov[$k];
            }
        }
        $asset += ['sha256' => $sha, 'w' => $kw, 'h' => $kh, 'registration' => $report['registration'], 'inputs' => $prov['inputs'], 'intake' => $report['id']];
        $out = self::done(true, $report, $findings);
        $out['file2'] = $file2;
        $out['file3'] = $file3;
        $out['asset'] = $asset;
        return $out;
    }

    /** @return array{ok: bool, report: array<string, mixed>, file2: null, file3: null, asset: null} */
    private static function done(bool $ok, array $report, array $findings): array
    {
        $counts = ['refuse' => 0, 'warn' => 0, 'info' => 0];
        foreach ($findings as $f) {
            $counts[$f['severity']]++;
        }
        return ['ok' => $ok, 'report' => ['ok' => $ok] + $report + ['counts' => $counts, 'findings' => $findings], 'file2' => null, 'file3' => null, 'asset' => null];
    }

    private static function px(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }

    /**
     * Licence, maker and inputs (spec 10). @return array<string, mixed> the clean values
     */
    private static function provenance(array $in, string $name, array $library, callable $say): array
    {
        $licence = $in['licence'] ?? null;
        $out = ['inputs' => []];
        if (!is_string($licence) || !isset(self::LICENCES[$licence]) || self::LICENCES[$licence]['origin'] !== 'new') {
            $say('provenance', 'refuse', 'licence', 'Name the licence: own-work-CC0 (your own work) or ai-generated. Both are CC0 1.0 dedications; nothing else is taken.');
            return $out;
        }
        $out['licence'] = $licence;
        $text = static function (string $k, int $max) use ($in): ?string {
            $v = $in[$k] ?? null;
            if (!is_string($v)) {
                return null;
            }
            $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '');
            return $v === '' ? null : mb_substr($v, 0, $max);
        };
        $author = $text('author', 120);
        if ($author === null) {
            $say('provenance', 'refuse', 'author', 'Name who made it (author).');
        } else {
            $out['author'] = $author;
        }
        foreach (['tool' => 120, 'prompt' => 4000] as $k => $max) {
            $v = $text($k, $max);
            if ($v !== null) {
                $out[$k] = $v;
            } elseif ($licence === 'ai-generated') {
                $say('provenance', 'refuse', $k, $k === 'tool' ? 'AI-generated art names its model and version (tool).' : 'AI-generated art keeps the prompt it was made from (prompt).');
            }
        }
        $inputs = $in['inputs'] ?? [];
        if (!is_array($inputs) || !array_is_list($inputs) || count($inputs) > self::INPUTS_MAX) {
            $say('provenance', 'refuse', 'inputs', 'inputs is a list of at most ' . self::INPUTS_MAX . ': library decos (deco:<name>) or your own work (own:<what>).');
            return $out;
        }
        foreach ($inputs as $i) {
            if (!is_string($i) || preg_match(self::INPUT, $i) !== 1) {
                $say('provenance', 'refuse', 'inputs', 'Inputs must be CC0 or your own: a library deco (deco:<name>) or your own work (own:<what>). ' . (is_string($i) ? '"' . mb_substr($i, 0, 60) . '"' : 'That') . ' is neither.');
                continue;
            }
            if (str_starts_with($i, 'deco:') && $library !== [] && !isset($library[substr($i, 5)])) {
                $say('provenance', 'refuse', 'inputs', 'The input ' . $i . ' is not a library deco.');
                continue;
            }
            $out['inputs'][] = $i;
        }
        return $out;
    }

    private static function checkName(string $name, string $licence, array $library, callable $say): void
    {
        if ($name === '' || strlen($name) > self::NAME_MAX || preg_match(self::NAME, $name) !== 1) {
            $say('name', 'refuse', 'name', 'Name new art gz_ and lowercase words joined by _ (gz_mossy_stump), at most ' . self::NAME_MAX . ' characters.');
            return;
        }
        if (isset($library[$name])) {
            $say('name', 'refuse', 'name_library', "{$name} is a library deco's name.");
        }
        $rule = self::LICENCES[$licence] ?? null;
        if ($rule !== null && $rule['origin'] === 'new') {
            if ($licence === 'ai-generated' && !str_starts_with($name, 'gz_ai_')) {
                $say('name', 'refuse', 'name_ai', 'AI-generated art is named gz_ai_… so it stays easy to list.');
            } elseif ($licence !== 'ai-generated' && str_starts_with($name, 'gz_ai_')) {
                $say('name', 'refuse', 'name_ai', 'gz_ai_ names are for AI-generated art.');
            }
        }
    }

    /**
     * One file's bytes from base64, its type from the bytes, its structure walked and its size read.
     *
     * @return array{type: string, w: int, h: int, alpha: bool, bytes: int, raw: string, stripped: list<string>}|null
     */
    private static function readFile(mixed $value, string $which, callable $say, bool $required): ?array
    {
        if (!is_string($value) || $value === '') {
            if ($required) {
                $say('format', 'refuse', 'missing', "Send the {$which} file as base64.");
            }
            return null;
        }
        if (str_starts_with($value, 'data:')) {
            $comma = strpos($value, ',');
            $value = $comma === false || $comma > 64 ? '' : substr($value, $comma + 1);
        }
        if (strlen($value) > intdiv(self::MAX_BYTES * 4, 3) + 8) {
            $say('size', 'refuse', 'too_large', "The {$which} file is over 8 MB.");
            return null;
        }
        $bytes = base64_decode($value, true);
        if (!is_string($bytes) || $bytes === '') {
            $say('format', 'refuse', 'base64', "The {$which} file is not base64.");
            return null;
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            $say('size', 'refuse', 'too_large', "The {$which} file is over 8 MB.");
            return null;
        }
        $head = ltrim(substr($bytes, 0, 1024), "\xEF\xBB\xBF \t\r\n");
        try {
            if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
                $info = self::walkPng($bytes);
            } elseif (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
                $info = self::walkWebp($bytes);
            } else {
                $what = match (true) {
                    str_starts_with($head, '<') || stripos($head, '<svg') !== false => 'an SVG (or other markup)',
                    str_starts_with($bytes, 'GIF8') => 'a GIF',
                    str_starts_with($bytes, "\xFF\xD8\xFF") => 'a JPEG',
                    default => 'not a picture this takes',
                };
                $say('format', 'refuse', 'type', "The {$which} file is {$what}. New art is PNG or WebP only.");
                return null;
            }
        } catch (\UnexpectedValueException $e) {
            $say('format', 'refuse', $e->getCode() === 2 ? 'animated' : 'malformed', "The {$which} file: " . $e->getMessage());
            return null;
        }
        if ($info['w'] > self::MAX_SIDE || $info['h'] > self::MAX_SIDE) {
            $say('size', 'refuse', 'too_big', "The {$which} file is {$info['w']} by {$info['h']} px; at most " . self::MAX_SIDE . ' px a side.', ['w' => $info['w'], 'h' => $info['h']]);
            return null;
        }
        if ($info['w'] < self::MIN_SIDE || $info['h'] < self::MIN_SIDE) {
            $say('size', 'refuse', 'too_small', "The {$which} file is {$info['w']} by {$info['h']} px; at least " . self::MIN_SIDE . ' px a side.', ['w' => $info['w'], 'h' => $info['h']]);
            return null;
        }
        return $info + ['bytes' => strlen($bytes), 'raw' => $bytes];
    }

    /** @return array{type: string, w: int, h: int, alpha: bool, stripped: list<string>} */
    public static function walkPng(string $b): array
    {
        $len = strlen($b);
        $bad = static fn (string $why): \UnexpectedValueException => new \UnexpectedValueException($why, 1);
        $at = 8;
        $w = $h = 0;
        $alpha = false;
        $colour = -1;
        $seenIdat = false;
        $idatDone = false;
        $stripped = [];
        for ($n = 0; ; $n++) {
            if ($n > 100_000 || $at + 12 > $len) {
                throw $bad('the PNG ends before its IEND chunk.');
            }
            $size = unpack('N', substr($b, $at, 4))[1];
            $type = substr($b, $at + 4, 4);
            if ($size > 0x7FFFFFFF || $at + 12 + $size > $len || preg_match('/^[A-Za-z]{4}$/', $type) !== 1) {
                throw $bad('a PNG chunk runs past the end of the file.');
            }
            $data = substr($b, $at + 8, $size);
            if (unpack('N', substr($b, $at + 8 + $size, 4))[1] !== crc32($type . $data)) {
                throw $bad("the PNG's {$type} chunk fails its checksum.");
            }
            $at += 12 + $size;
            if ($n === 0) {
                if ($type !== 'IHDR' || $size !== 13) {
                    throw $bad('a PNG starts with its IHDR chunk.');
                }
                $f = unpack('Nw/Nh/Cdepth/Ccolour/Ccompression/Cfilter/Cinterlace', $data);
                $w = (int) $f['w'];
                $h = (int) $f['h'];
                $colour = (int) $f['colour'];
                $depths = [0 => [1, 2, 4, 8, 16], 2 => [8, 16], 3 => [1, 2, 4, 8], 4 => [8, 16], 6 => [8, 16]];
                if (!isset($depths[$colour]) || !in_array((int) $f['depth'], $depths[$colour], true) || $f['compression'] !== 0 || $f['filter'] !== 0 || $f['interlace'] > 1 || $w < 1 || $h < 1) {
                    throw $bad('the PNG header is not one this takes.');
                }
                $alpha = $colour === 4 || $colour === 6;
                continue;
            }
            if ($type === 'acTL' || $type === 'fcTL' || $type === 'fdAT') {
                throw new \UnexpectedValueException('it is an animated PNG; animated art is not taken yet.', 2);
            }
            if ($type === 'IDAT') {
                if ($idatDone) {
                    throw $bad("the PNG's image data is split by other chunks.");
                }
                $seenIdat = true;
            } elseif ($seenIdat) {
                $idatDone = true;
            }
            if ($type === 'tRNS') {
                $alpha = true;
            }
            if ($type === 'IEND') {
                if (!$seenIdat) {
                    throw $bad('the PNG has no image data.');
                }
                if ($at < $len) {
                    $stripped[] = 'bytes after IEND (' . ($len - $at) . ')';
                }
                break;
            }
            if (!in_array($type, self::PNG_KEEP, true)) {
                $stripped[] = "{$type} ({$size})";
            }
        }
        return ['type' => 'png', 'w' => $w, 'h' => $h, 'alpha' => $alpha, 'stripped' => $stripped];
    }

    /** @return array{type: string, w: int, h: int, alpha: bool, stripped: list<string>} */
    public static function walkWebp(string $b): array
    {
        $len = strlen($b);
        $bad = static fn (string $why): \UnexpectedValueException => new \UnexpectedValueException($why, 1);
        $riff = unpack('V', substr($b, 4, 4))[1];
        if ($riff + 8 !== $len || $riff < 12) {
            throw $bad('the WebP file is not the size its header says.');
        }
        $at = 12;
        $w = $h = 0;
        $alpha = false;
        $image = false;
        $stripped = [];
        for ($n = 0; $at < $len; $n++) {
            if ($n > 10_000 || $at + 8 > $len) {
                throw $bad('a WebP chunk runs past the end of the file.');
            }
            $type = substr($b, $at, 4);
            $size = unpack('V', substr($b, $at + 4, 4))[1];
            if ($at + 8 + $size > $len) {
                throw $bad('a WebP chunk runs past the end of the file.');
            }
            $data = substr($b, $at + 8, $size);
            $at += 8 + $size + ($size & 1);
            if ($n === 0 && !in_array($type, ['VP8 ', 'VP8L', 'VP8X'], true)) {
                throw $bad('a WebP starts with its VP8, VP8L or VP8X chunk.');
            }
            switch ($type) {
                case 'VP8X':
                    if ($n !== 0 || $size < 10) {
                        throw $bad('the WebP VP8X chunk is out of place.');
                    }
                    $flags = ord($data[0]);
                    if (($flags & 0x02) !== 0) {
                        throw new \UnexpectedValueException('it is an animated WebP; animated art is not taken yet.', 2);
                    }
                    $alpha = ($flags & 0x10) !== 0;
                    $w = 1 + (ord($data[4]) | (ord($data[5]) << 8) | (ord($data[6]) << 16));
                    $h = 1 + (ord($data[7]) | (ord($data[8]) << 8) | (ord($data[9]) << 16));
                    break;
                case 'ANIM':
                case 'ANMF':
                    throw new \UnexpectedValueException('it is an animated WebP; animated art is not taken yet.', 2);
                case 'VP8L':
                    if ($image || $size < 5 || ord($data[0]) !== 0x2F) {
                        throw $bad('the WebP lossless header is not well formed.');
                    }
                    $bits = unpack('V', substr($data, 1, 4))[1];
                    $lw = ($bits & 0x3FFF) + 1;
                    $lh = (($bits >> 14) & 0x3FFF) + 1;
                    if ($w === 0) {
                        [$w, $h] = [$lw, $lh];
                        $alpha = (($bits >> 28) & 1) === 1;
                    }
                    $image = true;
                    break;
                case 'VP8 ':
                    if ($image || $size < 10 || substr($data, 3, 3) !== "\x9D\x01\x2A") {
                        throw $bad('the WebP lossy header is not well formed.');
                    }
                    if ($w === 0) {
                        $w = unpack('v', substr($data, 6, 2))[1] & 0x3FFF;
                        $h = unpack('v', substr($data, 8, 2))[1] & 0x3FFF;
                    }
                    $image = true;
                    break;
                case 'ALPH':
                    break;
                default:
                    $stripped[] = trim($type) . " ({$size})";
            }
        }
        if (!$image || $w < 1 || $h < 1) {
            throw $bad('the WebP has no image data.');
        }
        return ['type' => 'webp', 'w' => $w, 'h' => $h, 'alpha' => $alpha, 'stripped' => $stripped];
    }

    /** GD's reading of the pixels, as a true colour image with alpha, or null (refused). */
    private static function decode(array $file, callable $say): ?\GdImage
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            $say('format', 'refuse', 'unavailable', 'This site cannot read pictures right now (GD with WebP is missing).');
            return null;
        }
        $img = @imagecreatefromstring($file['raw']);
        if (!$img instanceof \GdImage || imagesx($img) !== $file['w'] || imagesy($img) !== $file['h']) {
            $say('format', 'refuse', 'decode', 'The ' . strtoupper($file['type']) . ' file could not be read as a picture.');
            return null;
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);
        return $img;
    }

    /** [minX, minY, maxX, maxY] of the pixels that are not fully transparent, or null when none are. */
    private static function visibleBox(\GdImage $img): ?array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $seen = static function (int $x, int $y) use ($img): bool {
            return ((imagecolorat($img, $x, $y) >> 24) & 0x7F) < 127;
        };
        $minY = -1;
        for ($y = 0; $y < $h && $minY < 0; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($seen($x, $y)) {
                    $minY = $y;
                    break;
                }
            }
        }
        if ($minY < 0) {
            return null;
        }
        $maxY = $minY;
        for ($y = $h - 1; $y > $minY; $y--) {
            for ($x = 0; $x < $w; $x++) {
                if ($seen($x, $y)) {
                    $maxY = $y;
                    break 2;
                }
            }
        }
        $minX = $w - 1;
        $maxX = 0;
        for ($y = $minY; $y <= $maxY; $y++) {
            for ($x = 0; $x < $minX; $x++) {
                if ($seen($x, $y)) {
                    $minX = $x;
                    break;
                }
            }
            for ($x = $w - 1; $x > $maxX; $x--) {
                if ($seen($x, $y)) {
                    $maxX = $x;
                    break;
                }
            }
        }
        return [$minX, $minY, max($minX, $maxX), $maxY];
    }

    /**
     * A copy of part of $img in which every fully transparent pixel is transparent black: GD's
     * blend leaves the canvas's own pixel wherever the source is fully transparent, so colour
     * hidden under transparency (and anything written into it) does not survive.
     */
    private static function cleared(\GdImage $img, int $x, int $y, int $w, int $h): \GdImage
    {
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagefilledrectangle($out, 0, 0, $w - 1, $h - 1, 0x7F000000);
        imagealphablending($out, true);
        imagecopy($out, $img, 0, 0, $x, $y, $w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        return $out;
    }

    /** The share of the visible box's outline that is solid (opacity over half), per side and in all. */
    private static function edge(\GdImage $img, int $x0, int $y0, int $x1, int $y1): array
    {
        $solid = static fn (int $x, int $y): int => ((imagecolorat($img, $x, $y) >> 24) & 0x7F) < 64 ? 1 : 0;
        $sides = ['top' => [0, 0], 'bottom' => [0, 0], 'left' => [0, 0], 'right' => [0, 0]];
        for ($x = $x0; $x <= $x1; $x++) {
            $sides['top'][0] += $solid($x, $y0);
            $sides['top'][1]++;
            $sides['bottom'][0] += $solid($x, $y1);
            $sides['bottom'][1]++;
        }
        for ($y = $y0; $y <= $y1; $y++) {
            $sides['left'][0] += $solid($x0, $y);
            $sides['left'][1]++;
            $sides['right'][0] += $solid($x1, $y);
            $sides['right'][1]++;
        }
        $out = [];
        $s = $t = 0;
        foreach ($sides as $k => [$a, $b]) {
            $out[$k] = $b ? round($a / $b, 3) : 0.0;
            $s += $a;
            $t += $b;
        }
        $out['share'] = $t ? round($s / $t, 3) : 0.0;
        return $out;
    }

    private static function allOpaque(\GdImage $img): bool
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $step = max(1, (int) ceil(sqrt($w * $h / self::STYLE_SAMPLES)));
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) !== 0) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * The colour of a picture: js/builder/colour-stats.js colourStats, ported (opacity-weighted
     * mean lightness and saturation, a 12-bin hue histogram weighted by opacity times saturation,
     * and the mean opacity). $step 0 picks an even grid of about STYLE_SAMPLES pixels.
     *
     * @return array{l: float, s: float, hue: list<float>, cover: float}
     */
    public static function colourStats(\GdImage $img, int $step = 0): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $step = $step > 0 ? $step : max(1, (int) ceil(sqrt($w * $h / self::STYLE_SAMPLES)));
        $wSum = $lSum = $sSum = 0.0;
        $n = 0;
        $hue = array_fill(0, self::HUE_BINS, 0.0);
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                $c = imagecolorat($img, $x, $y);
                $n++;
                $a = (127 - (($c >> 24) & 0x7F)) / 127;
                if ($a <= 0) {
                    continue;
                }
                [$hh, $ss, $ll] = self::hsl(($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF);
                $wSum += $a;
                $lSum += $a * $ll;
                $sSum += $a * $ss;
                if ($ss > 0) {
                    $hue[((int) floor($hh / 30)) % self::HUE_BINS] += $a * $ss;
                }
            }
        }
        $hueSum = array_sum($hue);
        return [
            'l' => $wSum > 0 ? round($lSum / $wSum, 3) : 0.0,
            's' => $wSum > 0 ? round($sSum / $wSum, 3) : 0.0,
            'hue' => array_map(static fn (float $v): float => $hueSum > 0 ? round($v / $hueSum, 3) : 0.0, $hue),
            'cover' => $n > 0 ? round($wSum / $n, 3) : 0.0,
        ];
    }

    /** HSL of 0-255 RGB as [h degrees, s, l] (colour-stats.js hsl). @return array{0: float, 1: float, 2: float} */
    public static function hsl(int $r, int $g, int $b): array
    {
        $R = $r / 255;
        $G = $g / 255;
        $B = $b / 255;
        $max = max($R, $G, $B);
        $min = min($R, $G, $B);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        if ($d == 0) {
            return [0.0, 0.0, $l];
        }
        $s = $d / (1 - abs(2 * $l - 1));
        if ($max == $R) {
            $h = fmod(($G - $B) / $d, 6);
        } elseif ($max == $G) {
            $h = ($B - $R) / $d + 2;
        } else {
            $h = ($R - $G) / $d + 4;
        }
        $h *= 60;
        if ($h < 0) {
            $h += 360;
        }
        return [$h, min(1.0, $s), $l];
    }

    /** Lightness, saturation and hues against the role's palette band (info only, spec 9.2). */
    private static function style(array $stats, ?string $role, array $palette, callable $say): void
    {
        $use = $role ?? 'middleground';
        $band = $palette[$use] ?? null;
        if (!is_array($band) || !isset($band['l'], $band['s'], $band['hue'])) {
            return;
        }
        $for = $role === null ? 'middleground art (no role was named)' : "{$use} art";
        foreach (['l' => 'lightness', 's' => 'saturation'] as $k => $word) {
            [$lo, , $hi] = $band[$k];
            if ($stats[$k] < $lo || $stats[$k] > $hi) {
                $say('style', 'info', "style_{$k}", "Mean {$word} " . $stats[$k] . " is outside the usual {$lo} to {$hi} for {$for}.", ['value' => $stats[$k], 'band' => $band[$k], 'role' => $use]);
            }
        }
        $overlap = 0.0;
        for ($i = 0; $i < self::HUE_BINS; $i++) {
            $overlap += min((float) ($stats['hue'][$i] ?? 0), (float) ($band['hue'][$i] ?? 0));
        }
        if (array_sum($stats['hue']) > 0 && $overlap < 0.25) {
            $say('style', 'info', 'style_hue', 'Its hues share ' . (int) round($overlap * 100) . "% with Tiny Speck's {$for}.", ['overlap' => round($overlap, 3), 'role' => $use]);
        }
    }

    /** A lossless WebP of the image, or null (refused). */
    private static function webp(\GdImage $img, callable $say): ?string
    {
        ob_start();
        $ok = imagewebp($img, null, defined('IMG_WEBP_LOSSLESS') ? IMG_WEBP_LOSSLESS : 101);
        $out = (string) ob_get_clean();
        if (!$ok || strlen($out) < 20 || substr($out, 0, 4) !== 'RIFF' || substr($out, 8, 4) !== 'WEBP') {
            $say('format', 'refuse', 'encode', 'The picture could not be written again as a WebP.');
            return null;
        }
        if (strlen($out) > self::MAX_KEPT) {
            $say('size', 'refuse', 'too_large', 'Written again as a lossless WebP it is over 15 MB. Send it smaller.');
            return null;
        }
        return $out;
    }
}
