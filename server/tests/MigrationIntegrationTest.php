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
            foreach (glob(__DIR__ . '/../migrations/*.sql') ?: [] as $source) {
                if (!copy($source, $directory . DIRECTORY_SEPARATOR . basename($source))) {
                    throw new RuntimeException('Cannot copy migration file.');
                }
            }

            $admin->exec("CREATE DATABASE `$databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci");
            $db = new PDO($dsn . ';dbname=' . $databaseName, $config['DB_USER'], $config['DB_PASSWORD'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]);
            $runner = new MigrationRunner($db, $directory, $databaseName);
            $applied = $runner->run();
            self::assertCount(4, $applied);
            foreach ($applied as $line) {
                self::assertStringStartsWith('APPLIED ', $line);
            }

            $skipped = $runner->run();
            self::assertCount(4, $skipped);
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
