<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;
use Minn\Html;

/**
 * The Groddle Wiki's own block: the topic strip (blocks/wiki-topics) on the
 * wiki's index and topic pages, every topic with entries as a link with its
 * count, the one being read marked. The wiki itself is the theme's declared
 * post type (wiki_entry at /wiki/<entry>/, its archive at /wiki/) and
 * taxonomy (wiki_topic at /wiki/topic/<topic>/), in minn.json; the
 * templates are archive-wiki_entry, taxonomy-wiki_topic and single-wiki_entry.
 */
final class Wiki
{
    public const BASE = '/wiki/';
    private const TOPIC_BASE = '/wiki/topic/';

    public static function register(Seams $minn): void
    {
        $minn->block('blocks/wiki-topics', static fn (array $a): string => self::topics($minn, (string) $a['allLabel']));
    }

    /** The strip: "All" for the index, then each topic with entries, by name, its path under its parents'. */
    private static function topics(Seams $minn, string $allLabel): string
    {
        $db = $minn->db;
        $rows = $db->rows(
            'SELECT t.term_id, t.name, t.slug, tt.parent, tt.count FROM ' . $db->table('terms') . ' t JOIN ' . $db->table('term_taxonomy') . " tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'wiki_topic' ORDER BY t.name",
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['term_id']] = $row;
        }
        $path = $minn->request->path;
        $link = static function (string $href, string $label, string $count = '') use ($path): string {
            $current = $path === $href ? ' aria-current="page"' : '';
            return '<a href="' . Html::attr($href) . '"' . $current . '>' . Html::esc($label) . ($count === '' ? '' : ' <span class="wiki-count">' . Html::esc($count) . '</span>') . '</a>';
        };
        $links = [$link(self::BASE, $allLabel)];
        foreach ($byId as $id => $row) {
            if ((int) $row['count'] > 0) {
                $links[] = $link(self::TOPIC_BASE . self::slugPath($byId, $id) . '/', (string) $row['name'], (string) (int) $row['count']);
            }
        }
        return '<nav class="wiki-topics jump" aria-label="Wiki topics">' . implode(' ', $links) . '</nav>';
    }

    /**
     * A topic's slug under its parents', as a hierarchical term's address spells it.
     *
     * @param array<int, array<string, mixed>> $byId
     */
    private static function slugPath(array $byId, int $id): string
    {
        $parts = [];
        $seen = [];
        while (isset($byId[$id]) && !isset($seen[$id])) {
            $seen[$id] = true;
            array_unshift($parts, (string) $byId[$id]['slug']);
            $id = (int) $byId[$id]['parent'];
        }
        return implode('/', $parts);
    }
}
