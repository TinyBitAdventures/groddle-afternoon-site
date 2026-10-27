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
 * groddle/v1/ledger/hall: a group hall's claim and its owner (G331,
 * groddle-afternoon-server group_halls.go). The realtime server runs the
 * project (its phases, contributions and rewards, projects.go); what makes
 * it a group's is decided here, where the groups and the ledger are
 * (GameServerJS quests/quest.js):
 *
 *   claim  claimJob 1297-1338: the claimer is in the group (pc.groups_get),
 *          the group owns no hall yet (group.hasPol()), and the claim's
 *          currants come out of the claimer's ledger, all in one
 *          transaction, recorded once per id like a street project's
 *          contribution (LedgerProject: groddle_trades, b_id 0), so the
 *          save catches up the same way (the Job Board's applied list).
 *   own    doJobEffects 1831-1863: the finished hall is the group's, unless
 *          it owns one by now ("Suckers!": refused, the server resets the job).
 *   expire onJobTimeout 1356-1380: the claimed phase ran out of time; the
 *          currants stay spent ("lose everything it has contributed").
 *
 * For the realtime server only, signed with the realtime secret (ServiceCall):
 *
 *   POST {"op":"claim","id":"job-g-…","job":"694","user":7,"group":12,"currants":200}
 *        200 {"status":"settled","group":{"id","name","private"}}
 *        409 {"status":"refused","why":"member"|"owns"|"currants"}
 *   POST {"op":"own","id":"job-g-…","job":"694"}   200 settled + group | 409 {"why":"owns"|"gone"}
 *   POST {"op":"expire","id":"job-g-…","job":"694"} 200 settled
 *
 * Each op answers the same when asked again. The hall itself (its street)
 * is not in the public dump: a row with status 'built' is the group's
 * ownership, kept for the day there is a street to open to its members
 * (locations/inc_pols.js pols_canEnter 278-352).
 */
final readonly class LedgerHall
{
    public const ROUTE = '/groddle/v1/ledger/hall';

    public const TABLE = 'groddle_group_halls';

    /** Most a claim can cost (the remake's 200 of the source's 10,000; never more than the source's). */
    public const CLAIM_MAX = 10000;

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Post, self::ROUTE, policy: new Policy(Access::Public))]
    public function hall(Request $request): Response
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null || !ServiceCall::verify($request->header(ServiceCall::HEADER), 'POST', self::ROUTE, $request->body, $secret, time())) {
            throw new RestError('groddle_service_unsigned', 'Not signed.', 401);
        }
        $body = json_decode($request->body, true);
        $op = is_array($body) ? ($body['op'] ?? null) : null;
        $id = is_array($body) ? ($body['id'] ?? null) : null;
        $job = is_array($body) ? ($body['job'] ?? null) : null;
        if (!in_array($op, ['claim', 'own', 'expire'], true) || !self::id($id) || !is_string($job) || preg_match('/^[0-9]{1,6}$/', $job) !== 1) {
            throw new RestError('groddle_ledger_invalid', 'Not a group hall call.', 400);
        }
        if ($op === 'claim') {
            $user = $body['user'] ?? null;
            $group = $body['group'] ?? null;
            $currants = $body['currants'] ?? null;
            if (!is_int($user) || $user <= 0 || !is_int($group) || $group <= 0 || !is_int($currants) || $currants < 0 || $currants > self::CLAIM_MAX) {
                throw new RestError('groddle_ledger_invalid', 'Not a group hall claim.', 400);
            }
            $out = Ledger::atomically($this->minn->db, fn () => $this->claim($id, $job, $user, $group, $currants, time()));
        } else {
            $out = Ledger::atomically($this->minn->db, fn () => $op === 'own' ? $this->own($id, time()) : $this->expire($id));
        }
        return Response::json($out, $out['status'] === 'refused' ? 409 : 200);
    }

    /** group_halls.go: a claim's id is a project give's (job-g-…), at most 40. */
    public static function id(mixed $id): bool
    {
        return is_string($id) && strlen($id) <= 40 && preg_match('/^job-g-[a-z0-9-]{1,34}$/', $id) === 1;
    }

    /** @return array{status: string, why?: string, group?: array} */
    private function claim(string $id, string $job, int $user, int $group, int $currants, int $now): array
    {
        $db = $this->minn->db;
        $halls = $db->table(self::TABLE);
        $row = $db->row("SELECT group_id FROM {$halls} WHERE claim_id = ? FOR UPDATE", [$id]);
        if ($row !== null) {
            return ['status' => 'settled', 'group' => $this->group((int) $row['group_id'])];
        }
        $member = $db->value('SELECT 1 FROM ' . $db->table(Groups::MEMBERS) . ' WHERE group_id = ? AND user_id = ?', [$group, $user]);
        if ($member === null) {
            return ['status' => 'refused', 'why' => 'member'];
        }
        // One hall a group (group.hasPol): lock the group's rows so two claims cannot both pass.
        $db->row('SELECT id FROM ' . $db->table(Groups::TABLE) . ' WHERE id = ? FOR UPDATE', [$group]);
        if ($this->owns($group)) {
            return ['status' => 'refused', 'why' => 'owns'];
        }
        $paid = (new LedgerProject($this->minn))->settle($id, $user, ['items' => [], 'currants' => $currants], ['items' => [], 'currants' => 0], $now);
        if ($paid['status'] !== 'settled') {
            return ['status' => 'refused', 'why' => 'currants'];
        }
        $db->execute("INSERT INTO {$halls} (claim_id, job_id, group_id, claimer_id, currants, status, claimed_at) VALUES (?, ?, ?, ?, ?, 'claimed', ?)", [$id, $job, $group, $user, $currants, gmdate('Y-m-d H:i:s', $now)]);
        return ['status' => 'settled', 'group' => $this->group($group)];
    }

    /** @return array{status: string, why?: string, group?: array} */
    private function own(string $id, int $now): array
    {
        $db = $this->minn->db;
        $halls = $db->table(self::TABLE);
        $row = $db->row("SELECT group_id, status FROM {$halls} WHERE claim_id = ? FOR UPDATE", [$id]);
        if ($row === null) {
            return ['status' => 'refused', 'why' => 'gone'];
        }
        $group = (int) $row['group_id'];
        if ($row['status'] === 'built') {
            return ['status' => 'settled', 'group' => $this->group($group)];
        }
        if ($row['status'] === 'lost') {
            return ['status' => 'refused', 'why' => 'owns'];
        }
        $db->row('SELECT id FROM ' . $db->table(Groups::TABLE) . ' WHERE id = ? FOR UPDATE', [$group]);
        if ($this->owns($group)) {
            $db->execute("UPDATE {$halls} SET status = 'lost' WHERE claim_id = ?", [$id]);
            return ['status' => 'refused', 'why' => 'owns'];
        }
        $db->execute("UPDATE {$halls} SET status = 'built', built_at = ? WHERE claim_id = ?", [gmdate('Y-m-d H:i:s', $now), $id]);
        return ['status' => 'settled', 'group' => $this->group($group)];
    }

    /** @return array{status: string} */
    private function expire(string $id): array
    {
        $this->minn->db->execute('UPDATE ' . $this->minn->db->table(self::TABLE) . " SET status = 'expired' WHERE claim_id = ? AND status = 'claimed'", [$id]);
        return ['status' => 'settled'];
    }

    private function owns(int $group): bool
    {
        $n = $this->minn->db->value('SELECT COUNT(*) FROM ' . $this->minn->db->table(self::TABLE) . " WHERE group_id = ? AND status = 'built'", [$group]);
        return (int) $n > 0;
    }

    /** What the realtime server shows of a group (Groups::card's name and mode); a group since deleted keeps its id. */
    private function group(int $id): array
    {
        $row = $this->minn->db->row('SELECT name, mode FROM ' . $this->minn->db->table(Groups::TABLE) . ' WHERE id = ?', [$id]);
        return ['id' => $id, 'name' => $row === null ? '' : (string) $row['name'], 'private' => $row === null || $row['mode'] === 'private'];
    }
}
