<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Ingredient;

use RuntimeException;

final class IngredientLineParser
{
    /** @var list<array{alias: string, unit: string}> */
    private array $units = [];

    /** @var list<array{phrase: string, position: string}> */
    private array $notes = [];

    /** @var list<array{token: string, mode: string}> */
    private array $conjunctions = [];

    public function __construct(string $seedDirectory)
    {
        foreach ($this->readCsv($seedDirectory . '/ingredient_units.csv', ['alias', 'unit']) as $row) {
            $this->units[] = ['alias' => $row[0], 'unit' => $row[1]];
        }
        usort($this->units, static fn (array $a, array $b): int => mb_strlen($b['alias']) <=> mb_strlen($a['alias']));
        foreach ($this->readCsv($seedDirectory . '/ingredient_notes.csv', ['phrase', 'position']) as $row) {
            if (!in_array($row[1], ['leading', 'trailing', 'both'], true)) {
                throw new RuntimeException('Invalid ingredient note position.');
            }
            $this->notes[] = ['phrase' => $row[0], 'position' => $row[1]];
        }
        usort($this->notes, static fn (array $a, array $b): int => mb_strlen($b['phrase']) <=> mb_strlen($a['phrase']));
        foreach ($this->readCsv($seedDirectory . '/ingredient_conjunctions.csv', ['token', 'mode']) as $row) {
            if (!in_array($row[1], ['split', 'flag'], true)) {
                throw new RuntimeException('Invalid ingredient conjunction mode.');
            }
            $this->conjunctions[] = ['token' => $row[0], 'mode' => $row[1]];
        }
    }

    public function parse(string $raw): ParsedIngredientLine
    {
        $notes = [];
        $text = $this->space($raw);
        $withoutParentheses = preg_replace_callback('/\s*\(([^()]*)\)/u', static function (array $matches) use (&$notes): string {
            $notes[] = trim($matches[1]);
            return '';
        }, $text);
        if ($withoutParentheses !== null) {
            $text = $this->space($withoutParentheses);
        }
        $commaParts = preg_split('/(?<!\d),(?!\d)/u', $text, 2);
        if (is_array($commaParts) && count($commaParts) === 2) {
            $text = $this->space($commaParts[0]);
            $notes[] = $this->space($commaParts[1]);
        }
        [$text, $leadingNote] = $this->stripPosition($text, 'leading', true);
        if ($leadingNote !== []) {
            array_push($notes, ...$leadingNote);
        }

        $quantity = null;
        $quantityMax = null;
        $number = '(?:\d+(?:[.,]\d+)?(?:\s+\d+\/\d+)?|\d+\/\d+|[¼½¾])';
        if (preg_match('/^(' . $number . ')(?:\s*[-–—]\s*(' . $number . '))?\s+/u', $text, $match) === 1) {
            $quantity = $this->number($match[1]);
            $quantityMax = isset($match[2]) ? $this->number($match[2]) : null;
            if ($quantityMax !== null && $quantityMax < $quantity) {
                [$quantity, $quantityMax] = [$quantityMax, $quantity];
            }
            $text = $this->space(substr($text, strlen($match[0])));
        }

        $unit = $quantity !== null ? 'piece' : null;
        $explicitUnit = false;
        if ($quantity !== null) {
            foreach ($this->units as $entry) {
                if (preg_match('/^' . preg_quote($entry['alias'], '/') . '(?:\s+|$)/iu', $text, $match) === 1) {
                    $unit = $entry['unit'];
                    $explicitUnit = true;
                    $text = $this->space(substr($text, strlen($match[0])));
                    break;
                }
            }
        }
        [$text, $trailingNote] = $this->stripPosition($text, 'trailing', false);
        if ($trailingNote !== []) {
            array_push($notes, ...$trailingNote);
        }

        $parts = [$text];
        $unsafeSplit = false;
        foreach ($this->conjunctions as $conjunction) {
            $pattern = '/\s+' . preg_quote($conjunction['token'], '/') . '\s+/iu';
            if (preg_match($pattern, $text) !== 1) {
                continue;
            }
            if ($conjunction['mode'] === 'flag' || $quantity !== null) {
                $unsafeSplit = true;
                break;
            }
            $split = preg_split($pattern, $text);
            if (!is_array($split) || count($split) < 2 || in_array('', $split, true)) {
                $unsafeSplit = true;
                break;
            }
            $parts = array_map($this->space(...), $split);
            break;
        }
        return new ParsedIngredientLine(
            $quantity,
            $quantityMax,
            $unit,
            $explicitUnit,
            $text,
            $notes === [] ? null : implode('; ', array_filter($notes)),
            $parts,
            $unsafeSplit,
        );
    }

    /** @return array{string, list<string>} */
    public function stripDescriptors(string $text): array
    {
        $notes = [];
        [$text, $leading] = $this->stripPosition($text, 'leading', false);
        [$text, $trailing] = $this->stripPosition($text, 'trailing', false);
        array_push($notes, ...$leading, ...$trailing);
        return [$text, $notes];
    }

    /** @return array{string, list<string>} */
    private function stripPosition(string $text, string $position, bool $onlyQuantityPrefix): array
    {
        $removed = [];
        do {
            $changed = false;
            foreach ($this->notes as $entry) {
                if ($entry['position'] !== $position && $entry['position'] !== 'both') {
                    continue;
                }
                if ($onlyQuantityPrefix && !preg_match('/^\d/u', mb_substr($text, mb_strlen($entry['phrase']) + 1))) {
                    continue;
                }
                $pattern = $position === 'leading'
                    ? '/^' . preg_quote($entry['phrase'], '/') . '\s+/iu'
                    : '/\s+' . preg_quote($entry['phrase'], '/') . '$/iu';
                if (preg_match($pattern, $text, $match) === 1) {
                    $text = $this->space($position === 'leading'
                        ? substr($text, strlen($match[0]))
                        : substr($text, 0, -strlen($match[0])));
                    $removed[] = $entry['phrase'];
                    $changed = true;
                    break;
                }
            }
        } while ($changed && $text !== '');
        return [$text, $removed];
    }

    private function number(string $value): float
    {
        $fractions = ['¼' => 0.25, '½' => 0.5, '¾' => 0.75];
        if (isset($fractions[$value])) {
            return $fractions[$value];
        }
        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $value, $match) === 1) {
            return round((int) $match[1] + (int) $match[2] / (int) $match[3], 3);
        }
        if (preg_match('/^(\d+)\/(\d+)$/', $value, $match) === 1) {
            return round((int) $match[1] / (int) $match[2], 3);
        }
        return (float) str_replace(',', '.', $value);
    }

    private function space(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param list<string> $header
     * @return list<list<string>>
     */
    private function readCsv(string $path, array $header): array
    {
        $file = fopen($path, 'rb');
        if ($file === false) {
            throw new RuntimeException('Cannot read ingredient vocabulary.');
        }
        try {
            if (fgetcsv($file, escape: '') !== $header) {
                throw new RuntimeException('Invalid ingredient vocabulary header.');
            }
            $rows = [];
            while (($row = fgetcsv($file, escape: '')) !== false) {
                if (count($row) !== count($header)) {
                    throw new RuntimeException('Invalid ingredient vocabulary row.');
                }
                $values = [];
                foreach ($row as $value) {
                    if (!is_string($value) || $value === '') {
                        throw new RuntimeException('Invalid ingredient vocabulary row.');
                    }
                    $values[] = $value;
                }
                $rows[] = $values;
            }
            return $rows;
        } finally {
            fclose($file);
        }
    }
}
