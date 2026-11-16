<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use DOMDocument;
use DOMXPath;
use Matsvep\Database\MigrationRunner;
use Matsvep\Ingest\Adapter\IngestUrlRepository;
use Matsvep\Ingest\Adapter\KoketAdapter;
use Matsvep\Ingest\Adapter\KoketDiscovery;
use Matsvep\Ingest\Http\HttpResponse;
use Matsvep\Ingest\Http\PoliteFetcher;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KoketAdapterIntegrationTest extends TestCase
{
    public function testRealFixtureDiscoveryClassificationRetriesAndRedirects(): void
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
            $sourceQuery = $db->prepare('SELECT id FROM source WHERE adapter = ?');
            $sourceQuery->execute(['koket']);
            $sourceValue = $sourceQuery->fetchColumn();
            if (!is_numeric($sourceValue)) {
                throw new RuntimeException('Missing fixture source.');
            }
            $sourceId = (int) $sourceValue;
            $queue = new IngestUrlRepository($db, $sourceId);

            $soupUrl = $this->canonical('recipe-soup.html');
            $secondUrl = $this->canonical('recipe-2.html');
            $chefUrl = $this->canonical('chef-page.html');
            $categoryUrl = $this->canonical('category.html');
            $discovery = new KoketDiscovery();
            $categoryUrls = $discovery->category($this->fixture('category.html'), $categoryUrl)['recipes'];
            $sitemapUrls = array_slice(array_keys($discovery->sitemap(
                $this->fixture('sitemap.xml'),
                'https://www.koket.se/sitemap.xml'
            )['recipes']), 0, 3);

            $client = new FakeHttpClient();
            $client->responses = [
                'https://www.koket.se/robots.txt' => new HttpResponse(200, [], $this->fixture('robots.txt')),
                'https://www.koket.se/sitemap.xml' => new HttpResponse(200, [], $this->sitemapExcerpt($sitemapUrls)),
                $categoryUrl => new HttpResponse(200, [], $this->fixture('category.html')),
                $soupUrl => new HttpResponse(200, ['etag' => '"version-one"'], $this->fixture('recipe-soup.html')),
                $secondUrl => new HttpResponse(200, [], $this->fixture('recipe-2.html')),
                $chefUrl => new HttpResponse(200, [], $this->fixture('chef-page.html')),
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
            $adapter = new KoketAdapter($db, $sourceId, $fetcher, 2, 5, 3);
            $localDiscovery = $adapter->discover(
                'https://www.koket.se/sitemap.xml',
                [],
                $this->fixture('sitemap.xml')
            );
            self::assertSame(33, $localDiscovery['sitemap']);
            self::assertSame(0, count($client->requests));
            $db->prepare('DELETE FROM ingest_url')->execute();
            $discovered = $adapter->discover('https://www.koket.se/sitemap.xml', [$categoryUrl]);
            self::assertSame(3, $discovered['sitemap']);
            self::assertGreaterThan(20, $discovered['category']);
            self::assertSame(3 + $discovered['category'], $this->countRows($db, 'SELECT COUNT(*) FROM ingest_url'));

            $db->prepare('DELETE FROM ingest_url')->execute();
            foreach ([$soupUrl, $secondUrl, $chefUrl] as $url) {
                self::assertTrue($queue->enqueue($url, 'sitemap'));
            }
            $result = $adapter->fetchDue(10);
            self::assertSame(2, $result['fetched']);
            self::assertSame(1, $result['not_recipe']);
            self::assertSame(2, $this->countRows($db, 'SELECT COUNT(*) FROM recipe'));
            self::assertSame(2, $this->countRows($db, 'SELECT COUNT(*) FROM recipe_raw'));
            self::assertSame(3, $this->countRows($db, 'SELECT COUNT(*) FROM recipe_tag'));
            self::assertSame(0, $this->countRows($db, 'SELECT COUNT(*) FROM recipe WHERE cuisine IS NOT NULL OR meal_type IS NOT NULL'));
            self::assertGreaterThan(0, $this->countRows(
                $db,
                'SELECT COUNT(*) FROM recipe_ingredient WHERE group_name IS NOT NULL'
            ));
            self::assertGreaterThan(0, $this->countRows($db, 'SELECT COUNT(*) FROM recipe_step WHERE group_name IS NOT NULL'));
            self::assertSame('not_recipe', $this->row($db, $chefUrl)['status']);
            self::assertFalse($queue->enqueue($chefUrl, 'sitemap'));

            $aliasUrl = 'https://www.koket.se/recipes/soup-alias';
            self::assertTrue($queue->enqueue($aliasUrl, 'category'));
            $client->responses[$aliasUrl] = new HttpResponse(200, [], $this->fixture('recipe-soup.html'));
            $aliasResult = $adapter->fetchDue(10);
            self::assertSame(1, $aliasResult['fetched'], json_encode($aliasResult, JSON_THROW_ON_ERROR));
            self::assertSame(2, $this->countRows($db, 'SELECT COUNT(*) FROM recipe'));
            self::assertSame(2, $this->countRows($db, 'SELECT COUNT(*) FROM recipe_raw'));
            self::assertNotNull($queue->linkedRecipeId($aliasUrl));
            $report = (new \Matsvep\Ingest\Adapter\KoketPilotReport($db, $sourceId, 3, 5))->build();
            self::assertSame(1, $report['nested_root_duplicates']);
            self::assertIsArray($report['completeness']);
            self::assertSame(2, $report['completeness']['recipes']);
            self::assertSame(10, $report['completeness']['ingredients']['min']);
            self::assertSame(14, $report['completeness']['steps']['min']);

            $setStoredUrl = $db->prepare('UPDATE recipe SET url = ? WHERE id = ?');
            $setStoredUrl->execute([$aliasUrl, $queue->linkedRecipeId($aliasUrl)]);
            $legacyReport = (new \Matsvep\Ingest\Adapter\KoketPilotReport($db, $sourceId, 3, 5))->build();
            self::assertSame(1, $legacyReport['nested_root_duplicates']);
            $setStoredUrl->execute([$soupUrl, $queue->linkedRecipeId($aliasUrl)]);

            $db->prepare("UPDATE ingest_url SET status = 'queued', recipe_id = NULL WHERE url = ?")
                ->execute([$aliasUrl]);
            $requestsBefore = count($client->requests);
            self::assertSame(1, $adapter->fetchDue(10)['duplicate']);
            self::assertCount($requestsBefore, $client->requests);
            self::assertSame('duplicate', $this->row($db, $aliasUrl)['status']);

            $newNestedUrl = 'https://www.koket.se/recipes/new-soup-alias';
            $newCanonicalUrl = 'https://www.koket.se/new-soup-canonical';
            self::assertTrue($queue->enqueue($newNestedUrl, 'sitemap'));
            $client->responses[$newNestedUrl] = new HttpResponse(
                200,
                [],
                str_replace($soupUrl, $newCanonicalUrl, $this->fixture('recipe-soup.html'))
            );
            self::assertSame(1, $adapter->fetchDue(10)['fetched']);
            $canonicalRecipe = $db->prepare('SELECT url FROM recipe WHERE id = ?');
            $canonicalRecipe->execute([$queue->linkedRecipeId($newNestedUrl)]);
            self::assertSame($newCanonicalUrl, $canonicalRecipe->fetchColumn());
            self::assertSame($queue->linkedRecipeId($newNestedUrl), $queue->linkedRecipeId($newCanonicalUrl));
            self::assertTrue($queue->enqueue($newCanonicalUrl, 'sitemap'));
            $requestsBefore = count($client->requests);
            self::assertSame(1, $adapter->fetchDue(10)['duplicate']);
            self::assertCount($requestsBefore, $client->requests);

            $requeue = $db->prepare("UPDATE ingest_url SET status = 'queued', next_attempt_at = NULL WHERE url = ?");
            $requeue->execute([$soupUrl]);
            $client->responses[$soupUrl] = new HttpResponse(304, [], '');
            self::assertSame(1, $adapter->fetchDue(10)['unchanged']);
            self::assertSame(0, $this->attempts($db, $soupUrl));
            self::assertSame('"version-one"', $this->lastHeader($client, 'If-None-Match'));

            $retryUrl = $sitemapUrls[1];
            self::assertTrue($queue->enqueue($retryUrl, 'sitemap'));
            $client->responses[$retryUrl] = new HttpResponse(429, ['retry-after' => '10'], '');
            self::assertSame(1, $adapter->fetchDue(10)['failed']);
            self::assertSame(1, $this->attempts($db, $retryUrl));
            self::assertGreaterThanOrEqual(9, $this->retrySeconds($db, $retryUrl));
            $due = $db->prepare('UPDATE ingest_url SET next_attempt_at = UTC_TIMESTAMP(6) WHERE url = ?');
            $due->execute([$retryUrl]);
            $client->responses[$retryUrl] = new HttpResponse(503, [], '');
            self::assertSame(1, $adapter->fetchDue(10)['failed']);
            self::assertSame(2, $this->attempts($db, $retryUrl));
            self::assertGreaterThanOrEqual(3, $this->retrySeconds($db, $retryUrl));
            $due->execute([$retryUrl]);
            self::assertSame(1, $adapter->fetchDue(10)['failed']);
            self::assertSame(3, $this->attempts($db, $retryUrl));
            self::assertNull($this->row($db, $retryUrl)['next_attempt_at']);
            self::assertSame(0, array_sum($adapter->fetchDue(10)));

            $redirectUrl = $sitemapUrls[2];
            $targetUrl = $categoryUrls[0];
            self::assertTrue($queue->enqueue($redirectUrl, 'sitemap'));
            $client->responses[$redirectUrl] = new HttpResponse(301, ['location' => $targetUrl], '');
            self::assertSame(1, $adapter->fetchDue(10)['redirected']);
            self::assertSame('gone', $this->row($db, $redirectUrl)['status']);
            self::assertSame('queued', $this->row($db, $targetUrl)['status']);
            $db->prepare("UPDATE ingest_url SET status = 'not_recipe' WHERE url = ?")->execute([$targetUrl]);

            $offHostUrl = $categoryUrls[1];
            self::assertTrue($queue->enqueue($offHostUrl, 'category'));
            $client->responses[$offHostUrl] = new HttpResponse(302, ['location' => 'https://other.example/page'], '');
            self::assertSame(1, $adapter->fetchDue(10)['failed']);
            self::assertSame('failed', $this->row($db, $offHostUrl)['status']);

            $requeue->execute([$soupUrl]);
            $client->responses[$soupUrl] = new HttpResponse(404, [], '');
            self::assertSame(1, $adapter->fetchDue(10)['gone']);
            self::assertSame(1, $this->countRows($db, "SELECT COUNT(*) FROM recipe WHERE source_status = 'source_gone'"));
        } finally {
            $admin->exec("DROP DATABASE IF EXISTS `$databaseName`");
        }
    }

    /** @param list<string> $urls */
    private function sitemapExcerpt(array $urls): string
    {
        $items = array_map(
            static fn (string $url): string => '<url><loc>' . htmlspecialchars($url, ENT_XML1) . '</loc></url>',
            $urls,
        );
        return '<urlset>' . implode('', $items) . '</urlset>';
    }

    /** @return array<string, mixed> */
    private function row(PDO $db, string $url): array
    {
        $query = $db->prepare('SELECT status, attempts, next_attempt_at FROM ingest_url WHERE url = ?');
        $query->execute([$url]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    private function attempts(PDO $db, string $url): int
    {
        $value = $this->row($db, $url)['attempts'] ?? null;
        if (!is_numeric($value)) {
            throw new RuntimeException('Invalid attempt count.');
        }
        return (int) $value;
    }

    private function retrySeconds(PDO $db, string $url): int
    {
        $query = $db->prepare('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(6), next_attempt_at) FROM ingest_url WHERE url = ?');
        $query->execute([$url]);
        $value = $query->fetchColumn();
        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('Missing retry delay.');
        }
        return (int) $value;
    }

    private function countRows(PDO $db, string $sql): int
    {
        $query = $db->prepare($sql);
        $query->execute();
        return (int) $query->fetchColumn();
    }

    private function lastHeader(FakeHttpClient $client, string $name): ?string
    {
        foreach (array_reverse($client->requests) as $request) {
            if (isset($request['headers'][$name])) {
                return $request['headers'][$name];
            }
        }
        return null;
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
