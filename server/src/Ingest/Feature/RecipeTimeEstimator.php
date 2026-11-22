<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Feature;

use RuntimeException;

final class RecipeTimeEstimator
{
    /** @var array<string, int> */
    private array $units = [];

    private string $pattern;

    private string $passivePattern;

    private string $passiveAfterPattern;

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
        $passive = [];
        foreach (CsvTable::read($seedDirectory . '/passive_time_phrases.csv', ['phrase']) as $row) {
            if (trim($row['phrase']) === '') {
                throw new RuntimeException('Invalid passive time phrase seed row.');
            }
            $passive[] = preg_quote(mb_strtolower($row['phrase']), '/');
        }
        if ($passive === []) {
            throw new RuntimeException('Passive time phrase seed data is empty.');
        }
        $phrases = implode('|', $passive);
        $this->passivePattern = '/(?<![\pL])(?:' . $phrases . ')(?![\pL])/iu';
        $this->passiveAfterPattern = '/^\s*(?:i|på)\s+(?:' . $phrases . ')(?![\pL])/iu';
        $this->pattern = '/(?<![\pL\pN])([0-9]+(?:[,.][0-9]+)?)'
            . '(?:\s*[-–]\s*([0-9]+(?:[,.][0-9]+)?))?\s*(' . $alternatives . ')(?![\pL])/iu';
    }

    /** @param list<string> $steps */
    public function estimate(array $steps): ?int
    {
        $total = 0;
        foreach ($steps as $step) {
            $text = mb_strtolower($step);
            $matches = [];
            if (preg_match_all($this->pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
                throw new RuntimeException('Cannot parse step durations.');
            }
            $previousEnd = 0;
            foreach ($matches as $match) {
                $start = $match[0][1];
                $end = $start + strlen($match[0][0]);
                $before = substr($text, $previousEnd, $start - $previousEnd);
                $clauses = preg_split('/[.!?;:,]|\s+och\s+/u', $before);
                if ($clauses === false) {
                    throw new RuntimeException('Cannot parse step clauses.');
                }
                $clause = end($clauses);
                $after = substr($text, $end);
                $passiveBefore = is_string($clause) && preg_match($this->passivePattern, $clause) === 1;
                $passiveAfter = preg_match($this->passiveAfterPattern, $after) === 1;
                if (!$passiveBefore && !$passiveAfter) {
                    $amount = (float) str_replace(',', '.', ($match[2][0] ?? '') !== '' ? $match[2][0] : $match[1][0]);
                    $total += (int) ceil($amount * $this->units[mb_strtolower($match[3][0])]);
                }
                $previousEnd = $end;
            }
        }
        return $total > 0 && $total <= 65535 ? $total : null;
    }
}
