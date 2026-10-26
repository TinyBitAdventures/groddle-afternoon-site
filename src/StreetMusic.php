<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;
use Minn\Html;

/**
 * The Street music page's data block (blocks/street-music), drawn in
 * full from public/street-music/data.json, which
 * groddle-afternoon tools/streetmusic/build-data.mjs writes from the game: one group of Tiny
 * Speck's published tracks, each with its title, its CC0 recording and where
 * the remake plays it. The cards are the Musicblocks entry's, so the same
 * player (musicblocks.js) plays one tune at a time.
 */
final class StreetMusic
{
    private static bool $used = false;
    private static ?array $data = null;

    public static function register(Seams $minn, StaticRoot $root): void
    {
        $file = $root->dir . '/street-music/data.json';
        $minn->block('blocks/street-music', static function (array $a) use ($file): string {
            self::$used = true;
            if (self::$data === null) {
                $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
                self::$data = is_array($decoded) ? $decoded : [];
            }
            return self::$data === [] ? '<p class="report-pending">The tracks appear once the game is built (public/street-music/data.json).</p>' : self::group(self::$data, (string) $a['group']);
        });
        $script = $root->dir . '/musicblocks/musicblocks.js';
        $minn->footer(static fn (): string => self::$used ? '<script src="/musicblocks/musicblocks.js?v=' . (is_file($script) ? filemtime($script) : 0) . '" type="module"></script>' . "\n" : '');
    }

    private static function group(array $d, string $id): string
    {
        foreach ($d['groups'] ?? [] as $group) {
            if (($group['id'] ?? '') === $id) {
                $cards = implode('', array_map(self::card(...), $group['tracks'] ?? []));
                return '<div class="mb-set sm-set" data-set="' . Html::attr($id) . '" style="--mb-glow: ' . Html::attr((string) $group['glow']) . '"><ul class="mb-grid">' . $cards . '</ul></div>';
            }
        }
        return '<p class="report-pending">' . Html::esc("There is no track group called {$id}.") . '</p>';
    }

    private static function card(array $t): string
    {
        $places = array_values(array_map('strval', (array) ($t['places'] ?? [])));
        $where = match (count($places)) {
            0 => '',
            1 => $places[0],
            default => implode(', ', array_slice($places, 0, -1)) . ' and ' . end($places),
        };
        return '<li class="mb-card">'
            . '<div class="mb-face"><h3 class="mb-name">' . Html::esc((string) $t['title']) . '</h3></div>'
            . ($where === '' ? '' : '<p class="mb-desc">' . Html::esc('In the remake: ' . $where . '.') . '</p>')
            . '<audio class="mb-audio" controls preload="none" src="' . Html::attr((string) $t['audio']) . '" aria-label="' . Html::attr((string) $t['title']) . '"></audio>'
            . '</li>';
    }
}
