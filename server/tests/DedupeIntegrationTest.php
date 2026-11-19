<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Database\MigrationRunner;
use Matsvep\Ingest\Dedupe\DedupeReport;
use Matsvep\Ingest\Dedupe\DishGrouper;
use Matsvep\Ingest\Dedupe\RecipeDeduplicator;
use Matsvep\Ingest\Ingredient\IngredientMatcher;
use Matsvep\Ingest\Ingredient\IngredientSeeder;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DedupeIntegrationTest extends TestCase
{
    public function testMergeReviewGroupingAndRerun(): void
    {
        $envFile = __DIR__ . '/../.env.testing';
        if (!is_file($envFile)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing for deduplication integration tests.');
            }
            self::markTestSkipped('Copy .env.testing.example to .env.testing to run MySQL integration tests.');
        }
        $config = parse_ini_file($envFile, false, INI_SCANNER_RAW);
        if (!is_array($config)) {
            throw new RuntimeException('Invalid test database configuration.');
        }
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
            if (!is_string($config[$key] ?? null)) {
                throw new RuntimeException('Invalid test database configuration.');
            }
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $config['DB_NAME'])) {
            throw new RuntimeException('Invalid test database prefix.');
        }
        $cases = json_decode((string) file_get_contents(__DIR__ . '/fixtures/dedupe_cases.json'), true);
        self::assertIsArray($cases);
        $name = $config['DB_NAME'] . '_' . bin2hex(random_bytes(6));
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['DB_HOST'], $config['DB_PORT']);
        $admin = new PDO($dsn, $config['DB_USER'], $config['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci");
        try {
            $db = new PDO($dsn . ';dbname=' . $name, $config['DB_USER'], $config['DB_PASSWORD'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false,
            ]);
            (new MigrationRunner($db, __DIR__ . '/../migrations', $name))->run();
            (new IngredientSeeder($db))->seed(__DIR__ . '/../seeds/ingredients.csv');
            $matcher = new IngredientMatcher($db);
            $source = $db->prepare("SELECT id FROM source WHERE adapter = 'koket'");
            $source->execute();
            $sourceId = (int) $source->fetchColumn();
            $recipeInsert = $db->prepare(
                'INSERT INTO recipe (source_id, url, title, main_protein, total_time_min, servings) ' .
                'VALUES (?, ?, ?, ?, 45, 4)'
            );
            $lineInsert = $db->prepare(
                'INSERT INTO recipe_ingredient (recipe_id, position, raw_text, ingredient_id, is_main) ' .
                'VALUES (?, ?, ?, ?, TRUE)'
            );
            $partInsert = $db->prepare(
                'INSERT INTO recipe_ingredient_part (recipe_id, position, part_position, ingredient_text, ingredient_id) ' .
                'VALUES (?, ?, 1, ?, ?)'
            );
            $stepInsert = $db->prepare('INSERT INTO recipe_step (recipe_id, position, text) VALUES (?, ?, ?)');
            $linkInsert = $db->prepare(
                'INSERT INTO recipe_source_link (recipe_id, source_id, url, url_hash, is_primary) ' .
                'VALUES (?, ?, ?, ?, TRUE)'
            );
            $ids = [];
            foreach ($cases as $key => $case) {
                self::assertIsString($key);
                self::assertIsArray($case);
                $recipeInsert->execute([$sourceId, $case['url'], $case['title'], $case['protein']]);
                $id = (int) $db->lastInsertId();
                $ids[$key] = $id;
                self::assertIsArray($case['ingredients']);
                foreach ($case['ingredients'] as $index => $ingredient) {
                    self::assertIsInt($index);
                    self::assertIsString($ingredient);
                    $ingredientId = $matcher->match($ingredient);
                    self::assertNotNull($ingredientId);
                    $lineInsert->execute([$id, $index + 1, $ingredient, $ingredientId]);
                    $partInsert->execute([$id, $index + 1, $ingredient, $ingredientId]);
                }
                self::assertIsInt($case['steps']);
                for ($position = 1; $position <= $case['steps']; $position++) {
                    $stepInsert->execute([$id, $position, 'Prepare recipe.']);
                }
                $link = $case['source_link'] ?? $case['url'];
                self::assertIsString($link);
                $linkInsert->execute([$id, $sourceId, $link, hash('sha256', $link, true)]);
            }
            $deduper = new RecipeDeduplicator($db);
            self::assertSame(['merged' => 2, 'queued_for_review' => 1], $deduper->run());
            $grouper = new DishGrouper($db, __DIR__ . '/../seeds');
            self::assertGreaterThan(0, $grouper->run());
            $report = (new DedupeReport($db))->build();
            self::assertSame(2, $report['merged_copies']);
            self::assertSame(1, $report['pending_duplicate_reviews']);
            self::assertSame(2, $report['dish_groups']);
            $inactive = $db->prepare('SELECT active, merged_into_id, dish_group_id FROM recipe WHERE id = ?');
            $inactive->execute([$ids['copy_removed']]);
            $removed = $inactive->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($removed);
            self::assertIsNumeric($removed['active']);
            self::assertIsNumeric($removed['merged_into_id']);
            self::assertSame(0, (int) $removed['active']);
            self::assertSame($ids['copy_kept'], (int) $removed['merged_into_id']);
            self::assertNull($removed['dish_group_id']);
            $links = $db->prepare('SELECT COUNT(*) FROM recipe_source_link WHERE recipe_id = ?');
            $links->execute([$ids['copy_kept']]);
            self::assertSame(2, (int) $links->fetchColumn());
            $groupMembers = $db->prepare('SELECT COUNT(*) FROM recipe WHERE dish_group_id = ? AND active = TRUE');
            $groupId = $db->prepare('SELECT dish_group_id FROM recipe WHERE id = ?');
            $groupId->execute([$ids['copy_kept']]);
            $groupMembers->execute([$groupId->fetchColumn()]);
            self::assertSame(2, (int) $groupMembers->fetchColumn());
            self::assertSame(['merged' => 0, 'queued_for_review' => 0], $deduper->run());
            self::assertSame(0, $grouper->run());
            self::assertSame($report, (new DedupeReport($db))->build());
            $manual = $db->prepare('UPDATE dish_group SET manual_override = TRUE WHERE id = ?');
            $groupId->execute([$ids['copy_kept']]);
            $manualGroupId = (int) $groupId->fetchColumn();
            $manual->execute([$manualGroupId]);
            self::assertSame(0, $grouper->run());
            $groupId->execute([$ids['copy_kept']]);
            self::assertSame($manualGroupId, (int) $groupId->fetchColumn());
        } finally {
            $admin->exec("DROP DATABASE IF EXISTS `$name`");
        }
    }
}
