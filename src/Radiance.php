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
use Minn\Json;
use Minn\RestError;

/**
 * groddle/v1/radiance: what the realtime server needs to know before a
 * player radiates to others (register G330, Transcendental Radiation): the
 * highest rank of the skill the multiplayer save has learned and the
 * Transcendental cards it holds, signed with the realtime secret
 * (RealtimeTicket::sign) so the server (radiance.go) takes neither from the
 * game. The rank sets the range and the time (GameServerJS
 * players/inc_imagination.js:906-933, items/catalog_skill_packages.js:2397-2426);
 * Transcendental Radius I-III widen the range, Radiation Prolonger and
 * Prolongest lengthen the time, Transcendental Highway is the radiator's own
 * bonus (items/focusing_orb.js:2046-2051, 1210-1235).
 *
 * Token: {k: "radiance", sub, r: 0-3, u: the cards, comma separated, iat, exp}.
 * REST rules: signed in, the cookie with its nonce; the caller only.
 */
final readonly class Radiance
{
    /** Seconds a token is good for (the game asks again before each Radiate and on each new socket). */
    public const TTL = 900;

    /** inc_data_skills.js transcendental_radiation_1..3. */
    public const RANKS = ['transcendental_radiation_1' => 1, 'transcendental_radiation_2' => 2, 'transcendental_radiation_3' => 3];

    /** inc_data_imagination.js: the cards radiance.go reads. */
    public const CARDS = ['transcendental_radius_1', 'transcendental_radius_2', 'transcendental_radius_3', 'transcendental_benefits_1', 'transcendental_benefits_2', 'transcendental_imagination'];

    public function __construct(private Seams $minn)
    {
    }

    /** The caller's rank and cards and the token over them. */
    #[Route(Method::Get, '/groddle/v1/radiance', policy: new Policy(Access::SignedIn))]
    public function mine(Request $request): Response
    {
        $me = $this->minn->reader->userId;
        $secret = (new RealtimeTicket($this->minn))->secret();
        if ($secret === null) {
            throw new RestError('groddle_realtime_off', 'Playing with others is not set up on this site.', 503);
        }
        ['rank' => $rank, 'cards' => $cards] = self::fromSave($this->minn->db, $me);
        $now = time();
        $payload = ['k' => 'radiance', 'sub' => $me, 'r' => $rank, 'u' => implode(',', $cards), 'iat' => $now, 'exp' => $now + self::TTL];
        return Response::json(['rank' => $rank, 'cards' => $cards, 'watch' => ['token' => RealtimeTicket::sign($payload, $secret), 'expiresIn' => self::TTL]]);
    }

    /**
     * The rank and cards a multiplayer save holds: playerState.systems.skills.learned
     * and playerState.player.imaginationUpgrades (game.js persistentSystems).
     *
     * @return array{rank: int, cards: list<string>}
     */
    public static function fromSave(Db $db, int $id): array
    {
        $out = ['rank' => 0, 'cards' => []];
        $stored = $db->value('SELECT data FROM ' . $db->table(MultiplayerSave::TABLE) . ' WHERE user_id = ?', [$id]);
        if (!is_string($stored) || $stored === '') {
            return $out;
        }
        $save = Json::decode($stored, or: null);
        $state = is_array($save) && is_string($save['entries']['playerState'] ?? null) ? Json::decode($save['entries']['playerState'], or: null) : null;
        if (!is_array($state)) {
            return $out;
        }
        $learned = $state['systems']['skills']['learned'] ?? [];
        if (is_array($learned)) {
            foreach (self::RANKS as $skill => $rank) {
                if (in_array($skill, $learned, true)) {
                    $out['rank'] = max($out['rank'], $rank);
                }
            }
        }
        $owned = $state['player']['imaginationUpgrades'] ?? [];
        if (is_array($owned)) {
            $out['cards'] = array_values(array_filter(self::CARDS, static fn (string $card): bool => in_array($card, $owned, true)));
        }
        return $out;
    }
}
