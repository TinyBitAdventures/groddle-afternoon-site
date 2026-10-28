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
 *
 * The ledger (Ledger, G335) holds the character's currants and tradeable
 * things: a write is checked against it inside the same transaction, and
 * what the write cannot account for is taken out of the stored save and
 * named in the answer ("ledger": {"corrected": {"items": {...},
 * "currants": n}, "text": "…"}), for the game to take out of the page too.
 * A read first applies any settled trade the stored save has not (a new
 * version then), so a reload never misses a trade or keeps what it gave.
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
        $db = $this->minn->db;
        $table = $db->table(self::TABLE);
        $user = $this->minn->reader->userId;
        self::refuseSuspended($db, (int) $user);
        $row = Ledger::atomically($db, function () use ($db, $table, $user): ?array {
            $row = $db->row("SELECT data, version, updated_at FROM {$table} WHERE user_id = ? FOR UPDATE", [$user]);
            if ($row === null) {
                return null;
            }
            $save = SaveShape::decode((string) $row['data']);
            $entries = $save === null ? [] : array_filter((array) $save->entries, 'is_string');
            $caught = Ledger::fromSeams($this->minn)->catchUp($user, $entries, time());
            if ($caught['changed'] && $save !== null) {
                $now = gmdate('Y-m-d H:i:s');
                $json = SaveShape::encode(['kind' => $save->kind, 'format' => $save->format, 'entries' => $caught['entries']]);
                $db->execute("UPDATE {$table} SET data = ?, version = version + 1, updated_at = ? WHERE user_id = ?", [$json, $now, $user]);
                return ['data' => $json, 'version' => (int) $row['version'] + 1, 'updated_at' => $now];
            }
            return $row;
        });
        if ($row === null) {
            return Response::json(['version' => 0, 'updatedAt' => null, 'data' => null]);
        }
        return Response::json(['version' => (int) $row['version'], 'updatedAt' => SaveShape::time((string) $row['updated_at']), 'data' => SaveShape::decode((string) $row['data'])]);
    }

    /** Writes the whole save over the version it was made from; 409 when the server holds another. */
    #[Route(Method::Put, '/groddle/v1/save', policy: new Policy(Access::SignedIn))]
    public function write(Request $request): Response
    {
        self::refuseSuspended($this->minn->db, (int) $this->minn->reader->userId);
        $body = $request->json();
        $version = $body['version'] ?? null;
        if (!is_int($version) || $version < 0) {
            throw new RestError('groddle_save_version', 'A save names the version it was made from.', 400);
        }
        $save = SaveShape::check($body['data'] ?? null, SaveShape::MULTIPLAYER);
        $db = $this->minn->db;
        $table = $db->table(self::TABLE);
        $user = $this->minn->reader->userId;
        $now = gmdate('Y-m-d H:i:s');
        [$json, $checked] = Ledger::atomically($db, function () use ($db, $table, $user, $version, $save, $now): array {
            // The save row first (its lock orders this write after any other), then the ledger.
            $held = $db->value("SELECT version FROM {$table} WHERE user_id = ? FOR UPDATE", [$user]);
            if (($held === null && $version !== 0) || ($held !== null && (int) $held !== $version)) {
                throw new RestError('groddle_save_conflict', 'This character was saved somewhere else after this copy was loaded.', 409, ['version' => (int) ($held ?? 0)]);
            }
            $checked = Ledger::fromSeams($this->minn)->write($user, $save['entries'], time());
            $json = SaveShape::encode(['kind' => $save['kind'], 'format' => $save['format'], 'entries' => $checked['entries']]);
            $written = $held === null
                ? $db->execute("INSERT IGNORE INTO {$table} (user_id, data, version, updated_at) VALUES (?, ?, 1, ?)", [$user, $json, $now])
                : $db->execute("UPDATE {$table} SET data = ?, version = version + 1, updated_at = ? WHERE user_id = ? AND version = ?", [$json, $now, $user, $version]);
            if ($written === 0) {
                throw new RestError('groddle_save_conflict', 'This character was saved somewhere else after this copy was loaded.', 409, ['version' => (int) ($db->value("SELECT version FROM {$table} WHERE user_id = ?", [$user]) ?? 0)]);
            }
            return [$json, $checked];
        });
        $answer = ['version' => $version + 1, 'updatedAt' => SaveShape::time($now), 'size' => strlen($json)];
        if (!LedgerMath::nothing($checked['clamped'])) {
            $answer['ledger'] = ['corrected' => ['items' => (object) $checked['clamped']['items'], 'currants' => $checked['clamped']['currants']], 'text' => $checked['text']];
        }
        return Response::json($answer);
    }

    /** A suspended account (AdminConsole bans) neither loads nor writes its multiplayer save. */
    private static function refuseSuspended(\Minn\Db $db, int $user): void
    {
        if (AdminConsole::banned($db, $user) !== null) {
            throw new RestError('groddle_suspended', AdminConsole::SUSPENDED, 403);
        }
    }
}
