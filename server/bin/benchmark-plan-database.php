<?php

declare(strict_types=1);

use Matsvep\Database\MigrationRunner;
use Matsvep\Engine\EngineConfig;
use Matsvep\Engine\PlanManager;
use Matsvep\Engine\ReadOnlyPlanBenchmark;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @internal The benchmark records execute time without logging parameters. */
final class PlanningBenchmarkStatement extends PDOStatement
{
    /** @var array<string, array{count: int, total_ms: float, max_ms: float}> */
    public static array $queries = [];

    protected function __construct()
    {
    }

    /** @param array<int|string, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $start = hrtime(true);
        try {
            return parent::execute($params);
        } finally {
            $ms = (hrtime(true) - $start) / 1_000_000;
            $name = self::name($this->queryString);
            self::$queries[$name] ??= ['count' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0];
            self::$queries[$name]['count']++;
            self::$queries[$name]['total_ms'] += $ms;
            self::$queries[$name]['max_ms'] = max(self::$queries[$name]['max_ms'], $ms);
        }
    }

    private static function name(string $sql): string
    {
        $operation = strtoupper(strtok(ltrim($sql), " \t\n") ?: 'OTHER');
        if (preg_match('/\\b(?:FROM|INTO|UPDATE|JOIN)\\s+([a-z_]+)/i', $sql, $match) === 1) {
            return $operation . ' ' . strtolower($match[1]);
        }
        return $operation;
    }
}

/** @return array{total_ms: float, query_ms: float, other_ms: float, queries: array<string, array{count: int, total_ms: float, max_ms: float}>} */
function finishBenchmark(float $startedAt): array
{
    $total = (hrtime(true) - $startedAt) / 1_000_000;
    $queries = PlanningBenchmarkStatement::$queries;
    uasort($queries, static fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);
    $queryMs = array_sum(array_column($queries, 'total_ms'));
    PlanningBenchmarkStatement::$queries = [];
    return [
        'total_ms' => round($total, 2),
        'query_ms' => round($queryMs, 2),
        'other_ms' => round($total - $queryMs, 2),
        'queries' => array_slice($queries, 0, 10, true),
    ];
}

/** @param list<string> $columns
 *  @param list<list<int|float|string|null>> $rows
 */
function insertRows(PDO $db, string $table, array $columns, array $rows): void
{
    if ($rows === []) {
        return;
    }
    $tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES '
        . implode(', ', array_fill(0, count($rows), $tuple));
    $values = [];
    foreach ($rows as $row) {
        array_push($values, ...$row);
    }
    $db->prepare($sql)->execute($values);
}

$arguments = $_SERVER['argv'] ?? [];
if (!is_array($arguments)) {
    throw new RuntimeException('Invalid command arguments.');
}
$phaseTimes = [];
$count = 16365;
$rated = 100;
$readonly = false;
$householdId = null;
foreach (array_slice($arguments, 1) as $argument) {
    if (!is_string($argument)) {
        throw new InvalidArgumentException('Invalid command argument.');
    }
    if ($argument === '--readonly') {
        $readonly = true;
    } elseif (preg_match('/^--household=([1-9][0-9]*)$/', $argument, $match) === 1) {
        $householdId = (int) $match[1];
    } elseif (preg_match('/^--candidates=([1-9][0-9]*)$/', $argument, $match) === 1) {
        $count = (int) $match[1];
    } elseif (preg_match('/^--rated=(0|[1-9][0-9]*)$/', $argument, $match) === 1) {
        $rated = (int) $match[1];
    } else {
        throw new InvalidArgumentException('Use --readonly [--household=ID], or --candidates=N [--rated=N].');
    }
}
if ($readonly) {
    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument !== '--readonly' && preg_match('/^--household=[1-9][0-9]*$/', (string) $argument) !== 1) {
            throw new InvalidArgumentException('Read-only mode accepts only --readonly and optional --household=ID.');
        }
    }
    $path = getenv('MATS_ENV_FILE') ?: dirname(__DIR__) . '/.env';
    $settings = is_file($path) ? parse_ini_file($path, false, INI_SCANNER_RAW) : false;
    if (!is_array($settings)) {
        throw new RuntimeException('Read-only mode requires server/.env.');
    }
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
        if (!is_string($settings[$key] ?? null)) {
            throw new RuntimeException('Invalid database configuration.');
        }
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $settings['DB_HOST'], $settings['DB_PORT'], $settings['DB_NAME']);
    $db = new PDO($dsn, $settings['DB_USER'], $settings['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false,
        PDO::ATTR_STATEMENT_CLASS => [PlanningBenchmarkStatement::class],
    ]);
    $result = (new ReadOnlyPlanBenchmark($db))->run($householdId, static fn (int $started): array => finishBenchmark($started));
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}
if ($householdId !== null) {
    throw new InvalidArgumentException('--household requires --readonly.');
}
if ($rated > $count) {
    throw new InvalidArgumentException('Rated count exceeds candidate count.');
}
$path = dirname(__DIR__) . '/.env.testing';
if (!is_file($path)) {
    throw new RuntimeException('The database benchmark requires .env.testing.');
}
$settings = parse_ini_file($path, false, INI_SCANNER_RAW);
if (!is_array($settings)) {
    throw new RuntimeException('Invalid test database configuration.');
}
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
    if (!is_string($settings[$key] ?? null)) {
        throw new RuntimeException('Invalid test database configuration.');
    }
}
if (preg_match('/^[A-Za-z0-9_]+$/', $settings['DB_NAME']) !== 1) {
    throw new RuntimeException('Invalid test database prefix.');
}
$database = $settings['DB_NAME'] . '_benchmark_' . bin2hex(random_bytes(5));
$dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $settings['DB_HOST'], $settings['DB_PORT']);
$root = new PDO($dsn, $settings['DB_USER'], $settings['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci");
try {
    $db = new PDO($dsn . ';dbname=' . $database, $settings['DB_USER'], $settings['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    (new MigrationRunner($db, dirname(__DIR__) . '/migrations', $database))->run();
    $db->prepare('INSERT INTO household (name) VALUES (?)')->execute(['Benchmark household']);
    $householdId = (int) $db->lastInsertId();
    $sourceQuery = $db->prepare("SELECT id FROM source WHERE adapter = 'koket'");
    $sourceQuery->execute();
    $sourceId = (int) $sourceQuery->fetchColumn();
    $ingredientRows = [];
    for ($id = 1; $id <= 24; $id++) {
        $ingredientRows[] = [$id, 'benchmark ingredient ' . $id, 'protein'];
    }
    insertRows($db, 'ingredient', ['id', 'canonical_name', 'category'], $ingredientRows);
    for ($startId = 1; $startId <= $count; $startId += 250) {
        $recipes = [];
        $ingredients = [];
        $tags = [];
        for ($id = $startId; $id < min($startId + 250, $count + 1); $id++) {
            $recipes[] = [$id, $sourceId, 'https://example.invalid/benchmark-' . $id, 'Benchmark ' . $id,
                1, 20 + $id % 70, ($id % 10) / 10, ($id % 8) / 8, 1 + $id % 3,
                ['chicken', 'fish', 'vegetarian', 'beef'][$id % 4],
                ['italian', 'swedish', 'indian'][$id % 3],
                ['pasta', 'rice', 'potato'][$id % 3], $id % 4 === 2 ? 1 : 0];
            for ($position = 1; $position <= 2; $position++) {
                $ingredients[] = [$id, $position, 'benchmark ingredient', ($id + $position) % 24 + 1, 1];
            }
            $tags[] = [$id, 'benchmark_tag'];
        }
        insertRows($db, 'recipe', ['id', 'source_id', 'url', 'title', 'is_dinner_candidate',
            'total_time_min', 'leftover_score', 'festive_score', 'effort', 'main_protein',
            'cuisine', 'carb_base', 'is_vegetarian'], $recipes);
        insertRows($db, 'recipe_ingredient', ['recipe_id', 'position', 'raw_text', 'ingredient_id', 'is_main'], $ingredients);
        insertRows($db, 'recipe_tag', ['recipe_id', 'tag'], $tags);
    }
    $rating = $db->prepare('INSERT INTO household_recipe '
        . '(household_id, recipe_id, status, rating, rating_source, rated_at) '
        . "VALUES (?, ?, 'library', 5, 'user', UTC_TIMESTAMP(6))");
    for ($id = 1; $id <= $rated; $id++) {
        $rating->execute([$householdId, $id]);
    }
    $config = EngineConfig::fromDatabase($db);
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PlanningBenchmarkStatement::class]);
    $manager = new PlanManager($db, $config, static function (string $phase, float $ms) use (&$phaseTimes): void {
        $phaseTimes[$phase] = round($ms, 2);
    });
    $week = (new DateTimeImmutable('next sunday'))->format('Y-m-d');
    $days = [];
    for ($offset = 0; $offset < 7; $offset++) {
        $days[] = (new DateTimeImmutable($week))->modify('+' . $offset . ' days')->format('Y-m-d');
    }
    $start = hrtime(true);
    $initial = $manager->prepare($householdId, $week, 1, $days);
    $full = finishBenchmark($start);
    $full['phases'] = $phaseTimes;
    $phaseTimes = [];
    $first = $days[0];
    $pick = $initial['decks'][$first][0]['recipe_id'];
    $start = hrtime(true);
    $manager->choose($initial['plan_id'], $initial['version'], $first, $pick);
    $rerank = finishBenchmark($start);
    $rerank['phases'] = $phaseTimes;
    echo json_encode([
        'candidate_count' => $count,
        'rated_count' => $rated,
        'database' => 'throwaway_mysql',
        'full_plan' => $full,
        'rerank_after_pick' => $rerank,
        'rerank_target_met' => $rerank['total_ms'] < 100,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    $root->exec("DROP DATABASE `$database`");
}
