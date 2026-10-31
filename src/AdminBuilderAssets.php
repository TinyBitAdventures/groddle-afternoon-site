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
 * phase P5). Every route is manage_options only and off unless groddle_builder_enabled is on, as
 * AdminBuilder's are.
 *
 *   POST builder/assets {draft, name, file2, file3?, licence, author, tool?, prompt?, inputs?, role?}
 *        201 {report, asset, op}: kept. asset is the zone's assets{} row; op is the asset.set op
 *        that puts it in the draft (sent through drafts/{id}/ops with If-Match, like any edit).
 *        200 the same when that name already holds that picture.
 *        422 builder_asset_refused {report}: a check refused it (the report says which and why).
 *        409 builder_asset_name_taken / builder_asset_exists; 413 past 200 a draft.
 *   GET  builder/assets?draft=<id>        {assets: [...]} metadata, reports, no bytes
 *   GET  builder/assets/{sha256 | name}   one asset's metadata and report
 *
 * Intake itself is BuilderIntake (pure); the rows are BuilderAssets; the files are served by the
 * page route BuilderArt (/builder-art/<sha256>).
 */
final readonly class AdminBuilderAssets
{
    private const ADMIN = 'manage_options';
    private const BASE = '/groddle/v1/admin/builder';
    /** Two files of 8 MB as base64, and the words around them. */
    private const MAX_BODY = 23_000_000;

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Post, self::BASE . '/assets', policy: new Policy(Access::Cap, self::ADMIN))]
    public function intake(Request $request): Response
    {
        $admin = $this->gate();
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
        [$asset, $new] = (new BuilderAssets($this->minn->db))->keep($draft, $done, (string) $admin);
        $name = (string) $done['report']['name'];
        if ($new) {
            AdminConsole::recordAction($this->minn->db, $admin, 0, 'builder', ['draft' => $draft, 'title' => $name, 'text' => 'new art ' . $name . ' (' . $asset['licence'] . ')'], 'stored');
        }
        return Response::json(['report' => $done['report'], 'asset' => $asset, 'op' => ['op' => 'asset.set', 'name' => $name, 'asset' => $asset], 'kept' => $new], $new ? 201 : 200)->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Get, self::BASE . '/assets', policy: new Policy(Access::Cap, self::ADMIN))]
    public function list(Request $request): Response
    {
        $this->gate();
        $draft = (string) ($request->query('draft') ?? '');
        if (preg_match(BuilderStore::ID, $draft) !== 1) {
            throw new RestError('builder_not_found', 'Name the draft (?draft=).', 404);
        }
        return Response::json(['assets' => (new BuilderAssets($this->minn->db))->forDraft($draft)])->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Get, self::BASE . '/assets/{key:[a-z0-9_]+}', policy: new Policy(Access::Cap, self::ADMIN))]
    public function one(Request $request, string $key): Response
    {
        $this->gate();
        $row = (new BuilderAssets($this->minn->db))->find($key) ?? throw new RestError('builder_not_found', 'No new art has that name or sha256.', 404);
        return Response::json($row)->withHeader('Cache-Control', 'no-store');
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

    /** The caller's id, once the builder is on (AdminBuilder's gate). */
    private function gate(): int
    {
        if (!AdminBuilder::enabled($this->minn)) {
            throw new RestError('builder_disabled', 'The level builder is not turned on for this site.', 404);
        }
        $user = $this->minn->user();
        if ($user === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        return $user->id;
    }
}
