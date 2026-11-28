<?php

declare(strict_types=1);

namespace Matsvep\Simulator;

use DateTimeImmutable;
use Matsvep\Engine\EngineConfig;

final class CooldownReport
{
    public function __construct(private readonly EngineConfig $config)
    {
    }

    /** @param list<array{date: string, recipe_id: int, group_id: int, rating: ?int, placed_carryover: bool}> $meals
     *  @return array{cooldown_violations: int, distinct_recipes_cooked: int, distinct_dish_groups_cooked: int}
     */
    public function summarize(array $meals): array
    {
        $lastCooked = [];
        $recipes = [];
        $groups = [];
        $violations = 0;
        foreach ($meals as $meal) {
            $group = $meal['group_id'];
            $date = new DateTimeImmutable($meal['date']);
            $rating = in_array($meal['rating'], [3, 5], true) ? $meal['rating'] : 4;
            if (!$meal['placed_carryover'] && isset($lastCooked[$group])) {
                $days = $lastCooked[$group]->diff($date)->days;
                if ($days < $this->config->integer('cooldown.days.' . $rating)) {
                    $violations++;
                }
            }
            $lastCooked[$group] = $date;
            $recipes[$meal['recipe_id']] = true;
            $groups[$group] = true;
        }
        return ['cooldown_violations' => $violations,
            'distinct_recipes_cooked' => count($recipes),
            'distinct_dish_groups_cooked' => count($groups)];
    }
}
