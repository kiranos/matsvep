<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Database\MigrationRunner;
use PDO;
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
}
