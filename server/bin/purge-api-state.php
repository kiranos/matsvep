<?php

declare(strict_types=1);

use Matsvep\Api\ApiStatePurger;
use Matsvep\Engine\EngineConfig;
use Matsvep\Ingest\Ingredient\DatabaseConnection;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $db = DatabaseConnection::fromEnvironment(dirname(__DIR__));
    $counts = (new ApiStatePurger($db, EngineConfig::fromDatabase($db)))->purge();
    echo json_encode($counts, JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'API state purge failed: ' . get_class($error) . PHP_EOL);
    exit(1);
}
