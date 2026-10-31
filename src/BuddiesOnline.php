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

/**
 * GET groddle/v1/buddies/online: which of the caller's buddies are playing
 * now and where, for the phone companion (the game's play/phone/ page, Austin
 * 2026-10-31), which reads the lists over REST and never opens the realtime
 * socket: a socket would take over the player's game on another device (one
 * live session a character) and count as time online.
 *
 *   {"live": true, "online": [{"id": 7, "name": "Ada", "street": "Gregarious Grange", "hub": "Groddle Meadow"}]}
 *   {"live": false, "online": []}   the realtime server did not answer (or the site has no secret)
 *
 * Who is told is buddies.go's rule (tellable): the players you hold as a
 * buddy and those who hold you (the reverse entries), never someone either
 * of you ignores. Where is what buddies.go tells a watcher: a street's public
 * name, or "" for a private place (a race, a home, a quest room: anything
 * that is not an ordinary street in the game's street index) or between
 * streets. The list of who is connected is the realtime server's own
 * (admin.go "online", the call the console makes, signed by RealtimeCall).
 *
 * REST rules: signed in, the cookie with its nonce; the user is the caller,
 * never read from the request. Read only.
 */
final readonly class BuddiesOnline
{
    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/buddies/online', policy: new Policy(Access::SignedIn))]
    public function online(Request $request): Response
    {
        $me = $this->minn->reader->userId;
        $db = $this->minn->db;
        $buddies = $db->table(Buddies::TABLE);
        $ignores = $db->table(Buddies::IGNORES);
        $ids = static fn (array $rows, string $key): array => array_map(static fn (array $r): int => (int) $r[$key], $rows);
        $holds = array_merge(
            $ids($db->rows("SELECT buddy_id FROM {$buddies} WHERE user_id = ?", [$me]), 'buddy_id'),
            $ids($db->rows("SELECT user_id FROM {$buddies} WHERE buddy_id = ?", [$me]), 'user_id'),
        );
        $blocked = array_merge(
            $ids($db->rows("SELECT ignored_id FROM {$ignores} WHERE user_id = ?", [$me]), 'ignored_id'),
            $ids($db->rows("SELECT user_id FROM {$ignores} WHERE ignored_id = ?", [$me]), 'user_id'),
        );
        $none = static fn (): Response => Response::json(['live' => false, 'online' => []])->withHeader('Cache-Control', 'no-store');
        if ($holds === []) {
            return Response::json(['live' => true, 'online' => []])->withHeader('Cache-Control', 'no-store');
        }
        $answer = (new RealtimeCall($this->minn))->post('online', []);
        if (!is_array($answer['players'] ?? null)) {
            return $none();
        }
        $shown = self::shown($answer['players'], $me, $holds, $blocked);
        $names = (new Characters($db))->namesOf(array_map(static fn (array $p): int => $p['id'], $shown));
        $index = $this->streetIndex();
        $out = [];
        foreach ($shown as $p) {
            $where = self::where($p['street'], $index);
            $out[] = ['id' => $p['id'], 'name' => (string) ($names[$p['id']] ?? $p['name'])] + $where;
        }
        return Response::json(['live' => true, 'online' => $out])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The connected players the caller may hear about (buddies.go tellable):
     * held either way, ignored neither way, not the caller; each once, by name.
     *
     * @param list<mixed> $players the realtime server's [{id, name, street}]
     * @param list<int> $holds buddies and reverse entries
     * @param list<int> $blocked ignored by the caller or ignoring the caller
     * @return list<array{id: int, name: string, street: string}>
     */
    public static function shown(array $players, int $me, array $holds, array $blocked): array
    {
        $hold = array_fill_keys($holds, true);
        $block = array_fill_keys($blocked, true);
        $out = [];
        foreach ($players as $p) {
            $id = is_array($p) && is_int($p['id'] ?? null) ? $p['id'] : 0;
            if ($id <= 0 || $id === $me || !isset($hold[$id]) || isset($block[$id]) || isset($out[$id])) {
                continue;
            }
            $out[$id] = ['id' => $id, 'name' => is_string($p['name'] ?? null) ? $p['name'] : '', 'street' => is_string($p['street'] ?? null) ? $p['street'] : ''];
        }
        usort($out, static fn (array $a, array $b): int => [strtolower($a['name']), $a['id']] <=> [strtolower($b['name']), $b['id']]);
        return $out;
    }

    /**
     * Where a street tsid reads for a buddy: its public name and hub, or ""
     * for a private place or between streets (buddies.go shownStreet; the
     * game's buddyWhere says "A secret place"). A location's L tsid reads as
     * its street's G one, as the game's street index lookups do (game.js
     * enterStreetWithTransition).
     *
     * @param array<string, mixed> $index the game's street index (tsid => row)
     * @return array{street: string, hub: string}
     */
    public static function where(string $tsid, array $index): array
    {
        $row = null;
        if ($tsid !== '' && !str_contains($tsid, '_')) {
            foreach ([$tsid, (string) preg_replace('/^L/', 'G', $tsid), (string) preg_replace('/^G/', 'L', $tsid)] as $key) {
                if (is_array($index[$key] ?? null)) {
                    $row = $index[$key];
                    break;
                }
            }
        }
        $label = is_string($row['label'] ?? null) ? $row['label'] : '';
        return $label === '' ? ['street' => '', 'hub' => ''] : ['street' => $label, 'hub' => is_string($row['hubName'] ?? null) ? $row['hubName'] : ''];
    }

    /** The game's street index (tsid => row), or empty. @return array<string, mixed> */
    private function streetIndex(): array
    {
        $file = StaticRoot::fromSite($this->minn)->dir . '/assets/streets/index.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data['streets'] ?? null) ? $data['streets'] : [];
    }
}
