<?php

declare(strict_types=1);

use Matsvep\Database\MigrationRunner;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $envFile = __DIR__ . '/../.env';
    $config = is_file($envFile) ? parse_ini_file($envFile, false, INI_SCANNER_RAW) : false;
    if (!is_array($config)) {
        throw new RuntimeException('Create server/.env from .env.example before running the pilot.');
    }
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'CRAWL_USER_AGENT'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key])) {
            throw new RuntimeException("Missing $key in server/.env.");
        }
    }
    if (!in_array($config['DB_HOST'], ['localhost', '127.0.0.1'], true)
        || !ctype_digit($config['DB_PORT']) || (int) $config['DB_PORT'] < 1
        || (int) $config['DB_PORT'] > 65535
        || preg_match('/^[A-Za-z0-9_]+_pilot$/', $config['DB_NAME']) !== 1
        || $config['DB_USER'] === '' || $config['CRAWL_USER_AGENT'] === '') {
        throw new RuntimeException('Pilot requires a local MySQL host, valid port, *_pilot database, user and crawler agent.');
    }

    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['DB_HOST'], $config['DB_PORT']);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false,
    ];
    $admin = new PDO($dsn, $config['DB_USER'], $config['DB_PASSWORD'], $options);
    $databaseName = $config['DB_NAME'];
    $create = $admin->prepare(
        "CREATE DATABASE IF NOT EXISTS `$databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci"
    );
    $create->execute();
    $db = new PDO($dsn . ';dbname=' . $databaseName, $config['DB_USER'], $config['DB_PASSWORD'], $options);
    $migrations = (new MigrationRunner($db, __DIR__ . '/../migrations', $databaseName))->run();
    $enable = $db->prepare("UPDATE source SET enabled = TRUE WHERE adapter = 'koket' AND base_url = ?");
    $enable->execute(['https://www.koket.se']);
    $check = $db->prepare("SELECT COUNT(*) FROM source WHERE adapter = 'koket' AND base_url = ? AND enabled = TRUE");
    $check->execute(['https://www.koket.se']);
    if ((int) $check->fetchColumn() !== 1) {
        throw new RuntimeException('Koket source was not enabled in the pilot database.');
    }
    echo json_encode(['database' => $databaseName, 'migrations' => $migrations], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Pilot setup failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
