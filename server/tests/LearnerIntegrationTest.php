<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Matsvep\Database\MigrationRunner;
use Matsvep\Engine\EngineConfig;
use Matsvep\Engine\Learner;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LearnerIntegrationTest extends TestCase
{
    public function testOnboardingRatingsRebuildAndOneContributionPerRecipe(): void
    {
        $config = $this->testDatabaseConfig();
        $database = $config['DB_NAME'] . '_' . bin2hex(random_bytes(6));
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['DB_HOST'], $config['DB_PORT']);
        $root = new PDO($dsn, $config['DB_USER'], $config['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $root->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci");
        try {
            $db = new PDO($dsn . ';dbname=' . $database, $config['DB_USER'], $config['DB_PASSWORD'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false,
            ]);
            (new MigrationRunner($db, __DIR__ . '/../migrations', $database))->run();
            $source = (int) $this->value($db, "SELECT id FROM source WHERE adapter = 'koket'");
            $db->exec("INSERT INTO household (name) VALUES ('First'), ('Second')");
            $household = (int) $this->value($db, "SELECT id FROM household WHERE name = 'First'");
            $otherHousehold = (int) $this->value($db, "SELECT id FROM household WHERE name = 'Second'");
            $db->exec("INSERT INTO ingredient (canonical_name, is_staple) VALUES "
                . "('first', FALSE), ('second', FALSE), ('staple', TRUE), "
                . "('third', FALSE), ('fourth', FALSE), ('fifth', FALSE)");
            $firstIngredient = (int) $this->value($db, "SELECT id FROM ingredient WHERE canonical_name = 'first'");
            $secondIngredient = (int) $this->value($db, "SELECT id FROM ingredient WHERE canonical_name = 'second'");
            $stapleIngredient = (int) $this->value($db, "SELECT id FROM ingredient WHERE canonical_name = 'staple'");
            $thirdIngredient = (int) $this->value($db, "SELECT id FROM ingredient WHERE canonical_name = 'third'");
            $fourthIngredient = (int) $this->value($db, "SELECT id FROM ingredient WHERE canonical_name = 'fourth'");
            $fifthIngredient = (int) $this->value($db, "SELECT id FROM ingredient WHERE canonical_name = 'fifth'");
            $recipe = $db->prepare('INSERT INTO recipe (source_id, url, title, main_protein, cuisine, meal_type, carb_base) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?)');
            $recipe->execute([$source, 'https://example.com/first', 'First', 'fish', 'italian', 'main', 'pasta']);
            $first = (int) $db->lastInsertId();
            $recipe->execute([$source, 'https://example.com/second', 'Second', 'fish', 'italian', 'main', 'rice']);
            $second = (int) $db->lastInsertId();
            $line = $db->prepare('INSERT INTO recipe_ingredient '
                . '(recipe_id, position, raw_text, ingredient_id, is_main) VALUES (?, ?, ?, ?, TRUE)');
            $line->execute([$first, 1, 'first', $firstIngredient]);
            $line->execute([$first, 2, 'staple', $stapleIngredient]);
            $line->execute([$first, 3, 'third', $thirdIngredient]);
            $line->execute([$first, 4, 'fourth', $fourthIngredient]);
            $line->execute([$first, 5, 'fifth', $fifthIngredient]);
            $line->execute([$second, 1, 'second', $secondIngredient]);
            $answers = $db->prepare('INSERT INTO onboarding_answer (household_id, question_id, answer) VALUES (?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE answer = VALUES(answer)');
            $answers->execute([$household, 'dish_rating', json_encode(['ratings' => [
                ['recipe_id' => $first, 'choice' => 'favourite'],
                ['recipe_id' => $second, 'choice' => 'not_for_us'],
            ]], JSON_THROW_ON_ERROR)]);
            $answers->execute([$household, 'cuisine_rating', '{"ratings":[{"code":"italian","rating":4}]}']);
            $answers->execute([$household, 'protein_rating', '{"ratings":[{"code":"fish","rating":5}]}']);
            $answers->execute([$household, 'disliked_ingredients', json_encode([
                'ingredient_ids' => [$secondIngredient],
            ], JSON_THROW_ON_ERROR)]);
            $when = new DateTimeImmutable('2026-11-22 12:00:00', new DateTimeZone('UTC'));
            $learner = new Learner($db, EngineConfig::fromDatabase($db));
            self::assertSame(2, $learner->applyOnboarding($household, $when)['ratings_changed']);
            self::assertSame(0, $learner->applyOnboarding($household, $when)['ratings_changed']);
            self::assertSame(2, (int) $this->value($db, 'SELECT COUNT(*) FROM rating_history'));
            self::assertSame('onboarding', $this->value(
                $db,
                "SELECT rating_source FROM household_recipe WHERE household_id = $household AND recipe_id = $first"
            ));
            $this->assertAffinity($db, $household, 'cuisine', 'italian', 0.375, 4.0);
            $this->assertAffinity($db, $household, 'protein', 'fish', 0.625, 4.0);
            $this->assertAffinity($db, $household, 'ingredient', (string) $secondIngredient, -0.7, 3.0);
            $this->assertAffinity($db, $household, 'ingredient', (string) $firstIngredient, 1.0, 0.5);
            foreach ($learner->affinitiesForHousehold($household) as $affinity) {
                if ($affinity['feature_type'] === 'cuisine' && $affinity['feature_value'] === 'italian') {
                    self::assertEqualsWithDelta(4 / 7, $affinity['confidence'], 0.0000001);
                }
            }
            self::assertSame(0, (int) $this->value(
                $db,
                "SELECT COUNT(*) FROM affinity WHERE feature_type = 'ingredient' AND feature_value = '$stapleIngredient'"
            ));

            $learner->setRating($household, $first, 4, $when);
            $db->prepare('UPDATE household_recipe SET times_cooked = 20 WHERE household_id = ? AND recipe_id = ?')
                ->execute([$household, $first]);
            $immediate = $this->affinityRows($db, $household);
            self::assertSame(['households' => 2, 'affinities' => count($immediate)], $learner->rebuildAll($when));
            self::assertSame($immediate, $this->affinityRows($db, $household));
            self::assertSame(3, (int) $this->value($db, 'SELECT COUNT(*) FROM rating_history'));
            $this->assertAffinity($db, $household, 'cuisine', 'italian', 0.25, 4.0);

            $answers->execute([$household, 'dish_rating', json_encode(['ratings' => [
                ['recipe_id' => $first, 'choice' => 'not_for_us'],
            ]], JSON_THROW_ON_ERROR)]);
            self::assertSame(1, $learner->applyOnboarding($household, $when)['ratings_changed']);
            self::assertSame(4, (int) $this->value($db, 'SELECT COUNT(*) FROM rating_history'));
            self::assertSame(1, (int) $this->value(
                $db,
                "SELECT COUNT(*) FROM rating_history WHERE recipe_id = $second AND new_rating IS NULL"
            ));
            self::assertSame('user', $this->value(
                $db,
                "SELECT rating_source FROM household_recipe WHERE household_id = $household AND recipe_id = $first"
            ));
            $this->assertAffinity($db, $household, 'cuisine', 'italian', 0.5, 3.0);
            self::assertSame(
                $this->affinityRows($db, $household),
                $this->rebuildAndRead($learner, $db, $household, $when)
            );
            self::assertSame([], $this->affinityRows($db, $otherHousehold));
        } finally {
            $root->exec("DROP DATABASE IF EXISTS `$database`");
        }
    }

    /** @return array{DB_HOST: string, DB_PORT: string, DB_NAME: string, DB_USER: string, DB_PASSWORD: string} */
    private function testDatabaseConfig(): array
    {
        $env = __DIR__ . '/../.env.testing';
        if (!is_file($env)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing for learner integration tests.');
            }
            self::markTestSkipped('Copy .env.testing.example to .env.testing to run MySQL tests.');
        }
        $values = parse_ini_file($env, false, INI_SCANNER_RAW);
        if (!is_array($values)) {
            throw new RuntimeException('Invalid test database configuration.');
        }
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
            if (!is_string($values[$key] ?? null)) {
                throw new RuntimeException('Invalid test database configuration.');
            }
        }
        if (preg_match('/^[A-Za-z0-9_]+$/', $values['DB_NAME']) !== 1) {
            throw new RuntimeException('Invalid test database prefix.');
        }
        return $values;
    }

    private function value(PDO $db, string $sql): int|string
    {
        $query = $db->prepare($sql);
        $query->execute();
        $value = $query->fetchColumn();
        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('Invalid test scalar.');
        }
        return $value;
    }

    private function assertAffinity(
        PDO $db,
        int $household,
        string $type,
        string $value,
        float $score,
        float $weight
    ): void {
        $query = $db->prepare('SELECT score, weight_sum FROM affinity '
            . 'WHERE household_id = ? AND feature_type = ? AND feature_value = ?');
        $query->execute([$household, $type, $value]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertIsNumeric($row['score']);
        self::assertIsNumeric($row['weight_sum']);
        self::assertEqualsWithDelta($score, (float) $row['score'], 0.00001);
        self::assertEqualsWithDelta($weight, (float) $row['weight_sum'], 0.000001);
    }

    /** @return list<array<string, mixed>> */
    private function affinityRows(PDO $db, int $household): array
    {
        $query = $db->prepare('SELECT feature_type, feature_value, score, weight_sum, updated_at FROM affinity '
            . 'WHERE household_id = ? ORDER BY feature_type, feature_value');
        $query->execute([$household]);
        $rows = [];
        while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function rebuildAndRead(Learner $learner, PDO $db, int $household, DateTimeImmutable $when): array
    {
        $learner->rebuildHousehold($household, $when);
        return $this->affinityRows($db, $household);
    }
}
