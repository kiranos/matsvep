<?php

declare(strict_types=1);

namespace Matsvep\Engine;

final class PlanCandidate
{
    /** @param list<int> $mainIngredientIds
     *  @param list<string> $tags
     *  @param array<string, list<array{affinity: float, confidence: float}>> $affinities
     */
    public function __construct(
        public readonly int $id,
        public readonly int $groupId,
        public readonly ?int $rating,
        public readonly ?float $siblingMeanRating,
        public readonly ?int $similarRatedRecipeId,
        public readonly ?int $totalTimeMinutes,
        public readonly float $leftoverScore,
        public readonly float $festiveScore,
        public readonly int $effort,
        public readonly array $tags,
        public readonly array $affinities,
        public readonly ?int $daysSinceGroupCooked,
        public readonly float $meanConfidence,
        public readonly ?string $mainProtein,
        public readonly ?string $cuisine,
        public readonly ?string $carbBase,
        public readonly array $mainIngredientIds,
        public readonly ?bool $vegetarian,
        public readonly bool $wishlisted,
        public readonly ?int $daysSinceReplaced = null,
        public readonly int $rejectedSessions = 0,
        public readonly ?int $daysSinceRejected = null,
        public readonly ?int $similarRatedStars = null,
        public readonly ?string $mealType = null,
    ) {
    }

    public function withSimilarity(?int $recipeId, ?int $stars): self
    {
        return new self(
            $this->id,
            $this->groupId,
            $this->rating,
            $this->siblingMeanRating,
            $recipeId,
            $this->totalTimeMinutes,
            $this->leftoverScore,
            $this->festiveScore,
            $this->effort,
            $this->tags,
            $this->affinities,
            $this->daysSinceGroupCooked,
            $this->meanConfidence,
            $this->mainProtein,
            $this->cuisine,
            $this->carbBase,
            $this->mainIngredientIds,
            $this->vegetarian,
            $this->wishlisted,
            $this->daysSinceReplaced,
            $this->rejectedSessions,
            $this->daysSinceRejected,
            $stars,
            $this->mealType
        );
    }

    public function withReplacementAge(int $days): self
    {
        return new self(
            $this->id,
            $this->groupId,
            $this->rating,
            $this->siblingMeanRating,
            $this->similarRatedRecipeId,
            $this->totalTimeMinutes,
            $this->leftoverScore,
            $this->festiveScore,
            $this->effort,
            $this->tags,
            $this->affinities,
            $this->daysSinceGroupCooked,
            $this->meanConfidence,
            $this->mainProtein,
            $this->cuisine,
            $this->carbBase,
            $this->mainIngredientIds,
            $this->vegetarian,
            $this->wishlisted,
            $days,
            $this->rejectedSessions,
            $this->daysSinceRejected,
            $this->similarRatedStars,
            $this->mealType
        );
    }

    /** @param list<array{protein: ?string, cuisine: ?string, carb: ?string, ingredients: list<int>, adjacent: bool, chosen: bool, in_plan_week: bool, vegetarian: bool}> $recentDinners */
    public function scoreInput(string $profile, bool $isSunday, array $recentDinners, int $daysLeft, bool $blockVegetarian): ScoreInput
    {
        return new ScoreInput(
            $profile,
            $isSunday,
            $this->totalTimeMinutes,
            $this->leftoverScore,
            $this->festiveScore,
            $this->effort,
            $this->tags,
            $this->rating,
            $this->siblingMeanRating,
            $this->affinities,
            $this->daysSinceGroupCooked,
            $this->meanConfidence,
            $this->mainProtein,
            $this->cuisine,
            $this->carbBase,
            $this->mainIngredientIds,
            $recentDinners,
            $daysLeft,
            $this->vegetarian === true,
            $blockVegetarian,
            $this->daysSinceReplaced,
            $this->rejectedSessions,
            $this->daysSinceRejected,
            $this->wishlisted,
        );
    }
}
