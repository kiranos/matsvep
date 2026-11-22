<?php

declare(strict_types=1);

namespace Matsvep\Engine;

final class ScoreInput
{
    /**
     * @param array<string, list<array{affinity: float, confidence: float}>> $affinities
     * @param list<string> $tags
     * @param list<array{protein: ?string, cuisine: ?string, carb: ?string, ingredients: list<int>, adjacent: bool, chosen: bool, in_plan_week: bool, vegetarian: bool}> $recentDinners
     * @param list<int> $mainIngredientIds
     */
    public function __construct(
        public readonly string $profile,
        public readonly bool $isSunday,
        public readonly ?int $totalTimeMinutes,
        public readonly float $leftoverScore,
        public readonly float $festiveScore,
        public readonly int $effort,
        public readonly array $tags,
        public readonly ?int $rating,
        public readonly ?float $siblingMeanRating,
        public readonly array $affinities,
        public readonly ?int $daysSinceGroupCooked,
        public readonly float $meanConfidence,
        public readonly ?string $mainProtein,
        public readonly ?string $cuisine,
        public readonly ?string $carbBase,
        public readonly array $mainIngredientIds,
        public readonly array $recentDinners,
        public readonly int $daysLeftInPlanWeek,
        public readonly bool $vegetarian,
        public readonly bool $blockVegetarian,
        public readonly ?int $daysSinceReplaced,
        public readonly int $rejectedSessions,
        public readonly ?int $daysSinceRejected,
        public readonly bool $wishlisted,
    ) {
    }
}
