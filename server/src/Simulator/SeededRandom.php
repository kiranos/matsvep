<?php

declare(strict_types=1);

namespace Matsvep\Simulator;

use InvalidArgumentException;

final class SeededRandom
{
    private int $counter = 0;

    public function __construct(private readonly string $seed)
    {
    }

    public function fraction(): float
    {
        $hex = substr(hash('sha256', $this->seed . ':' . $this->counter++), 0, 13);
        return hexdec($hex) / 4503599627370496;
    }

    public function index(int $count): int
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Cannot choose from an empty list.');
        }
        return min($count - 1, (int) floor($this->fraction() * $count));
    }

    /** @template T
     *  @param list<T> $items
     *  @return list<T> */
    public function shuffled(array $items): array
    {
        for ($index = count($items) - 1; $index > 0; $index--) {
            $other = $this->index($index + 1);
            [$items[$index], $items[$other]] = [$items[$other], $items[$index]];
        }
        return array_values($items);
    }
}
