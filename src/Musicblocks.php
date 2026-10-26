<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;
use Minn\Html;

/**
 * The Musicblocks page's data blocks (blocks/musicblocks, blocks/musicblocks-rules),
 * drawn in full from public/musicblocks/data.json, which
 * groddle-afternoon tools/musicblocks/build-data.mjs writes from the game: each block's card
 * with its Tiny Speck description, icon and CC0 recording, the series'
 * collector trophy, and what a play pays. Every card carries a plain <audio>
 * player; musicblocks.js, loaded once when a block is on the page, turns them
 * into play buttons that play one tune at a time, as the game does.
 */
final class Musicblocks
{
    private static bool $used = false;
    private static ?array $data = null;

    public static function register(Seams $minn, StaticRoot $root): void
    {
        $file = $root->dir . '/musicblocks/data.json';
        $data = static function () use ($file): array {
            if (self::$data === null) {
                $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
                self::$data = is_array($decoded) ? $decoded : [];
            }
            return self::$data;
        };
        $pending = '<p class="report-pending">The musicblocks appear once the game is built (public/musicblocks/data.json).</p>';
        $minn->block('blocks/musicblocks', static function (array $a) use ($data, $pending): string {
            self::$used = true;
            $d = $data();
            return $d === [] ? $pending : self::set($d, (string) $a['set']);
        });
        $minn->block('blocks/musicblocks-rules', static function (array $a) use ($data, $pending): string {
            $d = $data();
            return $d === [] ? $pending : self::rules($d['rules'] ?? []);
        });
        $script = $root->dir . '/musicblocks/musicblocks.js';
        $minn->footer(static fn (): string => self::$used ? '<script src="/musicblocks/musicblocks.js?v=' . (is_file($script) ? filemtime($script) : 0) . '" type="module"></script>' . "\n" : '');
    }

    /** One series (or the rare blocks): its trophy, then a card per block. */
    private static function set(array $d, string $id): string
    {
        $set = null;
        foreach ($d['sets'] ?? [] as $candidate) {
            if (($candidate['id'] ?? '') === $id) {
                $set = $candidate;
            }
        }
        if ($set === null) {
            return '<p class="report-pending">' . Html::esc("There is no musicblock set called {$id}.") . '</p>';
        }
        $cards = '';
        foreach ($set['blocks'] ?? [] as $block) {
            $cards .= self::card($block);
        }
        return '<div class="mb-set" data-set="' . Html::attr($id) . '" style="--mb-glow: ' . Html::attr((string) $set['glow']) . '">'
            . self::trophy($set['trophy'] ?? null, count($set['blocks'] ?? []))
            . '<ul class="mb-grid">' . $cards . '</ul></div>';
    }

    private static function card(array $b): string
    {
        $label = (string) $b['label'];
        $short = (string) preg_replace('/^Musicblock\s+/', '', $label);
        $icon = $b['icon'] === null ? '' : '<img class="mb-icon" src="' . Html::attr((string) $b['icon']) . '"'
            . ($b['icon3x'] === null ? '' : ' srcset="' . Html::attr((string) $b['icon3x']) . ' 3x"')
            . ' alt="" width="37" height="37" loading="lazy" />';
        return '<li class="mb-card" id="' . Html::attr((string) $b['id']) . '">'
            . '<div class="mb-face">' . $icon . '<h3 class="mb-name"><span class="mb-sr">Musicblock </span>' . Html::esc($short) . '</h3></div>'
            . '<p class="mb-desc">' . Html::esc((string) $b['desc']) . '</p>'
            . '<audio class="mb-audio" controls preload="none" src="' . Html::attr((string) $b['audio']) . '" aria-label="' . Html::attr($label) . '"></audio>'
            . '<p class="mb-meta">' . Html::esc(number_format((int) $b['cost']) . ' currants · ' . number_format((int) $b['firstPlay']) . ' iMG the first time you hear it') . '</p>'
            . '</li>';
    }

    private static function trophy(?array $t, int $count): string
    {
        if ($t === null) {
            return '';
        }
        $badge = $t['badge'] === null ? '' : '<img class="mb-badge" src="' . Html::attr((string) $t['badge']) . '" alt="" width="60" height="60" loading="lazy" />';
        $prize = sprintf('Collect all %d for %s iMG, %s mood, %s energy and the %s.', $count, number_format((int) $t['imagination']), number_format((int) $t['mood']), number_format((int) $t['energy']), (string) $t['item']);
        return '<div class="mb-trophy">' . $badge . '<p><strong>' . Html::esc((string) $t['name']) . '</strong> ' . Html::esc($prize) . '</p></div>';
    }

    /** What a play pays, every figure from the game's own play function. */
    private static function rules(array $r): string
    {
        $n = static fn (string $key): string => number_format((int) ($r[$key] ?? 0));
        $lines = [
            "Playing a block costs {$n('energy')} energy and lifts your mood by {$n('mood')}.",
            "The first time you hear a tune it pays {$n('fresh')} iMG, or {$n('secret')} for SB-1.",
            "Come back to it after a month away and it pays {$n('returning')} iMG.",
            sprintf('After %s hours it counts again for the day: %s iMG for the first tune of a game day, %s for each new one after that.', $n('replayHours'), $n('dailyFirst'), $n('dailyNew')),
            'Any sooner and it is stale: still a good tune, ' . ((int) ($r['stale'] ?? 0) === 0 ? 'but no iMG.' : "{$n('stale')} iMG."),
            'A low mood earns less iMG, as everything does.',
        ];
        return '<ul class="mb-rules">' . implode('', array_map(static fn (string $line): string => '<li>' . Html::esc($line) . '</li>', $lines)) . '</ul>';
    }
}
