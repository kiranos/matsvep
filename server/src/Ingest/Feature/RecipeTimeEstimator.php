<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Feature;

use RuntimeException;

final class RecipeTimeEstimator
{
    /** @var array<string, int> */
    private array $units = [];

    private string $pattern;

    public function __construct(string $seedDirectory)
    {
        foreach (CsvTable::read($seedDirectory . '/time_units.csv', ['unit', 'minutes']) as $row) {
            if ($row['unit'] === '' || !ctype_digit($row['minutes']) || (int) $row['minutes'] < 1) {
                throw new RuntimeException('Invalid time unit seed row.');
            }
            $unit = mb_strtolower($row['unit']);
            $this->units[$unit] = (int) $row['minutes'];
        }
        if ($this->units === []) {
            throw new RuntimeException('Time unit seed data is empty.');
        }
        $terms = array_keys($this->units);
        usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $alternatives = implode('|', array_map(static fn (string $unit): string => preg_quote($unit, '/'), $terms));
        $this->pattern = '/(?<![\pL\pN])([0-9]+(?:[,.][0-9]+)?)'
            . '(?:\s*[-–]\s*([0-9]+(?:[,.][0-9]+)?))?\s*(' . $alternatives . ')(?![\pL])/iu';
    }

    /** @param list<string> $steps */
    public function estimate(array $steps): ?int
    {
        $total = 0;
        foreach ($steps as $step) {
            $matches = [];
            if (preg_match_all($this->pattern, mb_strtolower($step), $matches, PREG_SET_ORDER) === false) {
                throw new RuntimeException('Cannot parse step durations.');
            }
            foreach ($matches as $match) {
                $amount = (float) str_replace(',', '.', ($match[2] ?? '') !== '' ? $match[2] : $match[1]);
                $minutes = (int) ceil($amount * $this->units[mb_strtolower($match[3])]);
                $total += $minutes;
            }
        }
        return $total > 0 && $total <= 65535 ? $total : null;
    }
}
