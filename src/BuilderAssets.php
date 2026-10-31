<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;
use Minn\RestError;

/**
 * The level builder's new art (the game's docs/level-builder/spec.md 5, 9.1; phase P5): one row per
 * gz_ sprite in groddle_builder_assets, holding the file intake wrote (BuilderIntake: a lossless
 * WebP, never the bytes as sent), an optional 3x file, the zone's assets{} row for it (meta: the
 * provenance) and the intake report. Bytes live in the database, never in a served folder;
 * AdminBuilderAssets::art serves them as image/webp to administrators only.
 *
 * A gz_ name is one picture everywhere (publishing writes it as public/assets/decos/groddle/<name>.webp),
 * so the name is the key and a picture is kept once (sha256 is unique too). Sending the same picture
 * under the same name again answers with the row already kept.
 *
 * Provenance is set at intake and is not an edit: BuilderStore::write calls checkZone, so a draft's
 * assets{} row for new art must be the stored row exactly (an op cannot make up a licence, a maker
 * or a sha256).
 */
final class BuilderAssets
{
    public const TABLE = 'groddle_builder_assets';
    /** New art a draft may bring in (spec 12). */
    public const PER_DRAFT = 200;

    public function __construct(private Db $db)
    {
    }

    /**
     * Keep what intake made. Returns [row, kept now?]: the row already kept for the same name and
     * picture, or the new one.
     *
     * @param array{report: array<string, mixed>, file2: string, file3: ?string, asset: array<string, mixed>} $done BuilderIntake::intake's answer (ok)
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function keep(string $draft, array $done, string $author, ?int $now = null): array
    {
        $t = $this->db->table(self::TABLE);
        $asset = $done['asset'];
        $name = (string) $done['report']['name'];
        $sha = (string) $asset['sha256'];
        return $this->db->transaction(function () use ($t, $draft, $done, $asset, $name, $sha, $author, $now): array {
            $same = $this->db->row("SELECT name, sha256, draft_id FROM {$t} WHERE name = ? OR sha256 = ? FOR UPDATE", [$name, $sha]);
            if ($same !== null) {
                if ($same['name'] === $name && $same['sha256'] === $sha) {
                    return [$this->meta($name) ?? [], false];
                }
                if ($same['name'] === $name) {
                    throw new RestError('builder_asset_name_taken', "{$name} is already another picture (from the draft {$same['draft_id']}). Pick a new name.", 409, ['name' => $name]);
                }
                throw new RestError('builder_asset_exists', "That picture is already in as {$same['name']}. Use that name.", 409, ['name' => (string) $same['name']]);
            }
            $count = (int) $this->db->value("SELECT COUNT(*) FROM {$t} WHERE draft_id = ?", [$draft]);
            if ($count >= self::PER_DRAFT) {
                throw new RestError('builder_too_large', 'A draft brings in at most ' . self::PER_DRAFT . ' pieces of new art.', 413);
            }
            $kept = $done['report']['kept'] ?? [];
            // Binary goes in as base64 and FROM_BASE64 (raw bytes are not a valid string to bind; TicketStore does the same).
            $this->db->execute(
                "INSERT INTO {$t} (name, sha256, draft_id, origin, licence, meta, file2, file3, width, height, bytes, report, created_by, created_at) VALUES (?, ?, ?, 'new', ?, ?, FROM_BASE64(?), " . ($done['file3'] !== null ? 'FROM_BASE64(?)' : 'NULL') . ', ?, ?, ?, ?, ?, ?)',
                array_merge(
                    [$name, $sha, $draft, (string) $asset['licence'], self::json($asset), base64_encode($done['file2'])],
                    $done['file3'] !== null ? [base64_encode($done['file3'])] : [],
                    [(int) ($kept['w'] ?? 0), (int) ($kept['h'] ?? 0), strlen($done['file2']), self::json($done['report']), $author, gmdate('Y-m-d H:i:s', $now ?? time())]
                )
            );
            if ($this->db->refused()) {
                throw new RestError('builder_store_failed', 'The art could not be kept.', 500);
            }
            return [$asset, true];
        });
    }

    /** A kept asset's zone row (the provenance), by name, or null. @return array<string, mixed>|null */
    public function meta(string $name): ?array
    {
        $json = $this->db->value('SELECT meta FROM ' . $this->db->table(self::TABLE) . ' WHERE name = ?', [$name]);
        $row = is_string($json) ? json_decode($json, true) : null;
        return is_array($row) ? $row : null;
    }

    /** Metadata and report (no bytes) by sha256 or name, or null. @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        $col = preg_match('/^[0-9a-f]{64}$/', $key) === 1 ? 'sha256' : 'name';
        $r = $this->db->row('SELECT name, sha256, draft_id, origin, licence, meta, width, height, bytes, LENGTH(file3) AS bytes3, report, created_by, created_at FROM ' . $this->db->table(self::TABLE) . " WHERE {$col} = ?", [$key]);
        return $r === null ? null : self::shape($r);
    }

    /** A draft's new art (no bytes), newest first. @return list<array<string, mixed>> */
    public function forDraft(string $draft): array
    {
        $rows = $this->db->rows('SELECT name, sha256, draft_id, origin, licence, meta, width, height, bytes, LENGTH(file3) AS bytes3, report, created_by, created_at FROM ' . $this->db->table(self::TABLE) . ' WHERE draft_id = ? ORDER BY created_at DESC, name', [$draft]);
        return array_map(self::shape(...), $rows);
    }

    /** The kept bytes (2x, or 3x when asked and kept), or null. */
    public function file(string $sha, bool $three = false): ?string
    {
        $bytes = $this->db->value('SELECT ' . ($three ? 'file3' : 'file2') . ' FROM ' . $this->db->table(self::TABLE) . ' WHERE sha256 = ?', [$sha]);
        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /**
     * Problems with a zone's new-art rows: each origin "new" row must be a kept asset's row exactly.
     * @return list<string>
     */
    public function checkZone(\stdClass $zone): array
    {
        $assets = $zone->assets ?? null;
        if (!$assets instanceof \stdClass) {
            return [];
        }
        $rows = [];
        foreach (get_object_vars($assets) as $name => $row) {
            if ($row instanceof \stdClass && ($row->origin ?? null) === 'new') {
                $rows[(string) $name] = $row;
            }
        }
        if ($rows === []) {
            return [];
        }
        $kept = [];
        foreach ($this->db->rows('SELECT name, meta FROM ' . $this->db->table(self::TABLE) . ' WHERE name IN (?)', [array_keys($rows)]) as $r) {
            $kept[(string) $r['name']] = (string) $r['meta'];
        }
        $bad = [];
        foreach ($rows as $name => $row) {
            if (!isset($kept[$name])) {
                $bad[] = "{$name} is marked as new art but never came in through asset intake.";
            } elseif (self::canonical(json_decode((string) json_encode($row), true)) !== self::canonical(json_decode($kept[$name], true))) {
                $bad[] = "{$name}'s provenance is set at intake and cannot be changed by an edit.";
            }
        }
        return $bad;
    }

    /** @return array<string, mixed> */
    private static function shape(array $r): array
    {
        return [
            'name' => (string) $r['name'],
            'sha256' => (string) $r['sha256'],
            'draft' => (string) $r['draft_id'],
            'licence' => (string) $r['licence'],
            'asset' => json_decode((string) $r['meta'], true),
            'w' => (int) $r['width'],
            'h' => (int) $r['height'],
            'bytes' => (int) $r['bytes'],
            'has3x' => (int) ($r['bytes3'] ?? 0) > 0,
            'report' => json_decode((string) $r['report'], true),
            'createdBy' => (string) $r['created_by'],
            'createdAt' => SaveShape::time((string) $r['created_at']),
            'url' => BuilderArt::PATH . $r['sha256'],
        ];
    }

    /** The same JSON whatever order the keys came in. */
    private static function canonical(mixed $v): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (!is_array($v)) {
                return is_float($v) && floor($v) === $v && abs($v) < 1e15 ? (int) $v : $v;
            }
            $v = array_map($sort, $v);
            if (!array_is_list($v)) {
                ksort($v, SORT_STRING);
            }
            return $v;
        };
        return self::json($sort($v));
    }

    private static function json(mixed $v): string
    {
        return (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
