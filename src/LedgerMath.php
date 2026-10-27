<?php

declare(strict_types=1);

namespace Groddle\Minn;

/**
 * The ledger's arithmetic, pure (no database, no engine), so a test can run
 * it on fixture saves: what a multiplayer save holds of the things that can
 * change hands, how that compares with the ledger, what a write may gain,
 * and the edits the site makes to a stored save (taking back what was not
 * accounted for, applying a settled trade). The design and its reasons are
 * glitch-audit research/coverage/g335-economy.md.
 *
 * Goods and holdings are ['items' => [class => n], 'currants' => n], only
 * tradeable classes (Economy). A trade's goods from the realtime server
 * are ['items' => [['c' => class, 'n' => n, 'r' => points?], ...], 'currants' => n].
 *
 * Where a save keeps things (all counted, so moving between them is never a
 * gain): the pack and purse (playerState inventory, player.currants), the
 * trade hold (systems.features.trade.hold, js/trade.js), home furniture
 * storage, placed furniture and Storage Display Boxes (systems.features.
 * homes decor and towerDecor, js/home-decor.js) and stacks dropped on
 * streets (the droppedItems entry, js/world-items.js). Smaller stashes are
 * not counted: taking from one reads as a gain, which the budget pays for.
 */
final class LedgerMath
{
    /** js/trade.js APPLIED_KEEP: trades a save remembers as applied. */
    public const APPLIED_KEEP = 24;

    /** js/street-jobs-mp.js APPLIED_KEEP: street project records a save remembers as applied. */
    public const PROJECT_APPLIED_KEEP = 64;

    /** js/feats-mp.js APPLIED_KEEP: Feat records a save remembers as applied. */
    public const FEAT_APPLIED_KEEP = 48;

    /** The save slices that list applied ids, and the prefix of a street project's records. */
    public const TRADE_SLICE = 'trade';
    public const PROJECT_SLICE = 'street-jobs';
    public const PROJECT_PREFIX = 'job-';
    /** G345: a shared Feat's reward (feats.go feat-r-), remembered in the Feats feature's slice. */
    public const FEAT_SLICE = 'solo-feats';
    public const FEAT_PREFIX = 'feat-';

    // —— Reading a save ——

    /** The save's tradeable holdings, everywhere it keeps them. */
    public static function holdings(array $entries, Economy $economy): array
    {
        $items = [];
        $add = static function (mixed $class, mixed $n) use (&$items, $economy): void {
            $count = self::count($n);
            if (is_string($class) && $count > 0 && $economy->tradeable($class)) {
                $items[$class] = ($items[$class] ?? 0) + $count;
            }
        };
        $state = self::decode($entries['playerState'] ?? null);
        $currants = 0;
        if (is_array($state)) {
            foreach ((array) ($state['inventory'] ?? []) as $class => $n) {
                $add($class, $n);
            }
            $currants += self::count($state['player']['currants'] ?? 0);
            $hold = $state['systems']['features']['trade']['hold'] ?? null;
            if (is_array($hold)) {
                foreach ((array) ($hold['items'] ?? []) as $class => $n) {
                    $add($class, $n);
                }
                $currants += self::count($hold['currants'] ?? 0);
            }
            $homes = $state['systems']['features']['homes'] ?? null;
            if (is_array($homes)) {
                $decors = array_merge([$homes['decor'] ?? null], is_array($homes['towerDecor'] ?? null) ? $homes['towerDecor'] : []);
                foreach ($decors as $decor) {
                    if (!is_array($decor)) {
                        continue;
                    }
                    foreach ((array) ($decor['storage'] ?? []) as $class => $n) {
                        $add($class, $n);
                    }
                    foreach ((array) ($decor['placed'] ?? []) as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $add($row['classTsid'] ?? null, 1);
                        if (is_array($row['display'] ?? null)) {
                            $add($row['display']['classTsid'] ?? null, $row['display']['count'] ?? 0);
                        }
                    }
                }
            }
        }
        $dropped = self::decode($entries['droppedItems'] ?? null);
        if (is_array($dropped)) {
            foreach ((array) ($dropped['streets'] ?? []) as $rows) {
                foreach ((array) $rows as $row) {
                    if (is_array($row)) {
                        $add($row['classTsid'] ?? null, $row['count'] ?? 0);
                    }
                }
            }
        }
        ksort($items);
        return ['items' => $items, 'currants' => $currants];
    }

    /**
     * The trade ids the save says it has applied (js/trade.js normalizeSlice),
     * and the street project ids (G346: a contribution taken out, a phase
     * reward put in; features/street-jobs-feature.js keeps them in its own
     * slice's `applied`), and the Feat records (G345: a shared Feat's reward,
     * features/solo-feats-feature.js keeps them in its slice's `applied` in
     * multiplayer).
     */
    public static function applied(array $entries): array
    {
        $state = self::decode($entries['playerState'] ?? null);
        $features = is_array($state) ? ($state['systems']['features'] ?? []) : [];
        $out = [];
        foreach ([self::TRADE_SLICE, self::PROJECT_SLICE, self::FEAT_SLICE] as $slice) {
            $list = is_array($features) ? ($features[$slice]['applied'] ?? []) : [];
            foreach (is_array($list) ? $list : [] as $id) {
                if (is_string($id) && $id !== '') {
                    $out[] = $id;
                }
            }
        }
        return $out;
    }

    /** A street project's ledger record (Ledger::project): its id starts so (projects.go). */
    public static function isProject(string $id): bool
    {
        return str_starts_with($id, self::PROJECT_PREFIX);
    }

    /** A shared Feat's ledger record (LedgerService::feat): its id starts so (feats.go). */
    public static function isFeat(string $id): bool
    {
        return str_starts_with($id, self::FEAT_PREFIX);
    }

    /** A trade's goods from the realtime server as counts, tradeable classes only. */
    public static function goods(mixed $goods): array
    {
        $items = [];
        foreach (is_array($goods['items'] ?? null) ? $goods['items'] : [] as $it) {
            if (is_array($it) && is_string($it['c'] ?? null) && self::count($it['n'] ?? 0) > 0) {
                $items[$it['c']] = ($items[$it['c']] ?? 0) + self::count($it['n']);
            }
        }
        ksort($items);
        return ['items' => $items, 'currants' => self::count($goods['currants'] ?? 0)];
    }

    // —— Comparing ——

    /**
     * What a save is expected to hold: the ledger, with every settled trade
     * this save has not applied yet undone (it still holds what it gave and
     * lacks what it got). $pending: list of ['gave' => counts, 'got' => counts].
     */
    public static function view(array $ledger, array $pending): array
    {
        $items = $ledger['items'];
        $currants = $ledger['currants'];
        foreach ($pending as $p) {
            foreach ($p['gave']['items'] as $c => $n) {
                $items[$c] = ($items[$c] ?? 0) + $n;
            }
            foreach ($p['got']['items'] as $c => $n) {
                $items[$c] = ($items[$c] ?? 0) - $n;
            }
            $currants += $p['gave']['currants'] - $p['got']['currants'];
        }
        return ['items' => $items, 'currants' => $currants];
    }

    /**
     * Compares a save's holdings with the expected view and decides what the
     * write keeps. Losses always stand and refund their value into the budget
     * (never above its capacity, so a craft split over two writes is not paid
     * twice). Gains stand smallest first (by their whole value) while the
     * budget lasts, so a forged pile never crowds out an honest pick; a
     * credit-only class never stands as a gain. Returns the changes that
     * stand (to add to the ledger), what was clamped, and the budget left.
     *
     * @return array{delta: array{items: array<string,int>, currants: int}, clamped: array{items: array<string,int>, currants: int}, budget: float, gained: float}
     */
    public static function reconcile(array $view, array $holdings, float $budget, float $capacity, Economy $economy): array
    {
        $delta = ['items' => [], 'currants' => 0];
        $clamped = ['items' => [], 'currants' => 0];
        $gains = [];
        foreach (array_unique(array_merge(array_keys($view['items']), array_keys($holdings['items']))) as $c) {
            $d = ($holdings['items'][$c] ?? 0) - ($view['items'][$c] ?? 0);
            if ($d < 0) {
                $delta['items'][$c] = $d;
                $budget = min($capacity, $budget + (-$d) * $economy->value($c));
            } elseif ($d > 0) {
                $gains[] = [$c, $d, $economy->value($c)];
            }
        }
        $dc = $holdings['currants'] - $view['currants'];
        if ($dc < 0) {
            $delta['currants'] = $dc;
            $budget = min($capacity, $budget + (-$dc));
        } elseif ($dc > 0) {
            $gains[] = [null, $dc, 1.0];
        }
        usort($gains, static fn ($a, $b) => $a[1] * $a[2] <=> $b[1] * $b[2] ?: strcmp((string) $a[0], (string) $b[0]));
        $gained = 0.0;
        foreach ($gains as [$c, $n, $v]) {
            $keep = ($c !== null && $economy->creditOnly($c)) ? 0 : (int) min($n, floor(($budget + 1e-9) / $v));
            $budget -= $keep * $v;
            $gained += $keep * $v;
            if ($c === null) {
                $delta['currants'] = $keep;
                if ($keep < $n) {
                    $clamped['currants'] = $n - $keep;
                }
                continue;
            }
            if ($keep > 0) {
                $delta['items'][$c] = $keep;
            }
            if ($keep < $n) {
                $clamped['items'][$c] = $n - $keep;
            }
        }
        ksort($delta['items']);
        ksort($clamped['items']);
        return ['delta' => $delta, 'clamped' => $clamped, 'budget' => max(0.0, $budget), 'gained' => $gained];
    }

    /** The ledger with changes added (counts never below 0). */
    public static function add(array $ledger, array $delta): array
    {
        $items = $ledger['items'];
        foreach ($delta['items'] as $c => $n) {
            $items[$c] = max(0, ($items[$c] ?? 0) + $n);
            if ($items[$c] === 0) {
                unset($items[$c]);
            }
        }
        ksort($items);
        return ['items' => $items, 'currants' => max(0, $ledger['currants'] + $delta['currants'])];
    }

    /** Whether goods are empty. */
    public static function nothing(array $goods): bool
    {
        return $goods['items'] === [] && $goods['currants'] === 0;
    }

    // —— Editing a stored save ——

    /**
     * Takes clamped goods out of a save: the pack and purse first, then the
     * trade hold, home furniture storage, dropped stacks, display boxes and
     * placed furniture. Returns the edited entries.
     */
    public static function remove(array $entries, array $goods): array
    {
        $state = self::objects($entries['playerState'] ?? null);
        $dropped = self::objects($entries['droppedItems'] ?? null);
        $want = $goods['items'];
        $take = static function (object $map, string $c) use (&$want): void {
            if (($want[$c] ?? 0) <= 0 || !isset($map->{$c})) {
                return;
            }
            $have = self::count($map->{$c});
            $n = min($have, $want[$c]);
            $want[$c] -= $n;
            if ($have - $n > 0) {
                $map->{$c} = $have - $n;
            } else {
                unset($map->{$c});
            }
        };
        $cur = $goods['currants'];
        if ($state instanceof \stdClass) {
            if (($state->inventory ?? null) instanceof \stdClass) {
                foreach (array_keys($want) as $c) {
                    $take($state->inventory, $c);
                }
            }
            if (($state->player ?? null) instanceof \stdClass && $cur > 0) {
                $have = self::count($state->player->currants ?? 0);
                $n = min($have, $cur);
                $state->player->currants = $have - $n;
                $cur -= $n;
            }
            $hold = $state->systems->features->trade->hold ?? null;
            if ($hold instanceof \stdClass) {
                if (($hold->items ?? null) instanceof \stdClass) {
                    foreach (array_keys($want) as $c) {
                        $take($hold->items, $c);
                    }
                }
                if ($cur > 0) {
                    $have = self::count($hold->currants ?? 0);
                    $n = min($have, $cur);
                    $hold->currants = $have - $n;
                    $cur -= $n;
                }
            }
            $homes = $state->systems->features->homes ?? null;
            $decors = [];
            if ($homes instanceof \stdClass) {
                if (($homes->decor ?? null) instanceof \stdClass) {
                    $decors[] = $homes->decor;
                }
                foreach (is_array($homes->towerDecor ?? null) ? $homes->towerDecor : [] as $d) {
                    if ($d instanceof \stdClass) {
                        $decors[] = $d;
                    }
                }
            }
            foreach ($decors as $d) {
                if (($d->storage ?? null) instanceof \stdClass) {
                    foreach (array_keys($want) as $c) {
                        $take($d->storage, $c);
                    }
                }
            }
        }
        if ($dropped instanceof \stdClass && ($dropped->streets ?? null) instanceof \stdClass) {
            foreach (get_object_vars($dropped->streets) as $street => $rows) {
                if (!is_array($rows)) {
                    continue;
                }
                $keep = [];
                foreach ($rows as $row) {
                    $c = $row->classTsid ?? null;
                    if (is_string($c) && ($want[$c] ?? 0) > 0) {
                        $n = min(self::count($row->count ?? 0), $want[$c]);
                        $want[$c] -= $n;
                        $row->count = self::count($row->count ?? 0) - $n;
                        if ($row->count <= 0) {
                            continue;
                        }
                    }
                    $keep[] = $row;
                }
                $dropped->streets->{$street} = $keep;
            }
        }
        if ($state instanceof \stdClass) {
            foreach ($decors ?? [] as $d) {
                if (!is_array($d->placed ?? null)) {
                    continue;
                }
                $keep = [];
                foreach ($d->placed as $row) {
                    $disp = $row->display ?? null;
                    if ($disp instanceof \stdClass && is_string($disp->classTsid ?? null) && ($want[$disp->classTsid] ?? 0) > 0) {
                        $n = min(self::count($disp->count ?? 0), $want[$disp->classTsid]);
                        $want[$disp->classTsid] -= $n;
                        $disp->count = self::count($disp->count ?? 0) - $n;
                        if ($disp->count <= 0) {
                            unset($row->display);
                        }
                    }
                    $c = $row->classTsid ?? null;
                    if (is_string($c) && ($want[$c] ?? 0) > 0) {
                        $want[$c] -= 1;
                        continue;
                    }
                    $keep[] = $row;
                }
                $d->placed = $keep;
            }
        }
        if ($state !== null) {
            $entries['playerState'] = self::encode($state);
        }
        if ($dropped !== null) {
            $entries['droppedItems'] = self::encode($dropped);
        }
        return $entries;
    }

    /**
     * Applies a settled trade to a stored save, as the game's settleDone does
     * (js/trade.js): what was given leaves the hold (or the pack, when the
     * save has no hold for it), the rest of the hold goes back to the pack,
     * what was got comes in, and the trade is remembered as applied. A save
     * that already lists the trade is left as it is.
     */
    public static function applyTrade(array $entries, string $trade, array $gave, array $got): array
    {
        $state = self::objects($entries['playerState'] ?? null);
        if (!$state instanceof \stdClass || in_array($trade, self::applied($entries), true)) {
            return $entries;
        }
        foreach (['inventory', 'player', 'systems'] as $k) {
            if (!($state->{$k} ?? null) instanceof \stdClass) {
                $state->{$k} = new \stdClass();
            }
        }
        if (!($state->systems->features ?? null) instanceof \stdClass) {
            $state->systems->features = new \stdClass();
        }
        // A street project's record is remembered in the Job Board's slice, a Feat's in the Feats', a trade in the trade's.
        $sliceId = self::isProject($trade) ? self::PROJECT_SLICE : (self::isFeat($trade) ? self::FEAT_SLICE : self::TRADE_SLICE);
        if (!($state->systems->features->{$sliceId} ?? null) instanceof \stdClass) {
            $state->systems->features->{$sliceId} = new \stdClass();
        }
        $slice = $state->systems->features->{$sliceId};
        $inv = $state->inventory;
        $put = static function (string $c, int $n) use ($inv): void {
            $v = self::count($inv->{$c} ?? 0) + $n;
            if ($v > 0) {
                $inv->{$c} = $v;
            } else {
                unset($inv->{$c});
            }
        };
        $hold = ($slice->hold ?? null) instanceof \stdClass && ($slice->hold->trade ?? null) === $trade ? $slice->hold : null;
        $held = $hold !== null && ($hold->items ?? null) instanceof \stdClass ? array_map(self::count(...), get_object_vars($hold->items)) : [];
        foreach (array_unique(array_merge(array_keys($held), array_keys($gave['items']))) as $c) {
            $put((string) $c, ($held[$c] ?? 0) - ($gave['items'][$c] ?? 0));
        }
        foreach ($got['items'] as $c => $n) {
            $put((string) $c, $n);
        }
        $purse = self::count($state->player->currants ?? 0) + ($hold !== null ? self::count($hold->currants ?? 0) : 0) - $gave['currants'] + $got['currants'];
        $state->player->currants = max(0, $purse);
        if ($hold !== null) {
            $slice->hold = null;
        }
        $applied = is_array($slice->applied ?? null) ? $slice->applied : [];
        $applied[] = $trade;
        $slice->applied = array_values(array_slice($applied, -(self::isProject($trade) ? self::PROJECT_APPLIED_KEEP : (self::isFeat($trade) ? self::FEAT_APPLIED_KEEP : self::APPLIED_KEEP))));
        $entries['playerState'] = self::encode($state);
        return $entries;
    }

    // —— Words ——

    /** What the player reads when a write is clamped (no em-dashes). */
    public static function correctedText(array $clamped, Economy $economy): string
    {
        $parts = [];
        $items = $clamped['items'];
        ksort($items);
        foreach ($items as $c => $n) {
            $parts[] = $economy->label((string) $c, $n);
        }
        if ($clamped['currants'] > 0) {
            $parts[] = number_format($clamped['currants']) . ($clamped['currants'] === 1 ? ' currant' : ' currants');
        }
        if ($parts === []) {
            return '';
        }
        $last = array_pop($parts);
        $list = $parts === [] ? $last : implode(', ', $parts) . ' and ' . $last;
        return "Your pack was corrected: {$list} could not be accounted for.";
    }

    // —— Helpers ——

    public static function count(mixed $n): int
    {
        return is_numeric($n) ? max(0, (int) floor((float) $n)) : 0;
    }

    private static function decode(mixed $json): mixed
    {
        return is_string($json) && $json !== '' ? json_decode($json, true) : null;
    }

    private static function objects(mixed $json): mixed
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $v = json_decode($json, false);
        return $v instanceof \stdClass ? $v : null;
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
