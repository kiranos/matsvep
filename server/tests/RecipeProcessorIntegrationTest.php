<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Database\MigrationRunner;
use Matsvep\Ingest\Processing\RecipeProcessor;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RecipeProcessorIntegrationTest extends TestCase
{
    public function testProcessingLocksCrawlAndPreservesManualCorrectionsOnRerun(): void
    {
        $env = __DIR__ . '/../.env.testing';
        if (!is_file($env)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing for processing integration tests.');
            }
            self::markTestSkipped('Copy .env.testing.example to .env.testing to run MySQL integration tests.');
        }
        $config = parse_ini_file($env, false, INI_SCANNER_RAW);
        if (!is_array($config)) {
            throw new RuntimeException('Invalid test database configuration.');
        }
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
            if (!is_string($config[$key] ?? null)) {
                throw new RuntimeException('Invalid test database configuration.');
            }
        }
        if (preg_match('/^[A-Za-z0-9_]+$/', $config['DB_NAME']) !== 1) {
            throw new RuntimeException('Invalid test database prefix.');
        }
        $database = $config['DB_NAME'] . '_' . bin2hex(random_bytes(6));
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['DB_HOST'], $config['DB_PORT']);
        $admin = new PDO($dsn, $config['DB_USER'], $config['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci");
        $reports = sys_get_temp_dir() . '/matsvep-process-' . bin2hex(random_bytes(6));
        try {
            $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
                \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false];
            $db = new PDO($dsn . ';dbname=' . $database, $config['DB_USER'], $config['DB_PASSWORD'], $options);
            $blocker = new PDO($dsn . ';dbname=' . $database, $config['DB_USER'], $config['DB_PASSWORD'], $options);
            (new MigrationRunner($db, __DIR__ . '/../migrations', $database))->run();
            $sourceId = (int) $this->scalar($db, "SELECT id FROM source WHERE adapter = 'koket'");
            $insertRecipe = $db->prepare('INSERT INTO recipe (source_id, url, title, total_time_min, total_time_source) VALUES (?, ?, ?, ?, ?)');
            $insertRecipe->execute([$sourceId, 'https://example.com/process-one', 'Process first dish', 30, 'given']);
            $first = (int) $db->lastInsertId();
            $insertRecipe->execute([$sourceId, 'https://example.com/process-two', 'Process second dish', null, 'unknown']);
            $second = (int) $db->lastInsertId();
            $ingredient = $db->prepare('INSERT INTO recipe_ingredient (recipe_id, position, raw_text) VALUES (?, ?, ?)');
            $ingredient->execute([$first, 1, 'salt']);
            $ingredient->execute([$second, 1, 'mysteryfood']);
            $step = $db->prepare('INSERT INTO recipe_step (recipe_id, position, text) VALUES (?, 1, ?)');
            $step->execute([$first, '30 min']);
            $step->execute([$second, '45 min']);
            $processor = new RecipeProcessor($db, __DIR__ . '/../seeds', $reports);

            self::assertSame(1, (int) $this->scalar($blocker, "SELECT GET_LOCK('matsvep:crawl:koket', 0)"));
            try {
                $processor->run();
                self::fail('Processing should reject an active crawler lock.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('active', $error->getMessage());
            } finally {
                $this->scalar($blocker, "SELECT RELEASE_LOCK('matsvep:crawl:koket')");
            }
            self::assertSame(0, (int) $this->scalar($db, 'SELECT COUNT(*) FROM ingredient'));

            $firstRun = $processor->run();
            $before = $firstRun['time_coverage_before'] ?? null;
            $after = $firstRun['time_coverage_after'] ?? null;
            self::assertIsArray($before);
            self::assertIsArray($after);
            self::assertSame(2, $before['total']);
            self::assertSame(1, $before['given']);
            self::assertSame(1, $after['estimated']);
            self::assertSame(100.0, $after['covered_share_pct']);
            self::assertSame(0, $firstRun['time_estimates_changed']);
            $time = $db->prepare('SELECT total_time_min, total_time_source FROM recipe WHERE id = ?');
            $time->execute([$second]);
            $estimated = $time->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($estimated);
            self::assertIsNumeric($estimated['total_time_min']);
            self::assertSame(45, (int) $estimated['total_time_min']);
            self::assertSame('estimated', $estimated['total_time_source']);

            $user = $db->prepare('INSERT INTO `user` (email, password_hash) VALUES (?, ?)');
            $user->execute(['admin@example.com', password_hash('test-password', PASSWORD_DEFAULT)]);
            $actor = (int) $db->lastInsertId();
            $override = $db->prepare('INSERT INTO recipe_feature_override (recipe_id, main_protein, updated_by) VALUES (?, ?, ?)');
            $override->execute([$first, 'fish', $actor]);
            $db->prepare('INSERT INTO dish_group (core_name, manual_override) VALUES (?, TRUE)')->execute(['manual']);
            $group = (int) $db->lastInsertId();
            $db->prepare('UPDATE recipe SET dish_group_id = ?, dish_group_manual = TRUE WHERE id = ?')->execute([$group, $first]);
            $db->prepare("UPDATE ingredient_unknown SET status = 'ignored' WHERE normalised_token = ?")
                ->execute(['mysteryfood']);
            $db->prepare("INSERT INTO duplicate_candidate (recipe_a, recipe_b, similarity, status) " .
                "VALUES (?, ?, 0.85, 'not_duplicate')")->execute([$first, $second]);

            $db->prepare('UPDATE recipe_step SET text = ? WHERE recipe_id = ?')->execute([
                'Låt vila 1 timme. Stek 45 min.', $second,
            ]);
            $db->prepare("UPDATE recipe SET total_time_min = 60, total_time_source = 'estimated' WHERE id = ?")
                ->execute([$second]);
            $secondRun = $processor->run();
            $secondBefore = $secondRun['time_coverage_before'] ?? null;
            $secondAfter = $secondRun['time_coverage_after'] ?? null;
            self::assertIsArray($secondBefore);
            self::assertIsArray($secondAfter);
            self::assertSame(100.0, $secondBefore['covered_share_pct']);
            self::assertSame(100.0, $secondAfter['covered_share_pct']);
            self::assertSame(1, $secondRun['time_estimates_changed']);
            $time->execute([$second]);
            self::assertSame(45, (int) $time->fetchColumn());
            $saved = $db->prepare('SELECT main_protein, dish_group_id, dish_group_manual FROM recipe WHERE id = ?');
            $saved->execute([$first]);
            $row = $saved->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($row);
            self::assertSame('fish', $row['main_protein']);
            self::assertIsNumeric($row['dish_group_id']);
            self::assertIsNumeric($row['dish_group_manual']);
            self::assertSame($group, (int) $row['dish_group_id']);
            self::assertSame(1, (int) $row['dish_group_manual']);
            self::assertSame('ignored', $this->scalar($db, "SELECT status FROM ingredient_unknown WHERE normalised_token = 'mysteryfood'"));
            self::assertSame('not_duplicate', $this->scalar($db, 'SELECT status FROM duplicate_candidate'));
            foreach (['ingredient-report.json', 'feature-report.json', 'dedupe-report.json', 'processing-report.json'] as $name) {
                self::assertFileExists($reports . '/' . $name);
            }
        } finally {
            $admin->exec("DROP DATABASE IF EXISTS `$database`");
            if (is_dir($reports)) {
                foreach (glob($reports . '/*.json') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($reports);
            }
        }
    }

    private function scalar(PDO $db, string $sql): int|string
    {
        $statement = $db->prepare($sql);
        $statement->execute();
        $value = $statement->fetchColumn();
        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('Invalid scalar test result.');
        }
        return $value;
    }
}
