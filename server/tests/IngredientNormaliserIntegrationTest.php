<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Database\MigrationRunner;
use Matsvep\Ingest\Ingredient\IngredientLineParser;
use Matsvep\Ingest\Ingredient\IngredientNormaliser;
use Matsvep\Ingest\Ingredient\IngredientReport;
use Matsvep\Ingest\Ingredient\IngredientSeeder;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IngredientNormaliserIntegrationTest extends TestCase
{
    private function value(string $key, string $field = 'raw'): string
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/ingredient_lines.json'), true);
        self::assertIsArray($data);
        $entry = $data[$key] ?? null;
        $value = is_array($entry) ? ($entry[$field] ?? null) : null;
        self::assertIsString($value);
        return $value;
    }

    public function testSeedMapSplitUnknownSuggestionsAndReruns(): void
    {
        $envFile = __DIR__ . '/../.env.testing';
        if (!is_file($envFile)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing for ingredient integration tests.');
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
            $seeder = new IngredientSeeder($db);
            $seedCount = $seeder->seed(__DIR__ . '/../seeds/ingredients.csv');
            self::assertGreaterThanOrEqual(300, $seedCount);
            self::assertSame($seedCount, $seeder->seed(__DIR__ . '/../seeds/ingredients.csv'));
            $countQuery = $db->query('SELECT COUNT(*) FROM ingredient');
            self::assertInstanceOf(\PDOStatement::class, $countQuery);
            self::assertSame($seedCount, (int) $countQuery->fetchColumn());

            $sourceQuery = $db->query("SELECT id FROM source WHERE adapter = 'koket'");
            self::assertInstanceOf(\PDOStatement::class, $sourceQuery);
            $sourceId = (int) $sourceQuery->fetchColumn();
            $insertRecipe = $db->prepare('INSERT INTO recipe (source_id, url, title) VALUES (?, ?, ?)');
            $insertRecipe->execute([$sourceId, 'https://www.koket.se/test-ingredient-normaliser', 'Fixture recipe']);
            $recipeId = (int) $db->lastInsertId();
            $insertLine = $db->prepare(
                'INSERT INTO recipe_ingredient (recipe_id, position, raw_text) VALUES (?, ?, ?)'
            );
            $lines = array_map($this->value(...), [
                'range_notes', 'split_pair', 'split_with_note', 'unsafe_quantity',
                'unknown_unit', 'compound_suggestion', 'unknown_spice_one',
                'unknown_spice_two', 'spelling_variant',
            ]);
            foreach ($lines as $index => $line) {
                $insertLine->execute([$recipeId, $index + 1, $line]);
            }
            $normaliser = new IngredientNormaliser($db, new IngredientLineParser(__DIR__ . '/../seeds'));
            self::assertSame(['lines' => 9, 'matched' => 5, 'unsafe_split' => 1], $normaliser->run($recipeId));
            $partsAtPosition = $db->prepare(
                'SELECT COUNT(*) FROM recipe_ingredient_part WHERE recipe_id = ? AND position = 2'
            );
            $partsAtPosition->execute([$recipeId]);
            self::assertSame(2, (int) $partsAtPosition->fetchColumn());
            $unknownCount = $db->prepare('SELECT occurrences FROM ingredient_unknown WHERE normalised_token = ?');
            $unknownCount->execute([$this->value('unknown_spice_one', 'token')]);
            self::assertSame(2, (int) $unknownCount->fetchColumn());
            $suggestionQuery = $db->prepare(
                'SELECT i.canonical_name FROM ingredient_unknown u JOIN ingredient i ' .
                'ON i.id = u.suggested_ingredient_id WHERE u.normalised_token = ?'
            );
            $suggestionQuery->execute([$this->value('compound_suggestion', 'token')]);
            self::assertSame($this->value('compound_suggestion', 'suggestion'), $suggestionQuery->fetchColumn());
            $lineAtPosition = $db->prepare(
                'SELECT ingredient_id, unparsed_unit FROM recipe_ingredient WHERE recipe_id = ? AND position = ?'
            );
            $lineAtPosition->execute([$recipeId, 6]);
            $compoundLine = $lineAtPosition->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($compoundLine);
            self::assertNull($compoundLine['ingredient_id']);
            $lineAtPosition->execute([$recipeId, 5]);
            $unitLine = $lineAtPosition->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($unitLine);
            self::assertSame($this->value('unknown_unit', 'unit_word'), $unitLine['unparsed_unit']);
            $normaliser->run($recipeId);
            $unknownCount->execute([$this->value('unknown_spice_one', 'token')]);
            self::assertSame(2, (int) $unknownCount->fetchColumn());
            $report = (new IngredientReport($db))->build();
            self::assertSame(9, $report['lines']);
            self::assertSame(1, $report['unsafe_split_lines']);
            self::assertNotEmpty($report['top_unparsed_unit_words']);
            self::assertNotEmpty($report['top_unknown_tokens']);
        } finally {
            $admin->exec("DROP DATABASE IF EXISTS `$name`");
        }
    }
}
