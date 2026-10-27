<?php

declare(strict_types=1);

namespace Groddle\Minn;

/**
 * The economy table the ledger checks against (data/economy.json, written
 * by the game repo's tools/build-economy.mjs, do not edit it by hand): for
 * each tradeable class its unit value (round(0.9 * base_cost), the best
 * haggled vendor price, else the fallback), its names, a tool's points
 * capacity and the credit gate for a class nothing in the remake grants.
 * Plain PHP, no engine calls, so tests can load it on its own.
 */
final class Economy
{
    /** @var array<string, array{v: int|float, n?: string, p?: string, pts?: int, g?: string}> */
    private array $items;

    private int $fallback;

    private static ?self $shared = null;

    /** @param array{items?: array, fallback?: int} $table */
    public function __construct(array $table)
    {
        $this->items = is_array($table['items'] ?? null) ? $table['items'] : [];
        $this->fallback = max(1, (int) ($table['fallback'] ?? 1000));
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
