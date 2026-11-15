<?php

declare(strict_types=1);

use Matsvep\Ingest\Adapter\KoketAdapter;
use Matsvep\Ingest\Http\PoliteFetcher;
use Matsvep\Ingest\Http\StreamHttpClient;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

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
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
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
    $delayQuery = $db->prepare('SELECT value FROM engine_config WHERE `key` = ?');
    $delayQuery->execute(['crawl.delay_s']);
    $configuredDelay = $delayQuery->fetchColumn();
    if (!is_string($configuredDelay) || !ctype_digit($configuredDelay)) {
        throw new RuntimeException('Invalid crawl.delay_s configuration.');
    }
    $sourceDelay = $source['crawl_delay_s'] ?? null;
    $delay = max((int) $configuredDelay, is_numeric($sourceDelay) ? (int) $sourceDelay : 0);
    $fetcher = new PoliteFetcher(new StreamHttpClient(), $source['base_url'], $config['CRAWL_USER_AGENT'], $delay);
    $adapter = new KoketAdapter($db, (int) $source['id'], $fetcher, $delay);

    $lockQuery = $db->prepare('SELECT GET_LOCK(?, 0)');
    $lockQuery->execute(['matsvep:crawl:koket']);
    if ((int) $lockQuery->fetchColumn() !== 1) {
        throw new RuntimeException('Another Koket crawler is active.');
    }
    try {
        $command = $argv[1] ?? '';
        if ($command === 'discover') {
            $categories = array_slice($argv, 2);
            if ($categories === []) {
                $categories = [rtrim($source['base_url'], '/') . '/recept'];
            }
            $result = $adapter->discover(rtrim($source['base_url'], '/') . '/sitemap.xml', $categories);
        } elseif ($command === 'fetch') {
            $limit = $argv[2] ?? '';
            if (!ctype_digit($limit) || (int) $limit < 1) {
                throw new RuntimeException('Usage: php bin/crawl-koket.php fetch <positive-limit>');
            }
            $result = $adapter->fetchDue((int) $limit);
        } else {
            throw new RuntimeException('Usage: php bin/crawl-koket.php discover [category-urls...] | fetch <limit>');
        }
        echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute(['matsvep:crawl:koket']);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Crawler failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
