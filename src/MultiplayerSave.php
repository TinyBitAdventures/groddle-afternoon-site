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
 * Which window plays (ticket 21, 2026-11-01): each game page names itself
 * (writer, a random id per page load) and numbers its writes (seq). The
 * page's boot read claims the save for it (?writer=), so the window that
 * loaded last is the one that saves, as the realtime server lets the newest
 * connection play (hub.go close 4001). A write from the claiming page always
 * goes in when it is newer than the page's last one, whatever version it
 * names: the page's own earlier write whose answer was lost (Safari "Load
 * failed"), or its keepalive write beside one in flight, can no longer stop
 * it saving. An older write of the same page is answered "superseded" and
 * changes nothing. A write from a page the save was claimed away from is
 * refused (409 groddle_save_elsewhere), and one over a version someone else
 * wrote (an old client, an administrator's teleport) is refused as before
 * (409 groddle_save_conflict); either answer names the version, when it was
 * saved and the other copy's savedAt, so the game can show the player the
 * choice. The player's "keep this window's game" is a takeover write: it
 * claims the save back, after the settled trades this copy has not applied
 * since it was last saved are applied to it (Ledger::reapply), so nothing
 * a trade, gift, sale or pickup moved is kept twice. ?peek=1 reads without
 * claiming or catching up (the phone companion).
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

    /** A page's name for itself (js/save-store.js): letters and digits. */
    private const WRITER = '/^[A-Za-z0-9]{8,32}$/';

    public function __construct(private Seams $minn)
    {
    }

    /**
     * The player's save and its version; version 0 and no data before the first write.
     * ?writer=<page id> claims it for that page; ?peek=1 neither claims nor catches up.
     */
    #[Route(Method::Get, '/groddle/v1/save', policy: new Policy(Access::SignedIn))]
    public function read(Request $request): Response
    {
        $db = $this->minn->db;
        $table = $db->table(self::TABLE);
        $user = $this->minn->reader->userId;
        self::refuseSuspended($db, (int) $user);
        if ($request->query('peek') === '1') {
            $row = $db->row("SELECT data, version, updated_at FROM {$table} WHERE user_id = ?", [$user]);
            return $row === null
                ? Response::json(['version' => 0, 'updatedAt' => null, 'data' => null])
                : Response::json(['version' => (int) $row['version'], 'updatedAt' => SaveShape::time((string) $row['updated_at']), 'data' => SaveShape::decode((string) $row['data'])]);
        }
        $writer = self::writerOf($request->query('writer'));
        $row = Ledger::atomically($db, function () use ($db, $table, $user, $writer): ?array {
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
                // The caught-up copy is nobody's page: only the reader that claims it may write over it unasked.
                $db->execute("UPDATE {$table} SET data = ?, version = version + 1, updated_at = ?, writer = ?, seq = 0 WHERE user_id = ?", [$json, $now, $writer, $user]);
                return ['data' => $json, 'version' => (int) $row['version'] + 1, 'updated_at' => $now];
            }
            if ($writer !== '') {
                // The newest window plays: the page that loads the save now is the one that writes it.
                $db->execute("UPDATE {$table} SET writer = ?, seq = 0 WHERE user_id = ?", [$writer, $user]);
            }
            return $row;
        });
        if ($row === null) {
            return Response::json(['version' => 0, 'updatedAt' => null, 'data' => null]);
        }
        return Response::json(['version' => (int) $row['version'], 'updatedAt' => SaveShape::time((string) $row['updated_at']), 'data' => SaveShape::decode((string) $row['data'])]);
    }

    /**
     * What a write does, from the stored row (null: no row yet) and what the write names:
     * write (the version it names, or the claiming page's newer write), superseded (an
     * older write of the claiming page: nothing changes), elsewhere (another page claimed
     * the save since this one loaded), conflict (someone else wrote over the version it
     * names), takeover (the player keeps this window's game).
     */
    public static function decide(?int $held, string $storedWriter, int $storedSeq, int $version, string $writer, int $seq, bool $takeover): string
    {
        if ($held === null) {
            return $version === 0 || ($takeover && $writer !== '') ? 'write' : 'conflict';
        }
        if ($writer !== '' && $takeover) {
            return 'takeover';
        }
        if ($writer !== '' && $writer === $storedWriter) {
            return $seq > $storedSeq ? 'write' : 'superseded';
        }
        if ($writer !== '' && $storedWriter !== '') {
            return 'elsewhere';
        }
        return $held === $version ? 'write' : 'conflict';
    }

    /** Writes the whole save over the version it was made from; 409 when the server holds another (see decide). */
    #[Route(Method::Put, '/groddle/v1/save', policy: new Policy(Access::SignedIn))]
    public function write(Request $request): Response
    {
        self::refuseSuspended($this->minn->db, (int) $this->minn->reader->userId);
        $body = $request->json();
        $version = $body['version'] ?? null;
        if (!is_int($version) || $version < 0) {
            throw new RestError('groddle_save_version', 'A save names the version it was made from.', 400);
        }
        $writer = self::writerOf($body['writer'] ?? null);
        $seq = $writer !== '' && is_int($body['seq'] ?? null) && $body['seq'] > 0 ? (int) $body['seq'] : 0;
        $takeover = $writer !== '' && ($body['takeover'] ?? false) === true;
        $since = is_string($body['since'] ?? null) ? strtotime((string) $body['since']) : false;
        $save = SaveShape::check($body['data'] ?? null, SaveShape::MULTIPLAYER);
        $db = $this->minn->db;
        $table = $db->table(self::TABLE);
        $user = $this->minn->reader->userId;
        $now = gmdate('Y-m-d H:i:s');
        $out = Ledger::atomically($db, function () use ($db, $table, $user, $version, $writer, $seq, $takeover, $since, $save, $now): array {
            // The save row first (its lock orders this write after any other), then the ledger.
            $row = $db->row("SELECT version, writer, seq, updated_at, data FROM {$table} WHERE user_id = ? FOR UPDATE", [$user]);
            $held = $row === null ? null : (int) $row['version'];
            $did = self::decide($held, (string) ($row['writer'] ?? ''), (int) ($row['seq'] ?? 0), $version, $writer, $seq, $takeover);
            if ($did === 'superseded') {
                return ['superseded' => true, 'version' => (int) $held, 'updated_at' => (string) $row['updated_at']];
            }
            if ($did === 'elsewhere' || $did === 'conflict') {
                throw self::refusal($did, $row);
            }
            $ledger = Ledger::fromSeams($this->minn);
            $entries = $save['entries'];
            if ($did === 'takeover') {
                // Kept over a copy saved elsewhere: what trades moved since this copy was last saved comes first.
                // The client names when this copy was last saved; never later than the stored copy's own save.
                $entries = $ledger->reapply($user, $entries, Ledger::takeoverSince(is_int($since) ? $since : 0, $row === null ? null : (string) $row['updated_at']), time());
            }
            $checked = $ledger->write($user, $entries, time());
            $json = SaveShape::encode(['kind' => $save['kind'], 'format' => $save['format'], 'entries' => $checked['entries']]);
            $written = $held === null
                ? $db->execute("INSERT IGNORE INTO {$table} (user_id, data, version, updated_at, writer, seq) VALUES (?, ?, 1, ?, ?, ?)", [$user, $json, $now, $writer, $seq])
                : $db->execute("UPDATE {$table} SET data = ?, version = version + 1, updated_at = ?, writer = ?, seq = ? WHERE user_id = ? AND version = ?", [$json, $now, $writer, $seq, $user, $held]);
            if ($written === 0) {
                throw self::refusal('conflict', $db->row("SELECT version, writer, seq, updated_at, data FROM {$table} WHERE user_id = ?", [$user]));
            }
            return ['json' => $json, 'checked' => $checked, 'version' => ($held ?? 0) + 1, 'took' => $did === 'takeover'];
        });
        if (isset($out['superseded'])) {
            return Response::json(['version' => $out['version'], 'updatedAt' => SaveShape::time($out['updated_at']), 'superseded' => true]);
        }
        $checked = $out['checked'];
        $answer = ['version' => $out['version'], 'updatedAt' => SaveShape::time($now), 'size' => strlen($out['json'])];
        if ($out['took']) {
            $answer['took'] = true;
        }
        if (!LedgerMath::nothing($checked['clamped'])) {
            $answer['ledger'] = ['corrected' => ['items' => (object) $checked['clamped']['items'], 'currants' => $checked['clamped']['currants']], 'text' => $checked['text']];
        }
        return Response::json($answer);
    }

    /** A page's name for itself, or '' (an older game, or none given). */
    private static function writerOf(mixed $value): string
    {
        return is_string($value) && preg_match(self::WRITER, $value) === 1 ? $value : '';
    }

    /** The 409 for a refused write: the version held, when it was saved and the stored copy's own savedAt. */
    private static function refusal(string $why, ?array $row): RestError
    {
        $savedAt = 0;
        if ($row !== null) {
            $stored = SaveShape::decode((string) ($row['data'] ?? ''));
            $state = is_string($stored?->entries?->playerState ?? null) ? json_decode((string) $stored->entries->playerState, true) : null;
            $savedAt = is_array($state) && is_numeric($state['savedAt'] ?? null) ? (int) $state['savedAt'] : 0;
        }
        $data = ['version' => (int) ($row['version'] ?? 0), 'updatedAt' => $row === null ? null : SaveShape::time((string) $row['updated_at']), 'savedAt' => $savedAt];
        return $why === 'elsewhere'
            ? new RestError('groddle_save_elsewhere', 'This character was opened in another window or device after this copy was loaded.', 409, $data)
            : new RestError('groddle_save_conflict', 'This character was saved somewhere else after this copy was loaded.', 409, $data);
    }

    /** A suspended account (AdminConsole bans) neither loads nor writes its multiplayer save. */
    private static function refuseSuspended(\Minn\Db $db, int $user): void
    {
        if (AdminConsole::banned($db, $user) !== null) {
            throw new RestError('groddle_suspended', AdminConsole::SUSPENDED, 403);
        }
    }
}
