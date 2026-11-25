<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Db;
use Minn\Extension\Seams;
use Minn\Http\Access;
use Minn\Http\Method;
use Minn\Http\Policy;
use Minn\Http\Request;
use Minn\Http\Response;
use Minn\Http\Route;
use Minn\RestError;

/**
 * The shared state of the Giant Icons placed in a home (#697, register G1302; Austin 2026-11-25:
 * "match Glitch's shared use of icons"). In the source an Icon set down is one item in the
 * location, and what it keeps is its own, for everyone (items/include/icon.js): the actions it
 * has left (instanceProps.actions_remaining, 20 when made; use 360-376 takes one for every
 * ruminate, revere and reflect, by anyone) and its last tither (this.tither: icon_tithe 297-328
 * sets it to whoever paid; the next ruminate, revere, reflect or bestowment of that player is
 * doubled and clears it, 87-94, 401-480). Whose a use is, once a game day per Icon class, stays the
 * player's own (pc.achievements_get_daily_label_count, canUse 352-356): kept in each player's
 * save, never here. A bestowment's minute is the player's too (achievements_place_time_ago_enough,
 * canBestow 330-338). A tithe's currants leave the payer and go to nobody (stats_try_remove_currants).
 *
 * The owner's save says where each Icon stands (the game's giant-icons slice, Neighbours::iconRows);
 * this table keeps, from the first use that came through here, each one's actions and last tither,
 * so every use, the owner's online ones too, is checked and spent in one place:
 *
 *   POST groddle/v1/homes/{owner}/icons  {"icon":"icon3","place":"street"|"house"|"house:1"|"tower:2","act":"ruminate|revere|reflect|tithe|bestow"}
 *        200 {"ok":true,"icon":Icon,"tither":bool,"tell":"<proof>"} | {"ok":false,"code":"tithe_due|no_tithe|not_tither","text","icon":Icon}
 *   GET  groddle/v1/homes/icons          the signed-in owner's Icons as this table keeps them: {"icons":[Icon]}
 *
 * Icon: {"id","place","actions","tither": the last tither's id or null}. A use with no actions left
 * is refused ("This Icon requires a tithe ..."; the game words it with the payer's cost), a tithe
 * above 10 actions is refused ("This Icon does not require a tithe."), a tithe at 10 or fewer adds 5
 * and makes the payer the last tither; a bestowment comes here only from the last tither, whose
 * double it spends (not_tither: someone tithed since, so it is not doubled). The payer's level,
 * currants and daily uses are the payer's game's to check, as the source checked the pc.
 *
 * Two at once never spend one action twice: each act locks the owner's save row (FOR UPDATE, as
 * HomeTending does) and reads the Icon's row inside that lock. The owner offline: visitors use the
 * Icon all the same; the owner's game reads its Icons here when it next loads (GET) or at once
 * through the realtime server's home_icon_tell proof, which tells everyone there the new count.
 * A row whose Icon the stored save no longer has in a home (picked up) goes on the owner's GET.
 *
 * Who may: the owner, or a visitor Neighbours lets visit (not blocked either way, neither in the
 * tutorial); the house's Icons only to whoever may enter it (G1018, Neighbours::mayEnter, ?in=).
 */
final readonly class HomeIcons
{
    public const TABLE = 'groddle_home_icons';

    public const USES = ['ruminate', 'revere', 'reflect'];
    public const ACTS = ['ruminate', 'revere', 'reflect', 'tithe', 'bestow'];

    /** include/icon.js icon_tithe_conditions 248-250, icon_tithe 309-313. */
    public const TITHE_AT_MOST = 10;
    public const TITHE_ADDS = 5;

    public const TELL_TTL = 120;

    public const TEXT_GONE = 'That Icon is not here any more.';
    /** icon_ruminate_conditions 57-60 (the game adds the payer's cost). */
    public const TEXT_TITHE_DUE = 'This Icon requires a tithe to become operational.';
    /** icon_tithe_conditions 248-250. */
    public const TEXT_NO_TITHE = 'This Icon does not require a tithe.';
    /** Ours: a bestowment's double that someone else's tithe took first. */
    public const TEXT_NOT_TITHER = 'Someone else tithed this Icon since.';
    public const TEXT_HOUSE = 'Only those let into the house can reach that Icon.';

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Post, '/groddle/v1/homes/{id:\d+}/icons', policy: new Policy(Access::SignedIn))]
    public function act(Request $request, string $id): Response
    {
        $owner = (int) $id;
        $me = $this->minn->reader->userId;
        $body = $request->json();
        $icon = is_string($body['icon'] ?? null) ? $body['icon'] : '';
        $place = is_string($body['place'] ?? null) ? $body['place'] : '';
        $act = is_string($body['act'] ?? null) ? $body['act'] : '';
        if (preg_match('/^icon[0-9]{1,9}$/', $icon) !== 1 || (preg_match('/^(street|tower:[0-9])$/', $place) !== 1 && !Neighbours::isHousePlace($place)) || !in_array($act, self::ACTS, true)) {
            throw new RestError('groddle_icon_invalid', 'Name the Icon, where it stands and what to do.', 400);
        }
        $db = $this->minn->db;
        if ($owner !== $me) {
            $ignores = $db->table(Buddies::IGNORES);
            if ($db->value("SELECT 1 FROM {$ignores} WHERE (user_id = ? AND ignored_id = ?) OR (user_id = ? AND ignored_id = ?)", [$owner, $me, $me, $owner]) !== null) {
                throw new RestError('groddle_icon_blocked', Neighbours::TEXT_BLOCKED, 403);
            }
            // R5 (G1313): any floor of the house, to whoever may enter it.
            if (Neighbours::isHousePlace($place) && !(new Neighbours($this->minn))->mayEnter($request, $owner, $me)) {
                throw new RestError('groddle_icon_house', self::TEXT_HOUSE, 403);
            }
            if (Neighbours::inTutorial(Neighbours::state($db, $me))) {
                throw new RestError('groddle_home_newxp_us', Neighbours::TEXT_NEWXP_US, 403);
            }
        }
        $out = Ledger::atomically($db, function () use ($db, $owner, $me, $icon, $place, $act): array {
            $saves = $db->table(MultiplayerSave::TABLE);
            $db->value("SELECT version FROM {$saves} WHERE user_id = ? FOR UPDATE", [$owner]);
            $state = Neighbours::state($db, $owner);
            if (!Neighbours::hasHome($state)) {
                throw new RestError('groddle_home_none', Neighbours::TEXT_NO_HOUSE, 404);
            }
            if ($owner !== $me && Neighbours::inTutorial($state)) {
                throw new RestError('groddle_home_newxp', Neighbours::TEXT_NEWXP, 403);
            }
            $saved = null;
            foreach (Neighbours::iconRows($state, PHP_INT_MAX) as $r) {
                if ($r['id'] === $icon && $r['place'] === $place) {
                    $saved = $r;
                }
            }
            if ($saved === null) {
                throw new RestError('groddle_icon_gone', self::TEXT_GONE, 404);
            }
            $t = $db->table(self::TABLE);
            $row = $db->row("SELECT actions, tither FROM {$t} WHERE owner_id = ? AND icon = ?", [$owner, $icon]);
            $now = [
                'id' => $icon,
                'place' => $place,
                'actions' => $row !== null ? (int) $row['actions'] : $saved['actions'],
                'tither' => $row !== null ? ($row['tither'] === null ? null : (int) $row['tither']) : $saved['tither'],
            ];
            $res = self::apply($now, $act, $me);
            if (!$res['ok']) {
                return $res + ['icon' => $now];
            }
            $after = $res['icon'];
            $ms = (int) floor(microtime(true) * 1000);
            $db->execute(
                "INSERT INTO {$t} (owner_id, icon, place, actions, tither, updated_ms) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE place = VALUES(place), actions = VALUES(actions), tither = VALUES(tither), updated_ms = VALUES(updated_ms)",
                [$owner, $icon, $place, $after['actions'], $after['tither'], $ms]
            );
            return $res + ['at' => $ms];
        });
        if ($out['ok']) {
            $out['tell'] = $this->tell($me, $owner, $act, $out['icon'], (int) $out['at']);
            unset($out['at']);
        }
        return Response::json($out);
    }

    /** The owner's own Icons as this table keeps them; a row whose Icon the stored save no longer has in a home goes. */
    #[Route(Method::Get, '/groddle/v1/homes/icons', policy: new Policy(Access::SignedIn))]
    public function mine(Request $request): Response
    {
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        $placed = [];
        foreach (Neighbours::iconRows(Neighbours::state($db, $me), PHP_INT_MAX) as $r) {
            $placed[$r['id']] = $r['place'];
        }
        $t = $db->table(self::TABLE);
        $out = [];
        foreach ($db->rows("SELECT icon, place, actions, tither FROM {$t} WHERE owner_id = ?", [$me]) as $r) {
            if (!isset($placed[(string) $r['icon']])) {
                $db->execute("DELETE FROM {$t} WHERE owner_id = ? AND icon = ?", [$me, (string) $r['icon']]);
                continue;
            }
            $out[] = self::shape($r);
        }
        return Response::json(['icons' => $out]);
    }

    /**
     * One act on an Icon's shared state (pure): ['ok' => true, 'icon' => after, 'tither' => whether
     * the actor was its last tither (their double)] or ['ok' => false, 'code', 'text'].
     *
     * @param array{id: string, place: string, actions: int, tither: ?int} $icon
     */
    public static function apply(array $icon, string $act, int $actor): array
    {
        $was = $icon['tither'] !== null && $icon['tither'] === $actor;
        if (in_array($act, self::USES, true)) {
            if ($icon['actions'] <= 0) {
                return ['ok' => false, 'code' => 'tithe_due', 'text' => self::TEXT_TITHE_DUE];
            }
            $icon['actions'] -= 1;
            if ($was) {
                $icon['tither'] = null;
            }
            return ['ok' => true, 'icon' => $icon, 'tither' => $was];
        }
        if ($act === 'tithe') {
            if ($icon['actions'] > self::TITHE_AT_MOST) {
                return ['ok' => false, 'code' => 'no_tithe', 'text' => self::TEXT_NO_TITHE];
            }
            $icon['actions'] += self::TITHE_ADDS;
            $icon['tither'] = $actor;
            return ['ok' => true, 'icon' => $icon, 'tither' => false];
        }
        if (!$was) {
            return ['ok' => false, 'code' => 'not_tither', 'text' => self::TEXT_NOT_TITHER];
        }
        $icon['tither'] = null;
        return ['ok' => true, 'icon' => $icon, 'tither' => true];
    }

    /**
     * A visit's Icons (Neighbours::iconRows) with what this table keeps for each laid over the save's.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function overlay(Db $db, int $owner, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $kept = [];
        foreach ($db->rows('SELECT icon, actions, tither FROM ' . $db->table(self::TABLE) . ' WHERE owner_id = ?', [$owner]) as $r) {
            $kept[(string) $r['icon']] = $r;
        }
        foreach ($rows as $i => $r) {
            $k = $kept[$r['id']] ?? null;
            if ($k !== null) {
                $rows[$i]['actions'] = (int) $k['actions'];
                $rows[$i]['tither'] = $k['tither'] === null ? null : (int) $k['tither'];
            }
        }
        return $rows;
    }

    /** @return array{id: string, place: string, actions: int, tither: ?int} */
    private static function shape(array $r): array
    {
        return ['id' => (string) $r['icon'], 'place' => (string) $r['place'], 'actions' => (int) $r['actions'], 'tither' => $r['tither'] === null ? null : (int) $r['tither']];
    }

    /** The proof for the realtime server to tell the owner and everyone in the home at once (homes_help.go iconTell). */
    private function tell(int $me, int $owner, string $act, array $icon, int $at): ?string
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null) {
            return null;
        }
        $now = time();
        $claims = ['i' => $icon['id'], 'pl' => $icon['place'], 'a' => $act, 'n' => $icon['actions'], 't' => $icon['tither'], 'at' => $at];
        return RealtimeTicket::sign(['k' => 'home_icon', 'sub' => $me, 'o' => $owner, 'act' => $claims, 'iat' => $now, 'exp' => $now + self::TELL_TTL, 'n' => bin2hex(random_bytes(8))], $secret);
    }
}
