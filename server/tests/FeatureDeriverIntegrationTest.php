<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Database\MigrationRunner;
use Matsvep\Ingest\Feature\FeatureDeriver;
use Matsvep\Ingest\Feature\FeatureReport;
use Matsvep\Ingest\Feature\FeatureSeeder;
use Matsvep\Ingest\Ingredient\IngredientLineParser;
use Matsvep\Ingest\Ingredient\IngredientMatcher;
use Matsvep\Ingest\Ingredient\IngredientNormaliser;
use Matsvep\Ingest\Ingredient\IngredientReport;
use Matsvep\Ingest\Ingredient\IngredientSeeder;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FeatureDeriverIntegrationTest extends TestCase
{
    public function testSeedNormaliseDeriveAndReport(): void
    {
        $envFile = __DIR__ . '/../.env.testing';
        if (!is_file($envFile)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing for feature integration tests.');
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
        $cases = json_decode((string) file_get_contents(__DIR__ . '/fixtures/feature_cases.json'), true);
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
            $seedDirectory = __DIR__ . '/../seeds';
            self::assertGreaterThan(300, (new IngredientSeeder($db))->seed($seedDirectory . '/ingredients.csv'));
            $featureSeeder = new FeatureSeeder($db);
            $keywordCount = $featureSeeder->seed($seedDirectory);
            self::assertSame($keywordCount, $featureSeeder->seed($seedDirectory));
            $matcher = new IngredientMatcher($db);
            $aliases = $cases['alias_checks'] ?? null;
            self::assertIsArray($aliases);
            $nameQuery = $db->prepare('SELECT canonical_name FROM ingredient WHERE id = ?');
            foreach ($aliases as $alias => $canonical) {
                self::assertIsString($alias);
                self::assertIsString($canonical);
                $id = $matcher->match($alias);
                self::assertNotNull($id);
                $nameQuery->execute([$id]);
                self::assertSame($canonical, $nameQuery->fetchColumn());
            }
            $sourceQuery = $db->prepare("SELECT id FROM source WHERE adapter = 'koket'");
            $sourceQuery->execute();
            $sourceId = (int) $sourceQuery->fetchColumn();
            $recipeInsert = $db->prepare(
                'INSERT INTO recipe (source_id, url, title, total_time_min) VALUES (?, ?, ?, ?)'
            );
            $lineInsert = $db->prepare(
                'INSERT INTO recipe_ingredient (recipe_id, position, raw_text) VALUES (?, ?, ?)'
            );
            $tagInsert = $db->prepare('INSERT INTO recipe_tag (recipe_id, tag) VALUES (?, ?)');
            $stepInsert = $db->prepare('INSERT INTO recipe_step (recipe_id, position, text) VALUES (?, ?, ?)');
            $ids = [];
            foreach (['chicken_stew', 'fish_fillet', 'vegetarian_lasagne',
                'uncertain_vegetarian', 'title_protein', 'one_pot_risoni', 'friday_pasta',
                'sill_buffet', 'dessert_exclusion', 'long_cook', 'short_cook', 'short_simmer',
                'title_chicken_chorizo', 'largest_amount', 'tie_first_main',
                'cheese_with_meat', 'halloumi_dinner', 'egg_dinner',
                'breakfast_cereal_side', 'salsa_side', 'bruschetta_side',
                'vegetable_salad_side', 'asparagus_side', 'chicken_salad_main', 'tofu_salad_main', 'salsa_accompaniment', 'dip_accompaniment',
                'porridge_exclusion', 'smoothie_exclusion'] as $key) {
                $case = $cases[$key] ?? null;
                self::assertIsArray($case);
                $recipeInsert->execute([$sourceId, $case['url'], $case['title'], $case['time']]);
                $id = (int) $db->lastInsertId();
                $ids[$key] = $id;
                self::assertIsArray($case['ingredients']);
                foreach ($case['ingredients'] as $index => $raw) {
                    $lineInsert->execute([$id, $index + 1, $raw]);
                }
                self::assertIsArray($case['tags']);
                foreach ($case['tags'] as $tag) {
                    $tagInsert->execute([$id, $tag]);
                }
                self::assertIsArray($case['steps']);
                foreach ($case['steps'] as $index => $step) {
                    $stepInsert->execute([$id, $index + 1, $step]);
                }
            }
            $normaliser = new IngredientNormaliser($db, new IngredientLineParser($seedDirectory));
            $normaliser->run();
            $deriver = new FeatureDeriver($db, $seedDirectory);
            self::assertSame(count($ids), $deriver->run());
            $featureQuery = $db->prepare(
                'SELECT main_protein, carb_base, cuisine, meal_type, is_vegetarian, ' .
                'is_dinner_candidate, leftover_score, festive_score, effort FROM recipe WHERE id = ?'
            );
            foreach ($ids as $key => $id) {
                $case = $cases[$key];
                self::assertIsArray($case);
                $featureQuery->execute([$id]);
                $actual = $featureQuery->fetch(PDO::FETCH_ASSOC);
                self::assertIsArray($actual);
                foreach (['protein' => 'main_protein', 'carb' => 'carb_base',
                    'cuisine' => 'cuisine', 'meal_type' => 'meal_type'] as $expectedKey => $column) {
                    if (isset($case[$expectedKey])) {
                        self::assertSame($case[$expectedKey], $actual[$column]);
                    }
                }
                self::assertSame($case['dinner_candidate'], (bool) $actual['is_dinner_candidate']);
                self::assertSame($case['vegetarian'], $actual['is_vegetarian'] === null
                    ? null : (bool) $actual['is_vegetarian']);
                self::assertIsNumeric($actual['leftover_score']);
                if (isset($case['leftover_score'])) {
                    self::assertIsNumeric($case['leftover_score']);
                    self::assertSame((float) $case['leftover_score'], (float) $actual['leftover_score']);
                }
                self::assertIsNumeric($case['effort']);
                self::assertIsNumeric($actual['effort']);
                self::assertSame((int) $case['effort'], (int) $actual['effort']);
                if (isset($case['festive_score'])) {
                    self::assertIsNumeric($case['festive_score']);
                    self::assertIsNumeric($actual['festive_score']);
                    self::assertSame((float) $case['festive_score'], (float) $actual['festive_score']);
                }
            }
            $ignoredQuery = $db->prepare(
                'SELECT normalise_status, is_main FROM recipe_ingredient WHERE recipe_id = ? AND position = ?'
            );
            if (!isset($ids['fish_fillet'])) {
                self::fail('Missing fish fillet fixture.');
            }
            $ignoredQuery->execute([$ids['fish_fillet'], 3]);
            self::assertSame(
                ['normalise_status' => 'ignored', 'is_main' => 0],
                $ignoredQuery->fetch(PDO::FETCH_ASSOC)
            );
            $report = (new FeatureReport($db))->build();
            self::assertSame(count($ids), $report['recipes']);
            self::assertGreaterThan(10, $report['main_ingredient_lines']);
            self::assertGreaterThan(8, $report['matched_main_ingredient_lines']);
            self::assertGreaterThan(80.0, $report['matched_main_ingredient_share_pct']);
            self::assertIsArray($report['main_protein_distribution']);
            self::assertSame(3, $report['main_protein_distribution']['fish']);
            $ingredientReport = (new IngredientReport($db))->build();
            self::assertSame($report['main_ingredient_lines'], $ingredientReport['main_lines']);
            self::assertSame($report['matched_main_ingredient_lines'], $ingredientReport['matched_main_lines']);
            self::assertSame($report['matched_main_ingredient_share_pct'], $ingredientReport['matched_main_share_pct']);
            self::assertIsArray($report['spot_check']);
            self::assertCount(min(20, count($ids)), $report['spot_check']);
            $dinners = $report['dinner_candidates'];
            self::assertIsArray($dinners);
            self::assertSame(20, $dinners['count']);
            self::assertIsArray($dinners['highest_leftover_score']);
            self::assertIsArray($dinners['lowest_leftover_score']);
            self::assertCount(10, $dinners['highest_leftover_score']);
            self::assertCount(10, $dinners['lowest_leftover_score']);
            self::assertSame(count($ids), $deriver->run());
            self::assertSame($report, (new FeatureReport($db))->build());
            $reportPath = sys_get_temp_dir() . '/matsvep-feature-' . bin2hex(random_bytes(5)) . '.json';
            try {
                (new FeatureReport($db))->save($reportPath);
                $bytes = file_get_contents($reportPath);
                self::assertIsString($bytes);
                self::assertTrue(mb_check_encoding($bytes, 'UTF-8'));
            } finally {
                if (is_file($reportPath)) {
                    unlink($reportPath);
                }
            }
        } finally {
            $admin->exec("DROP DATABASE IF EXISTS `$name`");
        }
    }
}
