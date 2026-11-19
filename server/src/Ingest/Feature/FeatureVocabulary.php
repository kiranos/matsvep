<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Feature;

use PDO;
use RuntimeException;

final class FeatureVocabulary
{
    /** @var array<string, array{protein: ?string, carb: ?string, animal: bool, premium: bool}> */
    public readonly array $ingredients;

    /** @var array<string, array{cuisine: ?string, meal_type: ?string, festive: bool}> */
    public readonly array $tags;

    /** @var list<array{term: string, feature: string, code: string}> */
    public readonly array $titleTerms;

    /** @var list<array{term: string, protein_code: string}> */
    public readonly array $proteinTitleTerms;

    /** @var list<string> */
    public readonly array $cutUpTerms;

    /** @var list<string> */
    public readonly array $longCookTerms;

    /** @var array<string, float> */
    public readonly array $durationUnits;

    /** @var array<string, list<string>> */
    public readonly array $dinnerExclusions;

    /** @var list<string> */
    public readonly array $dinnerMainIngredients;

    /** @var list<string> */
    public readonly array $dinnerTitleSeparators;

    /** @var array<string, list<array{keyword: string, value: float}>> */
    public readonly array $leftoverKeywords;

    public function __construct(PDO $db, string $seedDirectory)
    {
        $ingredients = [];
        foreach (CsvTable::read(
            $seedDirectory . '/ingredient_features.csv',
            ['ingredient_name', 'protein_code', 'carb_code', 'animal', 'premium']
        ) as $row) {
            if ($row['ingredient_name'] === '' || !in_array($row['animal'], ['0', '1'], true)
                || !in_array($row['premium'], ['0', '1'], true)) {
                throw new RuntimeException('Invalid ingredient feature seed row.');
            }
            $ingredients[mb_strtolower($row['ingredient_name'])] = [
                'protein' => $row['protein_code'] === '' ? null : $row['protein_code'],
                'carb' => $row['carb_code'] === '' ? null : $row['carb_code'],
                'animal' => $row['animal'] === '1',
                'premium' => $row['premium'] === '1',
            ];
        }
        $this->ingredients = $ingredients;
        $tags = [];
        foreach (CsvTable::read(
            $seedDirectory . '/feature_tag_map.csv',
            ['tag', 'cuisine_code', 'meal_type_code', 'festive']
        ) as $row) {
            if ($row['tag'] === '' || !in_array($row['festive'], ['0', '1'], true)) {
                throw new RuntimeException('Invalid feature tag seed row.');
            }
            $tags[mb_strtolower($row['tag'])] = [
                'cuisine' => $row['cuisine_code'] === '' ? null : $row['cuisine_code'],
                'meal_type' => $row['meal_type_code'] === '' ? null : $row['meal_type_code'],
                'festive' => $row['festive'] === '1',
            ];
        }
        $this->tags = $tags;
        $titleTerms = [];
        foreach (CsvTable::read(
            $seedDirectory . '/feature_title_terms.csv',
            ['term', 'feature', 'code']
        ) as $row) {
            if ($row['term'] === '' || !in_array($row['feature'], ['cuisine', 'meal_type'], true)
                || $row['code'] === '') {
                throw new RuntimeException('Invalid feature title term seed row.');
            }
            $titleTerms[] = $row;
        }
        $this->titleTerms = $titleTerms;
        $proteinTitleTerms = [];
        foreach (CsvTable::read(
            $seedDirectory . '/feature_protein_title_terms.csv',
            ['term', 'protein_code']
        ) as $row) {
            if ($row['term'] === '' || $row['protein_code'] === '') {
                throw new RuntimeException('Invalid protein title term seed row.');
            }
            $proteinTitleTerms[] = $row;
        }
        $this->proteinTitleTerms = $proteinTitleTerms;
        $cutUpTerms = [];
        foreach (CsvTable::read($seedDirectory . '/feature_cut_up_terms.csv', ['term']) as $row) {
            if ($row['term'] === '') {
                throw new RuntimeException('Invalid feature cut-up term.');
            }
            $cutUpTerms[] = $row['term'];
        }
        $this->cutUpTerms = $cutUpTerms;
        $this->longCookTerms = $this->readTerms($seedDirectory . '/feature_long_cook_terms.csv');
        $exclusions = ['unknown_only' => [], 'always' => [], 'side' => [], 'dish_title' => []];
        foreach (CsvTable::read($seedDirectory . '/feature_dinner_exclusions.csv', ['term', 'scope']) as $row) {
            if ($row['term'] === '' || !isset($exclusions[$row['scope']])) {
                throw new RuntimeException('Invalid dinner exclusion seed row.');
            }
            $exclusions[$row['scope']][] = $row['term'];
        }
        $this->dinnerExclusions = $exclusions;
        $this->dinnerMainIngredients = $this->readTerms($seedDirectory . '/feature_dinner_main_ingredients.csv');
        $this->dinnerTitleSeparators = $this->readTerms($seedDirectory . '/feature_dinner_title_separators.csv');
        $durationUnits = [];
        foreach (CsvTable::read($seedDirectory . '/feature_duration_units.csv', ['unit', 'minutes']) as $row) {
            if ($row['unit'] === '' || !is_numeric($row['minutes']) || (float) $row['minutes'] <= 0) {
                throw new RuntimeException('Invalid duration unit seed row.');
            }
            $durationUnits[mb_strtolower($row['unit'])] = (float) $row['minutes'];
        }
        $this->durationUnits = $durationUnits;
        $keywords = ['dish_type' => [], 'method' => [], 'protein_form' => []];
        $query = $db->prepare('SELECT keyword, signal_type, value FROM leftover_keyword');
        $query->execute();
        while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_string($row['keyword'] ?? null) || !is_string($row['signal_type'] ?? null)
                || !is_numeric($row['value'] ?? null) || !isset($keywords[$row['signal_type']])) {
                throw new RuntimeException('Invalid stored leftover keyword.');
            }
            $keywords[$row['signal_type']][] = [
                'keyword' => $row['keyword'],
                'value' => (float) $row['value'],
            ];
        }
        $this->leftoverKeywords = $keywords;
    }

    /** @return list<string> */
    private function readTerms(string $path): array
    {
        $terms = [];
        foreach (CsvTable::read($path, ['term']) as $row) {
            if ($row['term'] === '') {
                throw new RuntimeException('Invalid feature term seed row.');
            }
            $terms[] = $row['term'];
        }
        return $terms;
    }

    public function contains(string $text, string $term): bool
    {
        return mb_stripos($text, $term) !== false;
    }
}
