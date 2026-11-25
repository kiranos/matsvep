<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Feature;

use PDO;
use RuntimeException;

final class FeatureVocabulary
{
    /** @var array<string, array{protein: ?string, carb: ?string, animal: bool, premium: bool}> */
    public readonly array $ingredients;

    /** @var array<string, array{cuisine: ?string, meal_type: ?string, festive: bool, fest_bonus: bool, cuisine_scope: ?string, meal_tag_role: string}> */
    public readonly array $tags;

    /** @var list<array{term: string, feature: string, code: string}> */
    public readonly array $titleTerms;

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

    /** @var array<string, string> */
    public readonly array $dinnerBlockedTags;

    /** @var array<string, string> */
    public readonly array $dinnerBakingIngredients;

    /** @var list<string> */
    public readonly array $dinnerSavoryTitles;

    /** @var list<string> */
    public readonly array $dinnerBroadTitles;

    /** @var list<string> */
    public readonly array $dinnerUnambiguousTitles;

    /** @var list<string> */
    public readonly array $dinnerUnambiguousSuffixTitles;

    /** @var list<string> */
    public readonly array $dinnerSweetPieTitles;

    /** @var list<string> */
    public readonly array $dinnerSweetPieIngredients;

    /** @var list<string> */
    public readonly array $dinnerPieTitles;

    /** @var list<string> */
    public readonly array $dinnerEggIngredients;

    /** @var list<string> */
    public readonly array $proteinAccompaniments;

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
            ['tag', 'cuisine_code', 'meal_type_code', 'festive', 'fest_bonus', 'cuisine_scope', 'meal_tag_role']
        ) as $row) {
            if ($row['tag'] === '' || !in_array($row['festive'], ['0', '1'], true)
                || !in_array($row['fest_bonus'], ['0', '1'], true)
                || !in_array($row['cuisine_scope'], ['', 'broad', 'specific'], true)
                || !in_array($row['meal_tag_role'], ['none', 'main', 'exclude'], true)
                || ($row['cuisine_code'] === '') !== ($row['cuisine_scope'] === '')
                || ($row['meal_tag_role'] === 'main' && $row['meal_type_code'] !== 'main')
                || ($row['meal_tag_role'] === 'exclude' && $row['meal_type_code'] === '')) {
                throw new RuntimeException('Invalid feature tag seed row.');
            }
            $tags[mb_strtolower($row['tag'])] = [
                'cuisine' => $row['cuisine_code'] === '' ? null : $row['cuisine_code'],
                'meal_type' => $row['meal_type_code'] === '' ? null : $row['meal_type_code'],
                'festive' => $row['festive'] === '1',
                'fest_bonus' => $row['fest_bonus'] === '1',
                'cuisine_scope' => $row['cuisine_scope'] === '' ? null : $row['cuisine_scope'],
                'meal_tag_role' => $row['meal_tag_role'],
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
        $cutUpTerms = [];
        foreach (CsvTable::read($seedDirectory . '/feature_cut_up_terms.csv', ['term']) as $row) {
            if ($row['term'] === '') {
                throw new RuntimeException('Invalid feature cut-up term.');
            }
            $cutUpTerms[] = $row['term'];
        }
        $this->cutUpTerms = $cutUpTerms;
        $this->longCookTerms = $this->readTerms($seedDirectory . '/feature_long_cook_terms.csv');
        $exclusions = ['unknown_only' => [], 'always' => [], 'leading' => [],
            'leading_suffix' => [], 'side' => [], 'dish_title' => [],
            'starch_side' => [], 'starch_side_word_end' => []];
        foreach (CsvTable::read($seedDirectory . '/feature_dinner_exclusions.csv', ['term', 'scope']) as $row) {
            if ($row['term'] === '' || !isset($exclusions[$row['scope']])) {
                throw new RuntimeException('Invalid dinner exclusion seed row.');
            }
            $exclusions[$row['scope']][] = $row['term'];
        }
        $this->dinnerExclusions = $exclusions;
        $this->dinnerMainIngredients = $this->readTerms($seedDirectory . '/feature_dinner_main_ingredients.csv');
        $this->dinnerTitleSeparators = $this->readTerms($seedDirectory . '/feature_dinner_title_separators.csv');
        $blockedTags = [];
        foreach (CsvTable::read(
            $seedDirectory . '/feature_dinner_blocked_tags.csv',
            ['term', 'kind'],
        ) as $row) {
            if ($row['term'] === '' || !in_array($row['kind'], ['dessert', 'drink', 'broad'], true)) {
                throw new RuntimeException('Invalid blocked source tag seed row.');
            }
            $blockedTags[mb_strtolower($row['term'])] = $row['kind'];
        }
        $this->dinnerBlockedTags = $blockedTags;
        $bakingIngredients = [];
        foreach (CsvTable::read(
            $seedDirectory . '/feature_dinner_baking_ingredients.csv',
            ['ingredient_name', 'signal'],
        ) as $row) {
            if ($row['ingredient_name'] === '' || !in_array($row['signal'], ['direct', 'sugar', 'flour'], true)) {
                throw new RuntimeException('Invalid dinner baking ingredient seed row.');
            }
            $bakingIngredients[mb_strtolower($row['ingredient_name'])] = $row['signal'];
        }
        $this->dinnerBakingIngredients = $bakingIngredients;
        $this->dinnerSavoryTitles = $this->readTerms($seedDirectory . '/feature_dinner_savory_titles.csv');
        $this->dinnerBroadTitles = $this->readTerms($seedDirectory . '/feature_dinner_broad_titles.csv');
        $this->dinnerUnambiguousTitles = $this->readTerms($seedDirectory . '/feature_dinner_unambiguous_titles.csv');
        $this->dinnerUnambiguousSuffixTitles = $this->readTerms(
            $seedDirectory . '/feature_dinner_unambiguous_suffix_titles.csv'
        );
        $sweetTitles = [];
        $sweetIngredients = [];
        foreach (CsvTable::read($seedDirectory . '/feature_dinner_sweet_pie_terms.csv', ['term', 'scope']) as $row) {
            if ($row['term'] === '' || !in_array($row['scope'], ['title', 'ingredient', 'both'], true)) {
                throw new RuntimeException('Invalid sweet pie term seed row.');
            }
            if ($row['scope'] !== 'ingredient') {
                $sweetTitles[] = $row['term'];
            }
            if ($row['scope'] !== 'title') {
                $sweetIngredients[] = $row['term'];
            }
        }
        $this->dinnerSweetPieTitles = $sweetTitles;
        $this->dinnerSweetPieIngredients = $sweetIngredients;
        $this->dinnerPieTitles = $this->readTerms($seedDirectory . '/feature_dinner_pie_titles.csv');
        $this->dinnerEggIngredients = $this->readTerms($seedDirectory . '/feature_dinner_egg_ingredients.csv');
        $this->proteinAccompaniments = $this->readTerms($seedDirectory . '/feature_protein_accompaniments.csv');
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
        return str_contains(ForeignAccentFolder::fold($text), ForeignAccentFolder::fold($term));
    }

    public function containsAtWordEnd(string $text, string $term): bool
    {
        return preg_match(
            '/' . preg_quote(ForeignAccentFolder::fold($term), '/') . '(?!\p{L})/u',
            ForeignAccentFolder::fold($text),
        ) === 1;
    }
}
