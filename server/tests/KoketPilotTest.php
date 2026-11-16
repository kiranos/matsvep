<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use DOMDocument;
use DOMXPath;
use Matsvep\Database\MigrationRunner;
use Matsvep\Ingest\Adapter\KoketAdapter;
use Matsvep\Ingest\Adapter\KoketCrawlRunner;
use Matsvep\Ingest\Adapter\KoketDiscovery;
use Matsvep\Ingest\Adapter\KoketPilotReport;
use Matsvep\Ingest\Adapter\KoketPilotSampler;
use Matsvep\Ingest\Http\HttpResponse;
use Matsvep\Ingest\Http\PoliteFetcher;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KoketPilotTest extends TestCase
{
    public function testSampleIsStableAndIncludesBothPathShapes(): void
    {
        $xml = $this->fixture('sitemap.xml');
        $candidates = (new KoketDiscovery())->sitemap($xml, 'https://www.koket.se/sitemap.xml')['recipes'];
        $sampler = new KoketPilotSampler();
        $first = $sampler->sample($candidates, 12);
        self::assertSame($first, $sampler->sample($candidates, 12));
        self::assertCount(12, $first);
        self::assertCount(12, array_unique(array_column($first, 'url')));
        $rootCount = count(array_filter($first, static function (array $item): bool {
            $path = parse_url($item['url'], PHP_URL_PATH);
            return is_string($path) && substr_count(trim($path, '/'), '/') === 0;
        }));
        self::assertGreaterThan(0, $rootCount);
        self::assertLessThan(12, $rootCount);
        self::assertEqualsWithDelta(12 * 25 / 33, $rootCount, 1.0);
        $this->expectException(RuntimeException::class);
        $sampler->sample($candidates, 34);
    }

    public function testSampleFetchReportAndResumeUseFixturesOnly(): void
    {
        $envFile = __DIR__ . '/../.env.testing';
        if (!is_file($envFile)) {
            if (getenv('CI') !== false) {
                self::fail('CI requires .env.testing for the pilot integration test.');
            }
            self::markTestSkipped('Copy .env.testing.example to .env.testing to run the MySQL integration test.');
        }
        $config = parse_ini_file($envFile, false, INI_SCANNER_RAW);
        if (!is_array($config) || !is_string($config['DB_NAME'] ?? null)
            || !preg_match('/^[A-Za-z0-9_]+$/', $config['DB_NAME'])
            || !is_string($config['DB_HOST'] ?? null) || !is_string($config['DB_PORT'] ?? null)
            || !is_string($config['DB_USER'] ?? null) || !is_string($config['DB_PASSWORD'] ?? null)) {
            throw new RuntimeException('Invalid .env.testing.');
        }
        $databaseName = $config['DB_NAME'] . '_' . bin2hex(random_bytes(6));
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['DB_HOST'], $config['DB_PORT']);
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
        $admin = new PDO($dsn, $config['DB_USER'], $config['DB_PASSWORD'], $options);
        $admin->exec("CREATE DATABASE `$databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci");
        try {
            $db = new PDO($dsn . ';dbname=' . $databaseName, $config['DB_USER'], $config['DB_PASSWORD'], $options);
            (new MigrationRunner($db, __DIR__ . '/../migrations', $databaseName))->run();
            $sourceQuery = $db->query("SELECT id FROM source WHERE adapter = 'koket'");
            if ($sourceQuery === false) {
                throw new RuntimeException('Cannot read fixture source.');
            }
            $sourceValue = $sourceQuery->fetchColumn();
            if (!is_numeric($sourceValue)) {
                throw new RuntimeException('Missing fixture source.');
            }
            $sourceId = (int) $sourceValue;
            $sitemapUrl = 'https://www.koket.se/sitemap.xml';
            $xml = $this->fixture('sitemap.xml');
            $client = new FakeHttpClient();
            $client->responses[$sitemapUrl] = new HttpResponse(200, [], $xml);
            $client->responses['https://www.koket.se/robots.txt'] = new HttpResponse(200, [], $this->fixture('robots.txt'));
            $soupUrl = $this->canonical('recipe-soup.html');
            $secondUrl = $this->canonical('recipe-2.html');
            $chefUrl = $this->canonical('chef-page.html');
            foreach (array_keys((new KoketDiscovery())->sitemap($xml, $sitemapUrl)['recipes']) as $url) {
                $client->responses[$url] = new HttpResponse(503, [], '');
            }
            $client->responses[$soupUrl] = new HttpResponse(200, [], $this->fixture('recipe-soup.html'));
            $client->responses[$secondUrl] = new HttpResponse(200, [], $this->fixture('recipe-2.html'));
            $client->responses[$chefUrl] = new HttpResponse(200, [], $this->fixture('chef-page.html'));
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
            $adapter = new KoketAdapter($db, $sourceId, $fetcher, 600, 600, 3);
            $sample = $adapter->discoverSample($xml, $sitemapUrl, 33);
            self::assertSame(['sampled' => 33, 'queued' => 33, 'root' => 25, 'nested' => 8], $sample);
            self::assertSame([], $client->requests);
            try {
                $adapter->discoverSample($xml, $sitemapUrl, 33);
                self::fail('Pilot sampling should reject an existing queue.');
            } catch (RuntimeException $error) {
                self::assertSame('Pilot queue already exists; resume it without sampling again.', $error->getMessage());
            }
            $countQuery = $db->query('SELECT COUNT(*) FROM ingest_url');
            if ($countQuery === false) {
                throw new RuntimeException('Cannot count fixture URLs.');
            }
            self::assertSame(33, (int) $countQuery->fetchColumn());
            $results = $adapter->fetchDue(33);
            self::assertSame(2, $results['fetched']);
            self::assertSame(1, $results['not_recipe']);
            self::assertSame(30, $results['failed']);
            self::assertCount(34, $client->requests);
            $report = (new KoketPilotReport($db, $sourceId, 3, 5))->build();
            self::assertSame(33, $report['queue']['total']);
            self::assertSame(3, $report['queue']['done']);
            self::assertSame(30, $report['queue']['remaining']);
            self::assertSame(0, $report['queue']['due']);
            self::assertNotNull($report['queue']['next_retry_at']);
            self::assertCount(30, $report['pending_retries']);
            self::assertSame(2, $report['classification']['recipes']);
            self::assertIsArray($report['completeness']);
            self::assertSame(2, $report['completeness']['recipes']);
            self::assertSame(0, $report['nested_root_duplicates']);
            self::assertFalse($report['discovery_complete']);
            self::assertSame(1, $report['classification']['not_recipe']);
            self::assertSame(1.0, $report['recipe_paths']['root_share']);
            self::assertSame(30, $report['failure_http_statuses'][503]);
            self::assertCount(10, $report['failure_examples']['http_503']);
            self::assertArrayHasKey('(root)', $report['not_recipe_prefixes']);
            self::assertArrayNotHasKey('ingredients', $report);

            $reportFile = sys_get_temp_dir() . '/matsvep-crawl-' . bin2hex(random_bytes(6)) . '.json';
            try {
                $runner = new KoketCrawlRunner(
                    $db,
                    $sourceId,
                    $adapter,
                    new KoketPilotReport($db, $sourceId, 3, 5),
                    $sitemapUrl,
                    $reportFile,
                    7,
                    50,
                    300,
                    5,
                    static function () use (&$time): float {
                        return $time;
                    },
                    static function (int $seconds) use (&$time): void {
                        $time += $seconds;
                    },
                );
                $firstRun = $runner->run(0.01);
                self::assertTrue($firstRun['discovered']);
                self::assertSame(0, $firstRun['batches']);
                self::assertFileExists($reportFile);
                $savedReport = file_get_contents($reportFile);
                self::assertIsString($savedReport);
                $decodedReport = json_decode($savedReport, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($decodedReport);
                self::assertTrue($decodedReport['discovery_complete']);
                $requestsAfterDiscovery = count($client->requests);
                self::assertFalse($runner->run(0.01)['discovered']);
                self::assertCount($requestsAfterDiscovery, $client->requests);
                $db->prepare('UPDATE crawl_discovery SET completed_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 8 DAY) ' .
                    'WHERE source_id = ?')->execute([$sourceId]);
                self::assertTrue($runner->run(0.01)['discovered']);
                self::assertCount($requestsAfterDiscovery + 1, $client->requests);
            } finally {
                if (is_file($reportFile)) {
                    unlink($reportFile);
                }
            }
        } finally {
            $admin->exec("DROP DATABASE IF EXISTS `$databaseName`");
        }
    }

    private function canonical(string $name): string
    {
        $document = new DOMDocument();
        @$document->loadHTML($this->fixture($name), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $url = $xpath->evaluate('string((//link[@rel="canonical"]/@href)[1])');
        self::assertIsString($url);
        return $url;
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__ . '/fixtures/koket/' . $name);
        self::assertNotFalse($content);
        return $content;
    }
}
