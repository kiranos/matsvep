<?php

declare(strict_types=1);

use Matsvep\Engine\EngineConfig;
use Matsvep\Engine\PlanningMaintenance;
use Matsvep\Ingest\Ingredient\DatabaseConnection;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $db = DatabaseConnection::fromEnvironment(dirname(__DIR__));
    $counts = (new PlanningMaintenance($db, EngineConfig::fromDatabase($db)))->run();
    echo json_encode($counts, JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Planning maintenance failed: ' . get_class($error) . PHP_EOL);
    exit(1);
}
