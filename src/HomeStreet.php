<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Content\Users;
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
 * What else stands on a player's home street and in their house for anyone
 * there (register G328 and G332, lane HOMES3), read from the owner's stored
 * save and the site's own rows, never from what a visitor sends:
 *
 *   GET groddle/v1/homes/{id}/street
 *       200 {"owner":{"id","name"},"signpost":[{"position","id","name"}],
 *            "poles":[Pole],"sales":[{"op","uid","class","price"}],
 *            "restores":[{"job","proto","yard","item","cycle","mult","token"}]}
 *
 * - signpost: the arms of the owner's post (HomeSignpost).
 * - poles: Note Poles (items/note_pole.js) placed on the home street or in
 *   the house, with the note posted on each (post 117-171: title and
 *   contents, last_editor and last_edited; onInputBoxResponse 323-347 keeps
 *   150 and 1000 characters). Kept in the owner's save, the game's
 *   features/home-street-mp-feature.js slice; tags stripped here.
 * - sales: furniture the owner has put up for sale, at the site's price
 *   (LedgerFurniture).
 * - restores: each worn-out cultivation's restoration project, with a
 *   token signed with the realtime secret ({k: home_restore, sub: the
 *   caller, o: owner, job, proto, yard, mult}) the game hands the realtime
 *   server (projects.go project_home), which runs it as a project everyone
 *   on that street or in that house shares (players/inc_jobs.js 420-470,
 *   quest.js type 5): the save is the only word on what is worn out.
 *
 * Who may read it: the owner, or anyone Neighbours lets visit (not blocked,
 * neither in the tutorial, the owner has a home).
 */
final readonly class HomeStreet
{
    /** The feature slice the game keeps its poles in. */
    public const SLICE = 'home-street-mp';

    /** Poles on one home (ours: the source capped nothing but the street's width). */
    public const POLES_MAX = 24;

    /** note_pole.js onInputBoxResponse 342-343. */
    public const TITLE_MAX = 150;
    public const BODY_MAX = 1000;

    /** instancePropsChoices 33-38 (the AS3 file has more; the server's lists are these). */
    public const SIZES = ['Tall', 'Medium', 'Short'];
    public const POLE_MAX = 10;
    public const SIGN_MAX = 9;

    /** Seconds a restoration token is good for. */
    public const TOKEN_TTL = 1800;

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/homes/{id:\d+}/street', policy: new Policy(Access::SignedIn))]
    public function street(Request $request, string $id): Response
    {
        $owner = (int) $id;
        $db = $this->minn->db;
        if ($owner <= 0 || (new Users($db))->find($owner) === null) {
            throw new RestError('groddle_home_player', Neighbours::TEXT_NOT_FOUND, 404);
        }
        $me = $this->minn->reader->userId;
        $theirs = Neighbours::state($db, $owner);
        if (!Neighbours::hasHome($theirs)) {
            throw new RestError('groddle_home_none', Neighbours::TEXT_NO_HOUSE, 404);
        }
        if ($owner !== $me) {
            $ignores = $db->table(Buddies::IGNORES);
            if ($db->value("SELECT 1 FROM {$ignores} WHERE user_id = ? AND ignored_id = ?", [$owner, $me]) !== null) {
                throw new RestError('groddle_home_blocked', Neighbours::TEXT_BLOCKED, 403);
            }
            if (Neighbours::inTutorial($theirs)) {
                throw new RestError('groddle_home_newxp', Neighbours::TEXT_NEWXP, 403);
            }
            if (Neighbours::inTutorial(Neighbours::state($db, $me))) {
                throw new RestError('groddle_home_newxp_us', Neighbours::TEXT_NEWXP_US, 403);
            }
        }
        $name = (new Characters($db))->namesOf([$owner])[$owner] ?? 'Someone';
        return Response::json([
            'owner' => ['id' => $owner, 'name' => $name],
            'signpost' => HomeSignpost::neighbors($db, $owner),
            'poles' => self::poles($theirs),
            'sales' => LedgerFurniture::listed($db, $owner),
            'restores' => $this->restores($theirs, $me, $owner),
        ]);
    }

    /**
     * The owner's Note Poles as the save keeps them, cleaned: room (street or
     * house), rx (x from the house), the look and the note.
     *
     * @return list<array<string, mixed>>
     */
    public static function poles(?object $state): array
    {
        $slice = $state?->systems?->features?->{self::SLICE} ?? null;
        $list = is_object($slice) && is_array($slice->poles ?? null) ? $slice->poles : [];
        $out = [];
        foreach (array_slice($list, 0, self::POLES_MAX) as $p) {
            if (!is_object($p) || !is_numeric($p->id ?? null) || !is_numeric($p->rx ?? null)) {
                continue;
            }
            $cfg = is_object($p->cfg ?? null) ? $p->cfg : (object) [];
            $size = in_array($cfg->size ?? null, self::SIZES, true) ? $cfg->size : 'Tall';
            $pole = is_numeric($cfg->pole ?? null) ? max(1, min(self::POLE_MAX, (int) $cfg->pole)) : 1;
            $sign = is_numeric($cfg->sign ?? null) ? max(1, min(self::SIGN_MAX, (int) $cfg->sign)) : 1;
            $row = [
                'id' => max(1, (int) $p->id),
                'room' => ($p->room ?? 'street') === 'house' ? 'house' : 'street',
                'rx' => (int) round((float) $p->rx),
                'cfg' => ['size' => $size, 'pole' => (string) $pole, 'sign' => (string) $sign],
                'note' => null,
            ];
            $note = is_object($p->note ?? null) ? $p->note : null;
            if ($note !== null) {
                $by = is_object($note->by ?? null) ? $note->by : (object) [];
                $row['note'] = [
                    'title' => self::text($note->title ?? '', self::TITLE_MAX),
                    'body' => self::text($note->body ?? '', self::BODY_MAX, true),
                    'by' => ['id' => is_numeric($by->id ?? null) ? max(0, (int) $by->id) : 0, 'name' => self::text($by->name ?? '', 40)],
                    'at' => is_numeric($note->at ?? null) ? max(0, (int) $note->at) : 0,
                ];
            }
            $out[] = $row;
        }
        return $out;
    }

    /** Plain text: no tags or control characters (new lines kept in a body), at most max characters. */
    public static function text(mixed $s, int $max, bool $lines = false): string
    {
        if (!is_string($s)) {
            return '';
        }
        $s = strip_tags($s);
        $s = (string) preg_replace($lines ? '/[^\P{C}\n]/u' : '/\p{C}/u', '', $s);
        if (!$lines) {
            $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        }
        return mb_substr($s, 0, $max);
    }

    /**
     * Each worn-out cultivation in the owner's save (the home street's and
     * the back yard's), with its token for the realtime server.
     *
     * @return list<array<string, mixed>>
     */
    private function restores(?object $state, int $me, int $owner): array
    {
        $secret = (new RealtimeTicket($this->minn))->secret();
        $homes = $state?->systems?->features?->homes ?? null;
        if ($secret === null || !is_object($homes)) {
            return [];
        }
        $out = [];
        $now = time();
        foreach (['street' => $homes->cult ?? null, 'back' => $homes->backYard ?? null] as $yard => $where) {
            $items = is_object($where) && is_array($where->items ?? null) ? $where->items : [];
            foreach ($items as $it) {
                $row = self::restore($owner, $yard, $it);
                if ($row === null) {
                    continue;
                }
                $row['token'] = RealtimeTicket::sign(['k' => 'home_restore', 'sub' => $me, 'o' => $owner, 'job' => $row['job'], 'proto' => $row['proto'], 'yard' => $yard, 'mult' => $row['mult'], 'iat' => $now, 'exp' => $now + self::TOKEN_TTL], $secret);
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * A worn-out item's project: the job id the realtime server and both
     * games use (js/home-street-mp.js restoreWireId: h for the home street or
     * y for the back yard, then the owner, the item and its cycle, base 36), the proto and the lifespan
     * multiplier the rewards are scaled by (quest.js:1762-1767
     * getMaxWearMultiplier; js/home-cultivation.js lifespanMultiplier).
     *
     * @return array{job: string, proto: string, yard: string, item: int, cycle: int, mult: float}|null
     */
    public static function restore(int $owner, string $yard, mixed $it): ?array
    {
        if (!is_object($it) || ($it->depleted ?? false) !== true || !is_numeric($it->id ?? null)) {
            return null;
        }
        $proto = $it->protoClass ?? null;
        if (!is_string($proto) || preg_match('/^proto_[a-z_]{1,48}$/', $proto) !== 1 || $proto === 'proto_furniture_tower_chassis') {
            return null;
        }
        $id = (int) $it->id;
        $cycle = is_numeric($it->cycle ?? null) && (int) $it->cycle > 0 ? (int) $it->cycle : 1;
        if ($id <= 0) {
            return null;
        }
        $steps = is_numeric($it->lifeSteps ?? null) ? max(0, min(20, (int) floor((float) $it->lifeSteps))) : 0;
        $job = ($yard === 'back' ? 'y' : 'h') . base_convert((string) $owner, 10, 36) . '-' . base_convert((string) $id, 10, 36) . '-' . base_convert((string) $cycle, 10, 36);
        if (strlen($job) > 16) {
            return null;
        }
        return ['job' => $job, 'proto' => $proto, 'yard' => $yard, 'item' => $id, 'cycle' => $cycle, 'mult' => round(1 + 0.05 * $steps, 2)];
    }
}
