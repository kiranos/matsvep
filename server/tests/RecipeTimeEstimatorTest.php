<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Ingest\Feature\RecipeTimeEstimator;
use PHPUnit\Framework\TestCase;

final class RecipeTimeEstimatorTest extends TestCase
{
    public function testStepDurationsFromFixture(): void
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/time_steps.json');
        self::assertIsString($raw);
        $cases = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($cases);
        $estimator = new RecipeTimeEstimator(__DIR__ . '/../seeds');
        foreach ($cases as $case) {
            self::assertIsArray($case);
            self::assertIsArray($case['steps'] ?? null);
            $steps = [];
            foreach ($case['steps'] as $step) {
                self::assertIsString($step);
                $steps[] = $step;
            }
            self::assertSame($case['minutes'], $estimator->estimate($steps));
        }
    }
}
