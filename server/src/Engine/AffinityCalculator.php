<?php

declare(strict_types=1);

namespace Matsvep\Engine;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class AffinityCalculator
{
    public function __construct(private readonly EngineConfig $config)
    {
    }

    /**
     * @param list<array{type: string, value: string, score: float}> $seeds
     * @param list<array{recipe_id: int, rating: int, rated_at: string, features: list<array{type: string, value: string, eta: float}>}> $ratings
     * @return list<array{type: string, value: string, score: float, weight_sum: float, confidence: float}>
     */
    public function calculate(array $seeds, array $ratings, DateTimeImmutable $asOf): array
    {
        $weights = [];
        $weightedScores = [];
        $seedWeight = $this->config->decimal('affinity.seed_weight');
        $halfLife = $this->config->integer('affinity.half_life_days');
        $confidenceK0 = $this->config->decimal('affinity.conf_k0');
        if ($seedWeight <= 0 || $halfLife <= 0 || $confidenceK0 <= 0) {
            throw new InvalidArgumentException('Affinity configuration must be positive.');
        }

        foreach ($seeds as $seed) {
            $this->add($weights, $weightedScores, $seed['type'], $seed['value'], $seedWeight, $seed['score']);
        }
        usort($ratings, static fn (array $a, array $b): int =>
            strcmp($a['rated_at'], $b['rated_at']) ?: $a['recipe_id'] <=> $b['recipe_id']);
        $now = $asOf->setTimezone(new DateTimeZone('UTC'));
        foreach ($ratings as $rating) {
            if ($rating['rating'] < 1 || $rating['rating'] > 5) {
                throw new InvalidArgumentException('Rating must be between one and five.');
            }
            $ratedAt = new DateTimeImmutable($rating['rated_at'], new DateTimeZone('UTC'));
            $ageSeconds = max(0.0, (float) $now->format('U.u') - (float) $ratedAt->format('U.u'));
            $decay = 2 ** (-$ageSeconds / (86400 * $halfLife));
            $score = ($rating['rating'] - 3) / 2;
            foreach ($rating['features'] as $feature) {
                $this->add(
                    $weights,
                    $weightedScores,
                    $feature['type'],
                    $feature['value'],
                    $feature['eta'] * $decay,
                    $score
                );
            }
        }

        ksort($weights, SORT_STRING);
        $result = [];
        foreach ($weights as $key => $weight) {
            [$type, $value] = explode("\0", $key, 2);
            $result[] = [
                'type' => $type,
                'value' => $value,
                'score' => $weightedScores[$key] / $weight,
                'weight_sum' => $weight,
                'confidence' => $weight / ($weight + $confidenceK0),
            ];
        }
        return $result;
    }

    public function confidence(float $weightSum): float
    {
        $k0 = $this->config->decimal('affinity.conf_k0');
        if ($weightSum < 0 || $k0 <= 0) {
            throw new InvalidArgumentException('Invalid affinity confidence inputs.');
        }
        return $weightSum / ($weightSum + $k0);
    }

    /**
     * @param array<string, float> $weights
     * @param array<string, float> $weightedScores
     */
    private function add(
        array &$weights,
        array &$weightedScores,
        string $type,
        string $value,
        float $weight,
        float $score
    ): void {
        if ($type === '' || $value === '' || $weight <= 0 || $score < -1 || $score > 1) {
            throw new InvalidArgumentException('Invalid affinity evidence.');
        }
        $key = $type . "\0" . $value;
        $weights[$key] = ($weights[$key] ?? 0.0) + $weight;
        $weightedScores[$key] = ($weightedScores[$key] ?? 0.0) + $weight * $score;
    }
}
