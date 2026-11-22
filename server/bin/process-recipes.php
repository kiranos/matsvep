<?php

declare(strict_types=1);

use Matsvep\Ingest\Ingredient\DatabaseConnection;
use Matsvep\Ingest\Processing\RecipeProcessor;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $arguments = $_SERVER['argv'] ?? null;
    if (!is_array($arguments) || count($arguments) !== 1) {
        throw new RuntimeException('Usage: php bin/process-recipes.php');
    }
    $server = dirname(__DIR__);
    $result = (new RecipeProcessor(
        DatabaseConnection::fromEnvironment($server),
        $server . '/seeds',
        $server . '/var',
    ))->run();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Recipe processing failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
