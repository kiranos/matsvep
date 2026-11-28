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
            for ($id = 1; $id <= 20; $id++) {
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
            $db->prepare("UPDATE engine_config SET value = '2', version = 2 "
                . "WHERE `key` = 'builder.discovery_prefilter'")->execute();
            $db->prepare("UPDATE engine_config SET version = 4 "
                . "WHERE `key` = 'builder.temperature'")->execute();
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
            $phases = [];
            $manager = new PlanManager(
                $db,
                $engineConfig,
                static function (string $phase, float $milliseconds) use (&$phases): void {
                    $phases[] = $phase;
                }
            );
            $dates = [];
            for ($day = 0; $day < 7; $day++) {
                $dates[] = (new \DateTimeImmutable('2026-11-22'))->modify('+' . $day . ' days')->format('Y-m-d');
            }
            $prepared = $manager->prepare($householdId, '2026-11-22', 1, $dates);
            self::assertSame(1, $prepared['version']);
            self::assertCount(7, $prepared['slots']);
            self::assertGreaterThanOrEqual(10, $this->value($db, 'SELECT COUNT(*) FROM plan_candidate'));
            $planId = $prepared['plan_id'];
            self::assertSame(0, $this->value($db, 'SELECT COUNT(*) FROM plan_candidate WHERE recipe_id = 12'));
            $initialStateVersion = $this->value($db, 'SELECT state_version FROM household');
            $initialFilterVersion = $this->value($db, 'SELECT filter_version FROM household');
            $phases = [];
            $householdRecipe->execute([$householdId, 12, 'library', 5, 'user', '2026-11-22 00:00:00']);
            $rerated = $manager->prepare($householdId, '2026-11-22', 1, $dates);
            self::assertGreaterThan($initialStateVersion, $this->value($db, 'SELECT state_version FROM household'));
            self::assertSame($initialFilterVersion, $this->value($db, 'SELECT filter_version FROM household'));
            self::assertContains('shortlist_reuse', $phases);
            self::assertNotContains('shortlist_rebuild', $phases);
            self::assertSame(0, $this->value($db, 'SELECT COUNT(*) FROM plan_candidate WHERE recipe_id = 12'));
            self::assertNotContains(12, array_column($rerated['decks'][$dates[1]], 'recipe_id'));
            self::assertSame(
                $this->value($db, 'SELECT state_version FROM household'),
                $this->value($db, 'SELECT shortlist_state_version FROM plan')
            );

            $phases = [];
            $db->prepare('UPDATE household_recipe SET rating = ? WHERE household_id = ? AND recipe_id = ?')
                ->execute([3, $householdId, 1]);
            $manager->view($planId);
            self::assertContains('shortlist_reuse', $phases);
            self::assertSame($initialFilterVersion, $this->value($db, 'SELECT filter_version FROM household'));
            $shortlisted = (new PlanCandidateRepository($db, $engineConfig))->load(
                $householdId,
                new \DateTimeImmutable('2026-11-22'),
                false,
                [],
                $planId
            );
            $firstCandidate = array_values(array_filter(
                $shortlisted,
                static fn ($candidate): bool => $candidate->id === 1
            ));
            self::assertCount(1, $firstCandidate);
            self::assertSame(3, $firstCandidate[0]->rating);

            $phases = [];
            $householdRecipe->execute([$householdId, 9, 'blocked', null, null, null]);
            $blocked = $manager->prepare($householdId, '2026-11-22', 1, $dates);
            self::assertContains('shortlist_rebuild', $phases);
            self::assertGreaterThan($initialFilterVersion, $this->value($db, 'SELECT filter_version FROM household'));
            self::assertSame(0, $this->value($db, 'SELECT COUNT(*) FROM plan_candidate WHERE recipe_id = 9'));
            self::assertNotContains(9, array_column($blocked['decks'][$dates[1]], 'recipe_id'));
            self::assertSame(0, $this->value($db, 'SELECT COUNT(*) FROM plan_candidate WHERE recipe_id = 17'));

            $phases = [];
            $householdRecipe->execute([$householdId, 10, 'library', 2, 'user', '2026-11-22 00:00:00']);
            $hidden = $manager->prepare($householdId, '2026-11-22', 1, $dates);
            self::assertContains('shortlist_rebuild', $phases);
            self::assertSame(0, $this->value($db, 'SELECT COUNT(*) FROM plan_candidate WHERE recipe_id = 10'));
            self::assertNotContains(10, array_column($hidden['decks'][$dates[1]], 'recipe_id'));

            $priorConfigVersion = $this->value($db, 'SELECT shortlist_config_version FROM plan');
            $db->prepare("UPDATE engine_config SET value = '3', version = 3 "
                . "WHERE `key` = 'builder.discovery_prefilter'")->execute();
            $manager->prepare($householdId, '2026-11-22', 1, $dates);
            self::assertSame(
                $priorConfigVersion + 1,
                $this->value($db, 'SELECT shortlist_config_version FROM plan')
            );
            self::assertSame(1, $this->value($db, 'SELECT COUNT(*) FROM plan_candidate WHERE recipe_id = 17'));
            $phases = [];
            $db->prepare('INSERT INTO household_setting (household_id, `key`, value) VALUES (?, ?, ?)')
                ->execute([$householdId, 'block_vegetarian', 'true']);
            $vegetarianBlocked = $manager->view($planId);
            self::assertContains('shortlist_rebuild', $phases);
            foreach ($vegetarianBlocked['decks'] as $deck) {
                self::assertNotContains(2, array_column($deck, 'recipe_id'));
            }
            $db->prepare('DELETE FROM household_setting WHERE household_id = ? AND `key` = ?')
                ->execute([$householdId, 'block_vegetarian']);
            $manager->view($planId);
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
            $cookedSlot = $this->value($db, "SELECT id FROM plan_slot WHERE date = '2026-11-22'");
            $db->prepare("UPDATE plan_slot SET status = 'cooked' WHERE id = ?")->execute([$cookedSlot]);
            $db->prepare('INSERT INTO meal_log (household_id, recipe_id, cooked_on, plan_slot_id) '
                . 'VALUES (?, ?, ?, ?)')->execute([$householdId, $newPick, '2026-11-22', $cookedSlot]);
            $db->prepare('INSERT INTO household_recipe (household_id, recipe_id, last_cooked_at) '
                . 'VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_cooked_at = VALUES(last_cooked_at)')
                ->execute([$householdId, $newPick, '2026-11-22']);
            $nextWeek = [];
            for ($day = 0; $day < 5; $day++) {
                $nextWeek[] = (new \DateTimeImmutable('2026-11-29'))->modify('+' . $day . ' days')->format('Y-m-d');
            }
            $nextPlan = $manager->prepare($householdId, '2026-11-29', 1, $nextWeek);
            self::assertCount(5, $nextPlan['slots']);
            foreach ($nextPlan['decks'] as $deck) {
                self::assertNotContains($newPick, array_column($deck, 'recipe_id'));
            }
            try {
                $manager->choose($nextPlan['plan_id'], $nextPlan['version'], $nextWeek[0], $newPick);
                self::fail('A cooked recipe in the history cannot bypass the cooldown hard filter.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Recipe does not pass planning filters.', $error->getMessage());
            }
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
