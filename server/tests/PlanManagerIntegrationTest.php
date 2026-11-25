<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Database\MigrationRunner;
use Matsvep\Engine\EngineConfig;
use Matsvep\Engine\PlanCandidateRepository;
use Matsvep\Engine\PlanManager;
use Matsvep\Engine\PlanVersionConflict;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PlanManagerIntegrationTest extends TestCase
{
    public function testFilteredCandidatesAndVersionedPlanOperations(): void
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
            $db->prepare('INSERT INTO household (name) VALUES (?)')->execute(['Plan test']);
            $householdId = (int) $db->lastInsertId();
            $source = $this->value($db, "SELECT id FROM source WHERE adapter = 'koket'");
            $recipe = $db->prepare('INSERT INTO recipe (source_id, url, title, is_dinner_candidate, '
                . 'main_protein, leftover_score, festive_score, effort, active) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            for ($id = 1; $id <= 16; $id++) {
                $recipe->execute([$source, 'https://example.com/plan-' . $id, 'Plan ' . $id,
                    $id !== 15 ? 1 : 0, $id % 3 === 0 ? 'fish' : 'vegetarian', 0.7, 0.5, 2, $id !== 16 ? 1 : 0]);
            }
            $householdRecipe = $db->prepare('INSERT INTO household_recipe '
                . '(household_id, recipe_id, status, rating, rating_source, rated_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?)');
            for ($id = 1; $id <= 8; $id++) {
                $householdRecipe->execute([$householdId, $id, 'library', 5, 'user', '2026-11-22 00:00:00']);
            }
            $householdRecipe->execute([$householdId, 13, 'blocked', null, null, null]);
            $householdRecipe->execute([$householdId, 14, 'library', 2, 'user', '2026-11-22 00:00:00']);
            $engineConfig = EngineConfig::fromDatabase($db);
            $candidateIds = array_map(
                static fn ($candidate): int => $candidate->id,
                (new PlanCandidateRepository($db, $engineConfig))->load(
                    $householdId,
                    new \DateTimeImmutable('2026-11-22'),
                    false
                )
            );
            foreach ([13, 14, 15, 16] as $excluded) {
                self::assertNotContains($excluded, $candidateIds);
            }
            $manager = new PlanManager($db, $engineConfig);
            $dates = [];
            for ($day = 0; $day < 7; $day++) {
                $dates[] = (new \DateTimeImmutable('2026-11-22'))->modify('+' . $day . ' days')->format('Y-m-d');
            }
            $prepared = $manager->prepare($householdId, '2026-11-22', 1, $dates);
            self::assertSame(1, $prepared['version']);
            self::assertCount(7, $prepared['slots']);
            self::assertGreaterThanOrEqual(10, $this->value($db, 'SELECT COUNT(*) FROM plan_candidate'));
            $planId = $prepared['plan_id'];
            $pick = $prepared['decks'][$dates[0]][0]['recipe_id'];
            $chosen = $manager->choose($planId, 1, $dates[0], $pick);
            self::assertSame(2, $chosen['version']);
            self::assertSame($pick, $chosen['slots'][$dates[0]]['recipe_id']);
            self::assertNotEmpty($chosen['decks'][$dates[1]]);
            try {
                $manager->choose($planId, 1, $dates[1], $chosen['decks'][$dates[1]][0]['recipe_id']);
                self::fail('A stale plan version must fail.');
            } catch (PlanVersionConflict $error) {
                self::assertSame('plan_version_conflict', $error->getMessage());
            }
            $newPick = $prepared['decks'][$dates[0]][1]['recipe_id'];
            $replaced = $manager->replace($planId, 2, $dates[0], $newPick);
            self::assertSame(3, $replaced['version']);
            self::assertSame($newPick, $replaced['slots'][$dates[0]]['recipe_id']);
            self::assertSame(1, $this->value($db, 'SELECT COUNT(*) FROM slot_skip'));
            self::assertSame($pick, $this->value(
                $db,
                "SELECT replaced_from_recipe_id FROM plan_slot WHERE date = '2026-11-22'"
            ));
            $this->expectVersionConflict(static fn (): array => $manager->replace($planId, 2, $dates[0], $pick));
            $filled = $manager->autofill($planId, 3);
            self::assertSame(4, $filled['version']);
            $this->expectVersionConflict(static fn (): array => $manager->autofill($planId, 3));
            self::assertSame(7, $this->value($db, "SELECT COUNT(*) FROM plan_slot WHERE status = 'chosen'"));
            self::assertSame(7, $this->value($db, 'SELECT COUNT(*) FROM plan_slot WHERE explanation_json IS NOT NULL'));
            $shuffled = $manager->shuffle($planId, 4);
            self::assertSame(5, $shuffled['version']);
            $this->expectVersionConflict(static fn (): array => $manager->shuffle($planId, 4));
            self::assertSame(1, $this->value($db, 'SELECT reshuffle_count FROM plan'));
            $setting = $db->prepare('INSERT INTO household_setting (household_id, `key`, value) VALUES (?, ?, ?)');
            $setting->execute([$householdId, 'plan_days', '"sunday_thursday"']);
            $nextWeek = [];
            for ($day = 0; $day < 5; $day++) {
                $nextWeek[] = (new \DateTimeImmutable('2026-11-29'))->modify('+' . $day . ' days')->format('Y-m-d');
            }
            self::assertCount(5, $manager->prepare($householdId, '2026-11-29', 1, $nextWeek)['slots']);
        } finally {
            $root->exec("DROP DATABASE `$database`");
        }
    }

    /** @return array{DB_HOST: string, DB_PORT: string, DB_NAME: string, DB_USER: string, DB_PASSWORD: string} */
    private function testDatabaseConfig(): array
    {
        $env = __DIR__ . '/../.env.testing';
        if (!is_file($env)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing for plan integration tests.');
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

    private function value(PDO $db, string $sql): int
    {
        $query = $db->prepare($sql);
        $query->execute();
        $value = $query->fetchColumn();
        if (!is_string($value) && !is_int($value)) {
            throw new RuntimeException('Invalid test scalar.');
        }
        return (int) $value;
    }

    /** @param callable(): array<string, mixed> $operation */
    private function expectVersionConflict(callable $operation): void
    {
        try {
            $operation();
            self::fail('A stale plan version must fail.');
        } catch (PlanVersionConflict $error) {
            self::assertSame('plan_version_conflict', $error->getMessage());
        }
    }
}
