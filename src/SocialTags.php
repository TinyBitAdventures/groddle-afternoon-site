<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Html;

/**
 * Link previews: each page's own title and description as its Open Graph and Twitter card, with the
 * share card the game ships at /brand/share.jpg (groddle-afternoon tools/brand/share/). The static game
 * pages get the same tags at deploy (groddle-afternoon tools/deploy/social.mjs).
 */
final class SocialTags
{
    public const IMAGE = '/brand/share.jpg';
    public const IMAGE_WIDTH = 1200;
    public const IMAGE_HEIGHT = 630;
    public const IMAGE_ALT = 'A Glitchen in Kristel Kaos, under red mushrooms, beside the words Groddle Afternoon';

    /** The document with its tags before </head>; unchanged when it has no head or title, or tags already. */
    public static function insert(string $html, string $home, string $path): string
    {
        $at = stripos($html, '</head>');
        if ($at === false || str_contains($html, 'property="og:image"')) {
            return $html;
        }
        if (preg_match('/<title>([^<]*)<\/title>/i', $html, $title) !== 1) {
            return $html;
        }
        $description = preg_match('/<meta name="description" content="([^"]*)"/i', $html, $m) === 1 ? self::text($m[1]) : '';
        $origin = rtrim($home, '/');
        $url = preg_match('/<link rel="canonical" href="([^"]*)"/i', $html, $c) === 1 ? self::text($c[1]) : $origin . $path;
        $tags = [
            ['property', 'og:type', 'website'],
            ['property', 'og:site_name', 'Groddle Afternoon'],
            ['property', 'og:title', self::text(trim($title[1]))],
            ...($description !== '' ? [['property', 'og:description', $description]] : []),
            ['property', 'og:url', $url],
            ['property', 'og:image', $origin . self::IMAGE],
            ['property', 'og:image:width', (string) self::IMAGE_WIDTH],
            ['property', 'og:image:height', (string) self::IMAGE_HEIGHT],
            ['property', 'og:image:alt', self::IMAGE_ALT],
            ['name', 'twitter:card', 'summary_large_image'],
            ['name', 'twitter:image', $origin . self::IMAGE],
        ];
        $out = '';
        foreach ($tags as [$kind, $name, $value]) {
            $out .= "<meta {$kind}=\"{$name}\" content=\"" . Html::attr($value) . "\" />\n";
        }
        return substr($html, 0, $at) . $out . substr($html, $at);
    }

    /** Markup text as plain text (the title's &#8211;, a description's &#039;). */
    private static function text(string $markup): string
    {
        return html_entity_decode($markup, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
