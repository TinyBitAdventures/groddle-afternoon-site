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
 * groddle/v1/save: the signed-in player's one multiplayer character, the
 * whole save in one row (table groddle_mp_saves). The game reads it once
 * before it boots and writes it back whole. Each write names the version it
 * was made from, and a write from an older copy (another tab, another
 * device) is refused with 409 and the version the server holds, so a stale
 * copy never overwrites a newer one. REST rules: the sign-in cookie with
 * its nonce, or nobody.
 */
final readonly class MultiplayerSave
{
    public const TABLE = 'groddle_mp_saves';

    public function __construct(private Seams $minn)
    {
    }

    /** The player's save and its version; version 0 and no data before the first write. */
    #[Route(Method::Get, '/groddle/v1/save', policy: new Policy(Access::SignedIn))]
    public function read(Request $request): Response
    {
        $table = $this->minn->db->table(self::TABLE);
        $row = $this->minn->db->row("SELECT data, version, updated_at FROM {$table} WHERE user_id = ?", [$this->minn->reader->userId]);
        if ($row === null) {
            return Response::json(['version' => 0, 'updatedAt' => null, 'data' => null]);
        }
        return Response::json(['version' => (int) $row['version'], 'updatedAt' => SaveShape::time((string) $row['updated_at']), 'data' => SaveShape::decode((string) $row['data'])]);
    }

    /** Writes the whole save over the version it was made from; 409 when the server holds another. */
    #[Route(Method::Put, '/groddle/v1/save', policy: new Policy(Access::SignedIn))]
    public function write(Request $request): Response
    {
        $body = $request->json();
        $version = $body['version'] ?? null;
        if (!is_int($version) || $version < 0) {
            throw new RestError('groddle_save_version', 'A save names the version it was made from.', 400);
        }
        $json = SaveShape::encode(SaveShape::check($body['data'] ?? null, SaveShape::MULTIPLAYER));
        $db = $this->minn->db;
        $table = $db->table(self::TABLE);
        $user = $this->minn->reader->userId;
        $now = gmdate('Y-m-d H:i:s');
        $written = $version === 0
            ? $db->execute("INSERT IGNORE INTO {$table} (user_id, data, version, updated_at) VALUES (?, ?, 1, ?)", [$user, $json, $now])
            : $db->execute("UPDATE {$table} SET data = ?, version = version + 1, updated_at = ? WHERE user_id = ? AND version = ?", [$json, $now, $user, $version]);
        if ($written === 0) {
            $held = (int) ($db->value("SELECT version FROM {$table} WHERE user_id = ?", [$user]) ?? 0);
            throw new RestError('groddle_save_conflict', 'This character was saved somewhere else after this copy was loaded.', 409, ['version' => $held]);
        }
        return Response::json(['version' => $version + 1, 'updatedAt' => SaveShape::time($now), 'size' => strlen($json)]);
    }
}
