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
 * What an agent needs beyond the drafts (the game's docs/level-builder/spec.md 8.3, 8.5, 8.6, 12):
 * the validator, the renderer, the deco catalog, the style profiles and the guide, all answered by
 * the builder worker (BuilderWorker; 503 builder_worker_unavailable when it is not running), and
 * the publish request. An administrator's session or a builder key with the scope (BuilderAuth).
 *
 *   POST builder/drafts/{id}/validate {rules?, reach?}  validate scope; the report (7.2), at the current rev
 *   GET  builder/drafts/{id}/render.png                  render scope; image/png; X-Builder-Rev, X-Builder-Findings: <error>,<warn>
 *        ?street=<key>&view=full|camera&x=&y=&w=&h=&scale=0.05..1&overlay=geometry,reach,findings,arrivals,populations,grid,effects&layers=a,b
 *   POST builder/drafts/{id}/publish-request {note}      drafts:write; marks the draft ready (publishing stays local, P6)
 *   GET  builder/decos?q=&layer=&hub=&family=&animated=&limit=   drafts:read; catalog rows, best first
 *   GET  builder/style/{profile}                         drafts:read; default or hub-<id>
 *   GET  builder/guide                                   drafts:read; the agent's card (8.6)
 *
 * Rate limits per key (spec 12): renders 20 and validates 60 a minute (429 with Retry-After).
 */
final readonly class AdminBuilderAgent
{
    private const BASE = '/groddle/v1/admin/builder';
    public const OVERLAYS = ['geometry', 'reach', 'findings', 'arrivals', 'populations', 'grid', 'effects'];
    /** New art sent to the worker with a render, at most (base64 grows it by a third). */
    private const ART_BYTES = 6_000_000;

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Post, self::BASE . '/drafts/{id:[a-z0-9-]+}/validate', policy: new Policy(Access::Public))]
    public function validate(Request $request, string $id): Response
    {
        try {
            $this->gate($request, 'validate', 'validate');
            $row = $this->row($id);
            $body = json_decode($request->body ?: '{}', true, 8);
            $rules = is_array($body) && is_array($body['rules'] ?? null) ? array_values(array_filter($body['rules'], static fn ($r): bool => is_string($r) && preg_match('/^[A-Z]{3}-(\d{3}|\*)?$/', $r) === 1)) : null;
            $report = (new BuilderWorker($this->minn))->json('validate', [
                'zone' => $row['zone'],
                'rules' => $rules,
                'reach' => is_array($body) && ($body['reach'] ?? false) === true,
                'assetFiles' => $this->assetFiles($id),
            ], 30.0);
            return Response::json(['rev' => $row['rev']] + $report)->withHeader('X-Builder-Rev', (string) $row['rev'])->withHeader('Cache-Control', 'no-store');
        } catch (RestError $e) {
            return BuilderAuth::refusal($e);
        }
    }

    #[Route(Method::Get, self::BASE . '/drafts/{id:[a-z0-9-]+}/render.png', policy: new Policy(Access::Public))]
    public function render(Request $request, string $id): Response
    {
        try {
            $this->gate($request, 'render', 'render');
            $row = $this->row($id);
            $params = self::renderParams($request->query);
            $street = null;
            foreach (is_array($row['zone']->streets ?? null) ? $row['zone']->streets : [] as $s) {
                if (($s->key ?? null) === $params['street']) {
                    $street = $s->street ?? null;
                }
            }
            if (!$street instanceof \stdClass) {
                throw new RestError('builder_not_found', 'This draft has no street with that key.', 404);
            }
            $reply = (new BuilderWorker($this->minn))->call('render', [
                'draft' => $id,
                'rev' => $row['rev'],
                'zone' => $row['zone'],
                'params' => $params,
                'assetFiles' => $this->assetFiles($id),
                'art' => $this->art($row['zone'], $street),
            ], 45.0, 6_000_000);
            if ($reply->code !== 200 || !str_starts_with($reply->body, "\x89PNG")) {
                $data = json_decode($reply->body, true);
                throw new RestError(is_array($data) ? (string) ($data['code'] ?? 'builder_render_failed') : 'builder_render_failed', is_array($data) ? (string) ($data['message'] ?? 'The render failed.') : 'The render failed.', $reply->code >= 400 && $reply->code < 500 ? $reply->code : 503, is_array($data) && is_array($data['data'] ?? null) ? array_diff_key($data['data'], ['status' => 1]) : []);
            }
            return (new Response(200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Builder-Rev' => (string) $row['rev'],
                'X-Builder-Findings' => (string) ($reply->header('x-builder-findings') ?? ''),
                'X-Builder-Size' => (string) ($reply->header('x-builder-size') ?? ''),
            ], $reply->body));
        } catch (RestError $e) {
            return BuilderAuth::refusal($e);
        }
    }

    #[Route(Method::Post, self::BASE . '/drafts/{id:[a-z0-9-]+}/publish-request', policy: new Policy(Access::Public))]
    public function publishRequest(Request $request, string $id): Response
    {
        try {
            $who = $this->gate($request, 'drafts:write', 'write');
        } catch (RestError $e) {
            return BuilderAuth::refusal($e);
        }
        $row = $this->row($id);
        if (!in_array($row['state'], ['draft', 'ready'], true)) {
            throw new RestError('builder_bad_state', "A {$row['state']} draft cannot be marked ready.", 409, ['state' => $row['state']]);
        }
        $body = json_decode($request->body ?: '{}', true, 8);
        $note = is_array($body) && is_string($body['note'] ?? null) ? mb_substr(trim($body['note']), 0, 200) : '';
        $db = $this->minn->db;
        $db->execute('UPDATE ' . $db->table(BuilderStore::DRAFTS) . " SET state = 'ready', updated_by = ?, updated_at = ? WHERE id = ?", [$who->author, gmdate('Y-m-d H:i:s'), $id]);
        AdminConsole::recordAction($db, $who->admin, 0, 'builder', ['draft' => $id, 'title' => $row['title'], 'rev' => $row['rev'], 'text' => 'ready' . ($note !== '' ? ": {$note}" : '')] + ($who->isKey() ? ['key' => $who->keyId] : []), 'stored');
        return Response::json(['id' => $id, 'state' => 'ready', 'rev' => $row['rev']])->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Get, self::BASE . '/decos', policy: new Policy(Access::Public))]
    public function decos(Request $request): Response
    {
        $this->gate($request, 'drafts:read');
        $q = [];
        foreach (['q', 'layer', 'hub', 'family', 'animated', 'limit'] as $k) {
            $v = $request->query($k);
            if ($v !== null && $v !== '') {
                $q[$k] = mb_substr($v, 0, 80);
            }
        }
        return Response::json((new BuilderWorker($this->minn))->json('decos', $q))->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Get, self::BASE . '/style/{profile:[a-z0-9-]+}', policy: new Policy(Access::Public))]
    public function style(Request $request, string $profile): Response
    {
        $this->gate($request, 'drafts:read');
        return Response::json((new BuilderWorker($this->minn))->json('style', ['profile' => $profile]))->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Get, self::BASE . '/guide', policy: new Policy(Access::Public))]
    public function guide(Request $request): Response
    {
        $this->gate($request, 'drafts:read');
        return Response::json((new BuilderWorker($this->minn))->json('guide', []))->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The render's parameters, checked (spec 8.5). @param array<string, mixed> $query
     * @return array{street: string, view: string, x: ?float, y: ?float, w: int, h: int, scale: float, overlay: list<string>, layers: list<string>}
     */
    public static function renderParams(array $query): array
    {
        $s = static fn (string $k): string => is_string($query[$k] ?? null) ? trim($query[$k]) : '';
        $street = $s('street');
        if (preg_match('/^[a-z][a-z0-9-]{0,47}$/', $street) !== 1) {
            throw new RestError('builder_bad_render', 'Name the street by its key (?street=).', 400);
        }
        $view = $s('view') ?: 'full';
        if (!in_array($view, ['full', 'camera'], true)) {
            throw new RestError('builder_bad_render', 'view is full or camera.', 400);
        }
        $num = static function (string $v, float $lo, float $hi, string $name): ?float {
            if ($v === '') {
                return null;
            }
            if (!is_numeric($v) || (float) $v < $lo || (float) $v > $hi) {
                throw new RestError('builder_bad_render', "{$name} is a number from {$lo} to {$hi}.", 400);
            }
            return (float) $v;
        };
        $list = static function (string $v, ?array $allowed, string $name): array {
            $out = array_values(array_unique(array_filter(array_map('trim', explode(',', $v)), static fn (string $x): bool => $x !== '')));
            foreach ($out as $x) {
                if ($allowed !== null ? !in_array($x, $allowed, true) : preg_match('/^[A-Za-z0-9_ .-]{1,48}$/', $x) !== 1) {
                    throw new RestError('builder_bad_render', "{$name}: {$x} is not one" . ($allowed !== null ? ' of ' . implode(', ', $allowed) : '') . '.', 400);
                }
            }
            return $out;
        };
        return [
            'street' => $street,
            'view' => $view,
            'x' => $num($s('x'), -100000, 100000, 'x'),
            'y' => $num($s('y'), -100000, 100000, 'y'),
            'w' => (int) ($num($s('w'), 100, 8000, 'w') ?? 960),
            'h' => (int) ($num($s('h'), 100, 6000, 'h') ?? 540),
            'scale' => $num($s('scale'), 0.05, 1, 'scale') ?? 0.25,
            'overlay' => $list($s('overlay'), self::OVERLAYS, 'overlay'),
            'layers' => $list($s('layers'), null, 'layers'),
        ];
    }

    /** name => sha256 of the draft's kept new art (the validator's AST-003). @return array<string, string> */
    private function assetFiles(string $draft): array
    {
        $out = [];
        foreach ((new BuilderAssets($this->minn->db))->forDraft($draft) as $a) {
            $out[(string) $a['name']] = (string) $a['sha256'];
        }
        return $out;
    }

    /**
     * The 2x files of the new art one street draws (its decos', and its effects' particle art),
     * base64 by sha256 (the render page draws them). @return array<string, string>
     */
    private function art(\stdClass $zone, \stdClass $street): array
    {
        $used = [];
        foreach (is_array($street->layers ?? null) ? $street->layers : [] as $layer) {
            foreach (is_array($layer->decos ?? null) ? $layer->decos : [] as $d) {
                if (is_string($d->sprite ?? null) && str_starts_with($d->sprite, 'gz_')) {
                    $used[$d->sprite] = true;
                }
            }
        }
        foreach (is_array($street->emitters ?? null) ? $street->emitters : [] as $e) {
            if (is_object($e) && is_string($e->sprite ?? null) && str_starts_with($e->sprite, 'gz_')) {
                $used[$e->sprite] = true;
            }
        }
        $assets = new BuilderAssets($this->minn->db);
        $out = [];
        $total = 0;
        foreach (array_keys($used) as $name) {
            $sha = $zone->assets->{$name}->sha256 ?? null;
            $bytes = is_string($sha) ? $assets->file($sha) : null;
            if ($bytes === null || $total + strlen($bytes) > self::ART_BYTES) {
                continue;
            }
            $total += strlen($bytes);
            $out[$sha] = base64_encode($bytes);
        }
        return $out;
    }

    /** @return array{id: string, title: string, rev: int, state: string, zone: \stdClass} */
    private function row(string $id): array
    {
        return (new BuilderStore($this->minn->db))->find($id) ?? throw new RestError('builder_not_found', 'No draft has that id.', 404);
    }

    private function gate(Request $request, ?string $scope, string $kind = 'read'): BuilderActor
    {
        return (new BuilderAuth($this->minn))->gate($request, $scope, $kind);
    }
}
