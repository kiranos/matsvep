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
                'porridge_exclusion', 'smoothie_exclusion', 'category_dessert',
                'baking_muffin', 'savory_pizza', 'savory_paj', 'savory_broad_title', 'unambiguous_with_meat',
                'egg_only', 'unknown_meat', 'mapped_meat_other_line', 'marulk_dinner',
                'beans_dinner', 'shrimp_title', 'game_title', 'perch_title',
                'meat_with_cheese', 'kebab_bread', 'fish_tacos', 'ham_pie',
                'meat_dumplings', 'cabbage_tacos', 'cheese_pie', 'savory_pancake',
                'mung_bean_fritters', 'stock_only', 'small_anchovy', 'rum_dessert',
                'vegetarian_sausage', 'fish_roe', 'mixed_main_starter', 'mixed_main_dessert',
                'starter_fish', 'buffet_fish', 'side_chicken', 'snack_chicken',
                'brunch_chicken', 'fika_chicken', 'breakfast_chicken', 'between_chicken',
                'occasion_fest', 'occasion_picknick', 'specific_cuisine', 'tag_beats_title',
                'leading_fish_stock', 'leading_shrimp_stock', 'leading_dough',
                'noodle_stock_dinner', 'chicken_stock_dinner', 'pie_stock_dinner', 'pizza_dough_dinner',
                'untagged_potato_side', 'untagged_rice_side', 'untagged_couscous_side',
                'untagged_salad_side', 'untagged_stew', 'sweet_pie', 'sugar_pie',
                'savory_pie_no_sweet', 'accent_dessert', 'uncertain_tofu_protein',
                'pork_joint', 'reindeer_fillet', 'savory_citrus_pie', 'foamy_sauce_meat',
                'savory_sylta', 'untagged_linguine', 'untagged_frittata',
                'untagged_chicken_parm', 'untagged_cabbage_rolls', 'sweet_foam', 'fruit_jam',
                'standalone_aioli', 'standalone_pesto', 'pickled_cucumber', 'lassi_drink',
                'fruit_compote', 'norwegian_omelette', 'potato_gratin_side', 'cauliflower_tabbouleh_side',
                'roast_potatoes_side', 'roast_potatoes_with_fat',
                'greek_chickpea_salad', 'summer_couscous_chickpea_salad',
                'halloumi_potato_gratin', 'feta_bulgur_salad', 'untagged_orange_herring',
                'pancetta_wrapped_fish', 'bacon_fish', 'bacon_pasta_shrimp',
                'celery_stock', 'oyster_sauce_beef',
                'oyster_mushroom_wok', 'actual_oysters', 'hen_stew', 'langoustine',
                'kabanoss_stew', 'hare_saddle', 'hare_back'] as $key) {
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
            $audit = new \Matsvep\Ingest\Feature\DinnerCandidateAudit($db, $seedDirectory);
            $categoryDessertId = $ids['category_dessert'] ?? null;
            self::assertIsInt($categoryDessertId);
            $db->prepare('UPDATE recipe SET is_dinner_candidate = TRUE WHERE id = ?')
                ->execute([$categoryDessertId]);
            $beforeCandidates = $audit->candidates();
            $deriver = new FeatureDeriver($db, $seedDirectory);
            self::assertSame(count($ids), $deriver->run());
            $changes = $audit->build($beforeCandidates, $audit->candidates());
            self::assertSame(1, $changes['removed_count']);
            self::assertIsArray($changes['savory_title_removed']);
            self::assertCount(1, $changes['savory_title_removed']);
            self::assertIsArray($changes['savory_title_removed'][0]);
            self::assertSame($categoryDessertId, $changes['savory_title_removed'][0]['id']);
            $featureQuery = $db->prepare(
                'SELECT main_protein, carb_base, cuisine, meal_type, is_vegetarian, ' .
                'is_dinner_candidate, dinner_exclusion_reason, leftover_score, festive_score, effort '
                . 'FROM recipe WHERE id = ?'
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
                        self::assertSame($case[$expectedKey], $actual[$column], $key . ':' . $column);
                    }
                }
                if ($key === 'stock_only') {
                    self::assertNull($actual['main_protein'], $key . ':main_protein');
                }
                if ($key === 'rum_dessert') {
                    self::assertSame('vegetarian', $actual['main_protein'], $key . ':main_protein');
                }
                self::assertSame($case['dinner_candidate'], (bool) $actual['is_dinner_candidate'], $key . ':dinner_candidate');
                self::assertSame($case['vegetarian'], $actual['is_vegetarian'] === null
                    ? null : (bool) $actual['is_vegetarian'], $key . ':vegetarian');
                if (isset($case['exclusion_reason'])) {
                    self::assertSame($case['exclusion_reason'], $actual['dinner_exclusion_reason'], $key);
                } elseif ($case['dinner_candidate']) {
                    self::assertNull($actual['dinner_exclusion_reason'], $key);
                } else {
                    self::assertNotNull($actual['dinner_exclusion_reason'], $key);
                }
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
            $diagnostics = new \Matsvep\Ingest\Feature\FeatureDiagnosticSample($db);
            $gapSample = $diagnostics->build('protein_gap', 50, 42);
            self::assertSame($gapSample, $diagnostics->build('protein_gap', 50, 42));
            self::assertGreaterThan(0, $gapSample['eligible']);
            self::assertIsArray($gapSample['examples']);
            self::assertIsArray($gapSample['examples'][0]);
            self::assertContains(
                $gapSample['examples'][0]['reason'],
                ['unmatched_ingredient', 'missing_protein_category', 'mixed'],
            );
            $vegetarianSample = $diagnostics->build('vegetarian', 50, 42);
            self::assertSame($vegetarianSample, $diagnostics->build('vegetarian', 50, 42));
            self::assertGreaterThan(0, $vegetarianSample['eligible']);
            $dinnerSample = $diagnostics->build('dinner', 100, 42);
            self::assertSame($dinnerSample, $diagnostics->build('dinner', 100, 42));
            self::assertSame('dinner', $dinnerSample['kind']);
            self::assertSame(42, $dinnerSample['seed']);
            self::assertSame(min(100, $dinnerSample['eligible']), $dinnerSample['returned']);
            self::assertIsArray($dinnerSample['examples']);
            $hasMainTag = false;
            foreach ($dinnerSample['examples'] as $example) {
                self::assertIsArray($example);
                self::assertSame(
                    ['url', 'title', 'main_ingredients', 'meal_type_tags', 'main_protein', 'cuisine', 'is_vegetarian'],
                    array_keys($example),
                );
                self::assertIsArray($example['meal_type_tags']);
                self::assertNotContains('Fest', $example['meal_type_tags']);
                self::assertNotContains('Picknick', $example['meal_type_tags']);
                $hasMainTag = $hasMainTag || in_array('Huvudrätt', $example['meal_type_tags'], true);
            }
            self::assertTrue($hasMainTag);
            $report = (new FeatureReport($db))->build();
            self::assertSame(count($ids), $report['recipes']);
            self::assertGreaterThan(10, $report['main_ingredient_lines']);
            self::assertGreaterThan(8, $report['matched_main_ingredient_lines']);
            self::assertGreaterThan(80.0, $report['matched_main_ingredient_share_pct']);
            self::assertIsArray($report['main_protein_distribution']);
            self::assertSame(count(array_filter(
                $cases,
                static fn (mixed $case): bool => is_array($case) && ($case['protein'] ?? null) === 'fish',
            )), $report['main_protein_distribution']['fish']);
            $tagReport = (new \Matsvep\Ingest\Feature\RecipeTagReport($db))->build();
            self::assertContains('Huvudrätt', array_column($tagReport, 'tag'));
            $mainTag = array_values(array_filter(
                $tagReport,
                static fn (array $row): bool => $row['tag'] === 'Huvudrätt',
            ));
            self::assertCount(1, $mainTag);
            self::assertGreaterThan(0, $mainTag[0]['dinner_candidates']);
            $ingredientReport = (new IngredientReport($db))->build();
            self::assertSame($report['main_ingredient_lines'], $ingredientReport['main_lines']);
            self::assertSame($report['matched_main_ingredient_lines'], $ingredientReport['matched_main_lines']);
            self::assertSame($report['matched_main_ingredient_share_pct'], $ingredientReport['matched_main_share_pct']);
            self::assertIsArray($report['spot_check']);
            self::assertCount(min(20, count($ids)), $report['spot_check']);
            $dinners = $report['dinner_candidates'];
            self::assertIsArray($dinners);
            self::assertSame(count(array_filter(
                $cases,
                static fn (mixed $case): bool => is_array($case) && ($case['dinner_candidate'] ?? false) === true,
            )), $dinners['count']);
            self::assertIsArray($dinners['highest_leftover_score']);
            self::assertIsArray($dinners['lowest_leftover_score']);
            self::assertCount(10, $dinners['highest_leftover_score']);
            self::assertCount(10, $dinners['lowest_leftover_score']);
            self::assertSame(count($ids), $deriver->run());
            self::assertSame($report, (new FeatureReport($db))->build());
            $festId = $ids['occasion_fest'] ?? null;
            self::assertIsInt($festId);
            $setBonus = $db->prepare('UPDATE engine_config SET value = ? WHERE `key` = ?');
            $setBonus->execute(['1', 'feature.fest_tag_bonus']);
            self::assertSame(1, (new FeatureDeriver($db, $seedDirectory))->run($festId));
            $festiveQuery = $db->prepare('SELECT festive_score FROM recipe WHERE id = ?');
            $festiveQuery->execute([$festId]);
            $capped = $festiveQuery->fetchColumn();
            self::assertIsNumeric($capped);
            self::assertSame(1.0, (float) $capped);
            $setBonus->execute(['0.2', 'feature.fest_tag_bonus']);
            self::assertSame(1, $deriver->run($festId));
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
