<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Database\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigrationPrivilegeIntegrationTest extends TestCase
{
    public function testLimitedAccountFailsBeforeHistoryWriteAndCanCheckCurrentSchema(): void
    {
        $file = __DIR__ . '/../.env.testing';
        if (!is_file($file)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing for migration privilege tests.');
            }
            self::markTestSkipped('Configure .env.testing for migration privilege tests.');
        }
        $settings = parse_ini_file($file, false, INI_SCANNER_RAW);
        self::assertIsArray($settings);
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
            self::assertIsString($settings[$key] ?? null);
        }
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $settings['DB_NAME']);
        $database = $settings['DB_NAME'] . '_' . bin2hex(random_bytes(6));
        $user = 'matsvep_test_' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(16));
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $settings['DB_HOST'], $settings['DB_PORT']);
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false];
        $admin = new PDO($dsn, $settings['DB_USER'], $settings['DB_PASSWORD'], $options);
        $bootstrapDirectory = sys_get_temp_dir() . '/matsvep_bootstrap_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($bootstrapDirectory));
        self::assertTrue(copy(
            __DIR__ . '/../migrations/000_schema_migrations.sql',
            $bootstrapDirectory . '/000_schema_migrations.sql'
        ));
        $createdDatabase = false;
        $createdUser = false;
        try {
            $admin->prepare("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci")
                ->execute();
            $createdDatabase = true;
            $admin->prepare("CREATE USER '$user'@'%' IDENTIFIED BY '$password'")->execute();
            $createdUser = true;
            $admin->prepare("GRANT SELECT, INSERT, UPDATE, DELETE ON `$database`.* TO '$user'@'%'")->execute();
            $root = new PDO($dsn . ';dbname=' . $database, $settings['DB_USER'], $settings['DB_PASSWORD'], $options);
            $limited = new PDO($dsn . ';dbname=' . $database, $user, $password, $options);
            $env = ['DB_HOST' => $settings['DB_HOST'], 'DB_PORT' => $settings['DB_PORT'],
                'DB_NAME' => $database, 'DB_USER' => $user, 'DB_PASSWORD' => $password];

            [$code, $output, $error] = self::runCommand($env);
            self::assertSame(1, $code);
            self::assertSame('', $output);
            self::assertStringContainsString('CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER', $error);
            self::assertStringContainsString('deploy/setup-ubuntu.sh', $error);
            $history = $root->prepare('SELECT COUNT(*) FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
            $history->execute([$database, 'schema_migrations']);
            self::assertSame(0, (int) $history->fetchColumn());

            (new MigrationRunner($root, $bootstrapDirectory, $database))->run();
            [$code, $output, $error] = self::runCommand($env);
            self::assertSame(1, $code);
            self::assertSame('', $output);
            self::assertStringContainsString('deploy/setup-ubuntu.sh', $error);
            $rows = $root->prepare('SELECT migration, status FROM schema_migrations ORDER BY migration');
            $rows->execute();
            self::assertSame(
                [['migration' => '000_schema_migrations.sql', 'status' => 'applied']],
                $rows->fetchAll(PDO::FETCH_ASSOC)
            );

            (new MigrationRunner($root, __DIR__ . '/../migrations', $database))->run();
            $skipped = (new MigrationRunner($limited, __DIR__ . '/../migrations', $database))->run();
            self::assertCount(count(glob(__DIR__ . '/../migrations/*.sql') ?: []), $skipped);
            [$code, $output, $error] = self::runCommand($env);
            self::assertSame(0, $code);
            self::assertSame('', $error);
            self::assertStringContainsString('Up to date.', $output);
        } finally {
            if ($createdUser) {
                $admin->prepare("DROP USER '$user'@'%'")->execute();
            }
            if ($createdDatabase) {
                $admin->prepare("DROP DATABASE `$database`")->execute();
            }
            unlink($bootstrapDirectory . '/000_schema_migrations.sql');
            rmdir($bootstrapDirectory);
        }
    }

    /** @param array<string,string> $env
     *  @return array{int,string,string} */
    private static function runCommand(array $env): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../bin/migrate.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            $env
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start migration command.');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if (!is_string($output) || !is_string($error)) {
            throw new RuntimeException('Cannot read migration command output.');
        }
        return [$code, $output, $error];
    }
}
