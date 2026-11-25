<?php

declare(strict_types=1);

use Matsvep\Engine\EngineConfig;
use Matsvep\Engine\PlanBuilder;
use Matsvep\Engine\PlanCandidate;
use Matsvep\Engine\PlanCandidatePool;

require dirname(__DIR__) . '/vendor/autoload.php';

$count = null;
$rated = null;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--candidates=([1-9][0-9]*)$/', $argument, $matches) === 1) {
        $count = (int) $matches[1];
    } elseif (preg_match('/^--rated=(0|[1-9][0-9]*)$/', $argument, $matches) === 1) {
        $rated = (int) $matches[1];
    } else {
        throw new InvalidArgumentException('Use --candidates=N and optional --rated=N.');
    }
}
if ($count === null) {
    throw new InvalidArgumentException('A production-sized candidate count is required.');
}
$rated ??= min($count, 100);
if ($rated > $count) {
    throw new InvalidArgumentException('Rated count exceeds candidate count.');
}
$values = [];
foreach (['017_engine_defaults.sql', '025_plan_builder_defaults.sql'] as $name) {
    $sql = file_get_contents(dirname(__DIR__) . '/migrations/' . $name);
    if ($sql === false) {
        throw new RuntimeException('Cannot read benchmark configuration seeds.');
    }
    preg_match_all("/\\('([^']+)', '([^']*)', '(?:decimal|integer|json|string)'/", $sql, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $values[$match[1]] = $match[2];
    }
}
$config = new EngineConfig($values);
$builder = new PlanBuilder($config);
$recipes = [];
for ($id = 1; $id <= $count; $id++) {
    $recipes[] = new PlanCandidate(
        $id,
        $id,
        $id <= $rated ? 5 : null,
        null,
        null,
        20 + $id % 70,
        ($id % 10) / 10,
        ($id % 8) / 8,
        1 + $id % 3,
        [],
        [],
        null,
        0.0,
        ['chicken', 'fish', 'vegetarian', 'beef'][$id % 4],
        ['italian', 'swedish', 'indian'][$id % 3],
        ['pasta', 'rice', 'potato'][$id % 3],
        [$id % 100 + 1],
        $id % 4 === 2,
        false
    );
}
$days = [];
for ($offset = 0; $offset < $config->integer('builder.default_plan_days'); $offset++) {
    $date = (new DateTimeImmutable('2026-11-22'))->modify('+' . $offset . ' days');
    $days[] = ['date' => $date->format('Y-m-d'), 'profile' => match ((int) $date->format('w')) {
        5 => 'friday_treat', 6 => 'saturday_dinner', default => 'weekday_leftovers',
    }];
}
$start = hrtime(true);
$pool = (new PlanCandidatePool($config))->select($recipes);
$initial = $builder->build($days, $pool, $config->decimal('builder.default_favourite_share'), 'benchmark');
$fullMilliseconds = (hrtime(true) - $start) / 1_000_000;
$picked = $initial['decks'][$days[0]['date']][0]['recipe_id'];
$start = hrtime(true);
(new PlanBuilder($config))->build(
    $days,
    (new PlanCandidatePool($config))->select($pool),
    $config->decimal('builder.default_favourite_share'),
    'benchmark',
    [$days[0]['date'] => $picked]
);
$rerankMilliseconds = (hrtime(true) - $start) / 1_000_000;
echo json_encode([
    'candidate_count' => $count,
    'rated_count' => $rated,
    'pool_count' => count($pool),
    'full_plan_ms' => round($fullMilliseconds, 2),
    'rerank_after_pick_ms' => round($rerankMilliseconds, 2),
    'rerank_target_met' => $rerankMilliseconds < 100,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
