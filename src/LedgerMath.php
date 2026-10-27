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
 *
 * Slice 2 explains gains so the budget can be small: a write's losses may
 * turn into a transform's outputs outside the budget (explain: recipes,
 * Cubimal boxes, artifact assembly, Economy::producing), rewards the source
 * pays for a marker the save carries are credited exactly once per
 * character (credits: quests, badges, level-ups, imagination cards), and a
 * class only drop tables grant gains at most its cap a day (reconcile's
 * $capsLeft).
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

    /** Ours: purchases a day of one repeatable imagination card that are credited (more go through the budget). */
    public const CARD_DAILY = 3;

    /**
     * Ours: level-ups credited a day. Levels and xp are the client's, so a
     * script could step a character from 2 to 60 at once (1,441,400 currants
     * in all); a few a day keeps that slower than real play ever was, and a
     * level-up past the count is credited on a later day (the final level
     * reached then, as the source pays a jump).
     */
    public const LEVELS_DAILY = 3;

    /** Credited kinds that count per day (Ledger keeps today's only): capped gains, card purchases, level-ups. */
    public const DAILY_KINDS = ['cap', 'cardday', 'lvlday'];

    /** How deep explain looks for an input made from other losses in the same write (grain to flour to a bun). */
    public const EXPLAIN_DEPTH = 2;

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
     * write keeps. Losses always stand. Gains a transform explains from the
     * write's own losses stand outside the budget (explain), and the losses
     * they used refund nothing. The other losses refund their value into the
     * budget (never above its capacity, so a craft split over two writes is
     * not paid twice), and the other gains stand smallest first (by their
     * whole value) while the budget lasts, so a forged pile never crowds out
     * an honest pick. A credit-only class never stands as a gain; a capped
     * class stands at most $capsLeft[class] (default its daily cap). Returns
     * the changes that stand (to add to the ledger), what was clamped, the
     * budget left, what transforms explained and the capped counts taken.
     *
     * @param array<string,int>|null $capsLeft capped class -> gains left today (null: a full day)
     * @return array{delta: array{items: array<string,int>, currants: int}, clamped: array{items: array<string,int>, currants: int}, budget: float, gained: float, made: array<string,int>, runs: array<string,int>, capped: array<string,int>}
     */
    public static function reconcile(array $view, array $holdings, float $budget, float $capacity, Economy $economy, ?array $capsLeft = null): array
    {
        $delta = ['items' => [], 'currants' => 0];
        $clamped = ['items' => [], 'currants' => 0];
        $loss = [];
        $gain = [];
        foreach (array_unique(array_merge(array_keys($view['items']), array_keys($holdings['items']))) as $c) {
            $c = (string) $c;
            $d = ($holdings['items'][$c] ?? 0) - ($view['items'][$c] ?? 0);
            if ($d < 0) {
                $delta['items'][$c] = $d;
                $loss[$c] = -$d;
            } elseif ($d > 0) {
                $gain[$c] = $d;
            }
        }
        $x = self::explain($gain, $loss, $economy, $budget);
        foreach ($loss as $c => $n) {
            $budget = min($capacity, $budget + ($n - ($x['used'][$c] ?? 0)) * $economy->value((string) $c));
        }
        $gains = [];
        foreach ($gain as $c => $n) {
            $made = $x['made'][$c] ?? 0;
            if ($made > 0) {
                $delta['items'][$c] = $made;
            }
            if ($n - $made > 0) {
                $gains[] = [(string) $c, $n - $made, $economy->value((string) $c)];
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
        $capped = [];
        foreach ($gains as [$c, $n, $v]) {
            $most = $n;
            if ($c !== null && $economy->creditOnly($c)) {
                $most = 0;
            } elseif ($c !== null && $economy->cap($c) > 0) {
                $most = min($n, max(0, (int) ($capsLeft[$c] ?? $economy->cap($c))));
            }
            $keep = (int) min($most, floor(($budget + 1e-9) / $v));
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
                $delta['items'][$c] = ($delta['items'][$c] ?? 0) + $keep;
                if ($economy->cap($c) > 0) {
                    $capped[$c] = $keep;
                }
            }
            if ($keep < $n) {
                $clamped['items'][$c] = $n - $keep;
            }
        }
        ksort($delta['items']);
        ksort($clamped['items']);
        ksort($capped);
        return ['delta' => $delta, 'clamped' => $clamped, 'budget' => max(0.0, $budget), 'gained' => $gained, 'made' => $x['made'], 'runs' => $x['runs'], 'capped' => $capped];
    }

    /**
     * The gains a write's own losses explain through transforms
     * (Economy::producing): the most valuable gains first, each from the
     * transforms that make it, as many runs as the losses cover; an input
     * the losses lack may itself be made from other losses (EXPLAIN_DEPTH),
     * and what such a step makes beyond the need is not kept anywhere. A
     * roll (a Cubimal box) whose outcome is worth more than its inputs
     * charges the difference to the budget, so always choosing the rarest
     * figure is not free. Returns what was made (to stand outside the
     * budget), the losses used (which refund nothing) and the runs.
     *
     * @param array<string,int> $gain
     * @param array<string,int> $loss
     * @return array{made: array<string,int>, used: array<string,int>, runs: array<string,int>}
     */
    public static function explain(array $gain, array $loss, Economy $economy, float &$budget): array
    {
        $out = ['made' => [], 'used' => [], 'runs' => []];
        if ($gain === [] || $loss === []) {
            return $out;
        }
        $order = array_keys($gain);
        usort($order, static fn ($a, $b) => $gain[$b] * $economy->value((string) $b) <=> $gain[$a] * $economy->value((string) $a) ?: strcmp((string) $a, (string) $b));
        $need = $gain;
        $state = ['avail' => $loss, 'used' => [], 'budget' => $budget, 'runs' => []];
        foreach ($order as $c) {
            $c = (string) $c;
            foreach ($economy->producing($c) as $t) {
                if (($need[$c] ?? 0) <= 0) {
                    break;
                }
                $want = (int) ceil($need[$c] / max(1, (int) $t['o'][$c]));
                // A dry run says how many the losses cover; the real run then takes exactly that many.
                $try = $state;
                $k = self::run($t, $want, $try, $economy, self::EXPLAIN_DEPTH);
                if ($k <= 0) {
                    continue;
                }
                self::run($t, $k, $state, $economy, self::EXPLAIN_DEPTH);
                foreach ($t['o'] as $o => $q) {
                    $o = (string) $o;
                    $m = min($need[$o] ?? 0, $k * (int) $q);
                    if ($m > 0) {
                        $out['made'][$o] = ($out['made'][$o] ?? 0) + $m;
                        $need[$o] -= $m;
                    }
                }
            }
        }
        $budget = $state['budget'];
        ksort($out['made']);
        ksort($state['used']);
        ksort($state['runs']);
        $out['used'] = $state['used'];
        $out['runs'] = $state['runs'];
        return $out;
    }

    /**
     * Runs a transform up to $want times from the state's losses (making a
     * short input from other losses while $depth lasts), taking what it uses.
     * Returns the runs made. The state: avail (losses left), used (losses
     * taken), budget, runs.
     */
    private static function run(array $t, int $want, array &$state, Economy $economy, int $depth): int
    {
        $k = $want;
        // A roll worth more than its inputs pays the difference from the budget.
        if (!empty($t['r'])) {
            $premium = 0.0;
            foreach ($t['o'] as $o => $q) {
                $premium += (int) $q * $economy->value((string) $o);
            }
            foreach ($t['i'] as $i => $q) {
                $premium -= (int) $q * $economy->value((string) $i);
            }
            if ($premium > 0) {
                $k = min($k, (int) floor(($state['budget'] + 1e-9) / $premium));
            }
        }
        if ($k <= 0) {
            return 0;
        }
        foreach ($t['i'] as $i => $q) {
            $i = (string) $i;
            $q = max(1, (int) $q);
            $have = (int) ($state['avail'][$i] ?? 0);
            if ($have < $k * $q && $depth > 0) {
                // Make the shortfall from other losses: what is made stands in for a loss of this input.
                $short = $k * $q - $have;
                foreach ($economy->producing($i) as $sub) {
                    if ($short <= 0) {
                        break;
                    }
                    if (isset($sub['i'][$i])) {
                        continue;
                    }
                    $per = max(1, (int) $sub['o'][$i]);
                    $try = $state;
                    $m = self::run($sub, (int) ceil($short / $per), $try, $economy, $depth - 1);
                    if ($m > 0) {
                        self::run($sub, $m, $state, $economy, $depth - 1);
                        $state['made'][$i] = ($state['made'][$i] ?? 0) + $m * $per;
                        $short -= $m * $per;
                    }
                }
            }
            $k = min($k, intdiv((int) ($state['avail'][$i] ?? 0) + (int) ($state['made'][$i] ?? 0), $q));
        }
        if ($k <= 0) {
            return 0;
        }
        foreach ($t['i'] as $i => $q) {
            $i = (string) $i;
            $take = $k * max(1, (int) $q);
            // Made stand-ins first, then real losses (only those count as used).
            $fromMade = min($take, (int) ($state['made'][$i] ?? 0));
            if ($fromMade > 0) {
                $state['made'][$i] -= $fromMade;
            }
            $fromLoss = $take - $fromMade;
            if ($fromLoss > 0) {
                $state['avail'][$i] -= $fromLoss;
                $state['used'][$i] = ($state['used'][$i] ?? 0) + $fromLoss;
            }
        }
        if (!empty($t['r'])) {
            $premium = 0.0;
            foreach ($t['o'] as $o => $q) {
                $premium += (int) $q * $economy->value((string) $o);
            }
            foreach ($t['i'] as $i => $q) {
                $premium -= (int) $q * $economy->value((string) $i);
            }
            if ($premium > 0) {
                $state['budget'] -= $k * $premium;
            }
        }
        $state['runs'][$t['s']] = ($state['runs'][$t['s']] ?? 0) + $k;
        return $k;
    }

    /**
     * A whole save write against the ledger, as Ledger::write runs it: the
     * write's credits go into the ledger first, then the save is compared
     * with that (and with settled trades it has not applied yet). Returns
     * the ledger after, the credit result, the reconcile result and the
     * credited rows to store (credits and today's capped counts).
     *
     * @param array<string, array<string,int>> $credited kind -> ref -> n (daily kinds: today's)
     * @return array{after: array, credits: array, reconciled: array, rows: list<array{0: string, 1: string, 2: int}>}
     */
    public static function check(array $ledger, array $credited, array $pending, array $entries, float $budget, float $capacity, Economy $economy): array
    {
        $credits = self::credits($entries, $credited, $economy);
        $owed = self::add($ledger, $credits['goods']);
        $view = self::view($owed, $pending);
        $holdings = self::holdings($entries, $economy);
        $capsLeft = [];
        foreach ($credited['cap'] ?? [] as $c => $n) {
            $capsLeft[(string) $c] = max(0, $economy->cap((string) $c) - (int) $n);
        }
        $r = self::reconcile($view, $holdings, $budget, $capacity, $economy, $capsLeft);
        $rows = $credits['rows'];
        foreach ($r['capped'] as $c => $n) {
            $rows[] = ['cap', (string) $c, (int) ($credited['cap'][$c] ?? 0) + $n];
        }
        return ['after' => self::add($owed, $r['delta']), 'credits' => $credits, 'reconciled' => $r, 'rows' => $rows];
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
        // The pack keeps a class it ran out of at 0, as the game does (js/inventory.js removeItem):
        // an absent key reads as a save from before a starter grant, and the boot grants it again
        // (game.js the Hatchet and Paradise tickets; resource-nodes-feature.js the kit).
        $take = static function (object $map, string $c, bool $keepZero = false) use (&$want): void {
            if (($want[$c] ?? 0) <= 0 || !isset($map->{$c})) {
                return;
            }
            $have = self::count($map->{$c});
            $n = min($have, $want[$c]);
            $want[$c] -= $n;
            if ($have - $n > 0 || $keepZero) {
                $map->{$c} = $have - $n;
            } else {
                unset($map->{$c});
            }
        };
        $cur = $goods['currants'];
        if ($state instanceof \stdClass) {
            if (($state->inventory ?? null) instanceof \stdClass) {
                foreach (array_keys($want) as $c) {
                    $take($state->inventory, (string) $c, true);
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
        // A class the pack runs out of stays at 0, as the game keeps it (see remove).
        $put = static function (string $c, int $n) use ($inv): void {
            if ($n === 0 && !isset($inv->{$c})) {
                return;
            }
            $inv->{$c} = max(0, self::count($inv->{$c} ?? 0) + $n);
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

    // —— Credits (slice 2) ——

    /**
     * What the save says it has done that the source pays for: completed
     * quests (systems.quests.completed), unlocked badges (systems.
     * achievements.unlocked), the level and xp (player), the reward buff
     * multiplier and the owned imagination upgrades (player), and the
     * purchases of each card (systems.features.imagination.counts,
     * js/imagination.js grantUpgrade; a card owned with no count counts once).
     *
     * @return array{quests: list<string>, badges: list<string>, level: int, xp: int, mult: float, owned: list<string>, cards: array<string,int>}
     */
    public static function markers(array $entries): array
    {
        $state = self::decode($entries['playerState'] ?? null);
        $state = is_array($state) ? $state : [];
        $strings = static fn (mixed $list): array => array_values(array_unique(array_filter(is_array($list) ? $list : [], static fn ($v) => is_string($v) && $v !== '')));
        $player = is_array($state['player'] ?? null) ? $state['player'] : [];
        $owned = $strings($player['imaginationUpgrades'] ?? []);
        $cards = [];
        $counts = $state['systems']['features']['imagination']['counts'] ?? null;
        foreach (is_array($counts) ? $counts : [] as $id => $n) {
            if (is_string($id) && self::count($n) > 0) {
                $cards[$id] = self::count($n);
            }
        }
        foreach ($owned as $id) {
            $cards[$id] ??= 1;
        }
        ksort($cards);
        return [
            'quests' => $strings($state['systems']['quests']['completed'] ?? []),
            'badges' => $strings($state['systems']['achievements']['unlocked'] ?? []),
            'level' => max(1, self::count($player['level'] ?? 1)),
            'xp' => self::count($player['xp'] ?? 0),
            'mult' => is_numeric($player['rewardMultiplier'] ?? null) ? (float) $player['rewardMultiplier'] : 1.0,
            'owned' => $owned,
            'cards' => $cards,
        ];
    }

    /** js/progression.js levelInfoForXp: the level a lifetime xp total reaches. */
    public static function levelFor(int $xp, Economy $economy): int
    {
        $level = 1;
        foreach ((array) $economy->credits('xp') as $need) {
            if ($xp < (int) $need) {
                break;
            }
            $level++;
        }
        return $level;
    }

    /** common.js round_to_5 (js/quests.js roundTo5): a remainder of 2.5 rounds up. */
    public static function roundTo5(float $v): int
    {
        $t = (int) ($v / 5) * 5;
        return fmod($v, 5) >= 2.5 ? $t + 5 : $t;
    }

    /**
     * The rows that mark what a save has already done as credited without
     * paying it (a character's first write, or a save from before slice 2):
     * [kind, ref, n] with kinds quest, badge (n 0), level (n the level) and
     * card (n the purchases).
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public static function baseline(array $entries, Economy $economy): array
    {
        $m = self::markers($entries);
        $rows = [['base', 'base', 1]];
        foreach ($m['quests'] as $q) {
            if (is_array(($economy->credits('quests') ?? [])[$q] ?? null)) {
                $rows[] = ['quest', $q, 0];
            }
        }
        foreach ($m['badges'] as $b) {
            if (is_array(($economy->credits('badges') ?? [])[$b] ?? null)) {
                $rows[] = ['badge', $b, 0];
            }
        }
        $rows[] = ['level', 'level', min($m['level'], self::levelFor($m['xp'], $economy))];
        foreach ($m['cards'] as $id => $n) {
            if (is_array(($economy->credits('cards') ?? [])[$id] ?? null)) {
                $rows[] = ['card', (string) $id, $n];
            }
        }
        return $rows;
    }

    /**
     * The exact credits a write earns for markers not credited before
     * ($credited: kind -> ref -> n; cardday holds today's card purchases):
     * a newly completed quest's currants (times the reward multiplier, at
     * most the best buff plus the owned Fit for a King tier, js/quests.js
     * applyQuestRewards, inc_quests.js:844, round_to_5) and its tradeable
     * items; a newly unlocked badge's currants (Overachiever, js/
     * achievements.js unlockAchievement, inc_achievements.js:331, Completist
     * x level/4 past level 4); a level reached above the last one credited
     * whose xp agrees, the final level's currants only (js/progression.js
     * reconcilePlayerProgression, inc_stats.js), at most LEVELS_DAILY a
     * day; and each new purchase of an
     * imagination card that hands out an item (reward type 8), at most
     * CARD_DAILY a day for a repeatable card. Returns the goods (to add to
     * the ledger before the write is compared), the rows to store
     * ([kind, ref, n], n the new total) and what was credited, for the log.
     *
     * @return array{goods: array{items: array<string,int>, currants: int}, rows: list<array{0: string, 1: string, 2: int}>, detail: array}
     */
    public static function credits(array $entries, array $credited, Economy $economy): array
    {
        $m = self::markers($entries);
        $goods = ['items' => [], 'currants' => 0];
        $rows = [];
        $detail = [];
        $put = static function (array $items) use (&$goods): void {
            foreach ($items as $c => $n) {
                $goods['items'][(string) $c] = ($goods['items'][(string) $c] ?? 0) + max(0, (int) $n);
            }
        };
        $buff = min((float) ($economy->credits('buff') ?? 1.0), max(1.0, $m['mult']));
        $bonus = static function (mixed $tiers) use ($m): float {
            $best = 0.0;
            foreach (is_array($tiers) ? $tiers : [] as $id => $v) {
                if (in_array((string) $id, $m['owned'], true)) {
                    $best = max($best, (float) $v);
                }
            }
            return $best;
        };
        $quests = (array) ($economy->credits('quests') ?? []);
        $qm = $buff + $bonus($economy->credits('questBonus'));
        foreach ($m['quests'] as $q) {
            if (!is_array($quests[$q] ?? null) || isset($credited['quest'][$q])) {
                continue;
            }
            $c = self::roundTo5((float) ($quests[$q]['c'] ?? 0) * $qm);
            $goods['currants'] += $c;
            $put((array) ($quests[$q]['i'] ?? []));
            $rows[] = ['quest', $q, 1];
            $detail['quests'][$q] = $c;
        }
        $badges = (array) ($economy->credits('badges') ?? []);
        $bm = $buff + $bonus($economy->credits('badgeBonus'));
        foreach ($m['badges'] as $b) {
            if (!is_array($badges[$b] ?? null) || isset($credited['badge'][$b])) {
                continue;
            }
            $factor = !empty($badges[$b]['x']) && $m['level'] > 4 ? $m['level'] / 4 : 1;
            $c = self::roundTo5((float) ($badges[$b]['c'] ?? 0) * $bm * $factor);
            $goods['currants'] += $c;
            $rows[] = ['badge', $b, 1];
            $detail['badges'][$b] = $c;
        }
        $was = (int) ($credited['level']['level'] ?? 1);
        $today = (int) ($credited['lvlday']['level'] ?? 0);
        if ($m['level'] > $was && self::levelFor($m['xp'], $economy) >= $m['level']) {
            if ($today >= self::LEVELS_DAILY) {
                $detail['later'] = ['level' => $m['level']];
            } else {
                $c = (int) (((array) ($economy->credits('levels') ?? []))[(string) $m['level']] ?? 0);
                $goods['currants'] += $c;
                $rows[] = ['level', 'level', $m['level']];
                $rows[] = ['lvlday', 'level', $today + 1];
                $detail['level'] = [$was, $m['level'], $c];
            }
        }
        $cards = (array) ($economy->credits('cards') ?? []);
        foreach ($m['cards'] as $id => $n) {
            $card = $cards[$id] ?? null;
            $had = (int) ($credited['card'][$id] ?? 0);
            if (!is_array($card) || $n <= $had) {
                continue;
            }
            $add = $n - $had;
            if (!empty($card['k'])) {
                $add = min($add, max(0, self::CARD_DAILY - (int) ($credited['cardday'][$id] ?? 0)));
                if ($add > 0) {
                    $rows[] = ['cardday', (string) $id, (int) ($credited['cardday'][$id] ?? 0) + $add];
                }
            }
            if ($add <= 0) {
                continue;
            }
            $put([(string) $card['c'] => $add * max(1, (int) ($card['n'] ?? 1))]);
            $rows[] = ['card', (string) $id, $had + $add];
            $detail['cards'][$id] = $add;
        }
        ksort($goods['items']);
        return ['goods' => $goods, 'rows' => $rows, 'detail' => $detail];
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
