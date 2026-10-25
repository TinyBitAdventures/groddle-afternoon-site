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
 * groddle/v1/backups: copies of a signed-in player's solo saves, kept in a
 * few numbered slots (table groddle_solo_backups) so a solo game can move
 * to another browser or come back after one is cleared. A backup is only
 * ever restored into a browser as a solo game: nothing here reads or
 * writes the multiplayer save, and a backup takes only a solo save's shape.
 */
final readonly class SoloBackups
{
    public const TABLE = 'groddle_solo_backups';

    /** How many backups one player keeps. */
    public const SLOTS = 3;

    public function __construct(private Seams $minn)
    {
    }

    /** The player's backups, newest first, without their data. */
    #[Route(Method::Get, '/groddle/v1/backups', policy: new Policy(Access::SignedIn))]
    public function list(Request $request): Response
    {
        $table = $this->minn->db->table(self::TABLE);
        $rows = $this->minn->db->rows("SELECT slot, made_at, game_day, size FROM {$table} WHERE user_id = ? ORDER BY made_at DESC, slot ASC", [$this->minn->reader->userId]);
        return Response::json(['slots' => self::SLOTS, 'maxBytes' => SaveShape::MAX_BYTES, 'backups' => array_map(self::meta(...), $rows)]);
    }

    /** One backup with its save. */
    #[Route(Method::Get, '/groddle/v1/backups/{slot:\d+}', policy: new Policy(Access::SignedIn))]
    public function read(Request $request, string $slot): Response
    {
        $table = $this->minn->db->table(self::TABLE);
        $row = $this->minn->db->row("SELECT slot, data, made_at, game_day, size FROM {$table} WHERE user_id = ? AND slot = ?", [$this->minn->reader->userId, self::slot($slot)]);
        if ($row === null) {
            throw new RestError('groddle_backup_missing', 'There is no backup in that slot.', 404);
        }
        return Response::json(self::meta($row) + ['data' => SaveShape::decode((string) $row['data'])]);
    }

    /** Backs a solo save up into a slot, replacing what the slot held. */
    #[Route(Method::Put, '/groddle/v1/backups/{slot:\d+}', policy: new Policy(Access::SignedIn))]
    public function write(Request $request, string $slot): Response
    {
        $number = self::slot($slot);
        $body = $request->json();
        $json = SaveShape::encode(SaveShape::check($body['data'] ?? null, SaveShape::SOLO));
        $day = is_string($body['gameDay'] ?? null) ? $body['gameDay'] : '';
        if (preg_match('/^[\w .:,\/-]{0,64}$/u', $day) !== 1) {
            throw new RestError('groddle_backup_day', 'The game day is not one the game writes.', 400);
        }
        $now = gmdate('Y-m-d H:i:s');
        $table = $this->minn->db->table(self::TABLE);
        $this->minn->db->execute("REPLACE INTO {$table} (user_id, slot, data, made_at, game_day, size) VALUES (?, ?, ?, ?, ?, ?)", [$this->minn->reader->userId, $number, $json, $now, $day, strlen($json)]);
        return Response::json(self::meta(['slot' => $number, 'made_at' => $now, 'game_day' => $day, 'size' => strlen($json)]));
    }

    /** Empties a slot. */
    #[Route(Method::Delete, '/groddle/v1/backups/{slot:\d+}', policy: new Policy(Access::SignedIn))]
    public function delete(Request $request, string $slot): Response
    {
        $number = self::slot($slot);
        $table = $this->minn->db->table(self::TABLE);
        if ($this->minn->db->execute("DELETE FROM {$table} WHERE user_id = ? AND slot = ?", [$this->minn->reader->userId, $number]) === 0) {
            throw new RestError('groddle_backup_missing', 'There is no backup in that slot.', 404);
        }
        return Response::json(['deleted' => true, 'slot' => $number]);
    }

    private static function slot(string $slot): int
    {
        $number = (int) $slot;
        if ($number < 1 || $number > self::SLOTS) {
            throw new RestError('groddle_backup_slot', 'Backups are kept in slots 1 to ' . self::SLOTS . '.', 400, ['slots' => self::SLOTS]);
        }
        return $number;
    }

    /** @param array<string, mixed> $row */
    private static function meta(array $row): array
    {
        return ['slot' => (int) $row['slot'], 'madeAt' => SaveShape::time((string) $row['made_at']), 'gameDay' => (string) $row['game_day'], 'size' => (int) $row['size']];
    }
}
