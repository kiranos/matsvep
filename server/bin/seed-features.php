<?php

declare(strict_types=1);

use Matsvep\Ingest\Feature\FeatureSeeder;
use Matsvep\Ingest\Ingredient\DatabaseConnection;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $db = DatabaseConnection::fromEnvironment(dirname(__DIR__));
    $count = (new FeatureSeeder($db))->seed(__DIR__ . '/../seeds');
    fwrite(STDOUT, sprintf("Seeded %d feature keywords.\n", $count));
} catch (Throwable $error) {
    fwrite(STDERR, 'Feature seeding failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
