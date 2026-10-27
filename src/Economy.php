<?php

declare(strict_types=1);

namespace Groddle\Minn;

/**
 * The economy table the ledger checks against (data/economy.json, written
 * by the game repo's tools/build-economy.mjs, do not edit it by hand): for
 * each tradeable class its unit value (round(0.9 * base_cost), the best
 * haggled vendor price, else the fallback), its names, a tool's points
 * capacity and its gate (credit: nothing in the remake grants it; capped:
 * only drop tables do, so a write gains at most cap a day); the transforms
 * a write's losses may explain (recipes, Cubimal boxes, artifact assembly);
 * and the rewards credited once per character (quests, badges, level-ups,
 * imagination cards). Plain PHP, no engine calls, so tests can load it on
 * its own.
 */
final class Economy
{
    /** @var array<string, array{v: int|float, n?: string, p?: string, pts?: int, g?: string}> */
    private array $items;

    private int $fallback;

    /** @var list<array{s: string, i: array<string,int>, o: array<string,int>, r?: int}> */
    private array $transforms;

    /** @var array<string, list<int>> output class -> transform indexes */
    private ?array $producing = null;

    private array $credits;

    private static ?self $shared = null;

    /** @param array{items?: array, fallback?: int} $table */
    public function __construct(array $table)
    {
        $this->items = is_array($table['items'] ?? null) ? $table['items'] : [];
        $this->fallback = max(1, (int) ($table['fallback'] ?? 1000));
        $this->transforms = is_array($table['transforms'] ?? null) ? array_values(array_filter($table['transforms'], static fn ($t) => is_array($t) && is_array($t['i'] ?? null) && is_array($t['o'] ?? null))) : [];
        $this->credits = is_array($table['credits'] ?? null) ? $table['credits'] : [];
    }

    /** The site's table, read once per request. */
    public static function shared(): self
    {
        if (self::$shared === null) {
            $file = dirname(__DIR__) . '/data/economy.json';
            $text = is_file($file) ? (string) file_get_contents($file) : '';
            $table = json_decode($text, true);
            self::$shared = new self(is_array($table) ? $table : []);
        }
        return self::$shared;
    }

    /** Whether a class may change hands (and so lives in the ledger). */
    public function tradeable(string $class): bool
    {
        return isset($this->items[$class]);
    }

    /** The value of one, in currants (a currant is 1). */
    public function value(string $class): float
    {
        $v = (float) ($this->items[$class]['v'] ?? $this->fallback);
        return $v > 0 ? $v : (float) $this->fallback;
    }

    /** Only an exact credit (a trade receipt) may add this class. */
    public function creditOnly(string $class): bool
    {
        return ($this->items[$class]['g'] ?? '') === 'credit';
    }

    /** Gains of this class a day, when only drop tables grant it (0: not capped). */
    public function cap(string $class): int
    {
        return ($this->items[$class]['g'] ?? '') === 'capped' ? max(1, (int) ($this->items[$class]['cap'] ?? 1)) : 0;
    }

    /**
     * The transforms that make this class, in the table's order.
     *
     * @return list<array{s: string, i: array<string,int>, o: array<string,int>, r?: int}>
     */
    public function producing(string $class): array
    {
        if ($this->producing === null) {
            $this->producing = [];
            foreach ($this->transforms as $n => $t) {
                foreach (array_keys($t['o']) as $c) {
                    $this->producing[(string) $c][] = $n;
                }
            }
        }
        return array_map(fn (int $n) => $this->transforms[$n], $this->producing[$class] ?? []);
    }

    /**
     * One part of the credit table: quests, badges, levels, cards (maps),
     * xp (level thresholds), questBonus / badgeBonus (card -> bonus), buff.
     */
    public function credits(string $part): mixed
    {
        return $this->credits[$part] ?? null;
    }

    /** A tool's points capacity, 0 for anything else. */
    public function points(string $class): int
    {
        return (int) ($this->items[$class]['pts'] ?? 0);
    }

    /** "3 Cherries", "1 Pick": the trade table's names. */
    public function label(string $class, int $n): string
    {
        $row = $this->items[$class] ?? [];
        $name = $n === 1 ? ($row['n'] ?? $class) : ($row['p'] ?? $row['n'] ?? $class);
        return number_format($n) . ' ' . $name;
    }
}
