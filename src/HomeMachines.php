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
 * Machines set down on a home street (lane MACHINES; Austin 2026-11-01: "Let
 * owners set an assembled machine down on their street, as the original
 * did."). A Fuelmaker, Blockmaker, Metal Machine or Woodworker the owner put
 * down stands on their home street for everyone there, as a placed machine
 * did in the original (GameServerJS machine_base, the same in the four
 * files; fuelmaker.js lines):
 *
 * - Anyone on the street may use it, refuel it and collect what they made
 *   (verbs.use 633-684, refuel 442-558, collect 560-631: no owner check).
 *   One job at a time (canUse 1011-1021): the ingredients leave the maker's
 *   pack when it starts (players/inc_making.js 306-308), it runs count *
 *   wait_ms + 2200 ms (:187, :230-231), the tank pays the job's fuel when it
 *   is done (:760-777; Better / Betterer Blockmaker take 20 / 40% off the
 *   whole job, :768-776), and the outputs wait in the machine for whoever
 *   made it (setContents 1533-1544). While yours waits you cannot start
 *   another there (use 664-668). Anyone's but the owner's is gone after 6
 *   game days (expireOldContents 1146-1158, days_to_store_contents).
 * - A finished job by someone who is not the owner adds 7 to the owner's
 *   Magic Rock pool (inc_making.js:836; HomePool, inc_cultivation.js 168-178),
 *   on the game day it finished, whether or not anyone is online. (The source
 *   credited the street the maker stood on at that moment; here it is the
 *   machine's home: the intent of bug 8964.)
 * - Only the owner or a key-holder may take it away (disassemble 322,
 *   fully_disassemble 76, pick up 372), with Engineering (316), never while
 *   it runs (325-330), and other players' output in it is destroyed when they
 *   do (verifyDisassemble 1568-1581): here it is picked up whole, its tank
 *   with it (the remake's machines are pack tools).
 * - Where it may stand (machine_stand.js canDrop 770-788): not within 200 px
 *   of another machine nor within 70 px of an end of the street. Ours: at most
 *   8 in one place; only the owner's own home: the home street, the house
 *   (its ground floor, HOUSE_<id>) and the Tower's floors (lane HOMES2, Austin
 *   2026-11-01: "Machines may stand on the home street, in the house and in
 *   the Tower"; the source allowed any location but an instance). Each row
 *   keeps its place ("street", "house", "tower:<floor>"), named by the
 *   realtime server from the street the actor stands on; the 200 px counts
 *   only machines in the same place (findCloseStack looks through one
 *   location's items, players/player.js 1152-1186), and in a room the ends
 *   are its inner walls (the house's side walls 76 px inside each end
 *   segment, a Tower floor's 130 to 1102: the game's home-decor
 *   interiorBounds, data "rooms").
 * - Only a machine on the home street pays the owner's pool: the house is not
 *   public (inc_cultivation.js:170) and the Magic Rock reads only the home
 *   street's rewards (players/inc_houses.js 1706-1709), so a Tower's were
 *   never collected.
 *
 * The Community Machine Rooms (lane machines, report #152; Austin
 * 2026-11-08: "Allow machines in the community machine rooms only."): a
 * player may also set their own machine down in the Meadow, Besara or Forest
 * room (config_prod.js:1332 machine_rooms), place "mr:meadow", "mr:besara",
 * "mr:forest", named by the realtime server from the room the actor stands
 * in. The row's owner is whoever set it down. There canDrop counts every
 * machine in the room, everyone's and the room's own (data "machineRooms":
 * the room's ends and its own machines' x, from the game's
 * js/machine-rooms.js), anyone uses, refuels and collects as anywhere, a
 * room is no POL so everyone's output goes after 6 game days, the owner's
 * too (expireOldContents 1146-1158), no pool is paid (no home), and only the
 * owner picks it up (ours: the source hid disassemble in a machine room,
 * isInCraftingAltar, fuelmaker.js 64 and 310, so nobody could). A machine
 * stays where it was set down while its owner is away, or for good.
 *
 * Everything that moves items goes through the ledger (G335), the same
 * bookkeeping LedgerSpend uses: a job's ingredients and a refuel's Fuel Cells
 * are a spend (a settled trade with nobody, a_id the player, the trade id the
 * act's op), output collected is a credit from nobody (a_id the player,
 * b_gave the items, b's side applied), so a save applies each once (the trade
 * slice's applied list; a load applies a missed one, Ledger::catchUp). The
 * site decides from the recipe data (data/home-machines.json, the game's
 * tools/build-home-machines.mjs), the machine's row and the player's ledger
 * and stored save (skills, cards), never from an amount the client names. A
 * machine's fuel comes from the owner's pack when it is set down (tool
 * points are the client's, as for any tool, G335 2.6) and is the site's from
 * then on. The machine itself is not a ledger class (no_trade): setting it
 * down and picking it up are applied by the owner's game once each, from
 * this feature's own slice (systems.features['home-machines'] placed and
 * taken).
 *
 *   POST groddle/v1/homes/machines   (the realtime server, signed; home_machines.go)
 *        {"op":"hm-<16 hex>","owner":7,"actor":9,"place":"street|house|tower:<0..8>","verb":"place|take|use|collect|refuel|music|check",
 *         "id":"hm-…","class":"fuelmaker","rx":120,"fuel":50,"key":"fuel_cell","qty":2,"all":true,"force":true,"on":true}
 *        200 {"status":"settled","machine":{…}|null,"gone":"hm-…"?,"receipt":{"op","gave"|"got"}?,"taken":{"class","fuel"}?,"paid":[…],"now":ms}
 *        | 409 {"status":"refused","why":"…","text":"…","machine":{…}|null,"now":ms}
 *   GET  groddle/v1/homes/{owner}/machines   anyone not blocked: the machines as they stand (each with its place), whether I may pick one up, and the house's back yard and wall segments
 *   GET  groddle/v1/homes/machines           mine to apply: my placed machines (id, class), and what I picked up (dropped once my save lists it)
 *   GET  groddle/v1/machine-rooms/{meadow|besara|forest}/machines   anyone signed in: every player's machine in that room as it stands, with its owner and their name
 *
 * Table groddle_home_machines.
 */
final readonly class HomeMachines
{
    public const TABLE = 'groddle_home_machines';
    public const ROUTE = '/groddle/v1/homes/machines';
    public const SLICE = 'home-machines';
    public const STREET = 'RH_HOME_EXT';
    public const VERBS = ['place', 'take', 'use', 'collect', 'refuel', 'music', 'check'];
    public const PLACE_STREET = 'street';
    public const PLACE_HOUSE = 'house';
    /** The machine rooms' places (the game's js/home-machines.js MACHINE_ROOM_PLACES). */
    public const ROOMS = ['meadow', 'besara', 'forest'];
    public const ENGINEERING = 'engineering_1';
    /** The furthest from the house a machine may stand (ours: past any yard). */
    public const RX_MAX = 20000;

    // fuelmaker.js and machine_stand.js where marked; ours otherwise.
    public const TEXT_GONE = 'That is not there any more.';
    public const TEXT_HERE = 'This %s cannot be placed here.'; // machine_stand.js canDrop
    public const TEXT_MANY = 'You have a lot of machines out already.';
    public const TEXT_RUNNING = 'The machine is still running. %s remaining.'; // verbs.use 657
    public const TEXT_OTHER = 'Someone else is using this. %s remaining.'; // verbs.use 659
    public const TEXT_IN_USE = 'The machine is still in use.'; // disassemble 327
    public const TEXT_SOMEONE = 'Someone else is using this.'; // disassemble 329
    public const TEXT_SKILL = 'You need to know %s to use this.'; // inc_making.js:96
    public const TEXT_FUEL = 'This %s does not have enough fuel remaining.'; // inc_making.js:100
    public const TEXT_SHORT = "You don't have enough of that item."; // inc_trading.js:456
    public const TEXT_ROOM = 'There is not enough space for a fuel cell. Current level: %d/%d'; // refuel 458
    public const TEXT_CELLS = "You don't have any fuel cells."; // refuel 462
    public const TEXT_NOTHING = 'There is nothing in it for you.';
    public const TEXT_WAITING = 'Collect what is in the machine first.';
    public const TEXT_DENIED = 'Only the owner or a keyholder of this location can pick up this %s.'; // disassemble 322
    public const TEXT_ENGINEERING = 'You need to know Engineering to do this.'; // disassemble 316
    public const TEXT_OTHERS = 'Are you sure you want to pick this up? The machine contains items belonging to other players that will be destroyed.'; // 1573
    public const TEXT_RECIPE = "This machine doesn't make that.";
    public const TEXT_QTY = "That's more than it can make at once.";
    public const TEXT_OWN = 'Only the owner can set a machine down here.';
    public const TEXT_BLOCKED = 'You can\'t do that here.';

    public function __construct(private Seams $minn)
    {
    }

    // —— The realtime server ——

    #[Route(Method::Post, self::ROUTE, policy: new Policy(Access::Public))]
    public function act(Request $request): Response
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', self::ROUTE, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
        $b = json_decode($request->body, true);
        $b = is_array($b) ? $b : [];
        $op = $b['op'] ?? null;
        $owner = $b['owner'] ?? null;
        $actor = $b['actor'] ?? null;
        $verb = $b['verb'] ?? null;
        if (!self::opId($op) || !is_int($owner) || !is_int($actor) || $owner <= 0 || $actor <= 0 || !in_array($verb, self::VERBS, true)
            || ($verb !== 'place' && $verb !== 'check' && !self::opId($b['id'] ?? null))) {
            throw new RestError('groddle_machine_invalid', 'Name the act.', 400);
        }
        $place = $b['place'] ?? self::PLACE_STREET;
        if (!self::placeId($place)) {
            throw new RestError('groddle_machine_invalid', 'Name the place.', 400);
        }
        if ($owner !== $actor && $this->blocked($owner, $actor)) {
            return Response::json(['status' => 'refused', 'why' => 'blocked', 'text' => self::TEXT_BLOCKED, 'now' => self::nowMs()], 409);
        }
        $b['place'] = $place;
        // A machine room has no key-holders: only the owner picks one up there.
        $mayTake = $verb === 'take' && ($owner === $actor || (!self::isRoom($place) && (new Neighbours($this->minn))->holdsKeyTo($owner, $actor)));
        $out = Ledger::atomically($this->minn->db, fn (): array => $this->run($op, $owner, $actor, $verb, $b, $mayTake));
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    /** @return array<string, mixed> */
    private function run(string $op, int $owner, int $actor, string $verb, array $b, bool $mayTake): array
    {
        $db = $this->minn->db;
        $rules = self::rules();
        $now = self::nowMs();
        $t = $db->table(self::TABLE);
        $rows = [];
        foreach ($db->rows("SELECT * FROM {$t} WHERE owner_id = ? FOR UPDATE", [$owner]) as $r) {
            $rows[(string) $r['id']] = self::rowOf($r);
        }
        $paid = $this->settleAll($rules, $owner, $rows, $now);
        $answer = fn (string $status, array $extra = []): array => ['status' => $status, 'now' => $now, 'paid' => $paid] + $extra;
        $refuse = fn (string $why, string $text, ?array $row = null): array => $answer('refused', ['why' => $why, 'text' => $text, 'machine' => $row !== null ? self::view($row) : null]);

        if ($verb === 'place') {
            if (isset($rows[$op])) {
                return $answer('settled', ['machine' => self::view($rows[$op])]);
            }
            if ($actor !== $owner) {
                return $refuse('own', self::TEXT_OWN);
            }
            $class = $b['class'] ?? null;
            $place = (string) $b['place'];
            $fixed = [];
            if (self::isRoom($place)) {
                // Everyone's machines in the room, locked so two never land within 200 px of each other.
                $placed = [];
                foreach ($db->rows("SELECT * FROM {$t} WHERE place = ? AND status = 'placed' FOR UPDATE", [$place]) as $r) {
                    $placed[] = self::rowOf($r);
                }
                $span = self::roomSpan($rules, $place);
                $fixed = self::roomFixed($rules, $place);
            } else {
                $state = Neighbours::state($db, $owner);
                if (!Neighbours::hasHome($state)) {
                    return $refuse('no_home', HomePool::TEXT_NO_HOME);
                }
                $placed = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === 'placed' && $r['place'] === $place));
                $span = self::span($rules, $place, $state);
            }
            if ($span === false) {
                return $refuse('here', sprintf(self::TEXT_HERE, self::label($rules, is_string($class) ? $class : '')));
            }
            $why = self::placeWhy($rules, $placed, $class, $b['rx'] ?? null, $span, $fixed);
            if ($why !== null) {
                return $refuse($why, $why === 'many' ? self::TEXT_MANY : sprintf(self::TEXT_HERE, self::label($rules, is_string($class) ? $class : '')));
            }
            $fuel = max(0, min((int) $rules['maxFuel'], is_int($b['fuel'] ?? null) ? $b['fuel'] : (int) $rules['fresh']));
            $row = ['id' => $op, 'owner' => $owner, 'class' => $class, 'place' => $place, 'rx' => (int) $b['rx'], 'fuel' => $fuel, 'music' => true, 'job' => null, 'contents' => [], 'status' => 'placed', 'taken_op' => null, 'taker' => null];
            $stamp = gmdate('Y-m-d H:i:s');
            $db->execute("INSERT INTO {$t} (id, owner_id, class_tsid, place, rx, fuel, music, job, contents, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, NULL, '{}', 'placed', ?, ?)",
                [$op, $owner, $class, $place, $row['rx'], $fuel, $stamp, $stamp]);
            return $answer('settled', ['machine' => self::view($row)]);
        }

        if ($verb === 'check') {
            $id = $b['id'] ?? null;
            return $answer('settled', ['machine' => is_string($id) && isset($rows[$id]) && $rows[$id]['status'] === 'placed' && $rows[$id]['place'] === $b['place'] ? self::view($rows[$id]) : null]);
        }

        $id = (string) $b['id'];
        if ($verb === 'take') {
            foreach ($rows as $r) {
                if ($r['taken_op'] === $op) {
                    return $answer('settled', ['gone' => $r['id'], 'taken' => ['op' => $op, 'class' => $r['class'], 'fuel' => $r['fuel']]]);
                }
            }
        }
        $row = $rows[$id] ?? null;
        // The actor stands where the machine does (the verbs' ok_states "in_location").
        if ($row === null || $row['status'] !== 'placed' || $row['place'] !== $b['place']) {
            return $refuse('gone', self::TEXT_GONE);
        }
        $label = self::label($rules, $row['class']);

        if ($verb === 'take') {
            $actorState = $actor === $owner ? Neighbours::state($db, $owner) : Neighbours::state($db, $actor);
            $why = self::takeWhy($row, $actor, $mayTake, in_array(self::ENGINEERING, HomeYard::learned($actorState), true), ($b['force'] ?? false) === true, $now);
            if ($why !== null) {
                $text = match ($why) {
                    'denied' => sprintf(self::TEXT_DENIED, $label),
                    'engineering' => self::TEXT_ENGINEERING,
                    'running' => (string) ($row['job']['user'] ?? 0) === (string) $actor ? self::TEXT_IN_USE : self::TEXT_SOMEONE,
                    'waiting' => self::TEXT_WAITING,
                    default => self::TEXT_OTHERS,
                };
                return $refuse($why, $text, $row);
            }
            $db->execute("UPDATE {$t} SET status = 'taken', taken_op = ?, taker_id = ?, job = NULL, contents = '{}', updated_at = ? WHERE id = ?", [$op, $actor, gmdate('Y-m-d H:i:s'), $id]);
            return $answer('settled', ['gone' => $id, 'taken' => ['op' => $op, 'class' => $row['class'], 'fuel' => $row['fuel']]]);
        }

        if ($verb === 'music') {
            $on = ($b['on'] ?? true) === true;
            $db->execute("UPDATE {$t} SET music = ?, updated_at = ? WHERE id = ?", [$on ? 1 : 0, gmdate('Y-m-d H:i:s'), $id]);
            $row['music'] = $on;
            return $answer('settled', ['machine' => self::view($row)]);
        }

        $ledger = Ledger::fromSeams($this->minn);
        $trades = $db->table(Ledger::TRADES);
        $had = $db->row("SELECT status, a_gave, b_gave FROM {$trades} WHERE trade_id = ? FOR UPDATE", [$op]);
        if ($had !== null) {
            // The same act asked again (the server's retry): as recorded.
            $receipt = $verb === 'collect'
                ? ['op' => $op, 'got' => json_decode((string) $had['b_gave'], true)]
                : ['op' => $op, 'gave' => json_decode((string) $had['a_gave'], true)];
            return $had['status'] === 'settled' ? $answer('settled', ['machine' => self::view($row), 'receipt' => $receipt]) : $refuse('short', self::TEXT_SHORT, $row);
        }

        if ($verb === 'refuel') {
            $fit = self::refuelFit($rules, $row['fuel'], ($b['all'] ?? false) === true ? (int) floor((int) $rules['maxFuel'] / (int) $rules['perCell']) : 1);
            if ($fit === 0) {
                return $refuse('full', sprintf(self::TEXT_ROOM, $row['fuel'], (int) $rules['maxFuel']), $row);
            }
            $state = $ledger->lock($actor, time());
            $cells = min($fit, (int) ($state['items']['fuel_cell'] ?? 0));
            if ($cells < 1) {
                return $refuse('cells', self::TEXT_CELLS, $row);
            }
            $this->spend($ledger, $op, $actor, $state, ['fuel_cell' => $cells], 'home_machine_fuel');
            $row['fuel'] = min((int) $rules['maxFuel'], $row['fuel'] + $cells * (int) $rules['perCell']);
            $db->execute("UPDATE {$t} SET fuel = ?, updated_at = ? WHERE id = ?", [$row['fuel'], gmdate('Y-m-d H:i:s'), $id]);
            return $answer('settled', ['machine' => self::view($row), 'receipt' => ['op' => $op, 'gave' => self::goods(['fuel_cell' => $cells])], 'cells' => $cells]);
        }

        if ($verb === 'collect') {
            $items = $row['contents'][(string) $actor]['items'] ?? [];
            if ($items === []) {
                return $refuse('nothing', self::TEXT_NOTHING, $row);
            }
            $this->credit($ledger, $op, $actor, $items);
            unset($row['contents'][(string) $actor]);
            $db->execute("UPDATE {$t} SET contents = ?, updated_at = ? WHERE id = ?", [self::contentsJson($row['contents']), gmdate('Y-m-d H:i:s'), $id]);
            return $answer('settled', ['machine' => self::view($row), 'receipt' => ['op' => $op, 'got' => self::goods($items)]]);
        }

        // use: start a job.
        $actorState = Neighbours::state($db, $actor);
        $key = $b['key'] ?? null;
        $judged = self::start($rules, $row, $actor, is_string($key) ? $key : '', is_int($b['qty'] ?? null) ? $b['qty'] : 0, HomeYard::learned($actorState), HomeYard::upgrades($actorState), $now);
        if (isset($judged['why'])) {
            return $refuse($judged['why'], $judged['text'], $row);
        }
        $state = $ledger->lock($actor, time());
        foreach ($judged['held'] as $c => $n) {
            if (($state['items'][$c] ?? 0) < $n) {
                $db->execute("INSERT INTO {$trades} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'void', ?, 0, ?, '{\"items\":[],\"currants\":0}', NULL, ?)", [$op, $actor, self::goodsJson($judged['held']), gmdate('Y-m-d H:i:s')]);
                $ledger->log($actor, 'refuse', ['spend' => $op, 'why' => 'home_machine', 'short' => ['id' => $actor, 'c' => (string) $c]], time());
                return $refuse('short', self::TEXT_SHORT, $row);
            }
        }
        $this->spend($ledger, $op, $actor, $state, $judged['held'], 'home_machine');
        $row['job'] = $judged['job'];
        $db->execute("UPDATE {$t} SET job = ?, updated_at = ? WHERE id = ?", [json_encode($row['job'], JSON_UNESCAPED_SLASHES), gmdate('Y-m-d H:i:s'), $id]);
        return $answer('settled', ['machine' => self::view($row), 'receipt' => ['op' => $op, 'gave' => self::goods($judged['held'])]]);
    }

    // —— Anyone at the home ——

    /** The machines on a home street as they stand now, and whether I may pick one up (the owner or a key-holder). */
    #[Route(Method::Get, '/groddle/v1/homes/{id:\d+}/machines', policy: new Policy(Access::SignedIn))]
    public function view_(Request $request, string $id): Response
    {
        $owner = (int) $id;
        $me = $this->minn->reader->userId;
        $db = $this->minn->db;
        if ($owner <= 0 || ($owner !== $me && $this->blocked($owner, $me))) {
            throw new RestError('groddle_machines_blocked', self::TEXT_BLOCKED, 403);
        }
        $rules = self::rules();
        $out = Ledger::atomically($db, function () use ($db, $rules, $owner): array {
            $now = self::nowMs();
            $rows = [];
            foreach ($db->rows('SELECT * FROM ' . $db->table(self::TABLE) . " WHERE owner_id = ? AND status = 'placed' FOR UPDATE", [$owner]) as $r) {
                $rows[(string) $r['id']] = self::rowOf($r);
            }
            $this->settleAll($rules, $owner, $rows, $now);
            return ['now' => $now, 'machines' => array_values(array_map(self::view(...), $rows))];
        });
        $state = Neighbours::state($db, $owner);
        $homes = Neighbours::homesSlice($state);
        return Response::json($out + [
            'owner' => $owner,
            'name' => (new Characters($db))->namesOf([$owner])[$owner] ?? '',
            'cult' => is_object($homes?->cult ?? null) ? $homes->cult : (object) [],
            // Where the house's machines stand: past the back yard, between the house's walls.
            'backYard' => is_object($homes?->backYard ?? null) ? $homes->backYard : (object) [],
            'segments' => self::houseSegments(self::rules(), $state),
            'mayTake' => $owner === $me || (new Neighbours($this->minn))->holdsKeyTo($owner, $me),
        ]);
    }

    /** What my game applies once: the ids of my placed machines, and what I picked up (gone once my stored save lists it). */
    #[Route(Method::Get, self::ROUTE, policy: new Policy(Access::SignedIn))]
    public function mine(Request $request): Response
    {
        $db = $this->minn->db;
        $me = $this->minn->reader->userId;
        $t = $db->table(self::TABLE);
        $applied = self::sliceList(Neighbours::state($db, $me), 'taken');
        if ($applied !== []) {
            $db->execute("DELETE FROM {$t} WHERE taker_id = ? AND status = 'taken' AND taken_op IN (?)", [$me, $applied]);
        }
        $placed = array_map(static fn (array $r): array => ['id' => (string) $r['id'], 'class' => (string) $r['class_tsid']], $db->rows("SELECT id, class_tsid FROM {$t} WHERE owner_id = ? AND status = 'placed' ORDER BY created_at, id", [$me]));
        $taken = [];
        foreach ($db->rows("SELECT taken_op, class_tsid, fuel FROM {$t} WHERE taker_id = ? AND status = 'taken' ORDER BY updated_at, id", [$me]) as $r) {
            $taken[] = ['op' => (string) $r['taken_op'], 'class' => (string) $r['class_tsid'], 'fuel' => (int) $r['fuel']];
        }
        return Response::json(['placed' => $placed, 'taken' => $taken]);
    }

    /** Every player's machine in a machine room as it stands now, each with its owner and their name. */
    #[Route(Method::Get, '/groddle/v1/machine-rooms/{room:[a-z]+}/machines', policy: new Policy(Access::SignedIn))]
    public function room(Request $request, string $room): Response
    {
        $place = 'mr:' . $room;
        if (!self::isRoom($place)) {
            throw new RestError('groddle_machines_room', 'No such room.', 404);
        }
        $db = $this->minn->db;
        $rules = self::rules();
        $out = Ledger::atomically($db, function () use ($db, $rules, $place): array {
            $now = self::nowMs();
            $t = $db->table(self::TABLE);
            $rows = [];
            foreach ($db->rows("SELECT * FROM {$t} WHERE place = ? AND status = 'placed' ORDER BY created_at, id FOR UPDATE", [$place]) as $r) {
                $row = self::rowOf($r);
                // A room pays no pool: settle only finishes jobs and lets old output go.
                [$next] = self::settle($rules, $row, $row['owner'], $now);
                if ($next !== $row) {
                    $db->execute("UPDATE {$t} SET fuel = ?, job = ?, contents = ?, updated_at = ? WHERE id = ?",
                        [$next['fuel'], $next['job'] === null ? null : json_encode($next['job'], JSON_UNESCAPED_SLASHES), self::contentsJson($next['contents']), gmdate('Y-m-d H:i:s'), $next['id']]);
                }
                $rows[] = $next;
            }
            return ['now' => $now, 'rows' => $rows];
        });
        $names = (new Characters($db))->namesOf(array_values(array_unique(array_map(static fn (array $r): int => $r['owner'], $out['rows']))));
        $machines = array_map(static fn (array $r): array => self::view($r) + ['ownerName' => $names[$r['owner']] ?? ''], $out['rows']);
        return Response::json(['now' => $out['now'], 'room' => $room, 'machines' => $machines]);
    }

    // —— The rules (pure; tests: php -r) ——

    /** @return array<string, mixed> data/home-machines.json */
    public static function rules(): array
    {
        static $rules = null;
        return $rules ??= json_decode((string) file_get_contents(dirname(__DIR__) . '/data/home-machines.json'), true);
    }

    public static function opId(mixed $op): bool
    {
        return is_string($op) && preg_match('/^hm-[0-9a-f]{16}$/', $op) === 1;
    }

    public static function label(array $rules, string $class): string
    {
        return (string) ($rules['machines'][$class]['label'] ?? $class);
    }

    public static function placeId(mixed $place): bool
    {
        return is_string($place) && preg_match('/^(street|house|tower:[0-8]|mr:(meadow|besara|forest))$/', $place) === 1;
    }

    /** A Community Machine Room's place ("mr:meadow", "mr:besara", "mr:forest"). */
    public static function isRoom(mixed $place): bool
    {
        return is_string($place) && preg_match('/^mr:(meadow|besara|forest)$/', $place) === 1;
    }

    /** A machine room's ends in room x (data machineRooms: the rooms' bounds, -700..700). @return array{0: int, 1: int}|false */
    public static function roomSpan(array $rules, string $place): array|false
    {
        $room = $rules['machineRooms'][substr($place, 3)] ?? null;
        return is_array($room) ? [(int) $room['l'], (int) $room['r']] : false;
    }

    /** The room's own machines' x (they count for canDrop's 200 px as any machine does). @return list<int> */
    public static function roomFixed(array $rules, string $place): array
    {
        return array_map('intval', $rules['machineRooms'][substr($place, 3)]['fixed'] ?? []);
    }

    /** The house's wall segments in a stored save (home-decor normalizeHomeDecorState: 6 to 20, 7 when unset). */
    public static function houseSegments(array $rules, ?object $state): int
    {
        $room = $rules['rooms'];
        $decor = $state?->systems?->features?->homes?->decor ?? null;
        $n = is_object($decor) && is_numeric($decor->wallSegments ?? null) ? (int) floor((float) $decor->wallSegments) : (int) $room['startSegments'];
        return max((int) $room['minSegments'], min((int) $room['maxSegments'], $n));
    }

    /**
     * Where a machine may go in a place: null on the home street (the game
     * checks its ends from the street's layout), [lo, hi] in room x for the
     * house or a Tower floor (the inner walls), or false when there is no such
     * room (no Tower, or a floor it does not have).
     *
     * @return array{0: int, 1: int}|false|null
     */
    public static function span(array $rules, string $place, ?object $state): array|false|null
    {
        $room = $rules['rooms'];
        if ($place === self::PLACE_STREET) {
            return null;
        }
        if ($place === self::PLACE_HOUSE) {
            $w = self::houseSegments($rules, $state) * (int) $room['segmentW'];
            return [(int) $room['endCap'], $w - (int) $room['endCap']];
        }
        $tower = $state?->systems?->features?->{'home-tower'} ?? null;
        $floor = (int) substr($place, 6);
        if (!is_object($tower) || ($tower->built ?? false) !== true || $floor >= (int) ($tower->floors ?? 0)) {
            return false;
        }
        return [(int) $room['towerLeft'], (int) $room['towerRight']];
    }

    /**
     * machine_stand.js canDrop and ours: why a machine may not stand at rx (in the place's span, when it has one), or null.
     * $fixed: x of machines nobody placed (a machine room's own), as near as any other.
     *
     * @param list<int> $fixed
     */
    public static function placeWhy(array $rules, array $placed, mixed $class, mixed $rx, ?array $span = null, array $fixed = []): ?string
    {
        if (!is_string($class) || !isset($rules['machines'][$class])) {
            return 'class';
        }
        if (count($placed) >= (int) $rules['max']) {
            return 'many';
        }
        if (!is_int($rx) || abs($rx) > self::RX_MAX) {
            return 'here';
        }
        if ($span !== null && ($rx <= $span[0] + (int) $rules['edge'] || $rx >= $span[1] - (int) $rules['edge'])) {
            return 'edge';
        }
        foreach ($placed as $r) {
            if (abs((int) $r['rx'] - $rx) < (int) $rules['spacing']) {
                return 'near';
            }
        }
        foreach ($fixed as $x) {
            if (abs((int) $x - $rx) < (int) $rules['spacing']) {
                return 'near';
            }
        }
        return null;
    }

    /** How many Fuel Cells go in (refuel 457-470: only while 50 or more fit). */
    public static function refuelFit(array $rules, int $fuel, int $want): int
    {
        return max(0, min($want, intdiv((int) $rules['maxFuel'] - $fuel, (int) $rules['perCell'])));
    }

    /** Why the actor may not pick it up (disassemble 296-349), or null. */
    public static function takeWhy(array $row, int $actor, bool $mayTake, bool $engineering, bool $force, int $nowMs): ?string
    {
        if (!$mayTake) {
            return 'denied';
        }
        if (!$engineering) {
            return 'engineering';
        }
        if (is_array($row['job']) && $nowMs < (int) $row['job']['ready']) {
            return 'running';
        }
        if (isset($row['contents'][(string) $actor])) {
            return 'waiting';
        }
        foreach ($row['contents'] as $user => $c) {
            if ((string) $user !== (string) $actor && !$force) {
                return 'others';
            }
        }
        return null;
    }

    /**
     * Starting a job (verbs.use, making_check_allowed, making_make_known):
     * ['job' => …, 'held' => [class => n]] or ['why' => …, 'text' => …].
     *
     * @param list<string> $skills the maker's learned skills
     * @param list<string> $upgrades the maker's cards
     * @return array<string, mixed>
     */
    public static function start(array $rules, array $row, int $actor, string $key, int $qty, array $skills, array $upgrades, int $nowMs): array
    {
        $m = $rules['machines'][$row['class']] ?? null;
        $label = self::label($rules, $row['class']);
        if (is_array($row['job']) && $nowMs < (int) $row['job']['ready']) {
            $left = self::secondsToString((int) round(((int) $row['job']['ready'] - $nowMs) / 1000));
            return ['why' => 'busy', 'text' => sprintf((string) $row['job']['user'] === (string) $actor ? self::TEXT_RUNNING : self::TEXT_OTHER, $left)];
        }
        if (isset($row['contents'][(string) $actor])) {
            return ['why' => 'waiting', 'text' => self::TEXT_WAITING];
        }
        $r = is_array($m) ? ($m['recipes'][$key] ?? null) : null;
        if (!is_array($r)) {
            return ['why' => 'recipe', 'text' => self::TEXT_RECIPE];
        }
        if (!in_array((string) $m['skill'], $skills, true)) {
            return ['why' => 'skill', 'text' => sprintf(self::TEXT_SKILL, self::skillName((string) $m['skill']))];
        }
        if ($qty < 1 || $qty > self::jobLimit($rules, (int) $r['limit'], $upgrades)) {
            return ['why' => 'qty', 'text' => self::TEXT_QTY];
        }
        $need = self::jobFuel($rules, $row['class'], (int) $r['fuel'], $qty, $upgrades);
        if ($row['fuel'] < max(1, $need)) {
            return ['why' => 'fuel', 'text' => sprintf(self::TEXT_FUEL, $label)];
        }
        $held = [];
        foreach ($r['in'] as $c => $n) {
            $held[(string) $c] = (int) $n * $qty;
        }
        $ms = $qty * (int) $r['waitMs'] + (int) $rules['extraMs'];
        return [
            'held' => $held,
            'job' => ['user' => $actor, 'key' => $key, 'id' => (string) $r['id'], 'name' => (string) $r['name'], 'qty' => $qty, 'need' => $need, 'at' => $nowMs, 'ready' => $nowMs + $ms],
        ];
    }

    /** inc_making.js 760-777: per-unit fuel times the count, less the Blockmaker cards' share of the whole. */
    public static function jobFuel(array $rules, string $class, int $perUnit, int $qty, array $upgrades): int
    {
        $uses = $perUnit * $qty;
        if ($class === 'blockmaker') {
            foreach ($rules['saveFuel'] as $card => $share) {
                if (in_array($card, $upgrades, true)) {
                    $uses -= (int) round($uses * (float) $share);
                    break;
                }
            }
        }
        return $uses;
    }

    /** main.js 795-799 with the Mad Machinist cards. */
    public static function jobLimit(array $rules, int $limit, array $upgrades): int
    {
        foreach ($rules['taskCards'] as $card => $m) {
            if (in_array($card, $upgrades, true)) {
                return (int) round(max(1, $limit) * (float) $m);
            }
        }
        return max(1, $limit);
    }

    /**
     * A job whose time is up is done (finishMakingKnown): the tank pays its
     * fuel, the outputs wait for the maker, and a maker who is not the owner
     * pays the owner's pool. Expired output goes (expireOldContents).
     * Returns [row, paid] (paid: null or ['user' => id, 'amount' => 7, 'day' => d]).
     *
     * @return array{0: array, 1: ?array}
     */
    public static function settle(array $rules, array $row, int $owner, int $nowMs): array
    {
        $paid = null;
        $job = $row['job'];
        if (is_array($job) && $nowMs >= (int) $job['ready']) {
            $r = $rules['machines'][$row['class']]['recipes'][(string) $job['key']] ?? null;
            $row['fuel'] = max(0, $row['fuel'] - (int) $job['need']);
            $user = (string) $job['user'];
            $items = $row['contents'][$user]['items'] ?? [];
            foreach (is_array($r) ? $r['out'] : [] as $c => $n) {
                $items[(string) $c] = ($items[(string) $c] ?? 0) + (int) $n * (int) $job['qty'];
            }
            if ($items !== []) {
                $row['contents'][$user] = ['items' => $items, 'at' => (int) $job['ready']];
            }
            // Only the home street pays (inc_cultivation.js:170 is_public; inc_houses.js 1706-1709 reads the street's alone).
            if ((int) $job['user'] !== $owner && ($row['place'] ?? self::PLACE_STREET) === self::PLACE_STREET) {
                $paid = ['user' => (int) $job['user'], 'amount' => (int) $rules['pool'], 'day' => HomePool::dayOf((int) $job['ready']), 'id' => $row['id']];
            }
            $row['job'] = null;
        }
        // expireOldContents 1146-1158: only a POL's owner keeps theirs; a machine room is no POL.
        $keepOwner = !self::isRoom($row['place'] ?? self::PLACE_STREET);
        foreach ($row['contents'] as $user => $c) {
            $diff = $nowMs - (int) $c['at'];
            if ((!$keepOwner || (string) $user !== (string) $owner) && $diff >= 0 && (int) round($diff / (int) $rules['dayMs']) > (int) $rules['storeDays']) {
                unset($row['contents'][$user]);
            }
        }
        return [$row, $paid];
    }

    /** What everyone sees of a machine: no ingredients, no one's output, only who has some waiting. */
    public static function view(array $row): array
    {
        $job = is_array($row['job']) ? ['user' => (int) $row['job']['user'], 'name' => (string) $row['job']['name'], 'qty' => (int) $row['job']['qty'], 'at' => (int) $row['job']['at'], 'ready' => (int) $row['job']['ready']] : null;
        return [
            'id' => $row['id'],
            'owner' => (int) ($row['owner'] ?? 0),
            'class' => $row['class'],
            'place' => $row['place'] ?? self::PLACE_STREET,
            'rx' => $row['rx'],
            'fuel' => $row['fuel'],
            'music' => $row['music'],
            'job' => $job,
            'waiting' => array_map('intval', array_keys($row['contents'])),
        ];
    }

    /** A table row as the rules take it. */
    public static function rowOf(array $r): array
    {
        $job = json_decode((string) ($r['job'] ?? ''), true);
        $contents = json_decode((string) ($r['contents'] ?? ''), true);
        $clean = [];
        foreach (is_array($contents) ? $contents : [] as $user => $c) {
            $items = [];
            foreach (is_array($c['items'] ?? null) ? $c['items'] : [] as $cls => $n) {
                if ((int) $n > 0) {
                    $items[(string) $cls] = (int) $n;
                }
            }
            if ($items !== []) {
                $clean[(string) $user] = ['items' => $items, 'at' => (int) ($c['at'] ?? 0)];
            }
        }
        return [
            'id' => (string) $r['id'],
            'owner' => (int) $r['owner_id'],
            'class' => (string) $r['class_tsid'],
            'place' => self::placeId($r['place'] ?? null) ? (string) $r['place'] : self::PLACE_STREET,
            'rx' => (int) $r['rx'],
            'fuel' => (int) $r['fuel'],
            'music' => (int) $r['music'] === 1,
            'job' => is_array($job) && isset($job['ready'], $job['user'], $job['key']) ? $job : null,
            'contents' => $clean,
            'status' => (string) $r['status'],
            'taken_op' => isset($r['taken_op']) ? (string) $r['taken_op'] : null,
            'taker' => isset($r['taker_id']) ? (int) $r['taker_id'] : null,
        ];
    }

    /** common.js secondsToString (machine-rooms.js). */
    public static function secondsToString(int $secs): string
    {
        $s = max(0, $secs);
        if ($s >= 60) {
            $mins = intdiv($s, 60);
            $rest = $s - $mins * 60;
            $mw = $mins === 1 ? 'minute' : 'minutes';
            $sw = $rest === 1 ? 'second' : 'seconds';
            return $rest ? "{$mins} {$mw} and {$rest} {$sw}" : "{$mins} {$mw}";
        }
        return $s . ' ' . ($s === 1 ? 'second' : 'seconds');
    }

    /** "fuelmaking_1" -> "Fuelmaking" (the game's skill names for the four). */
    public static function skillName(string $id): string
    {
        return match ($id) {
            'fuelmaking_1' => 'Fuelmaking',
            'blockmaking_1' => 'Blockmaking',
            'woodworking_1' => 'Woodworking',
            'metalwork_1' => 'Metalwork',
            default => ucfirst(str_replace('_', ' ', (string) preg_replace('/_\d+$/', '', $id))),
        };
    }

    /** systems.features['home-machines'][$list] of a stored save. @return list<string> */
    public static function sliceList(?object $state, string $list): array
    {
        $slice = $state?->systems?->features?->{self::SLICE} ?? null;
        $ids = is_object($slice) && is_array($slice->{$list} ?? null) ? $slice->{$list} : [];
        return array_values(array_filter($ids, self::opId(...)));
    }

    // —— Settling ——

    /** Every due job on the owner's machines, written back; the pool paid. @param array<string, array> $rows */
    private function settleAll(array $rules, int $owner, array &$rows, int $now): array
    {
        $db = $this->minn->db;
        $t = $db->table(self::TABLE);
        $paid = [];
        foreach ($rows as $id => $row) {
            if ($row['status'] !== 'placed') {
                continue;
            }
            [$next, $pay] = self::settle($rules, $row, $owner, $now);
            if ($next !== $row) {
                $db->execute("UPDATE {$t} SET fuel = ?, job = ?, contents = ?, updated_at = ? WHERE id = ?",
                    [$next['fuel'], $next['job'] === null ? null : json_encode($next['job'], JSON_UNESCAPED_SLASHES), self::contentsJson($next['contents']), gmdate('Y-m-d H:i:s'), $id]);
                $rows[$id] = $next;
            }
            if ($pay !== null) {
                HomePool::add($db, $owner, $pay['amount'], $pay['day']);
                $paid[] = $pay;
            }
        }
        return $paid;
    }

    // —— The ledger ——

    /** A spend: a settled trade with nobody (LedgerSpend's bookkeeping); the items leave the ledger now. */
    private function spend(Ledger $ledger, string $op, int $id, array $state, array $items, string $why): void
    {
        $db = $this->minn->db;
        $now = time();
        $this->moveItems($id, $state, $items, -1);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $db->execute('INSERT INTO ' . $db->table(Ledger::TRADES) . " (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, '{\"items\":[],\"currants\":0}', ?, ?)", [$op, $id, self::goodsJson($items), $stamp, $stamp]);
        $ledger->log($id, 'spend', ['spend' => $op, 'why' => $why, 'gave' => ['items' => (object) $items, 'currants' => 0]], $now);
    }

    /** Output collected: a settled trade from nobody (LedgerSpend's refund shape); the items join the ledger now. */
    private function credit(Ledger $ledger, string $op, int $id, array $items): void
    {
        $db = $this->minn->db;
        $now = time();
        $state = $ledger->lock($id, $now);
        $this->moveItems($id, $state, $items, 1);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $db->execute('INSERT INTO ' . $db->table(Ledger::TRADES) . " (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, '{\"items\":[],\"currants\":0}', ?, ?, ?)", [$op, $id, self::goodsJson($items), $stamp, $stamp]);
        $ledger->log($id, 'credit', ['machine' => $op, 'got' => ['items' => (object) $items, 'currants' => 0]], $now);
    }

    private function moveItems(int $id, array $state, array $items, int $sign): void
    {
        $db = $this->minn->db;
        $t = $db->table(Ledger::ITEMS);
        foreach ($items as $c => $n) {
            $count = ($state['items'][$c] ?? 0) + $sign * (int) $n;
            if ($count <= 0) {
                $db->execute("DELETE FROM {$t} WHERE user_id = ? AND class_tsid = ?", [$id, (string) $c]);
            } else {
                $db->execute("INSERT INTO {$t} (user_id, class_tsid, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)", [$id, (string) $c, $count]);
            }
        }
        $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET updated_at = ? WHERE user_id = ?', [gmdate('Y-m-d H:i:s'), $id]);
    }

    /** @return array{items: list<array{c: string, n: int}>, currants: int} */
    public static function goods(array $items): array
    {
        $out = [];
        ksort($items);
        foreach ($items as $c => $n) {
            $out[] = ['c' => (string) $c, 'n' => (int) $n];
        }
        return ['items' => $out, 'currants' => 0];
    }

    private static function goodsJson(array $items): string
    {
        return (string) json_encode(self::goods($items), JSON_UNESCAPED_SLASHES);
    }

    private static function contentsJson(array $contents): string
    {
        return $contents === [] ? '{}' : (string) json_encode($contents, JSON_UNESCAPED_SLASHES);
    }

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function blocked(int $a, int $b): bool
    {
        $t = $this->minn->db->table(Buddies::IGNORES);
        return $this->minn->db->value("SELECT 1 FROM {$t} WHERE (user_id = ? AND ignored_id = ?) OR (user_id = ? AND ignored_id = ?)", [$a, $b, $b, $a]) !== null;
    }
}
