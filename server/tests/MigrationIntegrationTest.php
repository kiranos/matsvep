<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Database\MigrationRunner;
use Matsvep\Engine\EngineConfig;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigrationIntegrationTest extends TestCase
{
    public function testMigrationsApplySkipAndRejectChangedFile(): void
    {
        $envFile = __DIR__ . '/../.env.testing';
        if (!is_file($envFile)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing so the MySQL integration test runs.');
            }
            self::markTestSkipped('Copy .env.testing.example to .env.testing to run the MySQL integration test.');
        }

        $config = parse_ini_file($envFile, false, INI_SCANNER_RAW);
        if ($config === false) {
            throw new RuntimeException('Cannot parse .env.testing.');
        }
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key])) {
                throw new RuntimeException("Missing or invalid $key in .env.testing.");
            }
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $config['DB_NAME'])) {
            throw new RuntimeException('DB_NAME must contain only letters, digits and underscores.');
        }

        $databaseName = $config['DB_NAME'] . '_' . bin2hex(random_bytes(6));
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['DB_HOST'], $config['DB_PORT']);
        $admin = new PDO($dsn, $config['DB_USER'], $config['DB_PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'matsvep_migrations_' . bin2hex(random_bytes(6));
        if (!mkdir($directory)) {
            throw new RuntimeException('Cannot create temporary migration directory.');
        }

        try {
            $migrationFiles = glob(__DIR__ . '/../migrations/*.sql');
            if ($migrationFiles === false || $migrationFiles === []) {
                throw new RuntimeException('No migration files found.');
            }
            foreach ($migrationFiles as $source) {
                if (!copy($source, $directory . DIRECTORY_SEPARATOR . basename($source))) {
                    throw new RuntimeException('Cannot copy migration file.');
                }
            }

            $admin->exec("CREATE DATABASE `$databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci");
            $db = new PDO($dsn . ';dbname=' . $databaseName, $config['DB_USER'], $config['DB_PASSWORD'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false,
            ]);
            $runner = new MigrationRunner($db, $directory, $databaseName);
            $applied = $runner->run();
            self::assertCount(count($migrationFiles), $applied);
            foreach ($applied as $line) {
                self::assertStringStartsWith('APPLIED ', $line);
            }

            $source = $db->prepare('SELECT enabled, base_url FROM source WHERE adapter = ?');
            $source->execute(['koket']);
            $sourceRow = $source->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($sourceRow);
            $enabled = $sourceRow['enabled'] ?? null;
            if (!is_int($enabled) && !is_string($enabled)) {
                throw new RuntimeException('Invalid source enabled value.');
            }
            self::assertSame(0, (int) $enabled);
            self::assertSame('https://www.koket.se', $sourceRow['base_url']);

            $settings = $db->prepare('SELECT `key`, value FROM engine_config WHERE `key` IN (?, ?, ?, ?)');
            $settings->execute(['crawl.retry_base_s', 'crawl.retry_max_s', 'crawl.max_attempts', 'crawl.timeout_s']);
            $values = $settings->fetchAll(PDO::FETCH_KEY_PAIR);
            ksort($values);
            self::assertSame([
                'crawl.max_attempts' => '5',
                'crawl.retry_base_s' => '60',
                'crawl.retry_max_s' => '3600',
                'crawl.timeout_s' => '30',
            ], $values);

            $engine = EngineConfig::fromDatabase($db);
            self::assertSame(0.45, $engine->decimal('weight.pref'));
            self::assertSame(60, $engine->integer('dayfit.weekday_max_minutes'));
            self::assertSame(0.7, $engine->decimal('dayfit.leftovers.leftover_weight'));
            self::assertSame(0.4, $engine->decimal('dayfit.friday.festive_weight'));
            self::assertSame(0.6, $engine->decimal('dayfit.saturday.festive_weight'));
            self::assertCount(4, $engine->stringList('dayfit.friday_tags'));
            self::assertSame(2, $engine->integer('variety.target_days_left'));

            $db->exec("UPDATE engine_config SET value = '0.51' WHERE `key` = 'weight.pref'");
            $engineSql = file_get_contents($directory . '/017_engine_defaults.sql');
            self::assertIsString($engineSql);
            $db->exec($engineSql);
            self::assertSame(0.51, EngineConfig::fromDatabase($db)->decimal('weight.pref'));

            $defaults = $db->query('SELECT weekday, profile_code FROM day_profile_default ORDER BY weekday');
            self::assertInstanceOf(\PDOStatement::class, $defaults);
            self::assertSame([
                0 => 'weekday_leftovers',
                1 => 'weekday_leftovers',
                2 => 'weekday_leftovers',
                3 => 'weekday_leftovers',
                4 => 'weekday_leftovers',
                5 => 'friday_treat',
                6 => 'saturday_dinner',
            ], $defaults->fetchAll(PDO::FETCH_KEY_PAIR));
            foreach (range(0, 6) as $offset) {
                $day = new \DateTimeImmutable('2026-11-22 +' . $offset . ' days');
                self::assertSame($offset, (int) $day->format('w'));
            }
            $db->exec("INSERT INTO household (name) VALUES ('First'), ('Second')");
            $householdQuery = $db->query('SELECT id, timezone FROM household ORDER BY id');
            self::assertInstanceOf(\PDOStatement::class, $householdQuery);
            $households = $householdQuery->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(2, $households);
            self::assertIsArray($households[0]);
            self::assertIsArray($households[1]);
            self::assertSame('Europe/Stockholm', $households[0]['timezone'] ?? null);
            self::assertIsNumeric($households[0]['id'] ?? null);
            self::assertIsNumeric($households[1]['id'] ?? null);
            $firstHousehold = (int) $households[0]['id'];
            $secondHousehold = (int) $households[1]['id'];
            $roleColumn = $db->query("SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user' AND COLUMN_NAME = 'role'");
            self::assertInstanceOf(\PDOStatement::class, $roleColumn);
            self::assertSame(['IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null], $roleColumn->fetch(PDO::FETCH_ASSOC));
            $db->exec("INSERT INTO `user` (email, password_hash, role) VALUES ('admin@example.com', 'test', 'admin')");
            self::assertInsertRejected(
                $db,
                'INSERT INTO `user` (email, password_hash, role) VALUES (?, ?, ?)',
                ['missing@example.com', 'test', 'member']
            );
            $member = $db->prepare('INSERT INTO `user` (email, password_hash, role, household_id) VALUES (?, ?, ?, ?)');
            $member->execute(['first@example.com', 'test', 'member', $firstHousehold]);
            $firstMember = (int) $db->lastInsertId();
            $member->execute(['second@example.com', 'test', 'member', $secondHousehold]);
            $secondMember = (int) $db->lastInsertId();
            $inviteSql = 'INSERT INTO household_invite (household_id, created_by, code_hash, expires_at) '
                . 'VALUES (?, ?, ?, UTC_TIMESTAMP(6) + INTERVAL 72 HOUR)';
            self::assertInsertRejected($db, $inviteSql, [$firstHousehold, $secondMember, str_repeat('a', 64)]);
            $db->prepare($inviteSql)->execute([$firstHousehold, $firstMember, str_repeat('a', 64)]);
            $planSql = 'INSERT INTO plan (household_id, week_start, part, days, seed, config_version) '
                . 'VALUES (?, ?, 1, ?, ?, 1)';
            self::assertInsertRejected($db, $planSql, [$firstHousehold, '2026-11-23', '["2026-11-23"]', str_repeat('b', 64)]);
            $db->prepare($planSql)->execute([$firstHousehold, '2026-11-22', '["2026-11-22"]', str_repeat('b', 64)]);
            $planId = (int) $db->lastInsertId();
            $slotSql = 'INSERT INTO plan_slot (plan_id, household_id, date, day_profile) VALUES (?, ?, ?, ?)';
            self::assertInsertRejected($db, $slotSql, [$planId, $secondHousehold, '2026-11-22', 'weekday_leftovers']);
            $db->prepare($slotSql)->execute([$planId, $firstHousehold, '2026-11-22', 'weekday_leftovers']);
            $idempotencySql = 'INSERT INTO api_idempotency '
                . '(user_id, endpoint, idempotency_key, request_hash, response_status, response_json, expires_at) '
                . 'VALUES (?, ?, ?, ?, 200, ?, UTC_TIMESTAMP(6) + INTERVAL 48 HOUR)';
            $key = '11111111-1111-4111-8111-111111111111';
            $db->prepare($idempotencySql)->execute([$firstMember, 'POST /recipes/1/rating', $key, str_repeat('c', 64), '{}']);
            self::assertInsertRejected($db, $idempotencySql, [$firstMember, 'POST /recipes/1/rating', $key, str_repeat('c', 64), '{}']);
            $db->prepare($idempotencySql)->execute([$firstMember, 'POST /recipes/2/rating', $key, str_repeat('c', 64), '{}']);
            $db->prepare($idempotencySql)->execute([$secondMember, 'POST /recipes/1/rating', $key, str_repeat('c', 64), '{}']);
            $newSettings = $db->prepare('SELECT value FROM engine_config WHERE `key` = ?');
            $newSettings->execute(['household.invite_ttl_hours']);
            self::assertSame('72', $newSettings->fetchColumn());
            $newSettings->execute(['api.idempotency_ttl_hours']);
            self::assertSame('48', $newSettings->fetchColumn());

            $skipped = $runner->run();
            self::assertCount(count($migrationFiles), $skipped);
            foreach ($skipped as $line) {
                self::assertStringStartsWith('SKIPPED ', $line);
            }

            file_put_contents($directory . '/003_ingestion_and_review.sql', "\n-- changed after applying\n", FILE_APPEND);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Applied migration changed: 003_ingestion_and_review.sql');
            $runner->run();
        } finally {
            $admin->exec("DROP DATABASE IF EXISTS `$databaseName`");
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
    /** @param list<int|string> $values */
    private static function assertInsertRejected(PDO $db, string $sql, array $values): void
    {
        try {
            $db->prepare($sql)->execute($values);
            self::fail('Expected the database constraint to reject the insert.');
        } catch (PDOException $error) {
            self::assertNotSame('', $error->getMessage());
        }
    }

}
