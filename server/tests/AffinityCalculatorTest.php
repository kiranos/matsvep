<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Matsvep\Engine\AffinityCalculator;
use Matsvep\Engine\EngineConfig;
use PHPUnit\Framework\TestCase;

final class AffinityCalculatorTest extends TestCase
{
    public function testSeedsDecayMainIngredientWeightAndConfidence(): void
    {
        $calculator = $this->calculator();
        $asOf = new DateTimeImmutable('2026-11-22 00:00:00', new DateTimeZone('UTC'));
        $rows = $calculator->calculate(
            [['type' => 'cuisine', 'value' => 'italian', 'score' => 0.5]],
            [
                ['recipe_id' => 1, 'rating' => 1, 'rated_at' => '2025-11-22 00:00:00',
                    'features' => [['type' => 'cuisine', 'value' => 'italian', 'eta' => 1.0]]],
                ['recipe_id' => 2, 'rating' => 5, 'rated_at' => '2026-11-22 00:00:00',
                    'features' => [
                        ['type' => 'cuisine', 'value' => 'italian', 'eta' => 1.0],
                        ['type' => 'ingredient', 'value' => '42', 'eta' => 0.5],
                    ]],
            ],
            $asOf,
        );
        self::assertCount(2, $rows);
        self::assertSame('cuisine', $rows[0]['type']);
        self::assertEqualsWithDelta(3 / 7, $rows[0]['score'], 0.0000001);
        self::assertEqualsWithDelta(3.5, $rows[0]['weight_sum'], 0.0000001);
        self::assertEqualsWithDelta(7 / 13, $rows[0]['confidence'], 0.0000001);
        self::assertSame('ingredient', $rows[1]['type']);
        self::assertSame('42', $rows[1]['value']);
        self::assertEqualsWithDelta(0.5, $rows[1]['weight_sum'], 0.0000001);
        self::assertEqualsWithDelta(1 / 7, $rows[1]['confidence'], 0.0000001);
    }

    public function testEqualTimestampUsesRecipeIdForDeterministicOrder(): void
    {
        $a = ['recipe_id' => 2, 'rating' => 5, 'rated_at' => '2026-11-22 00:00:00',
            'features' => [['type' => 'protein', 'value' => 'fish', 'eta' => 1.0]]];
        $b = ['recipe_id' => 1, 'rating' => 1, 'rated_at' => '2026-11-22 00:00:00',
            'features' => [['type' => 'protein', 'value' => 'fish', 'eta' => 1.0]]];
        $at = new DateTimeImmutable('2026-11-22 00:00:00', new DateTimeZone('UTC'));
        self::assertSame(
            $this->calculator()->calculate([], [$a, $b], $at),
            $this->calculator()->calculate([], [$b, $a], $at),
        );
    }

    private function calculator(): AffinityCalculator
    {
        return new AffinityCalculator(new EngineConfig([
            'affinity.seed_weight' => '2',
            'affinity.half_life_days' => '365',
            'affinity.conf_k0' => '3',
        ]));
    }
}
