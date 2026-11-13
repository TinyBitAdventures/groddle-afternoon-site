<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;

/**
 * The Made for Groddle section of the restoration report (blocks/restoration-made):
 * the art made for the remake rather than restored from Tiny Speck, drawn in
 * full from public/restoration/made.json, which groddle-afternoon
 * tools/made-art/build-data.mjs writes from the game's provenance list and its
 * curated words: nothing here is typed in.
 *
 * Each piece is a card: the Tiny Speck original beside the made art at the
 * same display size (the original's pixels kept sharp, image-rendering:
 * pixelated, so the difference is honest), or, where there is no original,
 * what it was made from; then its caption and facts. A sprite sheet shows one
 * frame, and a one-row walk steps through its frames in CSS where motion is
 * welcome. No JavaScript: the page reads the same without it.
 *
 * Plain PHP with no engine classes in draw(), so tests run it with php -r.
 */
final class RestorationMade
{
    public static function register(Seams $minn, StaticRoot $root): void
    {
        $file = $root->dir . '/restoration/made.json';
        $minn->block('blocks/restoration-made', static function () use ($file, $root): string {
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            return is_array($decoded)
                ? DataKeys::html(self::draw($decoded), DataKeys::map($root->dir))
                : '<p class="report-pending">The art made for Groddle appears once the game is built (public/restoration/made.json).</p>';
        });
    }

    /** Every piece in made.json (register() adds the game's content keys to the images, DataKeys). */
    public static function draw(array $d): string
    {
        $out = '';
        foreach ($d['pieces'] ?? [] as $piece) {
            if (is_array($piece)) {
                $out .= self::piece($piece);
            }
        }
        return '<div class="made-list">' . $out . '</div>';
    }

    private static function esc(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** An image address under the built game's assets, or null (nothing else reaches src). */
    private static function src(mixed $src): ?string
    {
        $src = (string) $src;
        return preg_match('#^/assets/[A-Za-z0-9_@./-]+\.(?:webp|png|jpe?g)$#', $src) === 1 && !str_contains($src, '..') ? $src : null;
    }

    private static function piece(array $p): string
    {
        $anchor = preg_replace('/[^a-z0-9-]/', '', (string) ($p['anchor'] ?? '')) ?: 'made-piece';
        $height = max(1, (int) ($p['display']['h'] ?? 150));
        $figures = [];
        if (is_array($p['original'] ?? null)) {
            $figures[] = self::figure($p['original'], $height, 'made-fig-' . (($p['original']['kind'] ?? '') === 'guide' ? 'guide' : 'original'));
        }
        if (is_array($p['image'] ?? null)) {
            $figures[] = self::figure($p['image'], $height, 'made-fig-made');
        }
        $figures = array_filter($figures);
        $pairWidth = array_sum(array_map(static fn (array $f): int => $f[1], $figures));
        $facts = '';
        foreach ($p['facts'] ?? [] as $fact) {
            $facts .= '<div><dt>' . self::esc($fact['label'] ?? '') . '</dt><dd>' . self::esc($fact['text'] ?? '') . '</dd></div>';
        }
        return '<article class="made-piece" id="' . $anchor . '" aria-labelledby="' . $anchor . '-h" style="--pair:' . $pairWidth . '">'
            . '<div class="made-pair">'
            . implode('', array_map(static fn (array $f): string => $f[0], $figures)) . '</div>'
            . '<div class="made-words">'
            . '<h3 id="' . $anchor . '-h">' . self::esc($p['name'] ?? '') . '</h3>'
            . (empty($p['madeLabel']) ? '' : '<p class="made-when">Made ' . self::esc($p['madeLabel']) . '</p>')
            . '<p class="made-caption">' . self::esc($p['caption'] ?? '') . '</p>'
            . ($facts === '' ? '' : '<dl class="made-facts">' . $facts . '</dl>')
            . '</div></article>';
    }

    /**
     * One picture at the piece's display height: [html, display width], or null
     * when its address is not one of the game's images. A sheet shows its first
     * frame; a one-row sheet with a frame rate also walks (style.css).
     *
     * @return array{0: string, 1: int}|null
     */
    private static function figure(array $img, int $height, string $class): ?array
    {
        $src = self::src($img['src'] ?? '');
        $w = (int) ($img['w'] ?? 0);
        $h = (int) ($img['h'] ?? 0);
        if ($src === null || $w < 1 || $h < 1) {
            return null;
        }
        $width = max(1, (int) round($height * $w / $h));
        $sheet = is_array($img['sheet'] ?? null) ? $img['sheet'] : null;
        $cols = max(1, (int) ($sheet['cols'] ?? 1));
        $rows = max(1, (int) ($sheet['rows'] ?? 1));
        $frames = max(1, (int) ($sheet['frames'] ?? 1));
        $fps = (int) ($sheet['fps'] ?? 0);
        $walks = $sheet !== null && $rows === 1 && $frames === $cols && $frames > 1 && $fps > 0;
        $style = '--w:' . $width . ';--h:' . $height . ($sheet === null ? '' : ';--cols:' . $cols . ';--rows:' . $rows . ($walks ? ';--frames:' . $frames . ';--fps:' . $fps : ''));
        $frame = '<span class="made-frame' . ($sheet === null ? '' : ' made-sheet') . ($walks ? ' made-walk' : '') . '">'
            . '<img' . (empty($img['pixelated']) ? '' : ' class="made-px"') . ' src="' . self::esc($src) . '" alt="' . self::esc($img['alt'] ?? '') . '"'
            . ' width="' . ($w * $cols) . '" height="' . ($h * $rows) . '" loading="lazy" decoding="async" /></span>';
        return [
            '<figure class="made-fig ' . $class . '" style="' . $style . '">' . $frame . '<figcaption>' . self::esc($img['label'] ?? '') . '</figcaption></figure>',
            $width,
        ];
    }
}
