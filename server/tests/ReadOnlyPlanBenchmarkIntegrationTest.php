<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Database\MigrationRunner;
use Matsvep\Engine\ReadOnlyPlanBenchmark;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReadOnlyPlanBenchmarkIntegrationTest extends TestCase
{
    public function testRealRecipeReadsCannotWritePlansOrGeneratedRows(): void
    {
        $settings = $this->testDatabaseConfig();
        $database = $settings['DB_NAME'] . '_readonly_' . bin2hex(random_bytes(5));
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $settings['DB_HOST'], $settings['DB_PORT']);
        $root = new PDO($dsn, $settings['DB_USER'], $settings['DB_PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $root->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci");
        try {
            $db = new PDO($dsn . ';dbname=' . $database, $settings['DB_USER'], $settings['DB_PASSWORD'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            (new MigrationRunner($db, __DIR__ . '/../migrations', $database))->run();
            $source = $db->prepare("SELECT id FROM source WHERE adapter = 'koket'");
            $source->execute();
            $sourceId = (int) $source->fetchColumn();
            $insert = $db->prepare('INSERT INTO recipe '
                . '(source_id, url, title, active, is_dinner_candidate, main_protein, effort) '
                . 'VALUES (?, ?, ?, TRUE, TRUE, ?, 2)');
            for ($id = 1; $id <= 20; $id++) {
                $insert->execute([$sourceId, 'https://example.invalid/read-only-' . $id,
                    'Dinner ' . $id, $id % 2 === 0 ? 'fish' : 'chicken']);
            }
            $writeRejected = false;
            $finish = static function (int $started) use ($db, &$writeRejected): array {
                try {
                    $db->prepare('INSERT INTO household (name) VALUES (?)')->execute(['Must fail']);
                } catch (PDOException $error) {
                    $writeRejected = true;
                }
                return ['total_ms' => (hrtime(true) - $started) / 1_000_000];
            };
            $result = (new ReadOnlyPlanBenchmark($db))->run(null, $finish);
            self::assertTrue($writeRejected, 'MySQL must enforce a read-only transaction.');
            self::assertSame('existing_read_only', $result['database']);
            self::assertNull($result['household_id']);
            self::assertSame(7, $result['planning_days']);
            self::assertSame(20, $result['active_dinner_candidates']);
            self::assertSame(20, $result['eligible_dinner_candidates_loaded']);
            self::assertSame(0, $this->count($db, 'household'));
            self::assertSame(0, $this->count($db, 'plan'));
            self::assertSame(0, $this->count($db, 'plan_candidate'));
            self::assertSame(20, $this->count($db, 'recipe'));

            $db->prepare('INSERT INTO household (name) VALUES (?)')->execute(['Existing household']);
            $householdId = (int) $db->lastInsertId();
            $db->prepare('INSERT INTO household_setting (household_id, `key`, value) VALUES (?, ?, ?)')
                ->execute([$householdId, 'plan_days', '"sunday_thursday"']);
            $existing = (new ReadOnlyPlanBenchmark($db))->run($householdId);
            self::assertSame($householdId, $existing['household_id']);
            self::assertSame(5, $existing['planning_days']);
            self::assertSame(20, $existing['active_dinner_candidates']);
            self::assertSame(20, $existing['eligible_dinner_candidates_loaded']);
            self::assertSame(0, $this->count($db, 'plan'));
            self::assertSame(0, $this->count($db, 'plan_slot'));
        } finally {
            $root->exec("DROP DATABASE `$database`");
        }
    }

    /** @return array{DB_HOST: string, DB_PORT: string, DB_NAME: string, DB_USER: string, DB_PASSWORD: string} */
    private function testDatabaseConfig(): array
    {
        $path = __DIR__ . '/../.env.testing';
        if (!is_file($path)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing for the read-only benchmark test.');
            }
            self::markTestSkipped('Copy .env.testing.example to .env.testing to run MySQL tests.');
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
        return $settings;
    }

    private function count(PDO $db, string $table): int
    {
        $query = $db->prepare('SELECT COUNT(*) FROM ' . $table);
        $query->execute();
        return (int) $query->fetchColumn();
    }
}
