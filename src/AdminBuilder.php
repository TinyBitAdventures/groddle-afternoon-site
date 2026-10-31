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
 * The level builder's drafts over REST (the game's docs/level-builder/spec.md 5 and 8.3, phase
 * P1): groddle/v1/admin/builder/*, every route an administrator's (manage_options, the session
 * with its REST nonce) or a builder key's with the scope the route names (P4, BuilderAuth: the
 * routes are declared public and the gate is the first line of each). The editor page is
 * AdminBuilderPage, /console/builder/. Off unless the site option groddle_builder_enabled is on
 * (groddle.localhost only for now, spec Q2): with it unset every route answers 404
 * builder_disabled, so a release ships the builder dark.
 *
 *   GET    builder/drafts[?all=1]              {"drafts":[{id,title,rev,state,streets:[{key,tsid,label}],hub:{id,name,isNew},updatedBy,updatedAt,createdAt}]}
 *   POST   builder/drafts {id,title,hub?}      201 the zone (rev 1)
 *   GET    builder/drafts/{id}[?rev=n]         the zone; ETag "<rev>"; If-None-Match "<rev>" answers 304
 *   PUT    builder/drafts/{id} <zone>          If-Match: <rev> → {"rev"}; 409 builder_rev_conflict {rev}, 413, 422
 *   POST   builder/drafts/{id}/ops {ops,note}  If-Match → {"rev","changed":[...]}; a failing op: 422 builder_invalid {op}
 *   GET    builder/drafts/{id}/revisions       {"revisions":[{rev,at,author,note,ops,bytes}]}
 *   POST   builder/drafts/{id}/restore {rev}   If-Match → {"rev"}: the old rev copied forward
 *   GET    builder/drafts/{id}/play            the play-test bundle (js/builder/draft-play.js); the session only
 *   DELETE builder/drafts/{id}                 archived, never deleted
 *
 * Key scopes: drafts:read for the reads, drafts:write (counted as writes for the rate limit) for
 * the rest. A write without If-Match is refused (428 builder_rev_required). Errors keep the site's shape,
 * {code, message, data:{status, ...}}.
 */
final readonly class AdminBuilder
{
    public const OPTION = 'groddle_builder_enabled';
    private const BASE = '/groddle/v1/admin/builder';

    public function __construct(private Seams $minn)
    {
    }

    /** Whether the builder is on for this site (the option groddle_builder_enabled). */
    public static function enabled(Seams $minn): bool
    {
        return in_array(strtolower(trim((string) ($minn->site->option(self::OPTION) ?? ''))), ['1', 'yes', 'true', 'on'], true);
    }

    #[Route(Method::Get, self::BASE . '/drafts', policy: new Policy(Access::Public))]
    public function drafts(Request $request): Response
    {
        $this->gate($request, 'drafts:read');
        return Response::json(['drafts' => $this->store()->list($request->query('all') === '1')])->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Post, self::BASE . '/drafts', policy: new Policy(Access::Public))]
    public function create(Request $request): Response
    {
        $who = $this->gate($request, 'drafts:write', 'write');
        $body = BuilderOps::decode($request->body);
        $id = is_object($body) && is_string($body->id ?? null) ? $body->id : '';
        $title = is_object($body) && is_string($body->title ?? null) ? trim($body->title) : '';
        if (preg_match(BuilderStore::ID, $id) !== 1) {
            throw new RestError('builder_bad_id', 'A draft id is 3 to 48 lowercase letters, digits and hyphens, starting with a letter.', 400);
        }
        if ($title === '' || mb_strlen($title) > 80) {
            throw new RestError('builder_bad_title', 'Give the draft a title of up to 80 characters.', 400);
        }
        $hub = is_object($body) && ($body->hub ?? null) instanceof \stdClass ? $body->hub : null;
        $zone = $this->store()->create($id, $title, $hub, $who->author, $who->admin);
        return Response::json($zone, 201)->withHeader('ETag', '"1"')->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Get, self::BASE . '/drafts/{id:[a-z0-9-]+}', policy: new Policy(Access::Public))]
    public function draft(Request $request, string $id): Response
    {
        $this->gate($request, 'drafts:read');
        $row = $this->row($id);
        $want = $request->query('rev');
        if ($want !== null && $want !== '' && (int) $want !== $row['rev']) {
            $zone = $this->store()->revision($id, (int) $want) ?? throw new RestError('builder_not_found', 'That draft has no such rev.', 404);
            return Response::json($zone)->withHeader('ETag', '"' . (int) $want . '"')->withHeader('Cache-Control', 'no-store');
        }
        $etag = '"' . $row['rev'] . '"';
        if (trim((string) $request->header('if-none-match')) === $etag) {
            return (new Response(304, ['ETag' => $etag, 'Cache-Control' => 'no-store']))->withoutBody();
        }
        return Response::json($row['zone'])->withHeader('ETag', $etag)->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Put, self::BASE . '/drafts/{id:[a-z0-9-]+}', policy: new Policy(Access::Public))]
    public function replace(Request $request, string $id): Response
    {
        $who = $this->gate($request, 'drafts:write', 'write');
        $ifMatch = self::ifMatch($request);
        self::sized($request);
        $next = BuilderOps::decode($request->body);
        if (!$next instanceof \stdClass) {
            throw new RestError('builder_invalid', 'Send the zone as a JSON object.', 422);
        }
        $done = $this->store()->write($id, $ifMatch, static fn (\stdClass $zone): array => [$next, 'replaced the zone', 1], $who->author, $who->admin, 'replaced');
        return Response::json(['rev' => $done['rev']])->withHeader('ETag', '"' . $done['rev'] . '"');
    }

    #[Route(Method::Post, self::BASE . '/drafts/{id:[a-z0-9-]+}/ops', policy: new Policy(Access::Public))]
    public function ops(Request $request, string $id): Response
    {
        $who = $this->gate($request, 'drafts:write', 'write');
        $ifMatch = self::ifMatch($request);
        self::sized($request);
        $body = BuilderOps::decode($request->body);
        $ops = is_object($body) ? ($body->ops ?? null) : null;
        if (!is_array($ops) || !array_is_list($ops) || $ops === []) {
            throw new RestError('builder_bad_op', 'Send {"ops": [...]} with at least one op.', 400);
        }
        if (count($ops) > BuilderOps::MAX_OPS) {
            throw new RestError('builder_too_large', 'At most ' . BuilderOps::MAX_OPS . ' ops in a batch.', 413);
        }
        $note = is_object($body) && is_string($body->note ?? null) ? mb_substr(trim($body->note), 0, 200) : '';
        $done = $this->store()->write($id, $ifMatch, static function (\stdClass $zone) use ($ops): array {
            try {
                $r = BuilderOps::applyAll($zone, $ops);
            } catch (BuilderOpError $e) {
                throw new RestError('builder_invalid', $e->getMessage(), 422, ['op' => $e->index, 'reason' => $e->errorCode]);
            }
            return [$zone, BuilderOps::summary($ops), count($ops), ['changed' => $r['changed']]];
        }, $who->author, $who->admin, $note);
        return Response::json(['rev' => $done['rev'], 'changed' => $done['extra']['changed'] ?? []])->withHeader('ETag', '"' . $done['rev'] . '"');
    }

    #[Route(Method::Get, self::BASE . '/drafts/{id:[a-z0-9-]+}/revisions', policy: new Policy(Access::Public))]
    public function revisions(Request $request, string $id): Response
    {
        $this->gate($request, 'drafts:read');
        $this->row($id);
        return Response::json(['revisions' => $this->store()->revisions($id)])->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Post, self::BASE . '/drafts/{id:[a-z0-9-]+}/restore', policy: new Policy(Access::Public))]
    public function restore(Request $request, string $id): Response
    {
        $who = $this->gate($request, 'drafts:write', 'write');
        $ifMatch = self::ifMatch($request);
        $body = BuilderOps::decode($request->body);
        $rev = is_object($body) && is_int($body->rev ?? null) ? $body->rev : 0;
        $old = $this->store()->revision($id, $rev) ?? throw new RestError('builder_not_found', 'That draft has no such rev.', 404);
        $done = $this->store()->write($id, $ifMatch, static fn (\stdClass $zone): array => [$old, "restored rev {$rev}", 1], $who->author, $who->admin, "restored rev {$rev}");
        return Response::json(['rev' => $done['rev'], 'restored' => $rev])->withHeader('ETag', '"' . $done['rev'] . '"');
    }

    #[Route(Method::Delete, self::BASE . '/drafts/{id:[a-z0-9-]+}', policy: new Policy(Access::Public))]
    public function archive(Request $request, string $id): Response
    {
        $who = $this->gate($request, 'drafts:write', 'write');
        $this->store()->archive($id, $who->admin);
        return Response::json(['id' => $id, 'state' => 'archived']);
    }

    /**
     * The play-test bundle (spec 6.8): the zone's streets at the current rev, their index rows
     * (no file: js/street-loader.js registerDraftStreets primes them), their population as
     * ecology rows, the street keys, the hub, and the zone's edits on shipped streets (the
     * play-test puts them on copies of those streets, so the ways into the zone show there too),
     * and the zone's new art by name and sha256 (the play-test draws it from /builder-art/).
     */
    #[Route(Method::Get, self::BASE . '/drafts/{id:[a-z0-9-]+}/play', policy: new Policy(Access::Public))]
    public function play(Request $request, string $id): Response
    {
        $this->gate($request, null);
        $row = $this->row($id);
        return Response::json(self::bundle($row['zone']))->withHeader('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    public static function bundle(\stdClass $zone): array
    {
        $streets = $index = $ecology = $keys = [];
        foreach (is_array($zone->streets ?? null) ? $zone->streets : [] as $row) {
            $s = $row->street ?? null;
            if (!$s instanceof \stdClass || !is_string($s->tsid ?? null)) {
                continue;
            }
            $tsid = $s->tsid;
            $streets[$tsid] = $s;
            $keys[(string) ($row->key ?? '')] = $tsid;
            $index[$tsid] = ['label' => (string) ($s->label ?? $tsid), 'hubId' => (string) ($s->hubId ?? ''), 'hubName' => (string) ($s->hubName ?? ''), 'draft' => true];
            $pop = $row->population ?? null;
            if ($pop instanceof \stdClass) {
                $ecology[$tsid] = (object) (['label' => (string) ($s->label ?? $tsid)] + get_object_vars($pop));
            }
        }
        return [
            'draft' => ['id' => (string) ($zone->id ?? ''), 'title' => (string) ($zone->title ?? ''), 'rev' => (int) ($zone->rev ?? 0)],
            'keys' => (object) $keys,
            'streets' => (object) $streets,
            'index' => (object) $index,
            'ecology' => (object) $ecology,
            'hub' => $zone->hub ?? null,
            'edits' => is_array($zone->edits ?? null) ? $zone->edits : [],
            // The zone's own art (gz_), so the play-test draws it from /builder-art/<sha256>.
            'art' => (object) self::newArt($zone),
        ];
    }

    /**
     * The zone's new art for the play-test: name => {sha256} for every `origin: new` row with a
     * stored file's hash (the bytes stay behind /builder-art/, an administrator's session only).
     *
     * @return array<string, array{sha256: string}>
     */
    public static function newArt(\stdClass $zone): array
    {
        $out = [];
        $assets = $zone->assets ?? null;
        foreach ($assets instanceof \stdClass ? get_object_vars($assets) : [] as $name => $row) {
            if (str_starts_with((string) $name, 'gz_') && $row instanceof \stdClass && ($row->origin ?? null) === 'new' && is_string($row->sha256 ?? null) && preg_match('/^[0-9a-f]{64}$/', $row->sha256) === 1) {
                $out[(string) $name] = ['sha256' => $row->sha256];
            }
        }
        return $out;
    }

    /** The rev an If-Match header names ("7", 7 or W/"7"). */
    public static function ifMatch(Request $request): int
    {
        $raw = trim((string) $request->header('if-match'));
        if (preg_match('/^(?:W\/)?"?(\d{1,9})"?$/', $raw, $m) !== 1) {
            throw new RestError('builder_rev_required', 'Send If-Match with the rev this change was made against.', 428);
        }
        return (int) $m[1];
    }

    private static function sized(Request $request): void
    {
        if (strlen($request->body) > BuilderOps::MAX_ZONE_BYTES + 65536) {
            throw new RestError('builder_too_large', 'A draft is at most 4 MB.', 413);
        }
    }

    /** Who the request speaks for, once the builder is on: a session, or a key with the scope (BuilderAuth). */
    private function gate(Request $request, ?string $scope, string $kind = 'read'): BuilderActor
    {
        return (new BuilderAuth($this->minn))->gate($request, $scope, $kind);
    }

    /** @return array{id: string, title: string, rev: int, state: string, zone: \stdClass} */
    private function row(string $id): array
    {
        return $this->store()->find($id) ?? throw new RestError('builder_not_found', 'No draft has that id.', 404);
    }

    private function store(): BuilderStore
    {
        return new BuilderStore($this->minn->db);
    }
}
