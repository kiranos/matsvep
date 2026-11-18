<?php

declare(strict_types=1);

use Matsvep\Ingest\Ingredient\DatabaseConnection;
use Matsvep\Ingest\Ingredient\IngredientSeeder;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $db = DatabaseConnection::fromEnvironment(dirname(__DIR__));
    $count = (new IngredientSeeder($db))->seed(__DIR__ . '/../seeds/ingredients.csv');
    echo "Seeded $count ingredients.", PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Ingredient seed failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
