<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Database\MigrationRunner;
use Matsvep\Ingest\Ingredient\DatabaseConnection;
use Matsvep\Simulator\CatalogueClone;
use Matsvep\Simulator\PersonaSimulation;
use Matsvep\Simulator\SimulatorSettings;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PersonaSimulationIntegrationTest extends TestCase
{
    public function testSyntheticCatalogueRunsDeterministicApiPersonasAndBaselines(): void
    {
        $file = __DIR__ . '/../.env.testing';
        if (!is_file($file)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing for simulator integration tests.');
            }
            self::markTestSkipped('Configure .env.testing for simulator integration tests.');
        }
        $settings = parse_ini_file($file, false, INI_SCANNER_RAW);
        self::assertIsArray($settings);
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
            self::assertIsString($settings[$key] ?? null);
        }
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $settings['DB_NAME']);
        $prefix = $settings['DB_NAME'] . '_' . bin2hex(random_bytes(5));
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $settings['DB_HOST'], $settings['DB_PORT']);
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false];
        $admin = new PDO($dsn, $settings['DB_USER'], $settings['DB_PASSWORD'], $options);
        $names = [$prefix . '_source', $prefix . '_first', $prefix . '_second'];
        try {
            foreach ($names as $name) {
                $admin->prepare("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci")
                    ->execute();
            }
            $source = new PDO($dsn . ';dbname=' . $names[0], $settings['DB_USER'], $settings['DB_PASSWORD'], $options);
            DatabaseConnection::setUtc($source);
            (new MigrationRunner($source, __DIR__ . '/../migrations', $names[0]))->run();
            $this->seedSyntheticCatalogue($source);
            $personas = json_decode(
                (string) file_get_contents(__DIR__ . '/../seeds/simulator_personas.json'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            self::assertIsArray($personas);
            self::assertIsArray($personas[0]);
            /** @var array<string,mixed> $firstPersona */
            $firstPersona = $personas[0];
            $simulatorSettings = new SimulatorSettings(__DIR__ . '/../seeds/simulator_config.json');
            $sourceConfig = $source->prepare("SELECT COUNT(*) FROM engine_config WHERE `key` LIKE 'simulator.%'");
            $sourceConfig->execute();
            self::assertSame(0, (int) $sourceConfig->fetchColumn());
            $reports = [];
            foreach (array_slice($names, 1) as $name) {
                $target = new PDO($dsn . ';dbname=' . $name, $settings['DB_USER'], $settings['DB_PASSWORD'], $options);
                DatabaseConnection::setUtc($target);
                (new MigrationRunner($target, __DIR__ . '/../migrations', $name))->run();
                $clone = new CatalogueClone($source, $target, 7);
                $clone->acquireCrawlerLock();
                try {
                    $copyStarted = hrtime(true);
                    $counts = $clone->copy();
                    $copyMs = (hrtime(true) - $copyStarted) / 1_000_000;
                } finally {
                    $clone->releaseCrawlerLock();
                }
                self::assertSame(40, $counts['recipe']);
                self::assertGreaterThan(0, $copyMs);
                $simulatorSettings->seed($target);
                $report = (new PersonaSimulation($target))->run([$firstPersona], 2, 42);
                self::assertSame(42, $report['seed']);
                self::assertSame(2, $report['weeks']);
                self::assertIsArray($report['personas']);
                self::assertIsArray($report['personas']['husman_family']);
                self::assertSame(
                    ['engine', 'random', 'favourites_only'],
                    array_keys($report['personas']['husman_family'])
                );
                foreach ($report['personas']['husman_family'] as $policy) {
                    self::assertArrayHasKey('mean_hidden_utility', $policy);
                    self::assertArrayHasKey('hidden_utility_4_plus_share', $policy);
                    self::assertArrayHasKey('hit_rate', $policy);
                    self::assertArrayHasKey('mae', $policy);
                    self::assertGreaterThan(0, $policy['cooked']);
                    self::assertGreaterThanOrEqual(1, $policy['mean_hidden_utility']);
                    self::assertLessThanOrEqual(5, $policy['mean_hidden_utility']);
                    self::assertGreaterThanOrEqual(0, $policy['hidden_utility_4_plus_share']);
                    self::assertLessThanOrEqual(1, $policy['hidden_utility_4_plus_share']);
                }
                unset($report['timings_ms']);
                $reports[] = $report;
            }
            self::assertSame($reports[0], $reports[1]);
            $count = $source->prepare('SELECT COUNT(*) FROM household');
            $count->execute();
            self::assertSame(0, (int) $count->fetchColumn());
        } finally {
            foreach (array_reverse($names) as $name) {
                $admin->prepare("DROP DATABASE IF EXISTS `$name`")->execute();
            }
        }
    }

    public function testCrawlerLockPreventsCatalogueCopy(): void
    {
        $file = __DIR__ . '/../.env.testing';
        if (!is_file($file)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing for simulator lock tests.');
            }
            self::markTestSkipped('Configure .env.testing for simulator lock tests.');
        }
        $settings = parse_ini_file($file, false, INI_SCANNER_RAW);
        self::assertIsArray($settings);
        foreach (['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD'] as $key) {
            self::assertIsString($settings[$key] ?? null);
        }
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $settings['DB_HOST'], $settings['DB_PORT']);
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
        $first = new PDO($dsn, $settings['DB_USER'], $settings['DB_PASSWORD'], $options);
        $second = new PDO($dsn, $settings['DB_USER'], $settings['DB_PASSWORD'], $options);
        $lock = $first->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute(['matsvep:crawl:koket']);
        self::assertSame(1, (int) $lock->fetchColumn());
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('crawler lock is held');
            (new CatalogueClone($second, $second, 7))->acquireCrawlerLock();
        } finally {
            $release = $first->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute(['matsvep:crawl:koket']);
        }
    }

    private function seedSyntheticCatalogue(PDO $db): void
    {
        $source = $db->prepare('INSERT INTO source (name, base_url, adapter, enabled) VALUES (?, ?, ?, TRUE)');
        $source->execute(['Synthetic source', 'https://example.test', 'fixture']);
        $sourceId = (int) $db->lastInsertId();
        $ingredient = $db->prepare('INSERT INTO ingredient (canonical_name, category) VALUES (?, ?)');
        $ingredient->execute(['synthetic potato', 'vegetable']);
        $ingredientId = (int) $db->lastInsertId();
        $recipe = $db->prepare('INSERT INTO recipe (source_id, url, title, is_dinner_candidate, '
            . 'main_protein, cuisine, carb_base, is_vegetarian, leftover_score, festive_score, effort, active) '
            . 'VALUES (?, ?, ?, TRUE, ?, ?, ?, ?, ?, ?, ?, TRUE)');
        $line = $db->prepare('INSERT INTO recipe_ingredient '
            . '(recipe_id, position, raw_text, ingredient_id, is_main) VALUES (?, 1, ?, ?, TRUE)');
        for ($index = 1; $index <= 40; $index++) {
            $title = $index % 3 === 0 ? 'Synthetic pizza ' . $index : 'Synthetic dinner ' . $index;
            $recipe->execute([$sourceId, 'https://example.test/recipe-' . $index,
                $title, $index % 2 === 0 ? 'fish' : 'vegetarian', 'italian', 'potato',
                $index % 2 !== 0 ? 1 : 0, 0.6, 0.5, 2]);
            $line->execute([(int) $db->lastInsertId(), 'synthetic potato', $ingredientId]);
        }
    }
}
