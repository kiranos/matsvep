<?php

declare(strict_types=1);

use Matsvep\Ingest\Adapter\KoketAdapter;
use Matsvep\Ingest\Adapter\KoketCrawlRunner;
use Matsvep\Ingest\Adapter\KoketPilotReport;
use Matsvep\Ingest\Http\PoliteFetcher;
use Matsvep\Ingest\Http\StreamHttpClient;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$rawArguments = $_SERVER['argv'] ?? null;
if (!is_array($rawArguments)) {
    throw new RuntimeException('Missing CLI arguments.');
}
$argv = array_map(static function (mixed $argument): string {
    if (!is_string($argument)) {
        throw new RuntimeException('Invalid CLI argument.');
    }

    return $argument;
}, array_values($rawArguments));
$argc = count($argv);

try {
    $envFile = getenv('MATS_ENV_FILE') ?: __DIR__ . '/../.env';
    $fileConfig = is_file($envFile) ? parse_ini_file($envFile, false, INI_SCANNER_RAW) : [];
    if (!is_array($fileConfig)) {
        throw new RuntimeException('Cannot read crawler configuration.');
    }
    $config = [];
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'CRAWL_USER_AGENT'] as $key) {
        $fromEnvironment = getenv($key);
        $value = $fromEnvironment !== false ? $fromEnvironment : ($fileConfig[$key] ?? '');
        if (!is_string($value)) {
            throw new RuntimeException("Invalid $key in crawler configuration.");
        }
        $config[$key] = $value;
    }
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'CRAWL_USER_AGENT'] as $key) {
        if ($config[$key] === '') {
            throw new RuntimeException("Missing $key in crawler configuration.");
        }
    }
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $config['DB_HOST'])
        || !ctype_digit($config['DB_PORT']) || (int) $config['DB_PORT'] < 1
        || (int) $config['DB_PORT'] > 65535
        || !preg_match('/^[A-Za-z0-9_]+$/', $config['DB_NAME'])) {
        throw new RuntimeException('Invalid database host, port or name.');
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['DB_HOST'],
        $config['DB_PORT'],
        $config['DB_NAME'],
    );
    $db = new PDO($dsn, $config['DB_USER'], $config['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false,
    ]);

    $sourceQuery = $db->prepare(
        "SELECT id, base_url, crawl_delay_s FROM source WHERE adapter = 'koket' AND enabled = TRUE"
    );
    $sourceQuery->execute();
    $source = $sourceQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($source) || !is_numeric($source['id'] ?? null)
        || !is_string($source['base_url'] ?? null)) {
        throw new RuntimeException('Enabled Koket source is missing.');
    }
    $settingQuery = $db->prepare('SELECT value FROM engine_config WHERE `key` = ?');
    $settings = [];
    foreach (['crawl.delay_s', 'crawl.retry_base_s', 'crawl.retry_max_s', 'crawl.max_attempts',
        'crawl.timeout_s', 'crawl.discovery_max_age_days', 'crawl.batch_size', 'crawl.max_retry_wait_s'] as $key) {
        $settingQuery->execute([$key]);
        $value = $settingQuery->fetchColumn();
        if (!is_string($value) || !ctype_digit($value)
            || ((int) $value < 1 && $key !== 'crawl.max_retry_wait_s')) {
            throw new RuntimeException("Invalid $key configuration.");
        }
        $settings[$key] = (int) $value;
    }
    $sourceDelay = $source['crawl_delay_s'] ?? null;
    $delay = max($settings['crawl.delay_s'], is_numeric($sourceDelay) ? (int) $sourceDelay : 0);
    $fetcher = new PoliteFetcher(
        new StreamHttpClient($settings['crawl.timeout_s']),
        $source['base_url'],
        $config['CRAWL_USER_AGENT'],
        $delay,
    );
    $adapter = new KoketAdapter(
        $db,
        (int) $source['id'],
        $fetcher,
        $settings['crawl.retry_base_s'],
        $settings['crawl.retry_max_s'],
        $settings['crawl.max_attempts'],
    );

    $lockQuery = $db->prepare('SELECT GET_LOCK(?, 0)');
    $lockQuery->execute(['matsvep:crawl:koket']);
    if ((int) $lockQuery->fetchColumn() !== 1) {
        throw new RuntimeException('Another Koket crawler is active.');
    }
    try {
        $command = $argv[1] ?? '';
        if ($command === 'discover') {
            $categories = [];
            $sampleSize = null;
            $sitemapFile = null;
            for ($index = 2; $index < $argc; $index++) {
                $argument = $argv[$index];
                if ($argument === '--sample' && isset($argv[$index + 1])) {
                    $value = $argv[++$index];
                    if (!ctype_digit($value) || (int) $value < 1) {
                        throw new RuntimeException('--sample requires a positive integer.');
                    }
                    $sampleSize = (int) $value;
                } elseif ($argument === '--sitemap-file' && isset($argv[$index + 1])) {
                    $sitemapFile = $argv[++$index];
                } elseif (str_starts_with($argument, '--')) {
                    throw new RuntimeException("Unknown discover option: $argument");
                } else {
                    $categories[] = $argument;
                }
            }
            $sitemapUrl = rtrim($source['base_url'], '/') . '/sitemap.xml';
            if ($sampleSize !== null) {
                if ($categories !== []) {
                    throw new RuntimeException('Sample discovery does not accept category URLs.');
                }
                if ($sitemapFile !== null) {
                    $xml = file_get_contents($sitemapFile);
                    if ($xml === false) {
                        throw new RuntimeException('Cannot read the local sitemap file.');
                    }
                } else {
                    $response = $fetcher->get($sitemapUrl);
                    if ($response->status !== 200) {
                        throw new RuntimeException("Sitemap fetch failed with HTTP {$response->status}.");
                    }
                    $xml = $response->body;
                }
                $result = $adapter->discoverSample($xml, $sitemapUrl, $sampleSize);
            } else {
                $xml = null;
                if ($sitemapFile !== null) {
                    $xml = file_get_contents($sitemapFile);
                    if ($xml === false) {
                        throw new RuntimeException('Cannot read the local sitemap file.');
                    }
                }
                $result = $adapter->discover($sitemapUrl, $categories, $xml);
                $mark = $db->prepare(
                    'INSERT INTO crawl_discovery (source_id, sitemap_hash, completed_at) ' .
                    'VALUES (?, UNHEX(?), UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE ' .
                    'sitemap_hash = VALUES(sitemap_hash), completed_at = VALUES(completed_at)'
                );
                $mark->execute([(int) $source['id'], $result['sitemap_hash']]);
            }
        } elseif ($command === 'fetch') {
            $limit = $argv[2] ?? '';
            if (!ctype_digit($limit) || (int) $limit < 1) {
                throw new RuntimeException('Usage: php bin/crawl-koket.php fetch <positive-limit>');
            }
            $result = $adapter->fetchDue((int) $limit);
        } elseif ($command === 'report' || $command === 'progress') {
            $result = (new KoketPilotReport(
                $db,
                (int) $source['id'],
                $settings['crawl.max_attempts'],
                $delay,
            ))->build($command === 'report');
        } elseif ($command === 'crawl') {
            if ($argc !== 4 || ($argv[2] ?? '') !== '--hours' || !is_numeric($argv[3])
                || (float) $argv[3] <= 0) {
                throw new RuntimeException('Usage: php bin/crawl-koket.php crawl --hours <positive-number>');
            }
            if (!function_exists('pcntl_async_signals')) {
                throw new RuntimeException('The crawl command requires the PHP pcntl extension.');
            }
            $stop = false;
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function () use (&$stop): void {
                $stop = true;
            });
            pcntl_signal(SIGTERM, static function () use (&$stop): void {
                $stop = true;
            });
            $result = (new KoketCrawlRunner(
                $db,
                (int) $source['id'],
                $adapter,
                new KoketPilotReport($db, (int) $source['id'], $settings['crawl.max_attempts'], $delay),
                rtrim($source['base_url'], '/') . '/sitemap.xml',
                __DIR__ . '/../var/crawl-report.json',
                $settings['crawl.discovery_max_age_days'],
                $settings['crawl.batch_size'],
                $settings['crawl.max_retry_wait_s'],
                $delay,
                null,
                null,
                static function () use (&$stop): bool {
                    return $stop;
                },
            ))->run((float) $argv[3]);
        } else {
            throw new RuntimeException(
                'Usage: php bin/crawl-koket.php discover [--sample N] [--sitemap-file path] [category-urls...] ' .
                '| fetch <limit> | report | progress | crawl --hours N'
            );
        }
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute(['matsvep:crawl:koket']);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Crawler failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
