<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Api\Idempotency;
use Matsvep\Api\DbValue;
use Matsvep\Database\MigrationRunner;
use Matsvep\Engine\EngineConfig;
use Matsvep\Engine\PlanManager;
use PDO;
use PHPUnit\Framework\TestCase;

final class PlanManagerTransactionIntegrationTest extends TestCase
{
    private static PDO $admin;
    private static PDO $db;
    private static string $databaseName;
    private static int $householdId;
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        $file = __DIR__ . '/../.env.testing';
        if (!is_file($file)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing for planning transaction tests.');
            }
            self::markTestSkipped('Configure .env.testing for planning transaction tests.');
        }
        $settings = parse_ini_file($file, false, INI_SCANNER_RAW);
        self::assertIsArray($settings);
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
            self::assertIsString($settings[$key] ?? null);
        }
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $settings['DB_NAME']);
        self::$databaseName = $settings['DB_NAME'] . '_' . bin2hex(random_bytes(6));
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $settings['DB_HOST'], $settings['DB_PORT']);
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false];
        self::$admin = new PDO($dsn, $settings['DB_USER'], $settings['DB_PASSWORD'], $options);
        self::$admin->exec('CREATE DATABASE `' . self::$databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci');
        self::$db = new PDO($dsn . ';dbname=' . self::$databaseName, $settings['DB_USER'], $settings['DB_PASSWORD'], $options);
        self::$db->prepare("SET time_zone = '+00:00'")->execute();
        (new MigrationRunner(self::$db, __DIR__ . '/../migrations', self::$databaseName))->run();
        self::$db->prepare('INSERT INTO household (name) VALUES (?)')->execute(['Transaction test household']);
        self::$householdId = (int) self::$db->lastInsertId();
        self::$db->prepare("INSERT INTO `user` (email, password_hash, role, household_id) VALUES (?, ?, 'member', ?)")
            ->execute(['plan-transactions@example.test', password_hash('synthetic-password-long', PASSWORD_DEFAULT), self::$householdId]);
        self::$userId = (int) self::$db->lastInsertId();
        self::$db->prepare('INSERT INTO source (name, base_url, adapter, enabled) VALUES (?, ?, ?, TRUE)')
            ->execute(['Synthetic source', 'https://example.test', 'fixture']);
        $sourceId = (int) self::$db->lastInsertId();
        $recipe = self::$db->prepare('INSERT INTO recipe (source_id, url, title, is_dinner_candidate, main_protein, leftover_score, festive_score, effort, active) VALUES (?, ?, ?, TRUE, ?, ?, ?, ?, TRUE)');
        for ($index = 1; $index <= 20; $index++) {
            $recipe->execute([$sourceId, 'https://example.test/plan-' . $index, 'Synthetic plan ' . $index,
                $index % 3 === 0 ? 'fish' : 'vegetarian', 0.7, 0.5, 2]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$admin, self::$databaseName)) {
            self::$admin->exec('DROP DATABASE `' . self::$databaseName . '`');
        }
    }

    public function testStandalonePlanWritesCommit(): void
    {
        $manager = new PlanManager(self::$db, EngineConfig::fromDatabase(self::$db));
        $dates = self::dates('2026-11-22');
        $prepared = $manager->prepare(self::$householdId, '2026-11-22', 1, $dates);
        self::assertFalse(self::$db->inTransaction());
        $recipeId = $prepared['decks'][$dates[0]][0]['recipe_id'];
        $chosen = $manager->choose($prepared['plan_id'], $prepared['version'], $dates[0], $recipeId);
        self::assertFalse(self::$db->inTransaction());
        self::assertSame(2, $chosen['version']);
        $query = self::$db->prepare('SELECT version FROM plan WHERE id = ?');
        $query->execute([$prepared['plan_id']]);
        self::assertSame(2, (int) $query->fetchColumn());
    }

    public function testPlanWritesJoinIdempotencyTransaction(): void
    {
        $config = EngineConfig::fromDatabase(self::$db);
        $manager = new PlanManager(self::$db, $config);
        $idempotency = new Idempotency(self::$db, $config);
        $dates = self::dates('2026-11-29');
        $prepareKey = '123e4567-e89b-42d3-a456-426614174201';
        $prepare = fn (): array => [201, $manager->prepare(self::$householdId, '2026-11-29', 1, $dates)];
        $first = $idempotency->execute(self::$userId, 'POST /plans', $prepareKey, '{"week_start":"2026-11-29"}', $prepare);
        self::assertFalse(self::$db->inTransaction());
        $unexpectedWrite = static function (): array {
            throw new \LogicException('A replay must not execute the plan write.');
        };
        $replayedPrepare = $idempotency->execute(self::$userId, 'POST /plans', $prepareKey, '{"week_start":"2026-11-29"}', $unexpectedWrite);
        self::assertSame(201, $replayedPrepare[0]);
        self::assertSame($first[1]['plan_id'], $replayedPrepare[1]['plan_id']);
        $plan = $first[1];
        $date = $dates[0];
        $decks = DbValue::map($plan['decks']);
        $cards = DbValue::rows($decks[$date]);
        $recipeId = DbValue::integer($cards[0]['recipe_id']);
        $planId = DbValue::integer($plan['plan_id']);
        $version = DbValue::integer($plan['version']);
        $chooseKey = '123e4567-e89b-42d3-a456-426614174202';
        $choose = fn (): array => [200, $manager->choose($planId, $version, $date, $recipeId)];
        $picked = $idempotency->execute(self::$userId, 'POST /plans/choose', $chooseKey, '{"expected_version":1}', $choose);
        self::assertFalse(self::$db->inTransaction());
        self::assertSame(2, $picked[1]['version']);
        $replayedChoose = $idempotency->execute(self::$userId, 'POST /plans/choose', $chooseKey, '{"expected_version":1}', $unexpectedWrite);
        self::assertSame(200, $replayedChoose[0]);
        self::assertSame(2, DbValue::integer($replayedChoose[1]['version']));
        $query = self::$db->prepare('SELECT version FROM plan WHERE id = ?');
        $query->execute([$planId]);
        self::assertSame(2, (int) $query->fetchColumn());
    }

    public function testCallerCanRollBackJoinedPlanWrite(): void
    {
        $manager = new PlanManager(self::$db, EngineConfig::fromDatabase(self::$db));
        self::$db->beginTransaction();
        try {
            $manager->prepare(self::$householdId, '2026-12-06', 1, self::dates('2026-12-06'));
            self::assertTrue(self::$db->inTransaction());
        } finally {
            self::$db->rollBack();
        }
        $query = self::$db->prepare('SELECT COUNT(*) FROM plan WHERE household_id = ? AND week_start = ?');
        $query->execute([self::$householdId, '2026-12-06']);
        self::assertSame(0, (int) $query->fetchColumn());
    }

    /** @return list<string> */
    private static function dates(string $weekStart): array
    {
        $dates = [];
        for ($day = 0; $day < 7; $day++) {
            $dates[] = (new \DateTimeImmutable($weekStart))->modify('+' . $day . ' days')->format('Y-m-d');
        }
        return $dates;
    }
}
