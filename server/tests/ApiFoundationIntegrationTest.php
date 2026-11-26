<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Api\ApiError;
use Matsvep\Api\ApiKernel;
use Matsvep\Api\ApiStatePurger;
use Matsvep\Api\AppAuth;
use Matsvep\Api\DbValue;
use Matsvep\Api\Idempotency;
use Matsvep\Database\MigrationRunner;
use Matsvep\Database\WriteTransaction;
use Matsvep\Engine\EngineConfig;
use Matsvep\Ingest\Ingredient\DatabaseConnection;
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
        $newHousehold = json_encode(['email' => 'second@example.test', 'password' => 'a-long-test-password', 'platform' => 'windows', 'household_name' => 'Second household'], JSON_THROW_ON_ERROR);
        [$closedStatus, $closed] = $api->handle('POST', '/api/v1/auth/register', $newHousehold, [], '127.0.0.1');
        self::assertSame(403, $closedStatus);
        self::assertSame('registration_closed', $closed['code']);
        self::$db->prepare("UPDATE engine_config SET value = '1' WHERE `key` = ?")->execute(['auth.open_registration']);
        $openApi = new ApiKernel(self::$db);
        [$openStatus] = $openApi->handle('POST', '/api/v1/auth/register', $newHousehold, [], '127.0.0.1');
        self::assertSame(201, $openStatus);
        [$duplicateStatus, $duplicate] = $openApi->handle('POST', '/api/v1/auth/register', $newHousehold, [], '127.0.0.1');
        self::assertSame(409, $duplicateStatus);
        self::assertSame('email_taken', $duplicate['code']);
        self::$db->prepare("UPDATE engine_config SET value = '0' WHERE `key` = ?")->execute(['auth.open_registration']);
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
        $revokeBody = json_encode(['refresh_token' => $registered['refresh_token']], JSON_THROW_ON_ERROR);
        $revokeHeaders = $bearer + ['Idempotency-Key' => '123e4567-e89b-42d3-a456-426614174001'];
        [$status, $revoked] = $api->handle('POST', '/api/v1/auth/revoke', $revokeBody, $revokeHeaders, '127.0.0.1');
        self::assertSame(200, $status);
        $schema->assertResponse('/auth/revoke', 'POST', $status, $revoked);
        self::assertSame([$status, $revoked], $api->handle('POST', '/api/v1/auth/revoke', $revokeBody, $revokeHeaders, '127.0.0.1'));
        [$changedStatus, $changed] = $api->handle('POST', '/api/v1/auth/revoke', '{"refresh_token":"different"}', $revokeHeaders, '127.0.0.1');
        self::assertSame(422, $changedStatus);
        self::assertSame('idempotency_key_reused', $changed['code']);
        [$status, $loggedOut] = $api->handle('POST', '/api/v1/auth/logout', '{}', ['Authorization' => 'Bearer ' . DbValue::text($refreshed['access_token'])], '127.0.0.1');
        self::assertSame(200, $status);
        $schema->assertResponse('/auth/logout', 'POST', $status, $loggedOut);
    }

    public function testRefreshGraceAndOlderReuseRevokesTheDevice(): void
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
        [$status, $graceRotation] = $api->handle('POST', '/api/v1/auth/refresh', json_encode(['refresh_token' => $oldRefresh], JSON_THROW_ON_ERROR), [], '127.0.0.1');
        self::assertSame(200, $status);
        self::assertNotSame($rotated['refresh_token'], $graceRotation['refresh_token']);
        [$status, $error] = $api->handle('POST', '/api/v1/auth/refresh', json_encode(['refresh_token' => $oldRefresh], JSON_THROW_ON_ERROR), [], '127.0.0.1');
        self::assertSame(401, $status);
        self::assertSame('refresh_token_invalid', $error['code']);
        [$status] = $api->handle('GET', '/api/v1/recipes/search?q=Synthetic', '', ['Authorization' => 'Bearer ' . DbValue::text($graceRotation['access_token'])], '127.0.0.1');
        self::assertSame(401, $status);
        [$status] = $api->handle('GET', '/admin/', '', ['Authorization' => 'Bearer ' . DbValue::text($tokens['access_token'])], '127.0.0.1');
        self::assertSame(404, $status);
    }

    public function testRefreshGraceRejectsExpiredOrRevokedDevicesAndLateReuse(): void
    {
        $api = new ApiKernel(self::$db);
        $insert = self::$db->prepare("INSERT INTO `user` (email, password_hash, role) VALUES (?, ?, 'admin')");
        $insert->execute(['grace@example.test', password_hash('a-long-test-password', PASSWORD_DEFAULT)]);
        $login = static fn (): array => $api->handle('POST', '/api/v1/auth/token', json_encode(['email' => 'grace@example.test', 'password' => 'a-long-test-password', 'platform' => 'windows'], JSON_THROW_ON_ERROR), [], '127.0.4.1');
        [$status, $tokens] = $login();
        self::assertSame(200, $status);
        $old = DbValue::text($tokens['refresh_token']);
        [$status, $current] = $api->handle('POST', '/api/v1/auth/refresh', json_encode(['refresh_token' => $old], JSON_THROW_ON_ERROR), [], '127.0.4.1');
        self::assertSame(200, $status);
        self::$db->prepare('UPDATE device_token SET revoked_at = UTC_TIMESTAMP(6) WHERE refresh_token_hash = ?')->execute([hash('sha256', DbValue::text($current['refresh_token']))]);
        [$status] = $api->handle('POST', '/api/v1/auth/refresh', json_encode(['refresh_token' => $old], JSON_THROW_ON_ERROR), [], '127.0.4.1');
        self::assertSame(401, $status);

        [$status, $tokens] = $login();
        self::assertSame(200, $status);
        $old = DbValue::text($tokens['refresh_token']);
        [$status, $current] = $api->handle('POST', '/api/v1/auth/refresh', json_encode(['refresh_token' => $old], JSON_THROW_ON_ERROR), [], '127.0.4.1');
        self::assertSame(200, $status);
        self::$db->prepare('UPDATE device_token SET created_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 DAY), expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE refresh_token_hash = ?')->execute([hash('sha256', DbValue::text($current['refresh_token']))]);
        [$status] = $api->handle('POST', '/api/v1/auth/refresh', json_encode(['refresh_token' => $old], JSON_THROW_ON_ERROR), [], '127.0.4.1');
        self::assertSame(401, $status);

        [$status, $tokens] = $login();
        self::assertSame(200, $status);
        $old = DbValue::text($tokens['refresh_token']);
        [$status, $current] = $api->handle('POST', '/api/v1/auth/refresh', json_encode(['refresh_token' => $old], JSON_THROW_ON_ERROR), [], '127.0.4.1');
        self::assertSame(200, $status);
        self::$db->prepare('UPDATE device_refresh_history SET rotated_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 MINUTE) WHERE token_hash = ?')->execute([hash('sha256', $old)]);
        [$status] = $api->handle('POST', '/api/v1/auth/refresh', json_encode(['refresh_token' => $old], JSON_THROW_ON_ERROR), [], '127.0.4.1');
        self::assertSame(401, $status);
        [$status] = $api->handle('GET', '/api/v1/recipes/search?q=Synthetic', '', ['Authorization' => 'Bearer ' . DbValue::text($current['access_token'])], '127.0.4.1');
        self::assertSame(401, $status);
    }

    public function testUtcSessionKeepsRefreshGraceAndLoginWindowConsistent(): void
    {
        self::$db->prepare("SET time_zone = '+02:00'")->execute();
        $timeZone = self::$db->prepare('SELECT @@session.time_zone');
        $timeZone->execute();
        self::assertSame('+02:00', $timeZone->fetchColumn());
        DatabaseConnection::setUtc(self::$db);
        $timeZone->execute();
        self::assertSame('+00:00', $timeZone->fetchColumn());

        $api = new ApiKernel(self::$db);
        self::$db->prepare("INSERT INTO `user` (email, password_hash, role) VALUES (?, ?, 'admin')")
            ->execute(['utc@example.test', password_hash('a-long-test-password', PASSWORD_DEFAULT)]);
        $loginBody = json_encode(['email' => 'utc@example.test', 'password' => 'a-long-test-password', 'platform' => 'windows'], JSON_THROW_ON_ERROR);
        [$status, $tokens] = $api->handle('POST', '/api/v1/auth/token', $loginBody, [], '127.0.7.1');
        self::assertSame(200, $status);
        $refreshBody = json_encode(['refresh_token' => $tokens['refresh_token']], JSON_THROW_ON_ERROR);
        [$status] = $api->handle('POST', '/api/v1/auth/refresh', $refreshBody, [], '127.0.7.1');
        self::assertSame(200, $status);
        [$status] = $api->handle('POST', '/api/v1/auth/refresh', $refreshBody, [], '127.0.7.1');
        self::assertSame(200, $status);

        $config = EngineConfig::fromDatabase(self::$db);
        $emailHash = hash('sha256', 'window@example.test');
        $limit = $config->integer('auth.login_max_attempts');
        $insert = self::$db->prepare('INSERT INTO app_login_attempt (email_hash, ip_address) VALUES (?, ?)');
        for ($attempt = 0; $attempt < $limit; $attempt++) {
            $insert->execute([$emailHash, '127.0.7.' . ($attempt + 2)]);
        }
        $badLogin = json_encode(['email' => 'window@example.test', 'password' => 'incorrect-password', 'platform' => 'windows'], JSON_THROW_ON_ERROR);
        [$status] = $api->handle('POST', '/api/v1/auth/token', $badLogin, [], '127.0.7.100');
        self::assertSame(429, $status);
        self::$db->prepare('UPDATE app_login_attempt SET attempted_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? SECOND) WHERE email_hash = ?')
            ->execute([$config->integer('auth.login_window_s') + 1, $emailHash]);
        [$status] = $api->handle('POST', '/api/v1/auth/token', $badLogin, [], '127.0.7.100');
        self::assertSame(401, $status);
    }

    public function testUnknownAccountHashUsesCurrentPasswordDefaultCost(): void
    {
        $constant = (new \ReflectionClass(AppAuth::class))->getReflectionConstant('UNKNOWN_PASSWORD_HASH');
        self::assertInstanceOf(\ReflectionClassConstant::class, $constant);
        $hash = $constant->getValue();
        self::assertIsString($hash);
        $current = password_hash('current-default-cost', PASSWORD_DEFAULT);
        self::assertSame(password_get_info($current)['algoName'], password_get_info($hash)['algoName']);
        self::assertSame(password_get_info($current)['options']['cost'], password_get_info($hash)['options']['cost']);
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
        for ($attempt = 0; $attempt < 20; $attempt++) {
            [$status] = $badLogin('different' . $attempt . '@example.test', '127.0.2.1');
            self::assertSame(401, $status);
        }
        [$status] = $badLogin('unseen@example.test', '127.0.2.1');
        self::assertSame(429, $status);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            [$status] = $badLogin('creator@example.test', '127.0.3.1');
            self::assertSame(401, $status);
        }
        [$status] = $api->handle('POST', '/api/v1/auth/token', json_encode(['email' => 'creator@example.test', 'password' => 'a-long-test-password', 'platform' => 'windows'], JSON_THROW_ON_ERROR), [], '127.0.3.1');
        self::assertSame(200, $status);
        $count = self::$db->prepare('SELECT COUNT(*) FROM app_login_attempt WHERE email_hash = ?');
        $count->execute([hash('sha256', 'creator@example.test')]);
        self::assertSame(0, DbValue::integer($count->fetchColumn()));
        $count = self::$db->prepare('SELECT COUNT(*) FROM app_login_attempt WHERE ip_address = ?');
        $count->execute(['127.0.3.1']);
        self::assertSame(2, DbValue::integer($count->fetchColumn()));
    }

    public function testWriteTransactionBeginsWhenNeededAndJoinsAnActiveTransaction(): void
    {
        $hash = hash('sha256', 'transaction-test');
        $insert = self::$db->prepare('INSERT INTO app_login_attempt (email_hash, ip_address) VALUES (?, ?)');
        WriteTransaction::run(self::$db, static function () use ($insert, $hash): void {
            $insert->execute([$hash, '127.0.5.1']);
        });
        self::assertFalse(self::$db->inTransaction());
        $count = self::$db->prepare('SELECT COUNT(*) FROM app_login_attempt WHERE email_hash = ?');
        $count->execute([$hash]);
        self::assertSame(1, DbValue::integer($count->fetchColumn()));

        self::$db->beginTransaction();
        WriteTransaction::run(self::$db, static function () use ($insert, $hash): void {
            $insert->execute([$hash, '127.0.5.2']);
        });
        self::assertTrue(self::$db->inTransaction());
        $count->execute([$hash]);
        self::assertSame(2, DbValue::integer($count->fetchColumn()));
        self::$db->rollBack();
        $count->execute([$hash]);
        self::assertSame(1, DbValue::integer($count->fetchColumn()));
    }

    public function testPurgeRemovesOnlyExpiredApiState(): void
    {
        $user = self::$db->prepare("INSERT INTO `user` (email, password_hash, role) VALUES (?, ?, 'admin')");
        $user->execute(['purge@example.test', password_hash('a-long-test-password', PASSWORD_DEFAULT)]);
        $userId = (int) self::$db->lastInsertId();
        $expiredKey = '123e4567-e89b-42d3-a456-426614174009';
        self::$db->prepare('INSERT INTO api_idempotency (user_id, endpoint, idempotency_key, request_hash, response_status, response_json, created_at, expires_at) VALUES (?, ?, ?, ?, 200, ?, DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 3 DAY), DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 DAY))')->execute([$userId, 'POST /purge', $expiredKey, str_repeat('a', 64), '{}']);
        self::$db->prepare('INSERT INTO app_login_attempt (email_hash, ip_address, attempted_at) VALUES (?, ?, DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 DAY))')->execute([hash('sha256', 'purge@example.test'), '127.0.6.1']);
        $device = self::$db->prepare('INSERT INTO device_token (user_id, platform, refresh_token_hash, created_at, expires_at, access_expires_at, revoked_at) VALUES (?, ?, ?, DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 DAY), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 DAY), ?, ?)');
        $device->execute([$userId, 'windows', hash('sha256', 'active-device'), null, null]);
        $activeId = (int) self::$db->lastInsertId();
        self::$db->prepare('INSERT INTO device_refresh_history (token_hash, device_token_id, rotated_at) VALUES (?, ?, DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 40 DAY))')->execute([hash('sha256', 'old-history'), $activeId]);
        $device->execute([$userId, 'windows', hash('sha256', 'revoked-device'), date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() - 3600)]);
        $revokedId = (int) self::$db->lastInsertId();
        self::$db->prepare('INSERT INTO device_token (user_id, platform, refresh_token_hash, created_at, expires_at) VALUES (?, ?, ?, DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 DAY), DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 DAY))')->execute([$userId, 'windows', hash('sha256', 'expired-device')]);
        $expiredId = (int) self::$db->lastInsertId();

        $counts = (new ApiStatePurger(self::$db, EngineConfig::fromDatabase(self::$db)))->purge();
        self::assertGreaterThanOrEqual(1, $counts['api_idempotency']);
        self::assertGreaterThanOrEqual(1, $counts['app_login_attempt']);
        self::assertGreaterThanOrEqual(1, $counts['device_refresh_history']);
        self::assertGreaterThanOrEqual(2, $counts['device_token']);
        $lookup = self::$db->prepare('SELECT COUNT(*) FROM device_token WHERE id = ?');
        foreach ([$revokedId, $expiredId] as $id) {
            $lookup->execute([$id]);
            self::assertSame(0, DbValue::integer($lookup->fetchColumn()));
        }
        $lookup->execute([$activeId]);
        self::assertSame(1, DbValue::integer($lookup->fetchColumn()));

        $rollbackKey = '123e4567-e89b-42d3-a456-426614174010';
        self::$db->prepare('INSERT INTO api_idempotency (user_id, endpoint, idempotency_key, request_hash, response_status, response_json, created_at, expires_at) VALUES (?, ?, ?, ?, 200, ?, DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 3 DAY), DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 DAY))')->execute([$userId, 'POST /rollback', $rollbackKey, str_repeat('b', 64), '{}']);
        self::$db->beginTransaction();
        (new ApiStatePurger(self::$db, EngineConfig::fromDatabase(self::$db)))->purge();
        self::assertTrue(self::$db->inTransaction());
        $lookup = self::$db->prepare('SELECT COUNT(*) FROM api_idempotency WHERE user_id = ? AND idempotency_key = ?');
        $lookup->execute([$userId, $rollbackKey]);
        self::assertSame(0, DbValue::integer($lookup->fetchColumn()));
        self::$db->rollBack();
        $lookup->execute([$userId, $rollbackKey]);
        self::assertSame(1, DbValue::integer($lookup->fetchColumn()));
        (new ApiStatePurger(self::$db, EngineConfig::fromDatabase(self::$db)))->purge();
    }
}
