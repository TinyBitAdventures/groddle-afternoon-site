<?php

declare(strict_types=1);

namespace Groddle\Minn;

use Minn\Extension\Seams;

/**
 * A street project's moves in one character's ledger (G346,
 * groddle-afternoon-server projects.go): a contribution of items or currants
 * taken out of it (gave), or a phase reward's currants and items put in
 * (got). LedgerService's POST groddle/v1/ledger/project, signed by the
 * realtime server (ServiceCall), runs it:
 *
 *   {"id":"job-g-…"|"job-r-…","user":7,"gave":Goods,"got":Goods}
 *   200 {"status":"settled"} | 409 {"status":"refused","short":{"id":7,"c":"ginger"}|{"id":7,"currants":true}}
 *   | 200 {"status":"void"} (refused before: asked again)
 *
 * The server never counts a contribution the site has not taken: a giver
 * whose ledger lacks what it gives is refused, and nothing moves. Each id is
 * recorded once (groddle_trades, b_id 0: a trade with the project, b's side
 * applied), so asking again answers as recorded: a contribution is never
 * taken twice, a reward never paid twice. Until the player's save lists the
 * id as applied (the Job Board's slice, LedgerMath::applied) a save write is
 * compared as if the move had not happened, and a load applies it to the
 * stored save (Ledger::catchUp), like a settled trade. Only tradeable
 * classes live in the ledger: a reward item outside it is the game's to
 * give (the save carries it), never the ledger's.
 */
final readonly class LedgerProject
{
    public function __construct(private Seams $minn)
    {
    }

    /** projects.go: job-g- (a give) or job-r- (a reward), then lowercase letters, digits and dashes, at most 40. */
    public static function id(mixed $id): bool
    {
        return is_string($id) && preg_match('/^job-[gr]-[a-z0-9-]{1,34}$/', $id) === 1;
    }

    /**
     * @param array{items: array<string,int>, currants: int} $gave
     * @param array{items: array<string,int>, currants: int} $got
     * @return array{status: string, short?: array}
     */
    public function settle(string $id, int $user, array $gave, array $got, int $now): array
    {
        $db = $this->minn->db;
        $economy = Economy::shared();
        $t = $db->table(Ledger::TRADES);
        $held = $db->row("SELECT status FROM {$t} WHERE trade_id = ? FOR UPDATE", [$id]);
        if ($held !== null) {
            return ['status' => (string) $held['status']];
        }
        $ledger = Ledger::fromSeams($this->minn);
        $state = $ledger->lock($user, $now);
        $short = null;
        foreach ($gave['items'] as $c => $n) {
            if (!$economy->tradeable((string) $c) || ($state['items'][$c] ?? 0) < $n) {
                $short ??= ['id' => $user, 'c' => (string) $c];
            }
        }
        if ($short === null && $state['currants'] < $gave['currants']) {
            $short = ['id' => $user, 'currants' => true];
        }
        $got = ['items' => array_filter($got['items'], static fn ($n, $c) => $economy->tradeable((string) $c), ARRAY_FILTER_USE_BOTH), 'currants' => $got['currants']];
        $stamp = gmdate('Y-m-d H:i:s', $now);
        if ($short !== null) {
            $db->execute("INSERT INTO {$t} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'void', ?, 0, ?, ?, ?, ?)", [$id, $user, self::json($gave), self::json($got), $stamp, $stamp]);
            $ledger->log($user, 'refuse', ['project' => $id, 'short' => $short], $now);
            return ['status' => 'refused', 'short' => $short];
        }
        $delta = ['items' => [], 'currants' => $got['currants'] - $gave['currants']];
        foreach ($gave['items'] as $c => $n) {
            $delta['items'][$c] = ($delta['items'][$c] ?? 0) - $n;
        }
        foreach ($got['items'] as $c => $n) {
            $delta['items'][$c] = ($delta['items'][$c] ?? 0) + $n;
        }
        $after = LedgerMath::add(['items' => $state['items'], 'currants' => $state['currants']], $delta);
        $db->execute('UPDATE ' . $db->table(Ledger::TABLE) . ' SET currants = ?, updated_at = ? WHERE user_id = ?', [$after['currants'], $stamp, $user]);
        $items = $db->table(Ledger::ITEMS);
        foreach (array_keys($delta['items']) as $c) {
            $n = (int) ($after['items'][$c] ?? 0);
            if ($n <= 0) {
                $db->execute("DELETE FROM {$items} WHERE user_id = ? AND class_tsid = ?", [$user, (string) $c]);
            } else {
                $db->execute("INSERT INTO {$items} (user_id, class_tsid, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)", [$user, (string) $c, $n]);
            }
        }
        $db->execute("INSERT INTO {$t} (trade_id, status, a_id, b_id, a_gave, b_gave, b_applied_at, created_at) VALUES (?, 'settled', ?, 0, ?, ?, ?, ?)", [$id, $user, self::json($gave), self::json($got), $stamp, $stamp]);
        $ledger->log($user, 'project', ['project' => $id, 'gave' => ['items' => (object) $gave['items'], 'currants' => $gave['currants']], 'got' => ['items' => (object) $got['items'], 'currants' => $got['currants']]], $now);
        return ['status' => 'settled'];
    }

    /** Goods as the realtime server writes them (LedgerMath::goods reads them back). */
    private static function json(array $goods): string
    {
        $items = [];
        foreach ($goods['items'] as $c => $n) {
            $items[] = ['c' => (string) $c, 'n' => $n];
        }
        return (string) json_encode(['items' => $items, 'currants' => $goods['currants']], JSON_UNESCAPED_SLASHES);
    }
}
