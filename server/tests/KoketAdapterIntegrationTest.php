<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Database\MigrationRunner;
use Matsvep\Ingest\Adapter\KoketAdapter;
use Matsvep\Ingest\Http\HttpResponse;
use Matsvep\Ingest\Http\PoliteFetcher;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KoketAdapterIntegrationTest extends TestCase
{
    public function testDiscoveryFetchAndConditionalRecheckWithSavedFixtures(): void
    {
        $envFile = __DIR__ . '/../.env.testing';
        if (!is_file($envFile)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing for the Koket integration test.');
            }
            self::markTestSkipped('Copy .env.testing.example to .env.testing to run the MySQL integration test.');
        }
        $config = parse_ini_file($envFile, false, INI_SCANNER_RAW);
        if ($config === false) {
            throw new RuntimeException('Cannot parse .env.testing.');
        }
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key])) {
                throw new RuntimeException("Missing or invalid $key in .env.testing.");
            }
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $config['DB_NAME'])) {
            throw new RuntimeException('Invalid test database prefix.');
        }
        $databaseName = $config['DB_NAME'] . '_' . bin2hex(random_bytes(6));
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['DB_HOST'], $config['DB_PORT']);
        $admin = new PDO($dsn, $config['DB_USER'], $config['DB_PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $admin->exec("CREATE DATABASE `$databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci");
        try {
            $db = new PDO($dsn . ';dbname=' . $databaseName, $config['DB_USER'], $config['DB_PASSWORD'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]);
            (new MigrationRunner($db, __DIR__ . '/../migrations', $databaseName))->run();
            $db->prepare('INSERT INTO source (name, base_url, adapter, enabled) VALUES (?, ?, ?, TRUE)')
                ->execute(['Fixture source', 'https://www.koket.se', 'koket']);
            $sourceId = (int) $db->lastInsertId();

            $client = new FakeHttpClient();
            $client->responses = [
                'https://www.koket.se/robots.txt' => new HttpResponse(200, [], "User-agent: *\nAllow: /\n"),
                'https://www.koket.se/sitemap.xml' => new HttpResponse(200, [], $this->fixture('sitemap.xml')),
                'https://www.koket.se/recept' => new HttpResponse(200, [], $this->fixture('category.html')),
                'https://www.koket.se/recept?page=2' => new HttpResponse(200, [], '<html></html>'),
                'https://www.koket.se/recept/oven-curry' => new HttpResponse(
                    200,
                    ['etag' => '"version-one"'],
                    $this->fixture('recipe-jsonld.html')
                ),
                'https://www.koket.se/recept/bean-stew' => new HttpResponse(
                    200,
                    [],
                    $this->fixture('recipe-html.html')
                ),
            ];
            $time = 0.0;
            $fetcher = new PoliteFetcher(
                $client,
                'https://www.koket.se',
                'MatsvepCrawler/1.0',
                5,
                static function () use (&$time): float {
                    return $time;
                },
                static function (float $seconds) use (&$time): void {
                    $time += $seconds;
                },
            );
            $adapter = new KoketAdapter($db, $sourceId, $fetcher, 5);
            self::assertSame(
                ['sitemap' => 1, 'category' => 1],
                $adapter->discover('https://www.koket.se/sitemap.xml', ['https://www.koket.se/recept']),
            );
            self::assertSame(2, $this->countRows($db, 'SELECT COUNT(*) FROM ingest_url'));
            self::assertSame(2, $adapter->fetchDue(10)['fetched']);
            self::assertSame(2, $this->countRows($db, 'SELECT COUNT(*) FROM recipe'));
            self::assertSame(2, $this->countRows($db, 'SELECT COUNT(*) FROM recipe_raw'));
            self::assertSame(2, $this->countRows($db, "SELECT COUNT(*) FROM ingest_url WHERE status = 'fetched'"));

            $requeue = $db->prepare("UPDATE ingest_url SET status = 'queued', next_attempt_at = NULL WHERE url LIKE ?");
            $requeue->execute(['%oven-curry']);
            $client->responses['https://www.koket.se/recept/oven-curry'] = new HttpResponse(304, [], '');
            self::assertSame(1, $adapter->fetchDue(10)['unchanged']);
            $lastRequest = end($client->requests);
            self::assertNotFalse($lastRequest);
            self::assertSame('"version-one"', $lastRequest['headers']['If-None-Match']);

            $requeue->execute(['%bean-stew']);
            $client->responses['https://www.koket.se/recept/bean-stew'] = new HttpResponse(
                429,
                ['retry-after' => '30'],
                ''
            );
            self::assertSame(1, $adapter->fetchDue(10)['failed']);
            self::assertSame(
                1,
                $this->countRows(
                    $db,
                    "SELECT COUNT(*) FROM ingest_url WHERE status = 'failed' " .
                    'AND next_attempt_at >= DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 25 SECOND)',
                ),
            );

            $requeue->execute(['%bean-stew']);
            $client->responses['https://www.koket.se/recept/bean-stew'] = new HttpResponse(404, [], '');
            self::assertSame(1, $adapter->fetchDue(10)['gone']);
            self::assertSame(1, $this->countRows($db, "SELECT COUNT(*) FROM recipe WHERE source_status = 'source_gone'"));
        } finally {
            $admin->exec("DROP DATABASE IF EXISTS `$databaseName`");
        }
    }

    private function countRows(PDO $db, string $sql): int
    {
        $query = $db->prepare($sql);
        $query->execute();
        return (int) $query->fetchColumn();
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__ . '/fixtures/koket/' . $name);
        self::assertNotFalse($content);
        return $content;
    }
}
