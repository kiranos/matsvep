<?php

declare(strict_types=1);

namespace Matsvep\Engine;

final class Explainer
{
    public function __construct(private readonly EngineConfig $config)
    {
    }

    /** @param array<string, float> $score
     *  @return array{reasons: list<array{code: string, params: array<string, int|string>}>, breakdown: array<string, float>}
     */
    public function explain(PlanCandidate $recipe, string $profile, array $score, ?string $carryoverFrom = null): array
    {
        $parts = [
            'pref' => $score['pref'] * $this->config->decimal('weight.pref'),
            'dayfit' => $score['dayfit'] * $this->config->decimal('weight.dayfit'),
            'due' => $score['due'] * $this->config->decimal('weight.due'),
            'explore' => $score['explore'] * $this->config->decimal('weight.explore'),
            'wishlist_bonus' => $score['wishlist_bonus'],
        ];
        $order = array_keys($parts);
        usort($order, static fn (string $a, string $b): int => ($parts[$b] <=> $parts[$a]) ?: strcmp($a, $b));
        $reasons = [];
        if ($carryoverFrom !== null) {
            $reasons[] = ['code' => 'carried_over', 'params' => ['from_date' => $carryoverFrom]];
        }
        foreach ($order as $part) {
            if (count($reasons) >= 2 || $parts[$part] <= 0) {
                break;
            }
            $reason = match ($part) {
                'pref' => $this->preferenceReason($recipe),
                'dayfit' => ['code' => match ($profile) {
                    'friday_treat' => 'friday_treat',
                    'saturday_dinner' => 'saturday_dinner',
                    default => 'good_for_leftovers',
                }, 'params' => []],
                'due' => $recipe->daysSinceGroupCooked !== null
                    ? ['code' => 'not_cooked_recently', 'params' => [
                        'weeks' => intdiv($recipe->daysSinceGroupCooked, 7),
                    ]] : null,
                'explore' => $recipe->cuisine !== null
                    ? ['code' => 'new_cuisine', 'params' => ['cuisine_code' => $recipe->cuisine]] : null,
                'wishlist_bonus' => ['code' => 'wishlisted', 'params' => []],
            };
            if ($reason !== null) {
                $reasons[] = $reason;
            }
        }
        return ['reasons' => $reasons, 'breakdown' => $score];
    }

    /** @return array{code: string, params: array<string, int|string>}|null */
    private function preferenceReason(PlanCandidate $recipe): ?array
    {
        if ($recipe->rating !== null) {
            return ['code' => 'rated_stars', 'params' => ['stars' => $recipe->rating]];
        }
        if ($recipe->siblingMeanRating !== null) {
            return ['code' => 'liked_dish_group_variant', 'params' => ['dish_group_id' => $recipe->groupId]];
        }
        if ($recipe->similarRatedRecipeId !== null && $recipe->similarRatedStars !== null) {
            return ['code' => 'similar_rated_recipe', 'params' => [
                'recipe_id' => $recipe->similarRatedRecipeId,
                'stars' => $recipe->similarRatedStars,
            ]];
        }
        return null;
    }
}
