<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Api\ApiError;
use Matsvep\Api\ApiKernel;
use Matsvep\Api\DbValue;
use Matsvep\Api\Idempotency;
use Matsvep\Database\MigrationRunner;
use Matsvep\Engine\EngineConfig;
use Matsvep\Tests\Support\ApiResponseSchema;
use PDO;
use PHPUnit\Framework\TestCase;

final class ApiFoundationIntegrationTest extends TestCase
{
    private static PDO $db;
    private static PDO $admin;
    private static string $databaseName;

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
        self::$admin = new PDO($dsn, $config['DB_USER'], $config['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        self::$admin->exec('CREATE DATABASE `' . self::$databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci');
        self::$db = new PDO($dsn . ';dbname=' . self::$databaseName, $config['DB_USER'], $config['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false]);
        (new MigrationRunner(self::$db, __DIR__ . '/../migrations', self::$databaseName))->run();
        $source = self::$db->prepare('INSERT INTO source (name, base_url, adapter, enabled) VALUES (?, ?, ?, TRUE)');
        $source->execute(['Synthetic test', 'https://example.test', 'fixture']);
        $recipe = self::$db->prepare('INSERT INTO recipe (source_id, url, title, active) VALUES (?, ?, ?, TRUE)');
        $recipe->execute([(int) self::$db->lastInsertId(), 'https://example.test/recipe', 'Synthetic supper']);
        $ingredient = self::$db->prepare('INSERT INTO recipe_ingredient (recipe_id, position, raw_text, is_main) VALUES (?, 0, ?, TRUE)');
        $ingredient->execute([(int) self::$db->lastInsertId(), 'one synthetic ingredient']);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$admin, self::$databaseName)) {
            self::$admin->exec('DROP DATABASE `' . self::$databaseName . '`');
        }
    }

    public function testEveryFoundationEndpointValidatesAgainstOpenApiResponseSchema(): void
    {
        $bundle = __DIR__ . '/../var/openapi.json';
        if (!is_file($bundle)) {
            if (getenv('CI') !== false) {
                self::fail('CI must bundle api/openapi.yaml before contract tests.');
            }
            self::markTestSkipped('Bundle api/openapi.yaml to server/var/openapi.json before contract tests.');
        }
        $schema = ApiResponseSchema::fromBundle($bundle);
        $api = new ApiKernel(self::$db);
        [$status, $health] = $api->handle('GET', '/api/v1/health', '', [], '127.0.0.1');
        self::assertSame(200, $status);
        $schema->assertResponse('/health', 'GET', $status, $health);
        [$status, $registered] = $api->handle('POST', '/api/v1/auth/register', json_encode(['email' => 'member@example.test', 'password' => 'a-long-test-password', 'platform' => 'windows', 'household_name' => 'Test household'], JSON_THROW_ON_ERROR), [], '127.0.0.1');
        self::assertSame(201, $status);
        $schema->assertResponse('/auth/register', 'POST', $status, $registered);
        $bearer = ['Authorization' => 'Bearer ' . DbValue::text($registered['access_token'])];
        [$status, $recipe] = $api->handle('GET', '/api/v1/recipes/1', '', $bearer, '127.0.0.1');
        self::assertSame(200, $status);
        $schema->assertResponse('/recipes/{id}', 'GET', $status, $recipe);
        [$status, $search] = $api->handle('GET', '/api/v1/recipes/search?q=Synthetic', '', $bearer, '127.0.0.1');
        self::assertSame(200, $status);
        $schema->assertResponse('/recipes/search', 'GET', $status, $search);
        [$status, $login] = $api->handle('POST', '/api/v1/auth/token', json_encode(['email' => 'member@example.test', 'password' => 'a-long-test-password', 'platform' => 'android'], JSON_THROW_ON_ERROR), [], '127.0.0.1');
        self::assertSame(200, $status);
        $schema->assertResponse('/auth/token', 'POST', $status, $login);
        [$status, $refreshed] = $api->handle('POST', '/api/v1/auth/refresh', json_encode(['refresh_token' => $login['refresh_token']], JSON_THROW_ON_ERROR), [], '127.0.0.1');
        self::assertSame(200, $status);
        $schema->assertResponse('/auth/refresh', 'POST', $status, $refreshed);
        [$status, $revoked] = $api->handle('POST', '/api/v1/auth/revoke', json_encode(['refresh_token' => $registered['refresh_token']], JSON_THROW_ON_ERROR), $bearer, '127.0.0.1');
        self::assertSame(200, $status);
        $schema->assertResponse('/auth/revoke', 'POST', $status, $revoked);
        [$status, $loggedOut] = $api->handle('POST', '/api/v1/auth/logout', '{}', ['Authorization' => 'Bearer ' . DbValue::text($refreshed['access_token'])], '127.0.0.1');
        self::assertSame(200, $status);
        $schema->assertResponse('/auth/logout', 'POST', $status, $loggedOut);
    }

    public function testRefreshReuseRevokesTheDeviceAndAdminAppTokenCannotOpenAdminPage(): void
    {
        $api = new ApiKernel(self::$db);
        $insert = self::$db->prepare("INSERT INTO `user` (email, password_hash, role) VALUES (?, ?, 'admin')");
        $insert->execute(['admin@example.test', password_hash('a-long-test-password', PASSWORD_DEFAULT)]);
        [$status, $tokens] = $api->handle('POST', '/api/v1/auth/token', json_encode(['email' => 'admin@example.test', 'password' => 'a-long-test-password', 'platform' => 'windows'], JSON_THROW_ON_ERROR), [], '127.0.0.1');
        self::assertSame(200, $status);
        self::assertSame('admin', DbValue::map($tokens['user'])['role']);
        self::assertNull($tokens['household']);
        $oldRefresh = $tokens['refresh_token'];
        [$status, $rotated] = $api->handle('POST', '/api/v1/auth/refresh', json_encode(['refresh_token' => $oldRefresh], JSON_THROW_ON_ERROR), [], '127.0.0.1');
        self::assertSame(200, $status);
        [$status, $error] = $api->handle('POST', '/api/v1/auth/refresh', json_encode(['refresh_token' => $oldRefresh], JSON_THROW_ON_ERROR), [], '127.0.0.1');
        self::assertSame(401, $status);
        self::assertSame('refresh_token_invalid', $error['code']);
        [$status] = $api->handle('GET', '/api/v1/recipes/search?q=Synthetic', '', ['Authorization' => 'Bearer ' . DbValue::text($rotated['access_token'])], '127.0.0.1');
        self::assertSame(401, $status);
        [$status] = $api->handle('GET', '/admin/', '', ['Authorization' => 'Bearer ' . DbValue::text($tokens['access_token'])], '127.0.0.1');
        self::assertSame(404, $status);
    }

    public function testIdempotencyScopesKeysToEndpointAndChecksBodyBeforeMutation(): void
    {
        $idempotency = new Idempotency(self::$db, EngineConfig::fromDatabase(self::$db));
        $calls = 0;
        $key = '123e4567-e89b-42d3-a456-426614174000';
        $action = static function () use (&$calls): array {
            $calls++;
            return [200, ['event' => ['code' => 'token_revoked', 'params' => []]]];
        };
        $first = $idempotency->execute(1, '/test', $key, '{"value":1}', $action);
        self::assertSame($first, $idempotency->execute(1, '/test', $key, '{"value":1}', $action));
        self::assertSame(1, $calls);
        try {
            $idempotency->execute(1, '/test', $key, '{"value":2}', $action);
            self::fail('Changed request body should be rejected.');
        } catch (ApiError $error) {
            self::assertSame(422, $error->status);
        }
        $idempotency->execute(1, '/other', $key, '{"value":2}', $action);
        self::assertSame(2, $calls);
    }

    public function testInviteIsSingleUseAndFailedLoginLimitsApplyPerEmailAndIp(): void
    {
        $api = new ApiKernel(self::$db);
        $household = self::$db->prepare('INSERT INTO household (name) VALUES (?)');
        $household->execute(['Invite fixture']);
        $householdId = (int) self::$db->lastInsertId();
        $creator = self::$db->prepare("INSERT INTO `user` (email, password_hash, role, household_id) VALUES (?, ?, 'member', ?)");
        $creator->execute(['creator@example.test', password_hash('a-long-test-password', PASSWORD_DEFAULT), $householdId]);
        $creatorId = (int) self::$db->lastInsertId();
        $inviteCode = 'synthetic-invite-code';
        $invite = self::$db->prepare('INSERT INTO household_invite (household_id, created_by, code_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 DAY))');
        $invite->execute([$householdId, $creatorId, hash('sha256', $inviteCode)]);
        $body = json_encode(['email' => 'invitee@example.test', 'password' => 'a-long-test-password', 'platform' => 'windows', 'invite_code' => $inviteCode], JSON_THROW_ON_ERROR);
        [$status, $result] = $api->handle('POST', '/api/v1/auth/register', $body, [], '127.0.0.2');
        self::assertSame(201, $status);
        self::assertSame($householdId, DbValue::map($result['user'])['household_id']);
        $body = json_encode(['email' => 'another@example.test', 'password' => 'a-long-test-password', 'platform' => 'windows', 'invite_code' => $inviteCode], JSON_THROW_ON_ERROR);
        [$status, $result] = $api->handle('POST', '/api/v1/auth/register', $body, [], '127.0.0.2');
        self::assertSame(422, $status);
        self::assertSame('invite_used', $result['code']);

        $badLogin = static fn (string $email, string $ip): array => $api->handle('POST', '/api/v1/auth/token', json_encode(['email' => $email, 'password' => 'incorrect-password', 'platform' => 'windows'], JSON_THROW_ON_ERROR), [], $ip);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            [$status] = $badLogin('unknown@example.test', '127.0.1.' . ($attempt + 1));
            self::assertSame(401, $status);
        }
        [$status, $result] = $badLogin('unknown@example.test', '127.0.1.99');
        self::assertSame(429, $status);
        self::assertSame('rate_limited', $result['code']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            [$status] = $badLogin('different' . $attempt . '@example.test', '127.0.2.1');
            self::assertSame(401, $status);
        }
        [$status] = $badLogin('unseen@example.test', '127.0.2.1');
        self::assertSame(429, $status);
    }
}
