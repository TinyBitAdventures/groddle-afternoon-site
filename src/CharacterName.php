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
 * A player changes the name of the character they are playing (register G1213, report #636). Glitch's
 * Account page had "Change name" for 100 currants, about once in two months; Austin 2026-11-23: "1000
 * currants and a month cooldown". For the signed-in player only, the character playing now (the caller,
 * X-Groddle-Character checked as on every value-moving route):
 *
 *   GET  groddle/v1/characters/name           {"name","cost":1000,"days":30,"nextAt":unix|null,"currants":int,"formerly":string|null}
 *   POST groddle/v1/characters/name {"name"}  200 {"status":"renamed","player":{"id","name"},"was","receipt":Receipt,"nextAt","live":bool}
 *        422 groddle_invalid | name_taken (fields.name)    409 name_same    429 name_change_wait {nextAt}
 *        409 name_change_currants {cost, currants}          403 groddle_suspended
 *
 * The rules are a new character's (CharacterRoutes): PlayerAccount::NAME, the word filter, no other
 * character or account has it (Characters::taken, letter case aside; changing only the letter case of
 * your own name is allowed), and no waiting sign-up of another email asked for it. One change per DAYS
 * per character: a refusal says when the next is allowed. An administrator's rename (AdminConsole) costs
 * nothing and starts no wait.
 *
 * The cost comes out of the character's ledger (Ledger, G335: the currants the site holds the save to),
 * in the same transaction as the rename, recorded as a settled trade with nobody (groddle_trades, id
 * rename-<16 hex>, b_id 0), as an auction's fee is (LedgerAuction): the answer's receipt is applied once
 * by the game (the trade slice's applied list, ledgerReceipt), and a load applies it to the stored save
 * if the game never did (Ledger::catchUp). The ledger row is locked first (FOR UPDATE), so two windows
 * can't both pass the wait or both spend. Refused, nothing is written or spent.
 *
 * The old name is held for Logins::NAME_HOLD (30 days, as a retired character's name is held:
 * Characters::taken, heldByRename), so nobody can take it and pass as them; only they may take it
 * back, and only once their own wait allows a change.
 *
 * Kept: the new name on the character's row and its account's display name and nickname
 * (Characters::rename), and a row in groddle_player_renames with admin_user_id 0 (the player did it):
 * the old name, its /players/ address (PlayerPages answers a 301 from it, as for an administrator's
 * rename) and when. Every list that reads names by id (buddies, mail, auctions, boards, groups, the
 * Community) shows the new name at once; copies made before (chat lines, posts) keep the old one. The
 * realtime server is told at once (its signed rename call), so the street, chat and buddy lists change
 * without a reconnect; its next ticket carries the new name in any case (RealtimeTicket).
 *
 * "formerly <old name>" (ours, G1213: Glitch showed no old name): for FORMERLY seconds after a player's
 * own rename, their profile page and the /chat/ people list show the old name, so friends can still
 * find them. An administrator's rename never shows one (it is often a name that had to go).
 */
final readonly class CharacterName
{
    /** Austin 2026-11-23: 1000 currants (Glitch's Account page: 100). */
    public const COST = 1000;

    /** Austin 2026-11-23: one change a month (Glitch: about two months). */
    public const DAYS = 30;

    /** How long "formerly <old name>" shows after a player's own rename. */
    public const FORMERLY = 30 * 86400;

    /** The trade id's prefix (Ledger::TRADES). */
    public const OP = 'rename-';

    private const NAME_WORDS = 'Names are 2 to 19 letters, numbers, spaces, - or _, starting and ending with a letter or number.';

    private const WORDS = [
        'name_taken' => 'Someone already has that name. Try another.',
        'name_same' => 'That is already your name.',
        'name_change_currants' => 'Changing your name costs 1,000 currants.',
        'groddle_suspended' => AdminConsole::SUSPENDED,
    ];

    public function __construct(private Seams $minn)
    {
    }

    #[Route(Method::Get, '/groddle/v1/characters/name', policy: new Policy(Access::SignedIn))]
    public function read(Request $request): Response
    {
        $me = $this->me();
        $db = $this->minn->db;
        $now = time();
        $ledger = Ledger::fromSeams($this->minn)->peek($me);
        return Response::json([
            'name' => (new Characters($db))->namesOf([$me])[$me] ?? '',
            'cost' => self::COST,
            'days' => self::DAYS,
            'nextAt' => self::nextAt($db, $me, $now),
            'currants' => (int) ($ledger['currants'] ?? 0),
            'formerly' => self::formerly($db, [$me], $now)[$me] ?? null,
        ])->withHeader('Cache-Control', 'private, no-store');
    }

    #[Route(Method::Post, '/groddle/v1/characters/name', policy: new Policy(Access::SignedIn))]
    public function change(Request $request): Response
    {
        $me = $this->me();
        Logins::guard($request, $me); // 409 character_changed from a page of another character
        $db = $this->minn->db;
        if (AdminConsole::banned($db, $me) !== null) {
            return self::refused('groddle_suspended', 403);
        }
        $raw = $request->json()['name'] ?? '';
        $name = trim(preg_replace('/\s+/', ' ', is_string($raw) ? $raw : '') ?? '');
        $out = Ledger::atomically($db, fn () => $this->rename($me, $name, time()));
        if (!isset($out['status'])) {
            return $out['refusal'];
        }
        // The realtime server hears it at once; offline (or no answer), the next ticket carries it.
        $live = (new RealtimeCall($this->minn))->post('rename', ['player' => $me, 'name' => $name]);
        return Response::json($out + ['live' => ($live['status'] ?? '') === 'ok'])->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * The rename and its cost, inside the caller's transaction: the answer, or ['refusal' => Response]
     * with nothing written.
     *
     * @return array<string, mixed>
     */
    private function rename(int $me, string $name, int $now): array
    {
        $db = $this->minn->db;
        $characters = new Characters($db);
        $ledger = Ledger::fromSeams($this->minn);
        // The ledger row first: one character's renames and spends wait for each other.
        $state = $ledger->lock($me, $now);
        $was = $characters->namesOf([$me])[$me] ?? null;
        if ($was === null) {
            throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401);
        }
        $next = self::nextAt($db, $me, $now);
        if ($next !== null) {
            return ['refusal' => self::refused('name_change_wait', 429, [], ['nextAt' => $next], self::waitWords($next, $now))];
        }
        $bad = $this->nameRefusal($me, $was, $name, $now);
        if ($bad !== null) {
            return ['refusal' => $bad];
        }
        if ($state['currants'] < self::COST) {
            return ['refusal' => self::refused('name_change_currants', 409, [], ['cost' => self::COST, 'currants' => $state['currants']], self::WORDS['name_change_currants'] . ' You have ' . number_format($state['currants']) . '.')];
        }
        $oldSlug = Profiles::slugs($db)[$me] ?? Profiles::slug($was);
        try {
            $renamed = $characters->rename($me, $name);
        } catch (\mysqli_sql_exception $e) {
            // Another player took the name in the same instant (name_key is unique).
            if ($e->getCode() !== 1062) {
                throw $e;
            }
            $renamed = false;
        }
        if (!$renamed) {
            return ['refusal' => self::refused('name_taken', 422, ['name' => self::WORDS['name_taken']])];
        }
        $op = self::OP . bin2hex(random_bytes(8));
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET currants = ?, updated_at = ? WHERE user_id = ?', [$state['currants'] - self::COST, $stamp, $me]);
        $db->execute('INSERT INTO ' . $db->table(Ledger::TRADES) . " (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$op, $me, '{"items":[],"currants":' . self::COST . '}', '{"items":[],"currants":0}', $stamp, $stamp]);
        $ledger->log($me, 'spend', ['spend' => $op, 'why' => 'rename', 'gave' => ['items' => (object) [], 'currants' => self::COST], 'from' => $was, 'to' => $name], $now);
        $db->execute('INSERT INTO ' . $db->table(AdminConsole::RENAMES) . ' (user_id, old_name, old_slug, new_name, admin_user_id, renamed_at) VALUES (?, ?, ?, ?, 0, ?)', [$me, mb_substr($was, 0, 32), substr($oldSlug, 0, 64), $name, $stamp]);
        return [
            'status' => 'renamed',
            'player' => ['id' => $me, 'name' => $name],
            'was' => $was,
            'receipt' => ['op' => $op, 'gave' => ['items' => [], 'currants' => self::COST], 'got' => ['items' => [], 'currants' => 0]],
            'nextAt' => $now + self::DAYS * 86400,
        ];
    }

    /** A new character's rules (CharacterRoutes::nameRefusal), and the same name refused: null when it will do. */
    private function nameRefusal(int $me, string $was, string $name, int $now): ?Response
    {
        if (preg_match(PlayerAccount::NAME, $name) !== 1) {
            return self::refused('groddle_invalid', 422, ['name' => self::NAME_WORDS], [], self::NAME_WORDS);
        }
        if (WordFilter::has($name)) {
            return self::refused('groddle_invalid', 422, ['name' => WordFilter::NAME_REFUSED], [], WordFilter::NAME_REFUSED);
        }
        if ($name === $was) {
            return self::refused('name_same', 409, ['name' => self::WORDS['name_same']]);
        }
        // Only the letter case of your own name: it is still yours.
        if (strtolower($name) === strtolower($was)) {
            return null;
        }
        $db = $this->minn->db;
        $login = (new Logins($db))->loginOf($me);
        $email = (string) ($db->value('SELECT user_email FROM ' . $db->table('users') . ' WHERE ID = ?', [$login]) ?? '');
        // Your own old name is still held for you (Logins::NAME_HOLD), so you may take it back once the wait allows.
        if ((new Characters($db))->taken($name, $now, $me) || (new Signups($db))->nameHeldByOther($name, $email, $now)) {
            return self::refused('name_taken', 422, ['name' => self::WORDS['name_taken']]);
        }
        return null;
    }

    /** When this character may change its name again (unix), or null when it may now. */
    public static function nextAt(Db $db, int $id, int $now): ?int
    {
        $last = $db->value('SELECT MAX(renamed_at) FROM ' . $db->table(AdminConsole::RENAMES) . ' WHERE user_id = ? AND admin_user_id = 0', [$id]);
        if (!is_string($last) || $last === '') {
            return null;
        }
        $next = (int) strtotime($last . ' UTC') + self::DAYS * 86400;
        return $next > $now ? $next : null;
    }

    /**
     * The name each character had before its own rename, while it is shown (FORMERLY): id => old name.
     * Only the latest rename counts, and only a player's own (never an administrator's) that changed more
     * than the letter case.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public static function formerly(Db $db, array $ids, int $now): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $t = $db->table(AdminConsole::RENAMES);
        $rows = $db->rows("SELECT r.user_id, r.old_name, r.new_name, r.admin_user_id, r.renamed_at FROM {$t} r JOIN (SELECT user_id, MAX(id) AS id FROM {$t} WHERE user_id IN (?) GROUP BY user_id) l ON l.id = r.id", [$ids]);
        $out = [];
        foreach ($rows as $r) {
            // Only the letter case changed: nobody needs telling who it was.
            $same = strtolower((string) $r['old_name']) === strtolower((string) $r['new_name']);
            if (!$same && (int) $r['admin_user_id'] === 0 && (int) strtotime((string) $r['renamed_at'] . ' UTC') > $now - self::FORMERLY) {
                $out[(int) $r['user_id']] = (string) $r['old_name'];
            }
        }
        return $out;
    }

    /** The wait's words: the day the next change is allowed (UTC, as the game's dates are). */
    public static function waitWords(int $next, int $now): string
    {
        $days = max(1, (int) ceil(($next - $now) / 86400));
        return 'You can change your name once every 30 days. Your next change is allowed on ' . gmdate('F j', $next) . ($days === 1 ? ' (in 1 day).' : " (in {$days} days).");
    }

    private function me(): int
    {
        return ($this->minn->user() ?? throw new RestError('rest_not_logged_in', 'You are not currently logged in.', 401))->id;
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed> $extra
     */
    private static function refused(string $code, int $status, array $fields = [], array $extra = [], ?string $message = null): Response
    {
        $message ??= self::WORDS[$code] ?? 'That didn\'t work. Try again.';
        return Response::json(['code' => $code, 'message' => $message, 'fields' => (object) $fields] + $extra + ['data' => ['status' => $status]], $status)->withHeader('Cache-Control', 'private, no-store');
    }
}
