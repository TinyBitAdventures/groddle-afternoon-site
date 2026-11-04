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
 * What the /chat/ app needs to draw the players the realtime server names
 * (presence.go sends ids, names and street ids only):
 *
 *   GET groddle/v1/chat/people?ids=7,9&streets=GA5…,LA9…
 *       {"people":[{"id":7,"name":"Ada","profile":"/players/ada/"|null,"tile":"…png"|null,"buddy":true}],
 *        "streets":{"GA5…":{"street":"Gregarious Grange","hub":"Groddle Meadow"}}}
 *
 * A profile and its tile picture only where the profile is public (level
 * Profiles::LISTED_LEVEL and up, as on the Community boards); nobody either
 * of you blocks (they are left out); street ids by the game's street index,
 * a private place answering nothing (as BuddiesOnline). Signed-in players
 * only, private, no-store; at most 100 ids and 50 streets a call.
 */
final readonly class ChatPeople
{
    public const IDS_MAX = 100;
    public const STREETS_MAX = 50;

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/chat/people', policy: new Policy(Access::SignedIn))]
    public function people(Request $request): Response
    {
        $user = $this->minn->user() ?? throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        $me = $user->id;
        $db = $this->minn->db;
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($request->query('ids') ?? ''))), static fn (int $i): bool => $i > 0))), 0, self::IDS_MAX);
        $ignores = $db->table(Buddies::IGNORES);
        $blocked = [];
        foreach ($db->rows("SELECT ignored_id AS id FROM {$ignores} WHERE user_id = ? UNION SELECT user_id AS id FROM {$ignores} WHERE ignored_id = ?", [$me, $me]) as $r) {
            $blocked[(int) $r['id']] = true;
        }
        $ids = array_values(array_filter($ids, static fn (int $i): bool => $i === $me || !isset($blocked[$i])));
        $buddies = $db->table(Buddies::TABLE);
        $held = [];
        foreach ($db->rows("SELECT buddy_id AS id FROM {$buddies} WHERE user_id = ? UNION SELECT user_id AS id FROM {$buddies} WHERE buddy_id = ?", [$me, $me]) as $r) {
            $held[(int) $r['id']] = true;
        }
        $names = $ids === [] ? [] : (new Characters($db))->namesOf($ids);
        $authors = (new Community($this->minn))->authors(array_keys($names));
        $people = [];
        foreach ($names as $id => $name) {
            $a = $authors[$id] ?? ['profile' => null, 'tile' => null];
            $people[] = ['id' => (int) $id, 'name' => (string) $name, 'profile' => $a['profile'], 'tile' => $a['tile'], 'buddy' => isset($held[(int) $id])];
        }
        $streets = [];
        $tsids = array_slice(array_values(array_unique(array_filter(explode(',', (string) ($request->query('streets') ?? '')), static fn (string $s): bool => preg_match('/^[A-Za-z0-9]{1,32}$/', $s) === 1))), 0, self::STREETS_MAX);
        if ($tsids !== []) {
            $file = StaticRoot::fromSite($this->minn)->dir . '/assets/streets/index.json';
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $index = is_array($data['streets'] ?? null) ? $data['streets'] : [];
            foreach ($tsids as $t) {
                $streets[$t] = BuddiesOnline::where($t, $index);
            }
        }
        return Response::json(['people' => $people, 'streets' => (object) $streets])->withHeader('Cache-Control', 'private, no-store');
    }
}
