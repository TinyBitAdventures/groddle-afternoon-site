<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;

/**
 * The one-time move of the bug reports from before tickets (private
 * groddle_feedback posts, BugReports until 2026-10-30) into TicketStore: one
 * ticket each, by the post's author, status open, dated as the post; the
 * player's words (the post's paragraphs, before its Details) are the first
 * message; the context comes from the Details lines (BugReports' labels) and
 * the post's meta (groddle_street, groddle_build, groddle_agent,
 * groddle_errors), cleaned as a new report's is (BugReports::context).
 *
 * Safe to run again: each ticket names its post (legacy_post_id, a unique
 * key), so a post already brought in is skipped, and two runs at once make
 * one ticket. The posts stay as they are. Nobody is emailed; the players
 * find their reports in the game's Reports sheet, read (they wrote them),
 * and the administrators find them unread on the console's Reports tab.
 *
 * Run it from the console (Reports, "Bring in old reports": POST
 * groddle/v1/admin/tickets/migrate) or with WP-CLI from the webroot:
 *   wp eval-file wp-content/themes/groddle/bin/migrate-bug-reports.php [dry-run]
 */
final readonly class TicketMigration
{
    /** Post statuses that are reports (a trashed one is not). */
    private const STATUSES = ['private', 'publish', 'draft', 'pending', 'future'];

    /** The Details labels BugReports wrote, back to the context keys. */
    private const LABELS = ['Street' => 'street', 'Street name' => 'streetName', 'x' => 'x', 'y' => 'y', 'Build' => 'build', 'Page' => 'page', 'Browser' => 'agent', 'Screen' => 'screen'];

    public function __construct(private Db $db)
    {
    }

    /** How many report posts have no ticket yet. */
    public function pending(): int
    {
        $posts = $this->db->table('posts');
        $tickets = $this->db->table(TicketStore::TABLE);
        return (int) $this->db->value("SELECT COUNT(*) FROM {$posts} p LEFT JOIN {$tickets} t ON t.legacy_post_id = p.ID WHERE p.post_type = ? AND p.post_status IN (?) AND t.id IS NULL", [BugReports::TYPE, self::STATUSES]);
    }

    /**
     * Brings every report post in that has no ticket yet.
     *
     * @return array{posts: int, made: int, already: int, tickets: list<int>, dryRun: bool}
     */
    public function run(bool $dryRun = false): array
    {
        $posts = $this->db->table('posts');
        $rows = $this->db->rows("SELECT ID, post_author, post_title, post_content, post_date, post_date_gmt FROM {$posts} WHERE post_type = ? AND post_status IN (?) ORDER BY ID", [BugReports::TYPE, self::STATUSES]);
        $done = [];
        if ($rows !== []) {
            foreach ($this->db->rows('SELECT legacy_post_id FROM ' . $this->db->table(TicketStore::TABLE) . ' WHERE legacy_post_id IN (?)', [array_map(static fn (array $r): int => (int) $r['ID'], $rows)]) as $r) {
                $done[(int) $r['legacy_post_id']] = true;
            }
        }
        $store = new TicketStore($this->db);
        $made = [];
        $already = 0;
        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            if (isset($done[$id])) {
                $already++;
                continue;
            }
            [$message, $context] = self::fromPost((string) $row['post_content'], (string) $row['post_title'], $this->meta($id));
            if ($dryRun) {
                $made[] = 0;
                continue;
            }
            $gmt = (string) $row['post_date_gmt'];
            $at = strtotime(($gmt !== '' && !str_starts_with($gmt, '0000') ? $gmt : (string) $row['post_date']) . ' UTC') ?: time();
            $ticket = $store->open((int) $row['post_author'], $message, $context, $at, $id);
            if ($ticket > 0) {
                $made[] = $ticket;
            } else {
                // Brought in by a run at the same time (the unique key kept one).
                $already++;
            }
        }
        return ['posts' => count($rows), 'made' => count($made), 'already' => $already, 'tickets' => $dryRun ? [] : $made, 'dryRun' => $dryRun];
    }

    /**
     * A report post's words and context. The words are its paragraphs before
     * the Details heading (each <br> a line break, paragraphs a blank line
     * apart); when it has none, its text, then its title.
     *
     * @param array<string, string> $meta
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function fromPost(string $content, string $title, array $meta): array
    {
        $text = static fn (string $html): string => trim(html_entity_decode(strip_tags((string) preg_replace('/<br\s*\/?>\s*/i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $cut = preg_split('/<h[1-6][^>]*>\s*Details\s*<\/h[1-6]>/i', $content, 2) ?: [$content];
        $words = $cut[0];
        $paras = [];
        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $words, $m) > 0) {
            foreach ($m[1] as $p) {
                if (($p = $text($p)) !== '') {
                    $paras[] = $p;
                }
            }
        }
        $message = implode("\n\n", $paras);
        if ($message === '') {
            $message = $text((string) preg_replace('/<!--.*?-->/s', '', $words));
        }
        if ($message === '') {
            // The title was "<character>: <first line>".
            $message = (string) preg_replace('/^[^:\n]{1,40}: /u', '', $text($title));
        }
        $message = BugReports::clean($message, BugReports::MAX_MESSAGE, true);

        $raw = ['errors' => []];
        $details = isset($cut[1]) && preg_match('/<pre[^>]*>(.*?)<\/pre>/is', $cut[1], $pm) === 1 ? $text($pm[1]) : '';
        foreach (preg_split('/\n/', $details) ?: [] as $line) {
            if (preg_match('/^Error \d+: (.*)$/', $line, $em) === 1) {
                $raw['errors'][] = $em[1];
            } elseif (preg_match('/^([A-Za-z ]+): (.*)$/', $line, $lm) === 1 && isset(self::LABELS[$lm[1]])) {
                $raw[self::LABELS[$lm[1]]] = $lm[2];
            }
        }
        foreach (['groddle_street' => 'street', 'groddle_build' => 'build', 'groddle_agent' => 'agent'] as $key => $field) {
            if (($raw[$field] ?? '') === '' && ($meta[$key] ?? '') !== '') {
                $raw[$field] = $meta[$key];
            }
        }
        if ($raw['errors'] === [] && ($meta['groddle_errors'] ?? '') !== '') {
            $errors = json_decode($meta['groddle_errors'], true);
            $raw['errors'] = is_array($errors) ? $errors : [];
        }
        return [$message, BugReports::context($raw)];
    }

    /** The report meta a post carries. @return array<string, string> */
    private function meta(int $post): array
    {
        $out = [];
        foreach ($this->db->rows('SELECT meta_key, meta_value FROM ' . $this->db->table('postmeta') . " WHERE post_id = ? AND meta_key IN ('groddle_street', 'groddle_build', 'groddle_agent', 'groddle_errors')", [$post]) as $r) {
            $out[(string) $r['meta_key']] ??= (string) $r['meta_value'];
        }
        return $out;
    }
}
