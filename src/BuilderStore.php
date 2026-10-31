<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;
use Minn\RestError;

/**
 * The level builder's drafts (the game's docs/level-builder/spec.md section 5): one row per draft
 * in groddle_builder_drafts holding the whole zone at its current rev, and every rev kept in full
 * (gzip) in groddle_builder_revisions, thinned past REVISIONS_KEPT to one an hour. A write names
 * the rev it was made against (If-Match): a stale one is refused with 409 builder_rev_conflict and
 * the current rev, so two editors (or an editor and an agent) never overwrite each other. Restore
 * copies an old rev forward as a new one, so nothing is lost; a delete only archives.
 *
 * Each write is one line in groddle_admin_actions (kind builder, player 0). An open editor saves
 * every few seconds, so a run of writes to one draft by one administrator within LOG_MERGE_S
 * updates that line (rev from and to, how many ops) rather than adding one a save.
 */
final class BuilderStore
{
    public const DRAFTS = 'groddle_builder_drafts';
    public const REVISIONS = 'groddle_builder_revisions';
    public const REVISIONS_KEPT = 200;
    public const LOG_MERGE_S = 600;
    public const ID = '/^[a-z][a-z0-9-]{2,47}$/';
    public const STATES = ['draft', 'archived', 'published'];

    public function __construct(private Db $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(bool $all = false): array
    {
        $t = $this->db->table(self::DRAFTS);
        $rows = $this->db->rows("SELECT id, title, rev, zone, state, updated_by, updated_at, created_at FROM {$t}" . ($all ? '' : " WHERE state <> 'archived'") . ' ORDER BY updated_at DESC, id');
        $out = [];
        foreach ($rows as $r) {
            $zone = BuilderOps::decode((string) $r['zone']);
            $streets = [];
            foreach (is_object($zone) && is_array($zone->streets ?? null) ? $zone->streets : [] as $s) {
                $streets[] = ['key' => (string) ($s->key ?? ''), 'tsid' => (string) ($s->street->tsid ?? ''), 'label' => (string) ($s->street->label ?? '')];
            }
            $out[] = [
                'id' => (string) $r['id'],
                'title' => (string) $r['title'],
                'rev' => (int) $r['rev'],
                'state' => (string) $r['state'],
                'streets' => $streets,
                'hub' => is_object($zone) && ($zone->hub ?? null) instanceof \stdClass ? ['id' => (string) ($zone->hub->id ?? ''), 'name' => (string) ($zone->hub->name ?? ''), 'isNew' => ($zone->hub->isNew ?? false) === true] : null,
                'updatedBy' => (string) $r['updated_by'],
                'updatedAt' => SaveShape::time((string) $r['updated_at']),
                'createdAt' => SaveShape::time((string) $r['created_at']),
            ];
        }
        return $out;
    }

    /** The draft row (zone decoded), or null. @return array{id: string, title: string, rev: int, state: string, zone: \stdClass}|null */
    public function find(string $id, bool $lock = false): ?array
    {
        $r = $this->db->row('SELECT id, title, rev, state, zone FROM ' . $this->db->table(self::DRAFTS) . ' WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if ($r === null) {
            return null;
        }
        $zone = BuilderOps::decode((string) $r['zone']);
        return ['id' => (string) $r['id'], 'title' => (string) $r['title'], 'rev' => (int) $r['rev'], 'state' => (string) $r['state'], 'zone' => $zone instanceof \stdClass ? $zone : new \stdClass()];
    }

    /** An old rev's zone, or null. */
    public function revision(string $id, int $rev): ?\stdClass
    {
        $blob = $this->db->value('SELECT zone FROM ' . $this->db->table(self::REVISIONS) . ' WHERE draft_id = ? AND rev = ?', [$id, $rev]);
        if (!is_string($blob)) {
            return null;
        }
        $json = @gzdecode($blob);
        $zone = is_string($json) ? BuilderOps::decode($json) : null;
        return $zone instanceof \stdClass ? $zone : null;
    }

    /** @return list<array{rev: int, at: string, author: string, note: string, ops: string, bytes: int}> */
    public function revisions(string $id, int $limit = 100): array
    {
        $rows = $this->db->rows('SELECT rev, at, author, note, ops_summary, LENGTH(zone) AS bytes FROM ' . $this->db->table(self::REVISIONS) . ' WHERE draft_id = ? ORDER BY rev DESC LIMIT ' . max(1, min(500, $limit)), [$id]);
        return array_map(static fn (array $r): array => ['rev' => (int) $r['rev'], 'at' => SaveShape::time((string) $r['at']), 'author' => (string) $r['author'], 'note' => (string) $r['note'], 'ops' => (string) $r['ops_summary'], 'bytes' => (int) $r['bytes']], $rows);
    }

    /** A new draft at rev 1. */
    public function create(string $id, string $title, ?\stdClass $hub, string $author, int $admin, ?int $now = null): \stdClass
    {
        $zone = (object) ['format' => 'groddle-zone/1', 'id' => $id, 'title' => $title, 'rev' => 1];
        if ($hub !== null) {
            $zone->hub = $hub;
        }
        $zone->streets = [];
        $zone->edits = [];
        $zone->assets = new \stdClass();
        $bad = BuilderOps::check($zone, $id);
        if ($bad !== []) {
            throw new RestError('builder_invalid', $bad[0]['message'], 422, ['findings' => $bad]);
        }
        $now ??= time();
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $json = BuilderOps::encode($zone);
        return $this->db->transaction(function () use ($id, $title, $zone, $json, $author, $admin, $stamp, $now): \stdClass {
            if ($this->db->value('SELECT 1 FROM ' . $this->db->table(self::DRAFTS) . ' WHERE id = ?', [$id]) !== null) {
                throw new RestError('builder_exists', 'A draft already has that id.', 409);
            }
            $this->db->execute('INSERT INTO ' . $this->db->table(self::DRAFTS) . " (id, title, rev, zone, sha256, state, created_by, updated_by, created_at, updated_at) VALUES (?, ?, 1, ?, ?, 'draft', ?, ?, ?, ?)", [$id, $title, $json, hash('sha256', $json), $author, $author, $stamp, $stamp]);
            $this->keepRevision($id, 1, $json, $author, 'created', '', $stamp);
            $this->log($admin, $id, $title, 1, 'created', 0, $now);
            return $zone;
        });
    }

    /**
     * One write: lock the row, refuse a stale rev, let $change make the next zone, check it, keep it.
     * $change gets the zone (a copy it may change) and returns [zone, ops summary, op count, extra].
     *
     * @param callable(\stdClass): array{0: \stdClass, 1: string, 2: int, 3?: array<string, mixed>} $change
     * @return array{rev: int, zone: \stdClass, extra: array<string, mixed>}
     */
    public function write(string $id, int $ifMatch, callable $change, string $author, int $admin, string $note = '', ?int $now = null): array
    {
        $now ??= time();
        return $this->db->transaction(function () use ($id, $ifMatch, $change, $author, $admin, $note, $now): array {
            $row = $this->find($id, true);
            if ($row === null) {
                throw new RestError('builder_not_found', 'No draft has that id.', 404);
            }
            if ($row['state'] === 'archived') {
                throw new RestError('builder_archived', 'That draft is archived.', 409, ['rev' => $row['rev']]);
            }
            if ($ifMatch !== $row['rev']) {
                throw new RestError('builder_rev_conflict', "The draft has moved on to rev {$row['rev']}.", 409, ['rev' => $row['rev']]);
            }
            $result = $change($row['zone']);
            [$zone, $summary, $count] = $result;
            $extra = $result[3] ?? [];
            $rev = $row['rev'] + 1;
            $zone->rev = $rev;
            $bad = BuilderOps::check($zone, $id);
            if ($bad !== []) {
                throw new RestError('builder_invalid', $bad[0]['message'], 422, ['findings' => $bad]);
            }
            // New art's provenance is set at intake (BuilderAssets), never by an edit.
            $art = (new BuilderAssets($this->db))->checkZone($zone);
            if ($art !== []) {
                throw new RestError('builder_invalid', $art[0], 422, ['findings' => array_map(static fn (string $m): array => ['path' => '/assets', 'message' => $m], $art)]);
            }
            $json = BuilderOps::encode($zone);
            if (strlen($json) > BuilderOps::MAX_ZONE_BYTES) {
                throw new RestError('builder_too_large', 'A draft is at most 4 MB.', 413);
            }
            $stamp = gmdate('Y-m-d H:i:s', $now);
            $title = is_string($zone->title ?? null) ? $zone->title : $row['title'];
            $this->db->execute('UPDATE ' . $this->db->table(self::DRAFTS) . ' SET title = ?, rev = ?, zone = ?, sha256 = ?, updated_by = ?, updated_at = ? WHERE id = ?', [$title, $rev, $json, hash('sha256', $json), $author, $stamp, $id]);
            $this->keepRevision($id, $rev, $json, $author, $note, $summary, $stamp);
            $this->log($admin, $id, $title, $rev, $summary, $count, $now);
            return ['rev' => $rev, 'zone' => $zone, 'extra' => $extra];
        });
    }

    public function archive(string $id, int $admin, ?int $now = null): void
    {
        $title = $this->db->value('SELECT title FROM ' . $this->db->table(self::DRAFTS) . ' WHERE id = ?', [$id]);
        if ($title === null) {
            throw new RestError('builder_not_found', 'No draft has that id.', 404);
        }
        $this->db->execute('UPDATE ' . $this->db->table(self::DRAFTS) . " SET state = 'archived', updated_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', $now ?? time()), $id]);
        AdminConsole::recordAction($this->db, $admin, 0, 'builder', ['draft' => $id, 'title' => (string) $title, 'text' => 'archived'], 'stored', $now);
    }

    private function keepRevision(string $id, int $rev, string $json, string $author, string $note, string $summary, string $stamp): void
    {
        $t = $this->db->table(self::REVISIONS);
        // Binary goes in as base64 and FROM_BASE64 (raw bytes are not a valid string to bind; TicketStore does the same).
        $this->db->execute("INSERT INTO {$t} (draft_id, rev, zone, sha256, author, at, note, ops_summary) VALUES (?, ?, FROM_BASE64(?), ?, ?, ?, ?, ?)", [$id, $rev, base64_encode((string) gzencode($json, 6)), hash('sha256', $json), $author, $stamp, mb_substr($note, 0, 200), mb_substr($summary, 0, 2000)]);
        if ($this->db->refused()) {
            throw new RestError('builder_store_failed', 'The revision could not be kept.', 500);
        }
        $older = $this->db->rows("SELECT rev, at FROM {$t} WHERE draft_id = ? ORDER BY rev DESC LIMIT 100000 OFFSET " . self::REVISIONS_KEPT, [$id]);
        if ($older === []) {
            return;
        }
        // Past the newest REVISIONS_KEPT: the first rev of each hour stays (rev 1 always).
        $drop = [];
        $seen = [];
        foreach (array_reverse($older) as $r) {
            $hour = substr((string) $r['at'], 0, 13);
            if ((int) $r['rev'] === 1 || !isset($seen[$hour])) {
                $seen[$hour] = true;
                continue;
            }
            $drop[] = (int) $r['rev'];
        }
        if ($drop !== []) {
            $this->db->execute("DELETE FROM {$t} WHERE draft_id = ? AND rev IN (?)", [$id, $drop]);
        }
    }

    /** One log line per run of saves (see the class comment). */
    private function log(int $admin, string $id, string $title, int $rev, string $summary, int $count, int $now): void
    {
        $t = $this->db->table(AdminConsole::ACTIONS);
        $last = $this->db->row("SELECT id, at, admin_user_id, kind, detail FROM {$t} ORDER BY id DESC LIMIT 1");
        $detail = is_array($last) ? json_decode((string) $last['detail'], true) : null;
        if (is_array($last) && $last['kind'] === 'builder' && (int) $last['admin_user_id'] === $admin && is_array($detail) && ($detail['draft'] ?? null) === $id && isset($detail['from'])
            && $now - (int) strtotime($last['at'] . ' UTC') <= self::LOG_MERGE_S) {
            $detail['rev'] = $rev;
            $detail['title'] = $title;
            $detail['ops'] = (int) ($detail['ops'] ?? 0) + $count;
            $detail['saves'] = (int) ($detail['saves'] ?? 1) + 1;
            $detail['text'] = mb_substr($summary, 0, 200);
            $this->db->execute("UPDATE {$t} SET at = ?, detail = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', $now), (string) json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int) $last['id']]);
            return;
        }
        AdminConsole::recordAction($this->db, $admin, 0, 'builder', ['draft' => $id, 'title' => $title, 'from' => $rev, 'rev' => $rev, 'ops' => $count, 'saves' => 1, 'text' => mb_substr($summary, 0, 200)], 'stored', $now);
    }
}
