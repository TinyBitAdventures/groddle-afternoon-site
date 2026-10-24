<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Html;

/**
 * The inline markup a note's words may carry inside a block attribute:
 * emphasis, code, a code-styled span, links and line breaks. Every other tag
 * is dropped (its text stays), and the kept ones keep only their safe
 * attributes. Text passes through as written, entities included.
 */
final class Inline
{
    private const TAGS = ['em', 'strong', 'b', 'i', 'code', 'small', 'span', 'a', 'br'];

    public static function html(mixed $value): string
    {
        $out = '';
        foreach (Html::split((string) $value) as $piece) {
            if ($piece === '' || $piece[0] !== '<') {
                $out .= str_replace(['<', '>'], ['&lt;', '&gt;'], $piece);
                continue;
            }
            if (preg_match('#^<(/?)([a-z]+)\b([^>]*)>$#i', $piece, $m) !== 1 || !in_array(strtolower($m[2]), self::TAGS, true)) {
                continue;
            }
            $tag = strtolower($m[2]);
            if ($m[1] === '/') {
                $out .= $tag === 'br' ? '' : "</{$tag}>";
                continue;
            }
            $out .= '<' . $tag . self::attribute($m[3], $tag === 'a' ? 'href' : ($tag === 'span' ? 'class' : '')) . '>';
        }
        return $out;
    }

    /** The one attribute a kept tag may carry, escaped; a link only to the web or the site. */
    private static function attribute(string $attributes, string $name): string
    {
        if ($name === '' || preg_match('/\b' . $name . '\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $attributes, $m) !== 1) {
            return '';
        }
        $value = html_entity_decode($m[2] !== '' ? $m[2] : ($m[3] ?? ''), ENT_QUOTES | ENT_HTML5);
        if ($name === 'href' && preg_match('#^(https?://|/|\#|\.\./|[a-z0-9-]+/)#i', $value) !== 1) {
            return '';
        }
        return " {$name}=\"" . Html::attr($value) . '"';
    }
}
