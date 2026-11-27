<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Api\ApiKernel;
use Matsvep\Api\AppAuth;
use Matsvep\Api\DbValue;
use Matsvep\Database\MigrationRunner;
use Matsvep\Engine\EngineConfig;
use Matsvep\Ingest\Ingredient\DatabaseConnection;
use Matsvep\Tests\Support\ApiResponseSchema;
use PDO;
use PHPUnit\Framework\TestCase;

final class ApiPlanningIntegrationTest extends TestCase
{
    private static PDO $admin;
    private static PDO $db;
    private static string $databaseName;
    private static int $householdId;
    private static int $otherHouseholdId;
    private static string $token;
    private static string $otherToken;

    public static function setUpBeforeClass(): void
    {
        $file = __DIR__ . '/../.env.testing';
        if (!is_file($file)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing for planning API tests.');
            }
            self::markTestSkipped('Configure .env.testing for planning API tests.');
        }
        $settings = parse_ini_file($file, false, INI_SCANNER_RAW);
        self::assertIsArray($settings);
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
            self::assertIsString($settings[$key] ?? null);
        }
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $settings['DB_NAME']);
        self::$databaseName = $settings['DB_NAME'] . '_' . bin2hex(random_bytes(6));
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $settings['DB_HOST'], $settings['DB_PORT']);
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false];
        self::$admin = new PDO($dsn, $settings['DB_USER'], $settings['DB_PASSWORD'], $options);
        self::$admin->exec('CREATE DATABASE `' . self::$databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci');
        self::$db = new PDO($dsn . ';dbname=' . self::$databaseName, $settings['DB_USER'], $settings['DB_PASSWORD'], $options);
        DatabaseConnection::setUtc(self::$db);
        (new MigrationRunner(self::$db, __DIR__ . '/../migrations', self::$databaseName))->run();
        $household = self::$db->prepare('INSERT INTO household (name) VALUES (?)');
        $household->execute(['First synthetic household']);
        self::$householdId = (int) self::$db->lastInsertId();
        $household->execute(['Second synthetic household']);
        self::$otherHouseholdId = (int) self::$db->lastInsertId();
        $user = self::$db->prepare("INSERT INTO `user` (email, password_hash, role, household_id) VALUES (?, ?, 'member', ?)");
        $password = password_hash('synthetic-password-long', PASSWORD_DEFAULT);
        $user->execute(['plan-first@example.test', $password, self::$householdId]);
        $user->execute(['plan-second@example.test', $password, self::$otherHouseholdId]);
        $auth = new AppAuth(self::$db, EngineConfig::fromDatabase(self::$db));
        self::$token = DbValue::text($auth->login(['email' => 'plan-first@example.test',
            'password' => 'synthetic-password-long', 'platform' => 'windows'], '127.0.9.1')['access_token']);
        self::$otherToken = DbValue::text($auth->login(['email' => 'plan-second@example.test',
            'password' => 'synthetic-password-long', 'platform' => 'windows'], '127.0.9.2')['access_token']);
        self::$db->prepare('INSERT INTO source (name, base_url, adapter, enabled) VALUES (?, ?, ?, TRUE)')
            ->execute(['Synthetic source', 'https://example.test', 'fixture']);
        $source = (int) self::$db->lastInsertId();
        $recipe = self::$db->prepare('INSERT INTO recipe (source_id, url, title, is_dinner_candidate, main_protein, leftover_score, festive_score, effort, active) VALUES (?, ?, ?, TRUE, ?, ?, ?, ?, TRUE)');
        for ($index = 1; $index <= 30; $index++) {
            $recipe->execute([$source, 'https://example.test/meal-' . $index, 'Synthetic meal ' . $index,
                $index % 2 === 0 ? 'fish' : 'vegetarian', 0.7, 0.5, 2]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$admin, self::$databaseName)) {
            self::$admin->exec('DROP DATABASE `' . self::$databaseName . '`');
        }
    }

    protected function setUp(): void
    {
        if (isset(self::$db)) {
            self::$db->beginTransaction();
        }
    }

    protected function tearDown(): void
    {
        if (isset(self::$db) && self::$db->inTransaction()) {
            self::$db->rollBack();
        }
    }

    public function testEveryPlanningEndpointMatchesContract(): void
    {
        $bundle = __DIR__ . '/../var/openapi.json';
        if (!is_file($bundle)) {
            if (getenv('CI') !== false) {
                self::fail('CI must bundle api/openapi.yaml before contract tests.');
            }
            self::markTestSkipped('Bundle api/openapi.yaml before contract tests.');
        }
        $schema = ApiResponseSchema::fromBundle($bundle);
        $api = new ApiKernel(self::$db);
        $week = self::currentSunday();
        $created = $this->contract(
            $api,
            $schema,
            'POST',
            '/plans',
            ['week_start' => $week, 'part' => 1]
        );
        $planId = DbValue::integer($created['id']);
        $slots = DbValue::rows($created['slots']);
        $slotId = DbValue::integer($slots[0]['id']);
        $date = DbValue::text($slots[0]['date']);
        $this->contract($api, $schema, 'GET', '/plans', null);
        $this->contract($api, $schema, 'GET', '/plans/' . $planId, null, '/plans/{plan_id}');
        $deck = $this->contract(
            $api,
            $schema,
            'GET',
            '/plans/' . $planId . '/deck?date=' . $date,
            null,
            '/plans/{plan_id}/deck'
        );
        $cards = DbValue::rows($deck['items']);
        self::assertGreaterThan(1, count($cards));
        $first = DbValue::integer(DbValue::map($cards[0]['recipe'])['id']);
        $second = DbValue::integer(DbValue::map($cards[1]['recipe'])['id']);
        $swipe = ['date' => $date, 'recipe_id' => $first, 'position' => 0, 'action' => 'next',
            'session_id' => '123e4567-e89b-42d3-a456-426614174100'];
        $this->contract(
            $api,
            $schema,
            'POST',
            '/plans/' . $planId . '/swipes',
            $swipe,
            '/plans/{plan_id}/swipes'
        );
        $this->contract(
            $api,
            $schema,
            'POST',
            '/plans/' . $planId . '/swipes',
            ['date' => $date, 'recipe_id' => $first, 'position' => 0, 'action' => 'undo',
                'session_id' => $swipe['session_id']],
            '/plans/{plan_id}/swipes'
        );
        $chosen = $this->contract(
            $api,
            $schema,
            'POST',
            '/plans/' . $planId . '/slots/' . $slotId . '/choose',
            ['expected_version' => 1, 'recipe_id' => $first],
            '/plans/{plan_id}/slots/{slot_id}/choose'
        );
        $replaced = $this->contract(
            $api,
            $schema,
            'POST',
            '/plans/' . $planId . '/slots/' . $slotId . '/replace',
            ['expected_version' => $chosen['version'], 'recipe_id' => $second],
            '/plans/{plan_id}/slots/{slot_id}/replace'
        );
        $filled = $this->contract(
            $api,
            $schema,
            'POST',
            '/plans/' . $planId . '/autofill',
            ['expected_version' => $replaced['version']],
            '/plans/{plan_id}/autofill'
        );
        $this->contract(
            $api,
            $schema,
            'POST',
            '/plans/' . $planId . '/shuffle',
            ['expected_version' => $filled['version']],
            '/plans/{plan_id}/shuffle'
        );
        $this->contract(
            $api,
            $schema,
            'POST',
            '/plans/' . $planId . '/slots/' . $slotId . '/answer',
            ['outcome' => 'not_cooked'],
            '/plans/{plan_id}/slots/{slot_id}/answer'
        );
    }

    public function testHouseholdIsolationAndVersionReplay(): void
    {
        $api = new ApiKernel(self::$db);
        $week = self::currentSunday();
        [$status, $plan] = $this->request($api, 'POST', '/plans', ['week_start' => $week, 'part' => 1]);
        self::assertSame(201, $status);
        $id = DbValue::integer($plan['id']);
        $slot = DbValue::rows($plan['slots'])[0];
        $slotId = DbValue::integer($slot['id']);
        $foreign = $this->request($api, 'GET', '/plans/' . $id, null, self::$otherToken);
        self::assertSame(404, $foreign[0]);
        $foreign = $this->request(
            $api,
            'POST',
            '/plans/' . $id . '/slots/' . $slotId . '/choose',
            ['expected_version' => 1, 'recipe_id' => DbValue::integer($slot['recipe_id'])],
            self::$otherToken
        );
        self::assertSame(404, $foreign[0]);
        $key = '123e4567-e89b-42d3-a456-426614174101';
        $body = ['expected_version' => 1, 'recipe_id' => DbValue::integer($slot['recipe_id'])];
        $route = '/plans/' . $id . '/slots/' . $slotId . '/choose';
        $first = $this->request($api, 'POST', $route, $body, self::$token, $key);
        self::assertSame(200, $first[0], json_encode($first[1], JSON_THROW_ON_ERROR));
        self::assertEquals($first, $this->request($api, 'POST', $route, $body, self::$token, $key));
        self::assertSame(409, $this->request($api, 'POST', $route, $body, self::$token)[0]);
        $different = ['expected_version' => 2, 'recipe_id' => DbValue::integer($slot['recipe_id'])];
        self::assertSame(422, $this->request($api, 'POST', $route, $different, self::$token, $key)[0]);
    }

    public function testCookedAnswerCountsOneMealAndRequiresFirstRating(): void
    {
        $api = new ApiKernel(self::$db);
        $plan = $this->request(
            $api,
            'POST',
            '/plans',
            ['week_start' => self::currentSunday(), 'part' => 1]
        )[1];
        $slot = DbValue::rows($plan['slots'])[0];
        $id = DbValue::integer($plan['id']);
        $slotId = DbValue::integer($slot['id']);
        $recipeId = DbValue::integer($slot['recipe_id']);
        $this->request(
            $api,
            'POST',
            '/plans/' . $id . '/slots/' . $slotId . '/choose',
            ['expected_version' => 1, 'recipe_id' => $recipeId]
        );
        $route = '/plans/' . $id . '/slots/' . $slotId . '/answer';
        self::assertSame(422, $this->request($api, 'POST', $route, ['outcome' => 'cooked'])[0]);
        self::assertSame(200, $this->request($api, 'POST', $route, ['outcome' => 'cooked', 'rating' => 5])[0]);
        self::assertSame(409, $this->request($api, 'POST', $route, ['outcome' => 'cooked', 'rating' => 5])[0]);
        $count = self::$db->prepare('SELECT COUNT(*) FROM meal_log WHERE household_id = ? AND plan_slot_id = ?');
        $count->execute([self::$householdId, $slotId]);
        self::assertSame(1, DbValue::integer($count->fetchColumn()));
    }

    public function testConcurrentChoicesReturnOneVersionConflict(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('Concurrent planning test requires pcntl.');
        }
        self::$db->rollBack();
        $api = new ApiKernel(self::$db);
        $plan = $this->request(
            $api,
            'POST',
            '/plans',
            ['week_start' => self::currentSunday(), 'part' => 1]
        )[1];
        $slot = DbValue::rows($plan['slots'])[0];
        $route = '/plans/' . DbValue::integer($plan['id']) . '/slots/' . DbValue::integer($slot['id']) . '/choose';
        $body = ['expected_version' => 1, 'recipe_id' => DbValue::integer($slot['recipe_id'])];
        $directory = sys_get_temp_dir() . '/matsvep-plan-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                $settings = parse_ini_file(__DIR__ . '/../.env.testing', false, INI_SCANNER_RAW);
                if (!is_array($settings)) {
                    exit(2);
                }
                foreach (['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD'] as $field) {
                    if (!is_string($settings[$field] ?? null)) {
                        exit(2);
                    }
                }
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $settings['DB_HOST'],
                    $settings['DB_PORT'],
                    self::$databaseName
                );
                $connection = new PDO(
                    $dsn,
                    $settings['DB_USER'],
                    $settings['DB_PASSWORD'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
                );
                DatabaseConnection::setUtc($connection);
                while (!is_file($directory . '/start')) {
                    usleep(1000);
                }
                $response = (new ApiKernel($connection))->handle(
                    'POST',
                    '/api/v1' . $route,
                    json_encode($body, JSON_THROW_ON_ERROR),
                    ['Authorization' => 'Bearer ' . self::$token,
                        'Idempotency-Key' => sprintf('123e4567-e89b-42d3-a456-%012d', random_int(1, 999999999999))],
                    '127.0.9.10'
                );
                file_put_contents($directory . '/' . $index, json_encode($response, JSON_THROW_ON_ERROR));
                exit(0);
            }
            $children[] = $pid;
        }
        file_put_contents($directory . '/start', 'go');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertIsInt($status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        // Child teardown closes inherited MySQL sockets, so the parent reconnects.
        $settings = parse_ini_file(__DIR__ . '/../.env.testing', false, INI_SCANNER_RAW);
        self::assertIsArray($settings);
        foreach (['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD'] as $field) {
            self::assertIsString($settings[$field] ?? null);
        }
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $settings['DB_HOST'], $settings['DB_PORT']);
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
        self::$db = new PDO($dsn . ';dbname=' . self::$databaseName, $settings['DB_USER'], $settings['DB_PASSWORD'], $options);
        DatabaseConnection::setUtc(self::$db);
        self::$admin = new PDO($dsn, $settings['DB_USER'], $settings['DB_PASSWORD'], $options);
        $codes = [];
        for ($index = 0; $index < 2; $index++) {
            $result = file_get_contents($directory . '/' . $index);
            self::assertIsString($result);
            $response = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($response);
            $codes[] = $response[0];
            unlink($directory . '/' . $index);
        }
        unlink($directory . '/start');
        rmdir($directory);
        sort($codes);
        self::assertSame([200, 409], $codes);
    }

    /** @param array<string,mixed>|null $body
     *  @return array{0:int,1:array<string,mixed>} */
    private function request(
        ApiKernel $api,
        string $method,
        string $route,
        ?array $body,
        ?string $token = null,
        ?string $key = null
    ): array {
        $headers = ['Authorization' => 'Bearer ' . ($token ?? self::$token)];
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $headers['Idempotency-Key'] = $key ?? sprintf('123e4567-e89b-42d3-a456-%012d', random_int(1, 999999999999));
        }
        return $api->handle(
            $method,
            '/api/v1' . $route,
            $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
            $headers,
            '127.0.9.10'
        );
    }

    /** @param array<string,mixed>|null $body
     *  @return array<string,mixed> */
    private function contract(
        ApiKernel $api,
        ApiResponseSchema $schema,
        string $method,
        string $route,
        ?array $body,
        ?string $path = null
    ): array {
        [$status, $response] = $this->request($api, $method, $route, $body);
        self::assertTrue($status >= 200 && $status < 300, json_encode($response, JSON_THROW_ON_ERROR));
        $schema->assertResponse($path ?? $route, $method, $status, $response);
        return $response;
    }

    private static function currentSunday(): string
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Stockholm'));
        return $today->modify('-' . $today->format('w') . ' days')->format('Y-m-d');
    }
}
