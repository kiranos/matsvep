<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Engine\EngineConfig;
use Matsvep\Simulator\CooldownReport;
use PHPUnit\Framework\TestCase;

final class CooldownReportTest extends TestCase
{
    public function testOnlyCookedMealsStartCooldownAndPlacedCarryoversAreExempt(): void
    {
        $report = new CooldownReport(new EngineConfig([
            'cooldown.days.5' => '14', 'cooldown.days.4' => '21', 'cooldown.days.3' => '42',
        ]));
        $meals = [
            ['date' => '2026-01-01', 'recipe_id' => 1, 'group_id' => 10,
                'rating' => null, 'placed_carryover' => false],
            ['date' => '2026-01-15', 'recipe_id' => 2, 'group_id' => 10,
                'rating' => 5, 'placed_carryover' => false],
            ['date' => '2026-02-01', 'recipe_id' => 3, 'group_id' => 10,
                'rating' => 4, 'placed_carryover' => true],
            ['date' => '2026-02-20', 'recipe_id' => 1, 'group_id' => 10,
                'rating' => 3, 'placed_carryover' => false],
            ['date' => '2026-02-21', 'recipe_id' => 4, 'group_id' => -4,
                'rating' => 4, 'placed_carryover' => false],
        ];
        self::assertSame([
            'cooldown_violations' => 1,
            'distinct_recipes_cooked' => 4,
            'distinct_dish_groups_cooked' => 2,
        ], $report->summarize($meals));
    }
}
