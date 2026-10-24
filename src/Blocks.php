<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;
use Minn\Html;

/**
 * The note blocks (blocks/<name>/block.json): the parts of a note that are
 * more than prose, with their words in attributes so Minn Admin edits them
 * with a form. Each draws the markup the site's stylesheets already style.
 * A parent (features, milestones, ledger, quests, signpost) wraps its
 * children, which arrive rendered.
 */
final class Blocks
{
    public static function register(Seams $minn): void
    {
        $minn->block('blocks/stamp', static fn (array $a): string => "<aside class=\"note-stamp\">\n  <strong>" . Inline::html($a['value']) . "</strong>\n  <span>" . Inline::html($a['caption']) . "</span>\n</aside>");
        $minn->block('blocks/features', static fn (array $a, string $inner): string => '<div class="note-features' . ($a['variant'] === 'fix' ? ' note-features-fix' : '') . "\">\n{$inner}\n</div>");
        $minn->block('blocks/feature', static fn (array $a): string => "<article class=\"note-feature\">\n  <div class=\"note-mark\" aria-hidden=\"true\">" . self::mark((string) $a['icon']) . "</div>\n  <h3>" . Inline::html($a['title']) . "</h3>\n  <p>" . Inline::html($a['text']) . "</p>\n</article>");
        $minn->block('blocks/milestones', static fn (array $a, string $inner): string => '<ol class="note-milestones"' . self::label($a['label']) . ">\n{$inner}\n</ol>");
        $minn->block('blocks/milestone', static fn (array $a): string => "<li class=\"note-milestone\">\n  <p class=\"note-milestone-top\">" . ((string) $a['step'] === '' ? '' : '<span class="note-milestone-step">' . Inline::html($a['step']) . '</span>') . '<span class="note-kind">' . Inline::html($a['kind']) . "</span></p>\n  <h3>" . Inline::html($a['title']) . "</h3>\n  <p>" . Inline::html($a['text']) . "</p>\n</li>");
        $minn->block('blocks/ledger', static fn (array $a, string $inner): string => '<div class="note-ledger' . ($a['variant'] === 'stats' ? ' note-stats' : '') . '"' . ((string) $a['id'] === '' ? '' : ' id="' . Html::attr((string) $a['id']) . '"') . self::label($a['label']) . ">\n{$inner}\n</div>");
        $minn->block('blocks/figure', static fn (array $a): string => '<div><strong>' . Inline::html($a['value']) . ((string) $a['unit'] === '' ? '' : '<small>' . Inline::html($a['unit']) . '</small>') . '</strong><span>' . Inline::html($a['caption']) . '</span></div>');
        $minn->block('blocks/quests', static fn (array $a, string $inner): string => "<ol class=\"note-quests\">\n{$inner}\n</ol>");
        $minn->block('blocks/quest', static fn (array $a): string => "<li class=\"note-quest\">\n  <p class=\"note-quest-top\"><span class=\"note-quest-n\">" . Inline::html($a['n']) . '</span><span class="note-kind">' . Inline::html($a['kind']) . '</span><span class="note-quest-short">' . Inline::html($a['short']) . "</span></p>\n  <h3>" . Inline::html($a['title']) . "</h3>\n  <p>" . Inline::html($a['text']) . "</p>\n  <dl class=\"note-facts\">\n    <dt>Source</dt><dd>" . Inline::html($a['source']) . '</dd>' . "\n    <dt>Play</dt><dd>" . Inline::html($a['play']) . '</dd>' . "\n    <dt>Finish</dt><dd>" . Inline::html($a['finish']) . "</dd>\n  </dl>\n</li>");
        $minn->block('blocks/signpost', static fn (array $a, string $inner): string => '<figure class="note-signs"' . self::label($a['label']) . ">\n  <div class=\"note-signpost\">\n{$inner}\n  </div>\n  <figcaption>" . Inline::html($a['caption']) . "</figcaption>\n</figure>");
        $minn->block('blocks/sign', static fn (array $a): string => '<span class="note-sign-arm ' . ($a['side'] === 'right' ? 'right' : 'left') . '">' . Inline::html($a['text']) . '</span>');
    }

    /** An aria-label, when there is one. */
    private static function label(mixed $label): string
    {
        return (string) $label === '' ? '' : ' aria-label="' . Html::attr(Html::stripAllTags((string) $label)) . '"';
    }

    /** A card icon from marks/, by name; nothing for a name with no file. */
    private static function mark(string $name): string
    {
        $file = dirname(__DIR__) . "/marks/{$name}.svg";
        return preg_match('/^[a-z0-9-]+$/', $name) === 1 && is_file($file) ? trim((string) file_get_contents($file)) : '';
    }
}
