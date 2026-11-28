<?php

declare(strict_types=1);

use Matsvep\Database\MigrationRunner;
use Matsvep\Api\ApiKernel;
use Matsvep\Api\AppAuth;
use Matsvep\Api\DbValue;
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

/** @param list<float> $samples */
function percentile(array $samples, float $fraction): float
{
    sort($samples, SORT_NUMERIC);
    $index = max(0, (int) ceil(count($samples) * $fraction) - 1);
    return round($samples[$index], 2);
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
$recipeCount = 37600;
$rated = 100;
$readonly = false;
$apiMode = false;
$householdId = null;
foreach (array_slice($arguments, 1) as $argument) {
    if (!is_string($argument)) {
        throw new InvalidArgumentException('Invalid command argument.');
    }
    if ($argument === '--readonly') {
        $readonly = true;
    } elseif ($argument === '--api') {
        $apiMode = true;
    } elseif (preg_match('/^--household=([1-9][0-9]*)$/', $argument, $match) === 1) {
        $householdId = (int) $match[1];
    } elseif (preg_match('/^--candidates=([1-9][0-9]*)$/', $argument, $match) === 1) {
        $count = (int) $match[1];
    } elseif (preg_match('/^--recipes=([1-9][0-9]*)$/', $argument, $match) === 1) {
        $recipeCount = (int) $match[1];
    } elseif (preg_match('/^--rated=(0|[1-9][0-9]*)$/', $argument, $match) === 1) {
        $rated = (int) $match[1];
    } else {
        throw new InvalidArgumentException('Use --readonly [--household=ID], or --recipes=N --candidates=N [--rated=N].');
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
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $settings['DB_HOST'],
        $settings['DB_PORT'],
        $settings['DB_NAME']
    );
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
if ($count > $recipeCount) {
    throw new InvalidArgumentException('Candidate count exceeds recipe count.');
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
    for ($startId = 1; $startId <= $recipeCount; $startId += 250) {
        $recipes = [];
        $ingredients = [];
        $tags = [];
        for ($id = $startId; $id < min($startId + 250, $recipeCount + 1); $id++) {
            $recipes[] = [$id, $sourceId, 'https://example.invalid/benchmark-' . $id, 'Benchmark ' . $id,
                $id <= $count ? 1 : 0, 20 + $id % 70, ($id % 10) / 10, ($id % 8) / 8, 1 + $id % 3,
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
    if ($apiMode) {
        $db->prepare("SET time_zone = '+00:00'")->execute();
        $password = bin2hex(random_bytes(16));
        $db->prepare("INSERT INTO `user` (email, password_hash, role, household_id) VALUES (?, ?, 'member', ?)")
            ->execute(['benchmark@example.test', password_hash($password, PASSWORD_DEFAULT), $householdId]);
        $auth = new AppAuth($db, $config);
        $token = DbValue::text($auth->login(['email' => 'benchmark@example.test',
            'password' => $password, 'platform' => 'windows'], '127.0.0.1')['access_token']);
        PlanningBenchmarkStatement::$queries = [];
        $api = new ApiKernel($db);
        $week = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')));
        $week = $week->modify('-' . $week->format('w') . ' days')->format('Y-m-d');
        $request = static function (string $method, string $path, ?array $body = null) use ($api, $token): array {
            $headers = ['Authorization' => 'Bearer ' . $token];
            if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                $bytes = bin2hex(random_bytes(16));
                $headers['Idempotency-Key'] = substr($bytes, 0, 8) . '-' . substr($bytes, 8, 4)
                    . '-4' . substr($bytes, 13, 3) . '-a' . substr($bytes, 17, 3) . '-' . substr($bytes, 20, 12);
            }
            $response = $api->handle(
                $method,
                '/api/v1' . $path,
                $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
                $headers,
                '127.0.0.1'
            );
            if ($response[0] < 200 || $response[0] >= 300) {
                throw new RuntimeException('API benchmark request failed: ' . $path . ' ' . json_encode($response, JSON_THROW_ON_ERROR));
            }
            return $response[1];
        };
        $start = hrtime(true);
        $plan = $request('POST', '/plans', ['week_start' => $week, 'part' => 1]);
        $full = finishBenchmark($start);
        $planId = DbValue::integer($plan['id']);
        $slot = DbValue::rows($plan['slots'])[0];
        $slotId = DbValue::integer($slot['id']);
        $date = DbValue::text($slot['date']);
        $start = hrtime(true);
        $deck = $request('GET', '/plans/' . $planId . '/deck?date=' . $date);
        $deckFetch = finishBenchmark($start);
        $cards = DbValue::rows($deck['items']);
        $first = DbValue::integer(DbValue::map($cards[0]['recipe'])['id']);
        $second = DbValue::integer(DbValue::map($cards[1]['recipe'])['id']);
        $start = hrtime(true);
        $request('PUT', '/recipes/' . $first . '/rating', ['rating' => 4]);
        $ratingUpdate = finishBenchmark($start);
        $start = hrtime(true);
        $request('GET', '/plans/' . $planId . '/deck?date=' . $date);
        $deckAfterRating = finishBenchmark($start);
        $deckSamples = [];
        for ($sample = 0; $sample < 20; $sample++) {
            $start = hrtime(true);
            $request('GET', '/plans/' . $planId . '/deck?date=' . $date);
            $deckSamples[] = finishBenchmark($start)['total_ms'];
        }
        $start = hrtime(true);
        $chosen = $request(
            'POST',
            '/plans/' . $planId . '/slots/' . $slotId . '/choose',
            ['expected_version' => 1, 'recipe_id' => $first]
        );
        $choose = finishBenchmark($start);
        $nextDate = DbValue::text(DbValue::rows($plan['slots'])[1]['date']);
        $start = hrtime(true);
        $request('GET', '/plans/' . $planId . '/deck?date=' . $nextDate);
        $deckAfterPick = finishBenchmark($start);
        $start = hrtime(true);
        $request(
            'POST',
            '/plans/' . $planId . '/slots/' . $slotId . '/replace',
            ['expected_version' => DbValue::integer($chosen['version']), 'recipe_id' => $second]
        );
        $replace = finishBenchmark($start);
        $deckP95 = percentile($deckSamples, 0.95);
        $targetMet = $full['total_ms'] < 1000 && $deckFetch['total_ms'] < 100
            && $deckAfterRating['total_ms'] < 100 && $deckP95 < 100
            && $deckAfterPick['total_ms'] < 100;
        echo json_encode(
            ['recipe_count' => $recipeCount, 'candidate_count' => $count, 'rated_count' => $rated,
            'database' => 'throwaway_mysql', 'api_full_plan' => $full, 'api_deck_fetch' => $deckFetch,
            'api_rating_update' => $ratingUpdate, 'api_deck_after_rating' => $deckAfterRating,
            'api_deck_stable_p95_ms' => $deckP95,
            'api_deck_after_pick' => $deckAfterPick,
            'api_choose' => $choose, 'api_replace' => $replace,
            'api_target_met' => $targetMet],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        ) . PHP_EOL;
        if (!$targetMet) {
            throw new RuntimeException('Production-scale API planning target was missed.');
        }
        return;
    }
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
        'recipe_count' => $recipeCount,
        'candidate_count' => $count,
        'rated_count' => $rated,
        'database' => 'throwaway_mysql',
        'full_plan' => $full,
        'rerank_after_pick' => $rerank,
        'rerank_target_met' => $rerank['total_ms'] < 100,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
    if ($full['total_ms'] >= 1000 || $rerank['total_ms'] >= 100) {
        throw new RuntimeException('Production-scale planning target was missed.');
    }
} finally {
    $root->exec("DROP DATABASE `$database`");
}
