<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Admin\OnboardingDishService;
use Matsvep\Api\ApiError;
use Matsvep\Api\ApiKernel;
use Matsvep\Api\AppAuth;
use Matsvep\Api\DbValue;
use Matsvep\Api\Idempotency;
use Matsvep\Database\MigrationRunner;
use Matsvep\Ingest\Ingredient\DatabaseConnection;
use Matsvep\Engine\EngineConfig;
use Matsvep\Engine\HouseholdManager;
use Matsvep\Engine\HouseholdRecipeManager;
use Matsvep\Engine\Learner;
use Matsvep\Tests\Support\ApiResponseSchema;
use PDO;
use PHPUnit\Framework\TestCase;

final class ApiHouseholdIntegrationTest extends TestCase
{
    private static PDO $db;
    private static PDO $admin;
    private static string $databaseName;
    private static int $firstHousehold;
    private static int $secondHousehold;
    private static int $firstRecipe;
    private static int $secondRecipe;
    private static int $inactiveRecipe;
    private static string $firstToken;
    private static string $otherMemberToken;
    private static string $secondToken;
    private static string $adminToken;

    public static function setUpBeforeClass(): void
    {
        $file = __DIR__ . '/../.env.testing';
        if (!is_file($file)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing for API integration tests.');
            }
            self::markTestSkipped('Configure .env.testing for API integration tests.');
        }
        $settings = parse_ini_file($file, false, INI_SCANNER_RAW);
        self::assertIsArray($settings);
        /** @var array<string,string> $config */
        $config = [];
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
            $value = $settings[$key] ?? null;
            if (!is_string($value)) {
                throw new \RuntimeException('Invalid testing database configuration.');
            }
            $config[$key] = $value;
        }
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $config['DB_NAME']);
        self::$databaseName = $config['DB_NAME'] . '_' . bin2hex(random_bytes(6));
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['DB_HOST'], $config['DB_PORT']);
        self::$admin = new PDO($dsn, $config['DB_USER'], $config['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::$admin->exec('CREATE DATABASE `' . self::$databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci');
        self::$db = new PDO($dsn . ';dbname=' . self::$databaseName, $config['DB_USER'], $config['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false]);
        DatabaseConnection::setUtc(self::$db);
        (new MigrationRunner(self::$db, __DIR__ . '/../migrations', self::$databaseName))->run();
        $source = self::$db->prepare('INSERT INTO source (name, base_url, adapter, enabled) VALUES (?, ?, ?, TRUE)');
        $source->execute(['Synthetic source', 'https://example.test', 'fixture']);
        $sourceId = (int) self::$db->lastInsertId();
        $recipe = self::$db->prepare('INSERT INTO recipe (source_id, url, title, active, is_dinner_candidate, cuisine, main_protein) VALUES (?, ?, ?, ?, TRUE, ?, ?)');
        $recipe->execute([$sourceId, 'https://example.test/supper-one', 'Synthetic supper one', 1, 'italian', 'chicken']);
        self::$firstRecipe = (int) self::$db->lastInsertId();
        $recipe->execute([$sourceId, 'https://example.test/supper-two', 'Synthetic supper two', 1, 'italian', 'vegetarian']);
        self::$secondRecipe = (int) self::$db->lastInsertId();
        $recipe->execute([$sourceId, 'https://example.test/supper-old', 'Synthetic supper old', 0, 'italian', 'chicken']);
        self::$inactiveRecipe = (int) self::$db->lastInsertId();
        $household = self::$db->prepare('INSERT INTO household (name) VALUES (?)');
        $household->execute(['First test household']);
        self::$firstHousehold = (int) self::$db->lastInsertId();
        $household->execute(['Second test household']);
        self::$secondHousehold = (int) self::$db->lastInsertId();
        $user = self::$db->prepare('INSERT INTO `user` (email, password_hash, role, household_id) VALUES (?, ?, ?, ?)');
        $passwordHash = password_hash('synthetic-password-long', PASSWORD_DEFAULT);
        $user->execute(['first@example.test', $passwordHash, 'member', self::$firstHousehold]);
        $user->execute(['other@example.test', $passwordHash, 'member', self::$firstHousehold]);
        $user->execute(['second@example.test', $passwordHash, 'member', self::$secondHousehold]);
        $user->execute(['admin-household@example.test', $passwordHash, 'admin', null]);
        $auth = new AppAuth(self::$db, EngineConfig::fromDatabase(self::$db));
        self::$firstToken = DbValue::text($auth->login(['email' => 'first@example.test', 'password' => 'synthetic-password-long', 'platform' => 'windows'], '127.0.8.1')['access_token']);
        self::$otherMemberToken = DbValue::text($auth->login(['email' => 'other@example.test', 'password' => 'synthetic-password-long', 'platform' => 'windows'], '127.0.8.2')['access_token']);
        self::$secondToken = DbValue::text($auth->login(['email' => 'second@example.test', 'password' => 'synthetic-password-long', 'platform' => 'windows'], '127.0.8.3')['access_token']);
        self::$adminToken = DbValue::text($auth->login(['email' => 'admin-household@example.test', 'password' => 'synthetic-password-long', 'platform' => 'windows'], '127.0.8.4')['access_token']);
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

    public function testEveryHouseholdEndpointMatchesTheContract(): void
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
        $this->assertContract($api, $schema, 'GET', '/household', null);
        $this->assertContract($api, $schema, 'PATCH', '/household', ['name' => 'Updated test household']);
        $this->assertContract($api, $schema, 'POST', '/household/invites', []);
        $this->assertContract($api, $schema, 'GET', '/household/settings', null);
        $this->assertContract($api, $schema, 'PATCH', '/household/settings', ['plan_days' => 'sunday_thursday']);
        $this->assertContract($api, $schema, 'GET', '/household/day-profiles', null);
        $profiles = ['items' => []];
        for ($weekday = 0; $weekday < 7; $weekday++) {
            $profiles['items'][] = ['weekday' => $weekday, 'profile_code' => 'weekday_leftovers'];
        }
        $this->assertContract($api, $schema, 'PUT', '/household/day-profiles', $profiles);
        $this->assertContract($api, $schema, 'PATCH', '/devices/current', ['push_token' => 'synthetic-push-token']);
        $this->assertContract($api, $schema, 'GET', '/onboarding/questions', null);
        $this->assertContract($api, $schema, 'GET', '/onboarding/answers', null);
        $this->assertContract(
            $api,
            $schema,
            'PUT',
            '/onboarding/answers/dish_rating',
            ['answer' => ['ratings' => [['recipe_id' => self::$firstRecipe, 'choice' => 'favourite']]]],
            '/onboarding/answers/{question_id}'
        );
        $this->assertContract($api, $schema, 'POST', '/onboarding/complete', null);
        $this->assertContract($api, $schema, 'PUT', '/recipes/' . self::$firstRecipe . '/rating', ['rating' => 4], '/recipes/{id}/rating');
        $this->assertContract($api, $schema, 'PUT', '/recipes/' . self::$firstRecipe . '/wishlist', ['wishlist' => true], '/recipes/{id}/wishlist');
        $this->assertContract($api, $schema, 'PUT', '/recipes/' . self::$firstRecipe . '/library-status', ['status' => 'library'], '/recipes/{id}/library-status');
        $this->assertContract($api, $schema, 'GET', '/wishlist', null);
        $this->assertContract($api, $schema, 'GET', '/recipes/search?q=Synthetic', null, '/recipes/search');
    }

    public function testMembersShareStateWhileHouseholdsRemainIsolated(): void
    {
        $api = new ApiKernel(self::$db);
        $firstHousehold = $this->request($api, 'GET', '/household', null, self::$firstToken);
        $secondHousehold = $this->request($api, 'GET', '/household', null, self::$secondToken);
        self::assertSame(self::$firstHousehold, $firstHousehold[1]['id']);
        self::assertSame(self::$secondHousehold, $secondHousehold[1]['id']);
        $first = $this->request($api, 'PUT', '/recipes/' . self::$firstRecipe . '/wishlist', ['wishlist' => true], self::$firstToken);
        self::assertSame(200, $first[0]);
        $rating = $this->request($api, 'PUT', '/recipes/' . self::$firstRecipe . '/rating', ['rating' => 5], self::$otherMemberToken);
        self::assertSame(200, $rating[0]);
        $search = $this->request($api, 'GET', '/recipes/search?q=Synthetic', null, self::$otherMemberToken);
        self::assertSame(200, $search[0]);
        $firstItem = DbValue::rows($search[1]['items'])[0];
        self::assertTrue($firstItem['wishlist']);
        self::assertSame(5, $firstItem['rating']);
        $other = $this->request($api, 'GET', '/recipes/search?q=Synthetic', null, self::$secondToken);
        self::assertSame(200, $other[0]);
        self::assertFalse(DbValue::rows($other[1]['items'])[0]['wishlist']);
        self::assertNull(DbValue::rows($other[1]['items'])[0]['rating']);
        $otherWishlist = $this->request($api, 'GET', '/wishlist', null, self::$secondToken);
        self::assertSame([], $otherWishlist[1]['items']);
        $firstWishlist = $this->request($api, 'GET', '/wishlist', null, self::$firstToken);
        self::assertCount(1, DbValue::rows($firstWishlist[1]['items']));

        $blocked = $this->request($api, 'PUT', '/recipes/' . self::$firstRecipe . '/library-status', ['status' => 'blocked'], self::$firstToken);
        self::assertSame(200, $blocked[0]);
        $firstSearch = $this->request($api, 'GET', '/recipes/search?q=Synthetic', null, self::$firstToken);
        self::assertCount(1, DbValue::rows($firstSearch[1]['items']));
        $secondSearch = $this->request($api, 'GET', '/recipes/search?q=Synthetic', null, self::$secondToken);
        self::assertCount(2, DbValue::rows($secondSearch[1]['items']));
        self::assertNotContains(self::$inactiveRecipe, array_column(DbValue::rows($secondSearch[1]['items']), 'id'));
        $firstWishlist = $this->request($api, 'GET', '/wishlist', null, self::$firstToken);
        self::assertSame([], $firstWishlist[1]['items']);
    }

    public function testMemberWritesRequireIdempotencyKeyAndAdminNeedsHousehold(): void
    {
        $api = new ApiKernel(self::$db);
        [$status, $error] = $this->request($api, 'PATCH', '/household/settings', ['block_vegetarian' => true], self::$firstToken, false);
        self::assertSame(400, $status);
        self::assertSame('invalid_request', $error['code']);
        [$status, $error] = $this->request($api, 'GET', '/household', null, self::$adminToken);
        self::assertSame(403, $status);
        self::assertSame('household_required', $error['code']);
        [$status, $error] = $this->request($api, 'GET', '/recipes/search?q=Synthetic', null, self::$adminToken);
        self::assertSame(403, $status);
        self::assertSame('household_required', $error['code']);
    }

    public function testAnotherHouseholdsDeviceIsNotFound(): void
    {
        $config = EngineConfig::fromDatabase(self::$db);
        $auth = new AppAuth(self::$db, $config);
        $firstUser = $auth->authenticate(self::$firstToken)['user_id'];
        $secondDevice = $auth->authenticate(self::$secondToken)['device_id'];
        $manager = new HouseholdManager(self::$db, $config);
        try {
            $manager->updateDevice(self::$firstHousehold, $firstUser, $secondDevice, ['push_token' => 'synthetic-token']);
            self::fail('A member must not update another household\'s device.');
        } catch (ApiError $error) {
            self::assertSame(404, $error->status);
        }
    }

    public function testAnyMemberCanInviteAndCodeIsSingleUse(): void
    {
        self::$db->rollBack();
        $api = new ApiKernel(self::$db);
        [$status, $invite] = $this->request($api, 'POST', '/household/invites', [], self::$otherMemberToken);
        self::assertSame(201, $status);
        $code = DbValue::text($invite['invite_code']);
        $lookup = self::$db->prepare('SELECT code_hash FROM household_invite WHERE household_id = ? ORDER BY id DESC LIMIT 1');
        $lookup->execute([self::$firstHousehold]);
        self::assertSame(hash('sha256', $code), $lookup->fetchColumn());
        $body = json_encode(['email' => 'joined@example.test', 'password' => 'synthetic-password-long',
            'platform' => 'windows', 'invite_code' => $code], JSON_THROW_ON_ERROR);
        [$status, $joined] = $api->handle('POST', '/api/v1/auth/register', $body, [], '127.0.8.20');
        self::assertSame(201, $status);
        self::assertSame(self::$firstHousehold, DbValue::map($joined['user'])['household_id']);
        $otherBody = json_encode(['email' => 'second-join@example.test', 'password' => 'synthetic-password-long',
            'platform' => 'windows', 'invite_code' => $code], JSON_THROW_ON_ERROR);
        [$status, $error] = $api->handle('POST', '/api/v1/auth/register', $otherBody, [], '127.0.8.20');
        self::assertSame(422, $status);
        self::assertSame('invite_used', $error['code']);
    }

    public function testExpiredInviteCannotRegister(): void
    {
        self::$db->rollBack();
        $auth = new AppAuth(self::$db, EngineConfig::fromDatabase(self::$db));
        $userId = $auth->authenticate(self::$firstToken)['user_id'];
        $code = 'synthetic-expired-invite';
        $insert = self::$db->prepare('INSERT INTO household_invite (household_id, created_by, code_hash, created_at, expires_at) VALUES (?, ?, ?, DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 DAY), DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 DAY))');
        $insert->execute([self::$firstHousehold, $userId, hash('sha256', $code)]);
        $body = json_encode(['email' => 'expired@example.test', 'password' => 'synthetic-password-long',
            'platform' => 'windows', 'invite_code' => $code], JSON_THROW_ON_ERROR);
        [$status, $error] = (new ApiKernel(self::$db))->handle('POST', '/api/v1/auth/register', $body, [], '127.0.8.21');
        self::assertSame(422, $status);
        self::assertSame('invite_expired', $error['code']);
    }

    public function testSearchAndWishlistUseHouseholdScopedCursors(): void
    {
        $setting = self::$db->prepare("UPDATE engine_config SET value = '1' WHERE `key` = 'api.search_page_size'");
        $setting->execute();
        $api = new ApiKernel(self::$db);
        [$status, $firstPage] = $this->request($api, 'GET', '/recipes/search?q=Synthetic', null, self::$firstToken);
        self::assertSame(200, $status);
        self::assertCount(1, DbValue::rows($firstPage['items']));
        self::assertIsString($firstPage['next_cursor']);
        [$status, $secondPage] = $this->request($api, 'GET', '/recipes/search?q=Synthetic&cursor=' . $firstPage['next_cursor'], null, self::$firstToken);
        self::assertSame(200, $status);
        self::assertCount(1, DbValue::rows($secondPage['items']));
        self::assertNull($secondPage['next_cursor']);
        $this->request($api, 'PUT', '/recipes/' . self::$firstRecipe . '/wishlist', ['wishlist' => true], self::$firstToken);
        $this->request($api, 'PUT', '/recipes/' . self::$secondRecipe . '/wishlist', ['wishlist' => true], self::$firstToken);
        [$status, $wishlist] = $this->request($api, 'GET', '/wishlist', null, self::$firstToken);
        self::assertSame(200, $status);
        self::assertCount(1, DbValue::rows($wishlist['items']));
        self::assertIsString($wishlist['next_cursor']);
        [$status, $otherWishlist] = $this->request($api, 'GET', '/wishlist', null, self::$secondToken);
        self::assertSame(200, $status);
        self::assertSame([], $otherWishlist['items']);
    }

    public function testHouseholdStateVersionAdvancesOnSettingsRatingAndBlock(): void
    {
        $api = new ApiKernel(self::$db);
        $version = self::$db->prepare('SELECT state_version FROM household WHERE id = ?');
        $version->execute([self::$firstHousehold]);
        $before = DbValue::integer($version->fetchColumn());
        $this->request($api, 'PATCH', '/household/settings', ['block_vegetarian' => true], self::$firstToken);
        $this->request($api, 'PUT', '/recipes/' . self::$secondRecipe . '/rating', ['rating' => 4], self::$firstToken);
        $this->request($api, 'PUT', '/recipes/' . self::$secondRecipe . '/library-status', ['status' => 'blocked'], self::$firstToken);
        $version->execute([self::$firstHousehold]);
        self::assertGreaterThan($before, DbValue::integer($version->fetchColumn()));
        $version->execute([self::$secondHousehold]);
        self::assertSame(1, DbValue::integer($version->fetchColumn()));
    }

    public function testRatingThroughIdempotencyCommitsOnlyOnce(): void
    {
        self::$db->rollBack();
        $household = self::$db->prepare('INSERT INTO household (name) VALUES (?)');
        $household->execute(['Idempotency test household']);
        $householdId = (int) self::$db->lastInsertId();
        $user = self::$db->prepare("INSERT INTO `user` (email, password_hash, role, household_id) VALUES (?, ?, 'member', ?)");
        $user->execute(['idempotency@example.test', password_hash('synthetic-password-long', PASSWORD_DEFAULT), $householdId]);
        $userId = (int) self::$db->lastInsertId();
        $config = EngineConfig::fromDatabase(self::$db);
        $manager = new HouseholdRecipeManager(self::$db, new Learner(self::$db, $config), $config);
        $idempotency = new Idempotency(self::$db, $config);
        $key = '123e4567-e89b-42d3-a456-426614174099';
        $action = static fn (): array => [200, $manager->rate($householdId, self::$firstRecipe, 5)];
        $first = $idempotency->execute($userId, 'PUT /recipes/' . self::$firstRecipe . '/rating', $key, '{"rating":5}', $action);
        self::assertFalse(self::$db->inTransaction());
        self::assertEquals($first, $idempotency->execute($userId, 'PUT /recipes/' . self::$firstRecipe . '/rating', $key, '{"rating":5}', $action));
        $history = self::$db->prepare('SELECT COUNT(*) FROM rating_history WHERE household_id = ? AND recipe_id = ?');
        $history->execute([$householdId, self::$firstRecipe]);
        self::assertSame(1, DbValue::integer($history->fetchColumn()));
    }

    public function testPlanDaysCodesMatchConfigContractAndDesign(): void
    {
        $config = EngineConfig::fromDatabase(self::$db);
        self::assertSame(7, $config->integer('builder.plan_days.all_seven'));
        self::assertSame(5, $config->integer('builder.plan_days.sunday_thursday'));
        $contract = file_get_contents(__DIR__ . '/../../api/openapi.yaml');
        $design = file_get_contents(__DIR__ . '/../../docs/design.md');
        self::assertIsString($contract);
        self::assertIsString($design);
        foreach (['all_seven', 'sunday_thursday'] as $code) {
            self::assertStringContainsString($code, $contract);
            self::assertStringContainsString('builder.plan_days.' . $code, $design);
        }
    }

    public function testCuratedOnboardingDishesAreOrderedAuditedAndSkipIneligibleRecipes(): void
    {
        $api = new ApiKernel(self::$db);
        $service = new OnboardingDishService(self::$db);
        $actorQuery = self::$db->prepare('SELECT id FROM `user` WHERE email = ?');
        $actorQuery->execute(['admin-household@example.test']);
        $actor = DbValue::integer($actorQuery->fetchColumn());
        $fallback = $this->request($api, 'GET', '/onboarding/questions', null, self::$firstToken);

        $service->add($actor, self::$firstRecipe);
        $service->add($actor, self::$secondRecipe);
        $service->move($actor, self::$secondRecipe, 'up');
        $curated = $this->request($api, 'GET', '/onboarding/questions', null, self::$firstToken);
        self::assertSame(200, $curated[0]);
        self::assertSame([
            ['recipe_id' => self::$secondRecipe],
            ['recipe_id' => self::$firstRecipe],
        ], self::dishOptions($curated));
        self::assertSame(2, $service->countStatus()['count']);
        self::assertSame(30, $service->countStatus()['minimum']);
        self::assertSame(40, $service->countStatus()['maximum']);
        self::assertCount(3, $service->search('Synthetic supper'));

        $service->remove($actor, self::$secondRecipe);
        $onlyFirst = $this->request($api, 'GET', '/onboarding/questions', null, self::$firstToken);
        self::assertSame([['recipe_id' => self::$firstRecipe]], self::dishOptions($onlyFirst));
        try {
            $service->add($actor, self::$inactiveRecipe);
            self::fail('An inactive recipe was accepted.');
        } catch (\RuntimeException $expected) {
            self::assertSame('Select an active dinner candidate.', $expected->getMessage());
        }
        $notDinner = self::$db->prepare('UPDATE recipe SET is_dinner_candidate = FALSE WHERE id = ?');
        $notDinner->execute([self::$firstRecipe]);
        try {
            $service->add($actor, self::$firstRecipe);
            self::fail('A non-dinner recipe was accepted.');
        } catch (\RuntimeException $expected) {
            self::assertSame('Select an active dinner candidate.', $expected->getMessage());
        }
        $skipped = $this->request($api, 'GET', '/onboarding/questions', null, self::$firstToken);
        self::assertSame(self::dishOptions($fallback), self::dishOptions($skipped));
        self::assertSame(0, $service->countStatus()['count']);

        $actions = self::$db->prepare("SELECT action FROM admin_audit WHERE target_type = 'onboarding_dish' ORDER BY id");
        $actions->execute();
        self::assertSame([
            'onboarding_dish.add', 'onboarding_dish.add', 'onboarding_dish.move', 'onboarding_dish.remove',
        ], $actions->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string,mixed>|null $body
     *  @return array{0:int,1:array<string,mixed>} */
    private function request(ApiKernel $api, string $method, string $route, ?array $body, string $token, bool $withKey = true): array
    {
        $headers = ['Authorization' => 'Bearer ' . $token];
        if ($withKey && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $headers['Idempotency-Key'] = sprintf('123e4567-e89b-42d3-a456-%012d', random_int(1, 999999999999));
        }
        return $api->handle(
            $method,
            '/api/v1' . $route,
            $body === null ? '' : ($body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR)),
            $headers,
            '127.0.8.10'
        );
    }

    /** @param array{0:int,1:array<string,mixed>} $response
     *  @return list<array<string,mixed>> */
    private static function dishOptions(array $response): array
    {
        $questions = DbValue::rows($response[1]['items']);
        $dish = $questions[0] ?? null;
        if ($dish === null) {
            throw new \RuntimeException('Missing dish question.');
        }
        return DbValue::rows($dish['options']);
    }

    /** @param array<string,mixed>|null $body */
    private function assertContract(ApiKernel $api, ApiResponseSchema $schema, string $method, string $route, ?array $body, ?string $path = null): void
    {
        [$status, $response] = $this->request($api, $method, $route, $body, self::$firstToken);
        self::assertTrue($status >= 200 && $status < 300, $method . ' ' . $route . ': ' . json_encode($response));
        $schema->assertResponse($path ?? $route, $method, $status, $response);
    }
}
