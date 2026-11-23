<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Feature;

use PDO;
use RuntimeException;
use Throwable;

final class FeatureDeriver
{
    private readonly FeatureConfig $config;

    private readonly FeatureVocabulary $vocabulary;

    private readonly ProteinCandidateMatcher $proteinCandidates;

    private readonly RecipeTimeEstimator $timeEstimator;

    private int $changedTimeEstimates = 0;

    public function __construct(private readonly PDO $db, string $seedDirectory)
    {
        $this->config = new FeatureConfig($db);
        $this->vocabulary = new FeatureVocabulary($db, $seedDirectory);
        $this->proteinCandidates = new ProteinCandidateMatcher($db, $seedDirectory);
        $this->timeEstimator = new RecipeTimeEstimator($seedDirectory);
        if ($this->vocabulary->leftoverKeywords['dish_type'] === []) {
            throw new RuntimeException('Seed feature keywords before deriving recipes.');
        }
        if ($this->config->integer('feature.festive_time_full_min') < 1
            || $this->config->decimal('feature.main_min_mass_g') < 0
            || $this->config->integer('feature.effort_easy_time_min')
                >= $this->config->integer('feature.effort_hard_time_min')
            || $this->config->integer('feature.effort_easy_ingredient_count')
                >= $this->config->integer('feature.effort_hard_ingredient_count')) {
            throw new RuntimeException('Invalid feature derivation configuration.');
        }
    }

    public function changedTimeEstimates(): int
    {
        return $this->changedTimeEstimates;
    }

    public function run(?int $recipeId = null): int
    {
        $this->changedTimeEstimates = 0;
        $sql = 'SELECT id, title, total_time_min, total_time_source FROM recipe WHERE active = TRUE';
        $arguments = [];
        if ($recipeId !== null) {
            $sql .= ' AND id = ?';
            $arguments[] = $recipeId;
        }
        $sql .= ' ORDER BY id';
        $query = $this->db->prepare($sql);
        $query->execute($arguments);
        $recipes = $query->fetchAll(PDO::FETCH_ASSOC);
        $lineQuery = $this->db->prepare(
            'SELECT position, ingredient_text, raw_text, quantity, unit, normalise_status ' .
            'FROM recipe_ingredient WHERE recipe_id = ? ORDER BY position'
        );
        $partQuery = $this->db->prepare(
            'SELECT p.position, i.canonical_name, i.category, i.is_staple ' .
            'FROM recipe_ingredient_part p LEFT JOIN ingredient i ON i.id = p.ingredient_id ' .
            'WHERE p.recipe_id = ? ORDER BY p.position, p.part_position'
        );
        $tagQuery = $this->db->prepare('SELECT tag FROM recipe_tag WHERE recipe_id = ?');
        $stepQuery = $this->db->prepare('SELECT text FROM recipe_step WHERE recipe_id = ? ORDER BY position');
        $overrideQuery = $this->db->prepare(
            'SELECT main_protein, carb_base, cuisine, meal_type, is_dinner_candidate, leftover_score ' .
            'FROM recipe_feature_override WHERE recipe_id = ?'
        );
        $setMain = $this->db->prepare(
            'UPDATE recipe_ingredient SET is_main = ? WHERE recipe_id = ? AND position = ?'
        );
        $update = $this->db->prepare(
            'UPDATE recipe SET main_protein = ?, carb_base = ?, cuisine = ?, meal_type = ?, ' .
            'is_dinner_candidate = ?, is_vegetarian = ?, leftover_score = ?, festive_score = ?, effort = ?, ' .
            'total_time_min = ?, total_time_source = ? WHERE id = ?'
        );
        $count = 0;
        $this->db->beginTransaction();
        try {
            foreach ($recipes as $recipe) {
                if (!is_array($recipe) || !is_numeric($recipe['id'] ?? null)
                    || !is_string($recipe['title'] ?? null)) {
                    throw new RuntimeException('Invalid stored recipe for feature derivation.');
                }
                $id = (int) $recipe['id'];
                $lineQuery->execute([$id]);
                $lineRows = [];
                foreach ($lineQuery->fetchAll(PDO::FETCH_ASSOC) as $line) {
                    if (!is_array($line)) {
                        throw new RuntimeException('Invalid stored ingredient line.');
                    }
                    $lineRows[] = [
                        'position' => $line['position'] ?? null,
                        'raw_text' => $line['raw_text'] ?? null,
                        'ingredient_text' => $line['ingredient_text'] ?? null,
                        'quantity' => $line['quantity'] ?? null,
                        'unit' => $line['unit'] ?? null,
                        'normalise_status' => $line['normalise_status'] ?? null,
                    ];
                }
                $partQuery->execute([$id]);
                $parts = [];
                while (($part = $partQuery->fetch(PDO::FETCH_ASSOC)) !== false) {
                    if (!is_numeric($part['position'] ?? null)) {
                        throw new RuntimeException('Invalid stored ingredient part.');
                    }
                    $parts[(int) $part['position']][] = $part;
                }
                $tagQuery->execute([$id]);
                $tags = array_values(array_filter($tagQuery->fetchAll(PDO::FETCH_COLUMN), 'is_string'));
                $stepQuery->execute([$id]);
                $steps = array_values(array_filter($stepQuery->fetchAll(PDO::FETCH_COLUMN), 'is_string'));
                $storedTime = is_numeric($recipe['total_time_min'] ?? null)
                    ? (int) $recipe['total_time_min'] : null;
                $storedSource = $recipe['total_time_source'] ?? 'unknown';
                $given = $storedSource === 'given' || ($storedSource === 'unknown' && $storedTime !== null);
                $time = $given ? $storedTime : $this->timeEstimator->estimate($steps);
                $timeSource = $given ? 'given' : ($time === null ? 'unknown' : 'estimated');
                if ($storedSource === 'estimated' && $storedTime !== $time) {
                    $this->changedTimeEstimates++;
                }
                $recipeData = [
                    'id' => $id,
                    'title' => $recipe['title'],
                    'total_time_min' => $time,
                ];
                $features = $this->deriveOne($recipeData, $lineRows, $parts, $tags, $steps, $setMain);
                $overrideQuery->execute([$id]);
                $override = $overrideQuery->fetch(PDO::FETCH_ASSOC);
                if (is_array($override)) {
                    foreach (['main_protein' => 0, 'carb_base' => 1, 'cuisine' => 2,
                        'meal_type' => 3, 'is_dinner_candidate' => 4, 'leftover_score' => 6] as $column => $index) {
                        if ($override[$column] !== null) {
                            $features[$index] = $override[$column];
                        }
                    }
                }
                $update->execute([...$features, $time, $timeSource, $id]);
                $count++;
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        return $count;
    }

    /**
     * @param array{id: int, title: string, total_time_min: mixed} $recipe
     * @param list<array<string, mixed>> $lines
     * @param array<int, list<array<string, mixed>>> $parts
     * @param list<string> $tags
     * @param list<string> $steps
     * @return array{?string, ?string, ?string, ?string, int, ?int, float, float, int}
     */
    private function deriveOne(
        array $recipe,
        array $lines,
        array $parts,
        array $tags,
        array $steps,
        \PDOStatement $setMain,
    ): array {
        $mainCategories = $this->config->stringList('feature.main_categories');
        $mainMass = $this->config->decimal('feature.main_min_mass_g');
        $protein = null;
        $proteinChoices = [];
        $vegetarianProteinPresent = false;
        $substantialVegetarianMain = false;
        $carb = null;
        $animal = false;
        $premium = false;
        $matchedMain = false;
        $unmatchedMain = false;
        $unmatchedAnimal = false;
        $strongMainProtein = false;
        $eggPresent = false;
        $nonEggMain = false;
        $foodLines = 0;
        $sugarFlourLines = 0;
        $hasSugar = false;
        $hasFlour = false;
        $directBaking = false;
        $ingredientTexts = [];
        foreach ($lines as $line) {
            if (!is_numeric($line['position'] ?? null) || !is_string($line['raw_text'] ?? null)
                || !is_string($line['normalise_status'] ?? null)) {
                throw new RuntimeException('Normalise ingredient lines before deriving recipe features.');
            }
            $position = (int) $line['position'];
            $text = is_string($line['ingredient_text'] ?? null)
                ? $line['ingredient_text'] : $line['raw_text'];
            $ingredientTexts[] = $text;
            $main = false;
            $lineMapped = false;
            $lineSugarFlour = false;
            if ($line['normalise_status'] !== 'ignored') {
                $foodLines++;
            }
            foreach ($parts[$position] ?? [] as $part) {
                $name = $part['canonical_name'] ?? null;
                if (!is_string($name)) {
                    continue;
                }
                $lineMapped = true;
                $normalisedName = mb_strtolower($name);
                if (is_string($part['category'] ?? null) && is_numeric($part['is_staple'] ?? null)
                    && (int) $part['is_staple'] === 0 && in_array($part['category'], $mainCategories, true)) {
                    $main = true;
                }
                $bakingSignal = $this->vocabulary->dinnerBakingIngredients[$normalisedName] ?? null;
                $directBaking = $directBaking || $bakingSignal === 'direct';
                $hasSugar = $hasSugar || $bakingSignal === 'sugar';
                $hasFlour = $hasFlour || $bakingSignal === 'flour';
                $lineSugarFlour = $lineSugarFlour || in_array($bakingSignal, ['sugar', 'flour'], true);
                $eggPresent = $eggPresent || in_array($normalisedName, $this->vocabulary->dinnerEggIngredients, true);
                $nonEggMain = $nonEggMain || ($main
                    && !in_array($normalisedName, $this->vocabulary->dinnerEggIngredients, true));
                $rule = $this->vocabulary->ingredients[$normalisedName] ?? null;
                if ($rule === null) {
                    continue;
                }
                $animal = $animal || $rule['animal'];
                $premium = $premium || $rule['premium'];
                if ($rule['carb'] !== null && $carb === null) {
                    $carb = $rule['carb'];
                }
                if ($rule['protein'] === 'vegetarian') {
                    $vegetarianProteinPresent = true;
                    $substantialVegetarianMain = $substantialVegetarianMain
                        || in_array(mb_strtolower($name), $this->vocabulary->dinnerMainIngredients, true);
                }
                $strongMainProtein = $strongMainProtein || ($main
                    && $rule['protein'] !== null
                    && ($rule['protein'] !== 'vegetarian'
                        || in_array($normalisedName, $this->vocabulary->dinnerMainIngredients, true)));
                if ($rule['protein'] !== null && $rule['protein'] !== 'vegetarian') {
                    $proteinChoices[] = [
                        'code' => $rule['protein'],
                        'grams' => $this->gramAmount($line),
                        'position' => $position,
                        'main' => $main,
                    ];
                }
            }
            if (!$lineMapped && $line['normalise_status'] !== 'ignored') {
                $main = $this->proteinCandidates->matches($text)
                    || $this->hasMainMass($line, $mainMass);
            }
            $unmatchedAnimal = $unmatchedAnimal || ($line['normalise_status'] !== 'matched'
                && $line['normalise_status'] !== 'ignored'
                && $this->containsAny($line['raw_text'], $this->vocabulary->animalStems));
            $sugarFlourLines += $lineSugarFlour ? 1 : 0;
            if ($main) {
                $matchedMain = $matchedMain || $line['normalise_status'] === 'matched';
                $unmatchedMain = $unmatchedMain || $line['normalise_status'] !== 'matched';
            }
            $setMain->execute([$main ? 1 : 0, $recipe['id'], $position]);
        }
        $titleProtein = $this->proteinFromTitle($recipe['title']);
        $animal = $animal || ($titleProtein !== null && $titleProtein !== 'vegetarian');
        $unmatchedAnimal = $unmatchedAnimal || ($titleProtein === null
            && $this->containsAny($recipe['title'], $this->vocabulary->animalStems));
        $vegetarian = $animal ? 0 : ($matchedMain && !$unmatchedMain && !$unmatchedAnimal ? 1 : null);
        usort($proteinChoices, static fn (array $a, array $b): int =>
            ($b['grams'] ?? -1) <=> ($a['grams'] ?? -1)
                ?: (int) $b['main'] <=> (int) $a['main']
                ?: $a['position'] <=> $b['position']);
        $protein = ($titleProtein !== null && $titleProtein !== 'vegetarian')
            ? $titleProtein : ($proteinChoices[0]['code'] ?? ($vegetarian === 1 ? 'vegetarian' : null));
        [$cuisine, $mealType, $festiveTag] = $this->tagFeatures($recipe['title'], $tags);
        $stepText = implode(' ', $steps);
        $dishSignal = $this->signal('dish_type', $recipe['title'] . ' ' . implode(' ', $tags));
        $methodSignal = $this->signal('method', $stepText);
        if ($this->hasLongCookingStep($steps)) {
            $methodSignal = max($methodSignal, $this->config->decimal('feature.leftover_long_cook_signal'));
        }
        $proteinSignal = $this->signal('protein_form', implode(' ', $ingredientTexts));
        if ($dishSignal > 0 && $proteinSignal < 0 && $this->hasCutUpTerm($stepText)) {
            $proteinSignal = $this->config->decimal('feature.leftover_cut_up_signal');
        }
        $leftover = $this->clamp(
            $this->config->decimal('feature.leftover_baseline')
            + $this->config->decimal('feature.leftover_scale') * (
                $this->config->decimal('feature.leftover_dish_weight') * $dishSignal
                + $this->config->decimal('feature.leftover_method_weight') * $methodSignal
                + $this->config->decimal('feature.leftover_protein_weight') * $proteinSignal
            ),
        );
        $savoryTitle = $this->containsAny($recipe['title'], $this->vocabulary->dinnerSavoryTitles);
        $clearDinnerEvidence = $strongMainProtein || $savoryTitle
            || ($titleProtein !== null && $titleProtein !== 'vegetarian');
        $proteinEvidence = $titleProtein !== null || $proteinChoices !== [] || $vegetarianProteinPresent;
        $dinnerCandidate = $mealType === 'main' || ($mealType === null
            && ($proteinEvidence || $carb !== null)
            && (!$this->hasDinnerExclusion($recipe['title'] . ' ' . implode(' ', $tags), 'unknown_only')
                || $clearDinnerEvidence));
        $substantialMain = ($titleProtein !== null && $titleProtein !== 'vegetarian')
            || $proteinChoices !== [] || $carb !== null || $substantialVegetarianMain;
        $blockedSourceTag = false;
        foreach ($tags as $tag) {
            $blockedSourceTag = $blockedSourceTag || in_array(
                mb_strtolower(trim($tag)),
                $this->vocabulary->dinnerBlockedTags,
                true,
            );
        }
        $bakingShare = $foodLines === 0 ? 0.0 : $sugarFlourLines / $foodLines;
        $bakingIngredients = $directBaking || ($hasSugar && $hasFlour
            && $bakingShare >= $this->config->decimal('feature.baking_sugar_flour_share'));
        $broadTitle = $this->containsAny($recipe['title'], $this->vocabulary->dinnerBroadTitles);
        $unambiguousTitle = $this->containsAny($recipe['title'], $this->vocabulary->dinnerUnambiguousTitles);
        $eggAlone = $eggPresent && !$nonEggMain && $carb === null && !$savoryTitle
            && $proteinChoices === [] && !$strongMainProtein;
        if ($blockedSourceTag) {
            $dinnerCandidate = false;
        } elseif (!$clearDinnerEvidence && $bakingIngredients) {
            $dinnerCandidate = false;
        } elseif ($unambiguousTitle || (!$clearDinnerEvidence && $broadTitle)) {
            $dinnerCandidate = false;
        } elseif ($eggAlone || $this->hasDinnerExclusion($recipe['title'], 'always')) {
            $dinnerCandidate = false;
        } elseif ($dinnerCandidate) {
            $dishTitle = $recipe['title'];
            foreach ($this->vocabulary->dinnerTitleSeparators as $separator) {
                $offset = mb_stripos($dishTitle, ' ' . $separator . ' ');
                if ($offset !== false) {
                    $dishTitle = mb_substr($dishTitle, 0, $offset);
                }
            }
            $dinnerCandidate = !$this->hasDinnerExclusion($dishTitle, 'dish_title')
                && ($substantialMain || !$this->hasDinnerExclusion($recipe['title'], 'side'));
        }
        $time = is_numeric($recipe['total_time_min'] ?? null)
            ? (int) $recipe['total_time_min'] : null;
        $timeShare = $time === null ? 0.0 : min(
            1.0,
            $time / $this->config->integer('feature.festive_time_full_min'),
        );
        $festive = $this->clamp(
            $this->config->decimal('feature.festive_time_weight') * $timeShare
            + $this->config->decimal('feature.festive_premium_weight') * ($premium ? 1 : 0)
            + $this->config->decimal('feature.festive_tag_weight') * ($festiveTag ? 1 : 0),
        );
        $ingredientCount = count($lines);
        $effort = ($time !== null && $time >= $this->config->integer('feature.effort_hard_time_min'))
            || $ingredientCount >= $this->config->integer('feature.effort_hard_ingredient_count')
            ? 3 : (($time !== null && $time <= $this->config->integer('feature.effort_easy_time_min')
                && $ingredientCount <= $this->config->integer('feature.effort_easy_ingredient_count')) ? 1 : 2);
        return [$protein, $carb, $cuisine, $mealType, $dinnerCandidate ? 1 : 0, $vegetarian, round($leftover, 4), round($festive, 4), $effort];
    }

    /** @param array<string, mixed> $line */
    private function hasMainMass(array $line, float $minimumGrams): bool
    {
        return ($this->gramAmount($line) ?? 0.0) >= $minimumGrams;
    }

    /** @param array<string, mixed> $line */
    private function gramAmount(array $line): ?float
    {
        if (!is_numeric($line['quantity'] ?? null) || !is_string($line['unit'] ?? null)) {
            return null;
        }
        return match ($line['unit']) {
            'gram' => (float) $line['quantity'],
            'kilogram' => (float) $line['quantity'] * 1000,
            default => null,
        };
    }

    /** @param list<string> $tags
     * @return array{?string, ?string, bool}
     */
    private function tagFeatures(string $title, array $tags): array
    {
        $cuisine = null;
        $mealType = null;
        foreach ($this->vocabulary->titleTerms as $term) {
            if ($this->vocabulary->contains($title, $term['term'])) {
                if ($term['feature'] === 'cuisine' && $cuisine === null) {
                    $cuisine = $term['code'];
                }
                if ($term['feature'] === 'meal_type' && $mealType === null) {
                    $mealType = $term['code'];
                }
            }
        }
        $tagSet = array_fill_keys(array_map('mb_strtolower', $tags), true);
        $festive = false;
        foreach ($this->vocabulary->tags as $tag => $rule) {
            if (!isset($tagSet[$tag])) {
                continue;
            }
            $cuisine ??= $rule['cuisine'];
            $mealType ??= $rule['meal_type'];
            $festive = $festive || $rule['festive'];
        }
        return [$cuisine, $mealType, $festive];
    }

    private function signal(string $type, string $text): float
    {
        $value = 0.0;
        foreach ($this->vocabulary->leftoverKeywords[$type] ?? [] as $entry) {
            if ($this->vocabulary->contains($text, $entry['keyword'])
                && abs($entry['value']) > abs($value)) {
                $value = $entry['value'];
            }
        }
        return $value;
    }

    /** @param list<string> $steps */
    private function hasLongCookingStep(array $steps): bool
    {
        $minimum = $this->config->integer('feature.leftover_long_cook_min');
        foreach ($steps as $step) {
            $hasTerm = false;
            foreach ($this->vocabulary->longCookTerms as $term) {
                $hasTerm = $hasTerm || $this->vocabulary->contains($step, $term);
            }
            if (!$hasTerm) {
                continue;
            }
            preg_match_all('/([0-9]+(?:[.,][0-9]+)?)\s*([\p{L}]+)/u', $step, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $multiplier = $this->vocabulary->durationUnits[mb_strtolower($match[2])] ?? null;
                if ($multiplier !== null && (float) str_replace(',', '.', $match[1]) * $multiplier >= $minimum) {
                    return true;
                }
            }
        }
        return false;
    }

    private function hasDinnerExclusion(string $text, string $scope): bool
    {
        foreach ($this->vocabulary->dinnerExclusions[$scope] as $term) {
            if ($this->vocabulary->contains($text, $term)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $terms */
    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($this->vocabulary->contains($text, $term)) {
                return true;
            }
        }
        return false;
    }

    private function hasCutUpTerm(string $text): bool
    {
        foreach ($this->vocabulary->cutUpTerms as $term) {
            if ($this->vocabulary->contains($text, $term)) {
                return true;
            }
        }
        return false;
    }

    private function proteinFromTitle(string $title): ?string
    {
        $first = null;
        $protein = null;
        foreach ($this->vocabulary->proteinTitleTerms as $term) {
            $position = mb_stripos($title, $term['term']);
            if ($position !== false && ($first === null || $position < $first)) {
                $first = $position;
                $protein = $term['protein_code'];
            }
        }
        return $protein;
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
