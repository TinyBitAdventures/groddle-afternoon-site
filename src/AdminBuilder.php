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
 * P1): groddle/v1/admin/builder/*, every route manage_options only, as the console's (the editor
 * page is AdminBuilderPage, /console/builder/). Off unless the site option groddle_builder_enabled
 * is on (groddle.localhost only for now, spec Q2): with it unset every route answers 404
 * builder_disabled, so a release ships the builder dark.
 *
 *   GET    builder/drafts[?all=1]              {"drafts":[{id,title,rev,state,streets:[{key,tsid,label}],hub:{id,name,isNew},updatedBy,updatedAt,createdAt}]}
 *   POST   builder/drafts {id,title,hub?}      201 the zone (rev 1)
 *   GET    builder/drafts/{id}[?rev=n]         the zone; ETag "<rev>"; If-None-Match "<rev>" answers 304
 *   PUT    builder/drafts/{id} <zone>          If-Match: <rev> → {"rev"}; 409 builder_rev_conflict {rev}, 413, 422
 *   POST   builder/drafts/{id}/ops {ops,note}  If-Match → {"rev","changed":[...]}; a failing op: 422 builder_invalid {op}
 *   GET    builder/drafts/{id}/revisions       {"revisions":[{rev,at,author,note,ops,bytes}]}
 *   POST   builder/drafts/{id}/restore {rev}   If-Match → {"rev"}: the old rev copied forward
 *   GET    builder/drafts/{id}/play            the play-test bundle (js/builder/draft-play.js)
 *   DELETE builder/drafts/{id}                 archived, never deleted
 *
 * A write without If-Match is refused (428 builder_rev_required). Errors keep the site's shape,
 * {code, message, data:{status, ...}}.
 */
final readonly class AdminBuilder
{
    public const OPTION = 'groddle_builder_enabled';
    private const ADMIN = 'manage_options';
    private const BASE = '/groddle/v1/admin/builder';

    public function __construct(private Seams $minn)
    {
    }

    /** Whether the builder is on for this site (the option groddle_builder_enabled). */
    public static function enabled(Seams $minn): bool
    {
        return in_array(strtolower(trim((string) ($minn->site->option(self::OPTION) ?? ''))), ['1', 'yes', 'true', 'on'], true);
    }

    #[Route(Method::Get, self::BASE . '/drafts', policy: new Policy(Access::Cap, self::ADMIN))]
    public function drafts(Request $request): Response
    {
        $this->gate();
        return Response::json(['drafts' => $this->store()->list($request->query('all') === '1')])->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Post, self::BASE . '/drafts', policy: new Policy(Access::Cap, self::ADMIN))]
    public function create(Request $request): Response
    {
        $admin = $this->gate();
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
        $zone = $this->store()->create($id, $title, $hub, (string) $admin, $admin);
        return Response::json($zone, 201)->withHeader('ETag', '"1"')->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Get, self::BASE . '/drafts/{id:[a-z0-9-]+}', policy: new Policy(Access::Cap, self::ADMIN))]
    public function draft(Request $request, string $id): Response
    {
        $this->gate();
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

    #[Route(Method::Put, self::BASE . '/drafts/{id:[a-z0-9-]+}', policy: new Policy(Access::Cap, self::ADMIN))]
    public function replace(Request $request, string $id): Response
    {
        $admin = $this->gate();
        $ifMatch = self::ifMatch($request);
        self::sized($request);
        $next = BuilderOps::decode($request->body);
        if (!$next instanceof \stdClass) {
            throw new RestError('builder_invalid', 'Send the zone as a JSON object.', 422);
        }
        $done = $this->store()->write($id, $ifMatch, static fn (\stdClass $zone): array => [$next, 'replaced the zone', 1], (string) $admin, $admin, 'replaced');
        return Response::json(['rev' => $done['rev']])->withHeader('ETag', '"' . $done['rev'] . '"');
    }

    #[Route(Method::Post, self::BASE . '/drafts/{id:[a-z0-9-]+}/ops', policy: new Policy(Access::Cap, self::ADMIN))]
    public function ops(Request $request, string $id): Response
    {
        $admin = $this->gate();
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
        }, (string) $admin, $admin, $note);
        return Response::json(['rev' => $done['rev'], 'changed' => $done['extra']['changed'] ?? []])->withHeader('ETag', '"' . $done['rev'] . '"');
    }

    #[Route(Method::Get, self::BASE . '/drafts/{id:[a-z0-9-]+}/revisions', policy: new Policy(Access::Cap, self::ADMIN))]
    public function revisions(Request $request, string $id): Response
    {
        $this->gate();
        $this->row($id);
        return Response::json(['revisions' => $this->store()->revisions($id)])->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Post, self::BASE . '/drafts/{id:[a-z0-9-]+}/restore', policy: new Policy(Access::Cap, self::ADMIN))]
    public function restore(Request $request, string $id): Response
    {
        $admin = $this->gate();
        $ifMatch = self::ifMatch($request);
        $body = BuilderOps::decode($request->body);
        $rev = is_object($body) && is_int($body->rev ?? null) ? $body->rev : 0;
        $old = $this->store()->revision($id, $rev) ?? throw new RestError('builder_not_found', 'That draft has no such rev.', 404);
        $done = $this->store()->write($id, $ifMatch, static fn (\stdClass $zone): array => [$old, "restored rev {$rev}", 1], (string) $admin, $admin, "restored rev {$rev}");
        return Response::json(['rev' => $done['rev'], 'restored' => $rev])->withHeader('ETag', '"' . $done['rev'] . '"');
    }

    #[Route(Method::Delete, self::BASE . '/drafts/{id:[a-z0-9-]+}', policy: new Policy(Access::Cap, self::ADMIN))]
    public function archive(Request $request, string $id): Response
    {
        $admin = $this->gate();
        $this->store()->archive($id, $admin);
        return Response::json(['id' => $id, 'state' => 'archived']);
    }

    /**
     * The play-test bundle (spec 6.8): the zone's streets at the current rev, their index rows
     * (no file: js/street-loader.js registerDraftStreets primes them), their population as
     * ecology rows, the street keys, the hub, and the zone's edits on shipped streets (the
     * play-test puts them on copies of those streets, so the ways into the zone show there too).
     */
    #[Route(Method::Get, self::BASE . '/drafts/{id:[a-z0-9-]+}/play', policy: new Policy(Access::Cap, self::ADMIN))]
    public function play(Request $request, string $id): Response
    {
        $this->gate();
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
        ];
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

    /** The caller's id, once the builder is on. */
    private function gate(): int
    {
        if (!self::enabled($this->minn)) {
            throw new RestError('builder_disabled', 'The level builder is not turned on for this site.', 404);
        }
        $user = $this->minn->user();
        if ($user === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        return $user->id;
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
