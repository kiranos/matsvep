<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Admin\AdminAuth;
use Matsvep\Admin\AdminService;
use Matsvep\Database\MigrationRunner;
use Matsvep\Ingest\Dedupe\DishGrouper;
use Matsvep\Ingest\Feature\FeatureDeriver;
use Matsvep\Ingest\Feature\FeatureSeeder;
use Matsvep\Ingest\Ingredient\IngredientLineParser;
use Matsvep\Ingest\Ingredient\IngredientNormaliser;
use Matsvep\Ingest\Ingredient\IngredientSeeder;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AdminIntegrationTest extends TestCase
{
    public function testAdminCleanupAndAudit(): void
    {
        $env = __DIR__ . '/../.env.testing';
        if (!is_file($env)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing for admin integration tests.');
            }
            self::markTestSkipped('Copy .env.testing.example to .env.testing to run MySQL integration tests.');
        }
        $settings = parse_ini_file($env, false, INI_SCANNER_RAW);
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
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/admin_cases.json'), true);
        self::assertIsArray($fixture);
        foreach (['title', 'other_title', 'third_title', 'known_line', 'created_name', 'known_name'] as $key) {
            self::assertIsString($fixture[$key]);
        }
        self::assertIsArray($fixture['unknown_tokens']);
        $mapToken = $fixture['unknown_tokens']['map'];
        $createToken = $fixture['unknown_tokens']['create'];
        $stapleToken = $fixture['unknown_tokens']['staple'];
        $ignoreToken = $fixture['unknown_tokens']['ignore'];
        self::assertIsString($mapToken);
        self::assertIsString($createToken);
        self::assertIsString($stapleToken);
        self::assertIsString($ignoreToken);
        self::assertIsArray($fixture['unknown_lines']);
        foreach ($fixture['unknown_lines'] as $line) {
            self::assertIsString($line);
        }
        $database = $settings['DB_NAME'] . '_' . bin2hex(random_bytes(6));
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $settings['DB_HOST'], $settings['DB_PORT']);
        $admin = new PDO($dsn, $settings['DB_USER'], $settings['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci");
        try {
            $db = new PDO($dsn . ';dbname=' . $database, $settings['DB_USER'], $settings['DB_PASSWORD'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false,
            ]);
            (new MigrationRunner($db, __DIR__ . '/../migrations', $database))->run();
            $seedDirectory = __DIR__ . '/../seeds';
            (new IngredientSeeder($db))->seed($seedDirectory . '/ingredients.csv');
            (new FeatureSeeder($db))->seed($seedDirectory);
            $service = new AdminService($db, $seedDirectory);
            $userInsert = $db->prepare('INSERT INTO `user` (email, password_hash) VALUES (?, ?)');
            $userInsert->execute(['admin@example.com', password_hash('correct-test-password', PASSWORD_DEFAULT)]);
            $actor = (int) $db->lastInsertId();
            $userInsert->execute(['locked@example.com', password_hash('correct-test-password', PASSWORD_DEFAULT)]);
            $auth = new AdminAuth($db);
            $auth->startSession();
            self::assertFalse($auth->login('admin@example.com', 'wrong-password'));
            self::assertTrue($auth->login('admin@example.com', 'correct-test-password'));
            self::assertSame($actor, $auth->userId());
            $token = $auth->csrfToken();
            $auth->requireCsrf($token);
            try {
                $auth->requireCsrf('invalid');
                self::fail('Invalid CSRF token was accepted.');
            } catch (RuntimeException $expected) {
                self::assertSame('Invalid CSRF token.', $expected->getMessage());
            }
            for ($attempt = 0; $attempt < 5; $attempt++) {
                self::assertFalse($auth->login('locked@example.com', 'wrong-password'));
            }
            self::assertFalse($auth->login('locked@example.com', 'correct-test-password'));

            $source = $db->prepare("SELECT id FROM source WHERE adapter = 'koket'");
            $source->execute();
            $sourceId = (int) $source->fetchColumn();
            $recipeInsert = $db->prepare('INSERT INTO recipe (source_id, url, title) VALUES (?, ?, ?)');
            $lineInsert = $db->prepare('INSERT INTO recipe_ingredient (recipe_id, position, raw_text) VALUES (?, ?, ?)');
            $ids = [];
            foreach ([$fixture['title'], $fixture['other_title'], $fixture['third_title']] as $index => $title) {
                $url = 'https://example.com/admin-test-' . $index;
                $recipeInsert->execute([$sourceId, $url, $title]);
                $recipeId = (int) $db->lastInsertId();
                $ids[] = $recipeId;
                $lines = $index === 0 ? $fixture['unknown_lines'] : [$fixture['known_line']];
                foreach ($lines as $position => $line) {
                    $lineInsert->execute([$recipeId, $position + 1, $line]);
                }
            }
            $normaliser = new IngredientNormaliser($db, new IngredientLineParser($seedDirectory));
            $normaliser->run();
            $lookupUnknown = $db->prepare('SELECT id FROM ingredient_unknown WHERE normalised_token = ?');
            $unknownIds = [];
            foreach ([$mapToken, $createToken, $stapleToken, $ignoreToken] as $tokenName) {
                $lookupUnknown->execute([$tokenName]);
                $unknownIds[$tokenName] = (int) $lookupUnknown->fetchColumn();
                self::assertGreaterThan(0, $unknownIds[$tokenName]);
            }
            $knownIngredient = $db->prepare('SELECT id FROM ingredient WHERE canonical_name = ?');
            $knownIngredient->execute([$fixture['known_name']]);
            $tomatoId = (int) $knownIngredient->fetchColumn();
            $service->resolveUnknown($actor, $unknownIds[$mapToken], 'map', $tomatoId);
            $service->resolveUnknown($actor, $unknownIds[$createToken], 'create', null, $fixture['created_name'], 'vegetable');
            $service->resolveUnknown($actor, $unknownIds[$stapleToken], 'staple');
            $service->resolveUnknown($actor, $unknownIds[$ignoreToken], 'ignore');
            $statuses = $db->prepare('SELECT status FROM ingredient_unknown WHERE id = ?');
            foreach ([$mapToken => 'mapped', $createToken => 'mapped', $stapleToken => 'staple', $ignoreToken => 'ignored'] as $tokenName => $status) {
                $statuses->execute([$unknownIds[$tokenName]]);
                self::assertSame($status, $statuses->fetchColumn());
            }
            $lineStatus = $db->prepare('SELECT normalise_status FROM recipe_ingredient WHERE recipe_id = ? AND position = ?');
            $lineStatus->execute([$ids[0], 4]);
            self::assertSame('ignored', $lineStatus->fetchColumn());
            foreach ([1, 2, 3] as $position) {
                $lineStatus->execute([$ids[0], $position]);
                self::assertSame('matched', $lineStatus->fetchColumn());
            }
            $staple = $db->prepare('SELECT is_staple FROM ingredient WHERE canonical_name = ?');
            $staple->execute(['testbas']);
            self::assertSame(1, (int) $staple->fetchColumn());
            self::assertSame([], $service->unknowns());

            $groupInsert = $db->prepare('INSERT INTO dish_group (core_name, main_protein) VALUES (?, ?)');
            $groupInsert->execute(['test group', 'vegetarian']);
            $groupId = (int) $db->lastInsertId();
            $assignGroup = $db->prepare('UPDATE recipe SET dish_group_id = ? WHERE id IN (?, ?)');
            $assignGroup->execute([$groupId, $ids[0], $ids[1]]);
            $service->splitGroup($actor, $groupId, $ids[1]);
            $groupLookup = $db->prepare('SELECT dish_group_id, dish_group_manual FROM recipe WHERE id = ?');
            $groupLookup->execute([$ids[1]]);
            $split = $groupLookup->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($split);
            self::assertIsNumeric($split['dish_group_id']);
            self::assertIsNumeric($split['dish_group_manual']);
            $splitId = (int) $split['dish_group_id'];
            self::assertNotSame($groupId, $splitId);
            self::assertSame(1, (int) $split['dish_group_manual']);
            (new DishGrouper($db, $seedDirectory))->run();
            $groupLookup->execute([$ids[1]]);
            self::assertSame($splitId, (int) $groupLookup->fetchColumn());
            $service->mergeGroups($actor, $splitId, $groupId);
            $groupLookup->execute([$ids[1]]);
            self::assertSame($groupId, (int) $groupLookup->fetchColumn());
            (new DishGrouper($db, $seedDirectory))->run();
            $afterRegroup = $db->prepare('SELECT dish_group_id FROM recipe WHERE id = ?');
            $afterRegroup->execute([$ids[1]]);
            self::assertSame($groupId, (int) $afterRegroup->fetchColumn());

            $service->setFeatureOverride($actor, $ids[0], [
                'main_protein' => 'vegetarian', 'carb_base' => 'rice', 'cuisine' => 'italian',
                'meal_type' => 'main', 'is_dinner_candidate' => '0', 'leftover_score' => '0.8',
            ]);
            (new FeatureDeriver($db, $seedDirectory))->run($ids[0]);
            $feature = $db->prepare('SELECT main_protein, carb_base, cuisine, meal_type, is_dinner_candidate, leftover_score FROM recipe WHERE id = ?');
            $feature->execute([$ids[0]]);
            $derived = $feature->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($derived);
            self::assertSame('vegetarian', $derived['main_protein']);
            self::assertSame('rice', $derived['carb_base']);
            self::assertSame('italian', $derived['cuisine']);
            self::assertSame('main', $derived['meal_type']);
            self::assertIsNumeric($derived['is_dinner_candidate']);
            self::assertIsNumeric($derived['leftover_score']);
            self::assertSame(0, (int) $derived['is_dinner_candidate']);
            self::assertSame(0.8, (float) $derived['leftover_score']);

            $candidate = $db->prepare('INSERT INTO duplicate_candidate (recipe_a, recipe_b, similarity) VALUES (?, ?, ?)');
            $candidate->execute([$ids[1], $ids[2], 0.85]);
            $service->reviewDuplicate($actor, $ids[1], $ids[2], 'not_duplicate');
            $candidate->execute([$ids[0], $ids[1], 0.91]);
            $link = $db->prepare('INSERT INTO recipe_source_link (recipe_id, source_id, url, url_hash, is_primary) VALUES (?, ?, ?, ?, TRUE)');
            $otherUrl = 'https://example.com/admin-link';
            $link->execute([$ids[1], $sourceId, $otherUrl, hash('sha256', $otherUrl, true)]);
            $service->reviewDuplicate($actor, $ids[0], $ids[1], 'merge', $ids[0]);
            $active = $db->prepare('SELECT active, merged_into_id FROM recipe WHERE id = ?');
            $active->execute([$ids[1]]);
            $merged = $active->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($merged);
            self::assertIsNumeric($merged['active']);
            self::assertIsNumeric($merged['merged_into_id']);
            self::assertSame(0, (int) $merged['active']);
            self::assertSame($ids[0], (int) $merged['merged_into_id']);
            $linkOwner = $db->prepare('SELECT recipe_id FROM recipe_source_link WHERE url = ?');
            $linkOwner->execute([$otherUrl]);
            self::assertSame($ids[0], (int) $linkOwner->fetchColumn());

            $overview = $service->overview();
            self::assertSame(0, $overview['unknown_queue']);
            self::assertSame(0, $overview['duplicate_queue']);
            self::assertGreaterThan(0, $overview['ingredient_match_share_pct']);
            $audit = $db->prepare('SELECT COUNT(*) FROM admin_audit WHERE user_id = ?');
            $audit->execute([$actor]);
            self::assertSame(11, (int) $audit->fetchColumn());
            $auth->logout();
            self::assertNull($auth->userId());
        } finally {
            $admin->exec("DROP DATABASE IF EXISTS `$database`");
        }
    }
}
