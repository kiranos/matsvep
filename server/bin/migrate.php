<?php

declare(strict_types=1);

use Matsvep\Database\MigrationRunner;
use Matsvep\Ingest\Ingredient\DatabaseConnection;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $envFile = getenv('MATS_ENV_FILE') ?: __DIR__ . '/../.env';
    $fileConfig = [];
    if (is_file($envFile)) {
        $fileConfig = parse_ini_file($envFile, false, INI_SCANNER_RAW);
        if ($fileConfig === false) {
            throw new RuntimeException('Cannot read database configuration.');
        }
    }

    $config = [];
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
        $fromEnvironment = getenv($key);
        $value = $fromEnvironment !== false ? $fromEnvironment : ($fileConfig[$key] ?? '');
        if (!is_string($value)) {
            throw new RuntimeException("Invalid $key in database configuration.");
        }
        $config[$key] = $value;
    }
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER'] as $key) {
        if ($config[$key] === '') {
            throw new RuntimeException("Missing $key in environment or server/.env.");
        }
    }
    if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $config['DB_HOST'])) {
        throw new RuntimeException('DB_HOST must be a hostname or IP address.');
    }
    if (!ctype_digit($config['DB_PORT']) || (int) $config['DB_PORT'] < 1 || (int) $config['DB_PORT'] > 65535) {
        throw new RuntimeException('DB_PORT must be between 1 and 65535.');
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $config['DB_NAME'])) {
        throw new RuntimeException('DB_NAME must be a database identifier.');
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
    DatabaseConnection::setUtc($db);
    $runner = new MigrationRunner($db, __DIR__ . '/../migrations', $config['DB_NAME']);
    foreach ($runner->run() as $line) {
        echo $line, PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
