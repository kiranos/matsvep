<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Feature;

use RuntimeException;

final class ProteinStemMatcher
{
    /** @var list<array{stem: string, protein_code: ?string, kind: string, match_mode: string}> */
    private readonly array $rules;

    /** @var array<string, list<string>> */
    private readonly array $excludedWordPrefixes;

    public function __construct(string $seedDirectory)
    {
        $rules = [];
        foreach (CsvTable::read(
            $seedDirectory . '/feature_protein_stems.csv',
            ['stem', 'protein_code', 'kind', 'match_mode'],
        ) as $row) {
            if ($row['stem'] === ''
                || !in_array($row['kind'], ['animal', 'vegetarian', 'stock', 'animal_flavour', 'roe', 'generic'], true)
                || !in_array($row['match_mode'], ['whole', 'prefix', 'suffix', 'edge'], true)
                || ($row['protein_code'] === '' && !in_array($row['kind'], ['stock', 'animal_flavour'], true))) {
                throw new RuntimeException('Invalid protein stem seed row.');
            }
            $rules[] = [
                'stem' => mb_strtolower($row['stem']),
                'protein_code' => $row['protein_code'] === '' ? null : $row['protein_code'],
                'kind' => $row['kind'],
                'match_mode' => $row['match_mode'],
            ];
        }
        $explicit = array_fill_keys(array_column($rules, 'stem'), true);
        foreach (CsvTable::read(
            $seedDirectory . '/feature_protein_title_terms.csv',
            ['term', 'protein_code'],
        ) as $row) {
            $stem = mb_strtolower($row['term']);
            if ($stem === '' || $row['protein_code'] === '') {
                throw new RuntimeException('Invalid protein title seed row.');
            }
            if (!isset($explicit[$stem])) {
                $rules[] = [
                    'stem' => $stem,
                    'protein_code' => $row['protein_code'],
                    'kind' => $row['protein_code'] === 'vegetarian' ? 'vegetarian' : 'animal',
                    'match_mode' => 'edge',
                ];
            }
        }
        usort($rules, static fn (array $a, array $b): int => mb_strlen($b['stem']) <=> mb_strlen($a['stem']));
        $this->rules = $rules;
        $excludedWordPrefixes = [];
        foreach (CsvTable::read(
            $seedDirectory . '/feature_protein_stem_exclusions.csv',
            ['stem', 'word_prefix'],
        ) as $row) {
            if ($row['stem'] === '' || $row['word_prefix'] === '' || !isset($explicit[mb_strtolower($row['stem'])])) {
                throw new RuntimeException('Invalid protein stem exclusion seed row.');
            }
            $excludedWordPrefixes[mb_strtolower($row['stem'])][] = mb_strtolower($row['word_prefix']);
        }
        $this->excludedWordPrefixes = $excludedWordPrefixes;
    }

    /** @return list<array{stem: string, protein_code: ?string, kind: string}> */
    public function match(string $text, bool $allowRoe = false): array
    {
        preg_match_all('/\p{L}+/u', mb_strtolower($text), $matches);
        $words = $matches[0];
        $results = [];
        $previousVegetarian = false;
        foreach ($words as $word) {
            $candidates = [];
            foreach ($this->rules as $rule) {
                if ($rule['kind'] === 'roe' && !$allowRoe) {
                    continue;
                }
                foreach ($this->excludedWordPrefixes[$rule['stem']] ?? [] as $prefix) {
                    if (str_starts_with($word, $prefix)) {
                        continue 2;
                    }
                }
                if ($this->matches($word, $rule['stem'], $rule['match_mode'])) {
                    $candidates[] = $rule;
                }
            }
            $vegetarian = $this->firstKind($candidates, 'vegetarian');
            $stock = $this->firstKind($candidates, 'stock');
            $animalFlavour = $this->firstKind($candidates, 'animal_flavour');
            $animal = $this->firstKind($candidates, 'animal');
            $vegetarianWins = $vegetarian !== null && ($animal === null
                || str_starts_with($word, $vegetarian['stem'])
                && (str_starts_with($word, $animal['stem'])
                    ? mb_strlen($vegetarian['stem']) >= mb_strlen($animal['stem'])
                    : true));
            $selected = $vegetarianWins ? $vegetarian : ($previousVegetarian ? null : ($stock ?? $animalFlavour));
            if ($selected === null && !$previousVegetarian) {
                foreach ($candidates as $candidate) {
                    if (in_array($candidate['kind'], ['animal', 'roe'], true)
                        && str_ends_with($word, $candidate['stem'])) {
                        $selected = $candidate;
                        break;
                    }
                }
                if ($selected === null) {
                    foreach ($candidates as $candidate) {
                        if (in_array($candidate['kind'], ['animal', 'roe'], true)) {
                            $selected = $candidate;
                            break;
                        }
                    }
                }
                $selected ??= $this->firstKind($candidates, 'generic');
                $selected ??= $vegetarian;
            }
            if ($selected !== null) {
                $results[] = [
                    'stem' => $selected['stem'],
                    'protein_code' => $selected['protein_code'],
                    'kind' => $selected['kind'],
                ];
            }
            $previousVegetarian = $selected !== null && $selected['kind'] === 'vegetarian';
        }
        return $results;
    }

    /** @param list<array{stem: string, protein_code: ?string, kind: string, match_mode: string}> $candidates
     * @return array{stem: string, protein_code: ?string, kind: string, match_mode: string}|null
     */
    private function firstKind(array $candidates, string $kind): ?array
    {
        foreach ($candidates as $candidate) {
            if ($candidate['kind'] === $kind) {
                return $candidate;
            }
        }
        return null;
    }

    private function matches(string $word, string $stem, string $mode): bool
    {
        return match ($mode) {
            'whole' => $word === $stem,
            'prefix' => str_starts_with($word, $stem),
            'suffix' => str_ends_with($word, $stem),
            'edge' => str_starts_with($word, $stem) || str_ends_with($word, $stem),
            default => false,
        };
    }
}
