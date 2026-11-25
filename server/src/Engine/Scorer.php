<?php

declare(strict_types=1);

namespace Matsvep\Engine;

use InvalidArgumentException;

final class Scorer
{
    public function __construct(private readonly EngineConfig $config)
    {
    }

    /** @return array{content: float, pref: float, dayfit: float, due: float, explore: float, variety_pen: float, skip_pen: float, wishlist_bonus: float, total: float} */
    public function score(ScoreInput $input): array
    {
        $content = $this->content($input->affinities);
        $pref = $this->preference($content, $input->rating, $input->siblingMeanRating);
        $dayfit = $this->dayFit($input);
        $due = $this->due($input->rating, $input->daysSinceGroupCooked);
        $explore = $input->rating === null ? self::clamp(1 - $input->meanConfidence) : 0.0;
        $variety = $this->varietyPenalty($input);
        $skip = $this->skipPenalty($input->daysSinceReplaced, $input->rejectedSessions, $input->daysSinceRejected);
        $wish = $input->wishlisted ? $this->config->decimal('wishlist.bonus') : 0.0;
        $total = $this->config->decimal('weight.pref') * $pref
            + $this->config->decimal('weight.dayfit') * $dayfit
            + $this->config->decimal('weight.due') * $due
            + $this->config->decimal('weight.explore') * $explore
            - $this->config->decimal('weight.variety') * $variety
            - $this->config->decimal('weight.skip') * $skip + $wish;
        return ['content' => $content, 'pref' => $pref, 'dayfit' => $dayfit, 'due' => $due,
            'explore' => $explore, 'variety_pen' => $variety, 'skip_pen' => $skip,
            'wishlist_bonus' => $wish, 'total' => $total];
    }

    /** @param array<string, list<array{affinity: float, confidence: float}>> $affinities */
    public function content(array $affinities): float
    {
        $sum = 0.0;
        foreach (['ingredients', 'protein', 'cuisine', 'meal_type', 'carb'] as $type) {
            $numerator = 0.0;
            $confidence = 0.0;
            foreach ($affinities[$type] ?? [] as $feature) {
                $numerator += $feature['confidence'] * $feature['affinity'];
                $confidence += $feature['confidence'];
            }
            $sum += $this->config->decimal('content.beta.' . $type)
                * $numerator / ($confidence + $this->config->decimal('content.confidence_epsilon'));
        }
        return self::clamp(0.5 + 0.5 * $sum);
    }

    public function preference(float $content, ?int $rating, ?float $siblingMeanRating): float
    {
        if ($rating !== null) {
            $contentWeight = $this->config->decimal('pref.content_weight');
            $ratingWeight = $this->config->decimal('pref.rating_weight');
            return ($contentWeight * $content + $ratingWeight * (($rating - 1) / 4))
                / ($contentWeight + $ratingWeight);
        }
        if ($siblingMeanRating !== null) {
            $weight = $this->config->decimal('pref.sibling_weight');
            return ($content + $weight * (($siblingMeanRating - 1) / 4)) / (1 + $weight);
        }
        return $content;
    }

    public function dayFit(ScoreInput $input): float
    {
        if ($input->profile === 'weekday_leftovers') {
            $value = $this->config->decimal('dayfit.leftovers.leftover_weight') * $input->leftoverScore
                + $this->config->decimal('dayfit.leftovers.effort_weight') * (1 - $input->effort / 3);
            $cap = $this->config->integer($input->isSunday ? 'dayfit.sunday_max_minutes' : 'dayfit.weekday_max_minutes');
            if ($input->totalTimeMinutes !== null && $input->totalTimeMinutes > $cap) {
                $value *= $this->config->decimal('dayfit.softcap_factor');
            }
            return self::clamp($value);
        }
        if ($input->profile === 'friday_treat') {
            return array_intersect($input->tags, $this->config->stringList('dayfit.friday_tags')) !== []
                ? 1.0 : self::clamp($this->config->decimal('dayfit.friday.festive_weight') * $input->festiveScore);
        }
        if ($input->profile === 'saturday_dinner') {
            return self::clamp($this->config->decimal('dayfit.saturday.festive_weight') * $input->festiveScore
                + $this->config->decimal('dayfit.saturday.low_leftover_weight') * (1 - $input->leftoverScore));
        }
        throw new InvalidArgumentException('Unknown day profile: ' . $input->profile);
    }

    public function due(?int $rating, ?int $daysSinceGroupCooked): float
    {
        if ($daysSinceGroupCooked === null) {
            return $rating === null ? 0.0 : 1.0;
        }
        $bucket = $rating === 5 ? 5 : ($rating === 3 ? 3 : 4);
        $cooldown = $this->config->integer('cooldown.days.' . $bucket);
        return self::clamp(($daysSinceGroupCooked - $cooldown) / $cooldown);
    }

    /** @param list<array{protein: ?string, cuisine: ?string, carb: ?string, ingredients: list<int>, adjacent: bool, chosen: bool, in_plan_week: bool, vegetarian: bool}> $recent
     *  @return array{proteins: array<string, int>, cuisines: array<string, int>, carbs: array<string, int>, adjacent_proteins: array<string, bool>, fish_count: int, vegetarian_count: int, chosen_ingredients: list<list<int>>}
     */
    public function summarizeRecent(array $recent): array
    {
        $proteins = [];
        $cuisines = [];
        $carbs = [];
        $adjacentProteins = [];
        $fishCount = 0;
        $vegCount = 0;
        $chosenIngredients = [];
        foreach ($recent as $dinner) {
            if ($dinner['in_plan_week'] && $dinner['protein'] === 'fish') {
                $fishCount++;
            }
            if ($dinner['in_plan_week'] && $dinner['vegetarian']) {
                $vegCount++;
            }
            if ($dinner['in_plan_week'] && $dinner['protein'] !== null) {
                $proteins[$dinner['protein']] = ($proteins[$dinner['protein']] ?? 0) + 1;
            }
            if ($dinner['in_plan_week'] && $dinner['cuisine'] !== null) {
                $cuisines[$dinner['cuisine']] = ($cuisines[$dinner['cuisine']] ?? 0) + 1;
            }
            if ($dinner['in_plan_week'] && $dinner['carb'] !== null) {
                $carbs[$dinner['carb']] = ($carbs[$dinner['carb']] ?? 0) + 1;
            }
            if ($dinner['adjacent'] && $dinner['protein'] !== null) {
                $adjacentProteins[$dinner['protein']] = true;
            }
            if ($dinner['chosen']) {
                $chosenIngredients[] = $dinner['ingredients'];
            }
        }
        return ['proteins' => $proteins, 'cuisines' => $cuisines, 'carbs' => $carbs,
            'adjacent_proteins' => $adjacentProteins, 'fish_count' => $fishCount,
            'vegetarian_count' => $vegCount, 'chosen_ingredients' => $chosenIngredients];
    }

    public function varietyPenalty(ScoreInput $input): float
    {
        return $this->varietyPenaltyFromSummary($input, $this->summarizeRecent($input->recentDinners));
    }

    /** @param array{proteins: array<string, int>, cuisines: array<string, int>, carbs: array<string, int>, adjacent_proteins: array<string, bool>, fish_count: int, vegetarian_count: int, chosen_ingredients: list<list<int>>} $summary */
    public function varietyPenaltyFromSummary(ScoreInput $input, array $summary): float
    {
        $penalty = 0.0;
        $proteinCount = $input->mainProtein === null ? 0 : ($summary['proteins'][$input->mainProtein] ?? 0);
        $cuisineCount = $input->cuisine === null ? 0 : ($summary['cuisines'][$input->cuisine] ?? 0);
        $carbCount = $input->carbBase === null ? 0 : ($summary['carbs'][$input->carbBase] ?? 0);
        $adjacentProtein = $input->mainProtein !== null && isset($summary['adjacent_proteins'][$input->mainProtein]);
        $overlapFound = false;
        foreach ($summary['chosen_ingredients'] as $ingredients) {
            if ($this->jaccard($input->mainIngredientIds, $ingredients) > $this->config->decimal('variety.overlap_jaccard')) {
                $overlapFound = true;
                break;
            }
        }
        if ($adjacentProtein) {
            $penalty += $this->config->decimal('variety.pen.adjacent_protein');
        }
        $penalty += max(0, $proteinCount + 1 - $this->config->integer('variety.max_protein_week')) * $this->config->decimal('variety.pen.protein');
        $penalty += max(0, $cuisineCount + 1 - $this->config->integer('variety.max_cuisine_week')) * $this->config->decimal('variety.pen.cuisine');
        $penalty += max(0, $carbCount + 1 - $this->config->integer('variety.max_carb_week')) * $this->config->decimal('variety.pen.carb');
        if ($overlapFound) {
            $penalty += $this->config->decimal('variety.pen.overlap');
        }
        if ($input->daysLeftInPlanWeek <= $this->config->integer('variety.target_days_left')) {
            if ($summary['fish_count'] < $this->config->integer('variety.target_fish') && $input->mainProtein === 'fish') {
                $penalty -= $this->config->decimal('variety.bonus.fish');
            }
            if (!$input->blockVegetarian && $summary['vegetarian_count'] < $this->config->integer('variety.target_veg') && $input->vegetarian) {
                $penalty -= $this->config->decimal('variety.bonus.vegetarian');
            }
        }
        return min(1.0, max(-1.0, $penalty));
    }

    public function skipPenalty(?int $daysSinceReplaced, int $rejectedSessions, ?int $daysSinceRejected): float
    {
        $replaced = $daysSinceReplaced === null ? 0.0
            : self::clamp(1 - $daysSinceReplaced / $this->config->integer('skip.replace_days'));
        $rejected = $rejectedSessions < $this->config->integer('swipe.reject_sessions') || $daysSinceRejected === null
            ? 0.0 : self::clamp(1 - $daysSinceRejected / $this->config->integer('swipe.reject_days'));
        return max($replaced, $rejected);
    }

    /**
     * @param list<int> $left
     * @param list<int> $right
     */
    private function jaccard(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }
        if (count($left) === 1 && count($right) === 1) {
            return $left[0] === $right[0] ? 1.0 : 0.0;
        }
        $leftSet = array_fill_keys($left, true);
        $rightSet = array_fill_keys($right, true);
        $shared = 0;
        foreach ($leftSet as $ingredientId => $_present) {
            $shared += isset($rightSet[$ingredientId]) ? 1 : 0;
        }
        return $shared / (count($leftSet) + count($rightSet) - $shared);
    }

    private static function clamp(float $value): float
    {
        return min(1.0, max(0.0, $value));
    }
}
