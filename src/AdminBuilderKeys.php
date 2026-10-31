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
 * Builder keys from the console's Builder tab (the game's docs/level-builder/spec.md 8.3, 8.4):
 * the administrator's session only, never a key (BuilderAuth with no scope), so a key can neither
 * make, list nor revoke keys.
 *
 *   GET    builder/keys                       {keys:[{id,label,scopes,createdBy,createdAt,expiresAt,lastUsedAt,revokedAt,state}], scopes, master}
 *   POST   builder/keys {label, scopes, days?} 201 {key, secret, line, env}: the secret is shown here once, never again
 *   DELETE builder/keys/{id}[?purge=1]        revoked (its nonces dropped); the row stays for the log, unless purge
 *                                             deletes it too (test keys)
 *   POST   builder/drafts/{id}/purge          an archived draft deleted for good, with its revisions and new art
 *                                             (what tools/builder/example-session.mjs cleans up with); 409 unless archived
 *
 * Each is a line in groddle_admin_actions (kind builder).
 */
final readonly class AdminBuilderKeys
{
    private const BASE = '/groddle/v1/admin/builder';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, self::BASE . '/keys', policy: new Policy(Access::Public))]
    public function list(Request $request): Response
    {
        $this->gate($request);
        return Response::json([
            'keys' => (new BuilderKeys($this->minn->db))->list(),
            'scopes' => BuilderKeys::SCOPES,
            'master' => BuilderAuth::master($this->minn) !== null,
        ])->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Post, self::BASE . '/keys', policy: new Policy(Access::Public))]
    public function create(Request $request): Response
    {
        $who = $this->gate($request);
        $master = BuilderAuth::master($this->minn) ?? throw new RestError('builder_no_master', 'This site has no builder master secret. Point the option ' . BuilderKeys::SECRET_OPTION . ' at a file outside the webroot holding at least 32 random characters.', 503);
        $body = json_decode($request->body, true, 8);
        $label = is_array($body) && is_string($body['label'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $body['label'])) : '';
        if ($label === '' || mb_strlen($label) > 60) {
            throw new RestError('builder_bad_key', 'Give the key a label of up to 60 characters.', 400);
        }
        $scopes = BuilderKeys::scopes($body['scopes'] ?? null) ?? throw new RestError('builder_bad_key', 'Scopes are some of: ' . implode(', ', BuilderKeys::SCOPES) . '.', 400);
        $days = is_int($body['days'] ?? null) ? $body['days'] : BuilderKeys::DEFAULT_DAYS;
        if ($days < 1 || $days > BuilderKeys::MAX_DAYS) {
            throw new RestError('builder_bad_key', 'A key lasts 1 to ' . BuilderKeys::MAX_DAYS . ' days.', 400);
        }
        $made = (new BuilderKeys($this->minn->db))->create($master, $label, $scopes, $days, $who->admin);
        $made['env'] = 'GRODDLE_BUILDER_URL=' . ($request->secure ? 'https' : 'http') . '://' . $request->host . "\nGRODDLE_BUILDER_KEY=" . $made['line'] . "\n";
        return Response::json($made, 201)->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Delete, self::BASE . '/keys/{id:[0-9a-f]{16}}', policy: new Policy(Access::Public))]
    public function revoke(Request $request, string $id): Response
    {
        $who = $this->gate($request);
        $keys = new BuilderKeys($this->minn->db);
        if (!$keys->revoke($id, $who->admin)) {
            throw new RestError('builder_not_found', 'No builder key has that id.', 404);
        }
        if ($request->flag('purge')) {
            $keys->purge($id, $who->admin);
            return Response::json(['id' => $id, 'state' => 'deleted'])->withHeader('Cache-Control', 'no-store');
        }
        return Response::json(['id' => $id, 'state' => 'revoked'])->withHeader('Cache-Control', 'no-store');
    }

    #[Route(Method::Post, self::BASE . '/drafts/{id:[a-z0-9-]+}/purge', policy: new Policy(Access::Public))]
    public function purge(Request $request, string $id): Response
    {
        $who = $this->gate($request);
        $db = $this->minn->db;
        $done = $db->transaction(function () use ($db, $id): array {
            $row = $db->row('SELECT title, state FROM ' . $db->table(BuilderStore::DRAFTS) . ' WHERE id = ? FOR UPDATE', [$id]) ?? throw new RestError('builder_not_found', 'No draft has that id.', 404);
            if ($row['state'] !== 'archived') {
                throw new RestError('builder_not_archived', 'Archive the draft first; only an archived draft can be deleted for good.', 409);
            }
            $revs = $db->execute('DELETE FROM ' . $db->table(BuilderStore::REVISIONS) . ' WHERE draft_id = ?', [$id]);
            $art = $db->execute('DELETE FROM ' . $db->table(BuilderAssets::TABLE) . ' WHERE draft_id = ?', [$id]);
            $db->execute('DELETE FROM ' . $db->table(BuilderStore::DRAFTS) . ' WHERE id = ?', [$id]);
            return ['title' => (string) $row['title'], 'revisions' => $revs, 'assets' => $art];
        });
        AdminConsole::recordAction($db, $who->admin, 0, 'builder', ['draft' => $id, 'title' => $done['title'], 'text' => 'purged'], 'stored');
        return Response::json(['id' => $id, 'purged' => true, 'revisions' => $done['revisions'], 'assets' => $done['assets']])->withHeader('Cache-Control', 'no-store');
    }

    private function gate(Request $request): BuilderActor
    {
        return (new BuilderAuth($this->minn))->gate($request, null);
    }
}
