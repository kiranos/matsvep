<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Ingredient;

use PDO;
use RuntimeException;

final class DatabaseConnection
{
    public static function fromEnvironment(string $serverRoot): PDO
    {
        $envFile = getenv('MATS_ENV_FILE') ?: $serverRoot . '/.env';
        $fileConfig = is_file($envFile) ? parse_ini_file($envFile, false, INI_SCANNER_RAW) : [];
        if (!is_array($fileConfig)) {
            throw new RuntimeException('Cannot read database configuration.');
        }
        $config = [];
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
            $environment = getenv($key);
            $value = $environment !== false ? $environment : ($fileConfig[$key] ?? '');
            if (!is_string($value)) {
                throw new RuntimeException("Invalid $key in database configuration.");
            }
            $config[$key] = $value;
        }
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $config['DB_HOST'])
            || !ctype_digit($config['DB_PORT']) || (int) $config['DB_PORT'] < 1
            || (int) $config['DB_PORT'] > 65535
            || !preg_match('/^[A-Za-z0-9_]+$/', $config['DB_NAME'])
            || $config['DB_USER'] === '') {
            throw new RuntimeException('Invalid database connection settings.');
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
        self::setUtc($db);
        return $db;
    }

    public static function setUtc(PDO $db): void
    {
        $db->prepare("SET time_zone = '+00:00'")->execute();
    }
}
