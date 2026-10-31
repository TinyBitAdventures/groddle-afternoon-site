<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * The level builder's asset intake over REST (the game's docs/level-builder/spec.md 8.3, 9.1, 13;
 * phase P5). Every route is an administrator's or a builder key's (BuilderAuth: assets:write to
 * send art, drafts:read to list it), and off unless groddle_builder_enabled is on, as AdminBuilder's
 * are.
 *
 *   POST builder/assets {draft, name, file2, file3?, licence, author, tool?, prompt?, inputs?, role?}
 *        201 {report, asset, op}: kept. asset is the zone's assets{} row; op is the asset.set op
 *        that puts it in the draft (sent through drafts/{id}/ops with If-Match, like any edit).
 *        200 the same when that name already holds that picture.
 *        422 builder_asset_refused {report}: a check refused it (the report says which and why).
 *        409 builder_asset_name_taken / builder_asset_exists; 413 past 200 a draft.
 *   GET  builder/assets?draft=<id>        {assets: [...]} metadata, reports, no bytes
 *   GET  builder/assets/{sha256 | name}   one asset's metadata and report
 *   GET  builder/drafts/{id}/art/{sha256}[?size=3]   the kept WebP itself (2x, or the 3x), for the CLI's
 *        publish: drafts:read, counted in the art rate (BuilderKeys::RATES), and only for art the
 *        draft's zone names (an `origin: new` row with that sha256) or that this caller sent into
 *        the draft. A keyed run of fetches is one log line per key, draft and 10 minutes.
 *
 * Intake itself is BuilderIntake (pure); the rows are BuilderAssets; the files are served by the
 * page route BuilderArt (/builder-art/<sha256>).
 */
final readonly class AdminBuilderAssets
{
    private const BASE = '/groddle/v1/admin/builder';
    /** Two files of 8 MB as base64, and the words around them. */
    private const MAX_BODY = 23_000_000;

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Post, self::BASE . '/assets', policy: new Policy(Access::Public))]
    public function intake(Request $request): Response
    {
        $who = $this->gate($request, 'assets:write', 'write');
        if (strlen($request->body) > self::MAX_BODY) {
            throw new RestError('builder_too_large', 'Send at most 8 MB a file.', 413);
        }
        $body = json_decode($request->body, true, 16);
        if (!is_array($body) || array_is_list($body)) {
            throw new RestError('builder_bad_asset', 'Send the art as a JSON object: {draft, name, file2, licence, author, ...}.', 400);
        }
        $draft = is_string($body['draft'] ?? null) ? $body['draft'] : '';
        if (preg_match(BuilderStore::ID, $draft) !== 1 || (new BuilderStore($this->minn->db))->find($draft) === null) {
            throw new RestError('builder_not_found', 'Name the draft the art is for (draft).', 404);
        }
        $root = StaticRoot::fromSite($this->minn)->dir;
        $done = BuilderIntake::intake($body, ['library' => self::library($root), 'palette' => self::palette($root)]);
        if (!$done['ok']) {
            throw new RestError('builder_asset_refused', self::firstRefusal($done['report']), 422, ['report' => $done['report']]);
        }
        [$asset, $new] = (new BuilderAssets($this->minn->db))->keep($draft, $done, $who->author);
        $name = (string) $done['report']['name'];
        if ($new) {
            AdminConsole::recordAction($this->minn->db, $who->admin, 0, 'builder', ['draft' => $draft, 'title' => $name, 'text' => 'new art ' . $name . ' (' . $asset['licence'] . ')'] + ($who->isKey() ? ['key' => $who->keyId] : []), 'stored');
        }
        return Response::json(['report' => $done['report'], 'asset' => $asset, 'op' => ['op' => 'asset.set', 'name' => $name, 'asset' => $asset], 'kept' => $new], $new ? 201 : 200)->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Get, self::BASE . '/assets', policy: new Policy(Access::Public))]
    public function list(Request $request): Response
    {
        $this->gate($request, 'drafts:read');
        $draft = (string) ($request->query('draft') ?? '');
        if (preg_match(BuilderStore::ID, $draft) !== 1) {
            throw new RestError('builder_not_found', 'Name the draft (?draft=).', 404);
        }
        return Response::json(['assets' => (new BuilderAssets($this->minn->db))->forDraft($draft)])->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Get, self::BASE . '/assets/{key:[a-z0-9_]+}', policy: new Policy(Access::Public))]
    public function one(Request $request, string $key): Response
    {
        $this->gate($request, 'drafts:read');
        $row = (new BuilderAssets($this->minn->db))->find($key) ?? throw new RestError('builder_not_found', 'No new art has that name or sha256.', 404);
        return Response::json($row)->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Get, self::BASE . '/drafts/{id:[a-z0-9-]+}/art/{sha:[0-9a-f]{64}}', policy: new Policy(Access::Public))]
    public function file(Request $request, string $id, string $sha): Response
    {
        try {
            $who = $this->gate($request, 'drafts:read', 'art');
            $draft = (new BuilderStore($this->minn->db))->find($id) ?? throw new RestError('builder_not_found', 'No draft has that id.', 404);
            $assets = new BuilderAssets($this->minn->db);
            $row = $assets->find($sha);
            if ($row === null || !self::artAllowed($draft['zone'], $id, $row, $who->author)) {
                throw new RestError('builder_not_found', 'This draft names no new art with that sha256.', 404);
            }
            $three = $request->query('size') === '3';
            $bytes = $assets->file($sha, $three);
            if ($bytes === null || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
                throw new RestError('builder_not_found', $three ? 'That art has no 3x file.' : 'That art has no kept file.', 404);
            }
            if ($who->isKey()) {
                self::logFetch($this->minn->db, $who, $id, (string) $draft['title'], (string) $row['name'], time());
            }
            return new Response(200, BuilderArt::headers(strlen($bytes)) + ['X-Builder-Sha256' => $sha, 'X-Builder-Name' => (string) $row['name']], $bytes);
        } catch (RestError $e) {
            return BuilderAuth::refusal($e);
        }
    }

    /**
     * PURE: whether a draft may hand out a kept picture: its zone names it (an `origin: new` row
     * whose sha256 is the picture's, under the kept name), or the caller sent it into this draft
     * (intake's created_by is the caller's author: the user id, or "key:<id>").
     *
     * @param array<string, mixed> $row BuilderAssets::find's shape
     */
    public static function artAllowed(\stdClass $zone, string $draftId, array $row, string $author): bool
    {
        $assets = $zone->assets ?? null;
        $named = $assets instanceof \stdClass ? ($assets->{(string) $row['name']} ?? null) : null;
        if ($named instanceof \stdClass && ($named->origin ?? null) === 'new' && ($named->sha256 ?? null) === $row['sha256']) {
            return true;
        }
        return ($row['draft'] ?? null) === $draftId && ($row['createdBy'] ?? null) === $author && $author !== '';
    }

    /** One log line per run of a key's fetches from one draft (10 minutes), as BuilderStore merges saves. */
    private static function logFetch(\Minn\Db $db, BuilderActor $who, string $draft, string $title, string $name, int $now): void
    {
        $t = $db->table(AdminConsole::ACTIONS);
        $last = $db->row("SELECT id, at, admin_user_id, kind, detail FROM {$t} ORDER BY id DESC LIMIT 1");
        $detail = is_array($last) ? json_decode((string) $last['detail'], true) : null;
        if (is_array($last) && $last['kind'] === 'builder' && (int) $last['admin_user_id'] === $who->admin && is_array($detail)
            && ($detail['draft'] ?? null) === $draft && ($detail['key'] ?? null) === $who->keyId && isset($detail['artFetched'])
            && $now - (int) strtotime($last['at'] . ' UTC') <= BuilderStore::LOG_MERGE_S) {
            $detail['artFetched'] = (int) $detail['artFetched'] + 1;
            $detail['text'] = 'art fetched: ' . $detail['artFetched'] . ' files, last ' . $name;
            $db->execute("UPDATE {$t} SET at = ?, detail = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', $now), (string) json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int) $last['id']]);
            return;
        }
        AdminConsole::recordAction($db, $who->admin, 0, 'builder', ['draft' => $draft, 'title' => $title, 'artFetched' => 1, 'text' => 'art fetched: ' . $name, 'key' => $who->keyId], 'stored', $now);
    }

    /** The library's deco names (the built game's assets/decos/manifest.json), as name => true. @return array<string, bool> */
    public static function library(string $root): array
    {
        $list = is_file("{$root}/assets/decos/manifest.json") ? json_decode((string) file_get_contents("{$root}/assets/decos/manifest.json"), true) : null;
        return is_array($list) ? array_fill_keys(array_map('strval', $list), true) : [];
    }

    /** The world's palette bands per layer role (style-profiles.json), or []. @return array<string, mixed> */
    public static function palette(string $root): array
    {
        $file = "{$root}/assets/content/style-profiles.json";
        $all = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $out = [];
        foreach (is_array($all) ? ($all['profiles']['default']['roles'] ?? []) : [] as $role => $r) {
            if (is_array($r['palette'] ?? null)) {
                $out[(string) $role] = $r['palette'];
            }
        }
        return $out;
    }

    private static function firstRefusal(array $report): string
    {
        foreach ($report['findings'] ?? [] as $f) {
            if (($f['severity'] ?? '') === 'refuse') {
                return (string) $f['message'];
            }
        }
        return 'That art was not taken.';
    }

    /** Who the request speaks for (BuilderAuth: a session, or a key with the scope). */
    private function gate(Request $request, ?string $scope, string $kind = 'read'): BuilderActor
    {
        return (new BuilderAuth($this->minn))->gate($request, $scope, $kind);
    }
}
