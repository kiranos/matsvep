<?php

declare(strict_types=1);

use Matsvep\Ingest\Feature\FeatureDeriver;
use Matsvep\Ingest\Feature\FeatureReport;
use Matsvep\Ingest\Ingredient\DatabaseConnection;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $arguments = $_SERVER['argv'] ?? null;
    if (!is_array($arguments) || count($arguments) > 2 || !is_string($arguments[1] ?? '--report')) {
        throw new RuntimeException('Usage: php bin/derive-features.php [--all|--recipe=ID|--report]');
    }
    $option = $arguments[1] ?? '--report';
    if (!in_array($option, ['--all', '--report'], true)
        && preg_match('/^--recipe=[1-9][0-9]*$/', $option) !== 1) {
        throw new RuntimeException('Usage: php bin/derive-features.php [--all|--recipe=ID|--report]');
    }
    $db = DatabaseConnection::fromEnvironment(dirname(__DIR__));
    if ($option !== '--report') {
        $recipeId = str_starts_with($option, '--recipe=') ? (int) substr($option, 9) : null;
        $count = (new FeatureDeriver($db, __DIR__ . '/../seeds'))->run($recipeId);
        fwrite(STDERR, sprintf("Derived features for %d recipes.\n", $count));
    }
    $path = __DIR__ . '/../var/feature-report.json';
    (new FeatureReport($db))->save($path);
    fwrite(STDOUT, 'Feature report saved to ' . realpath($path) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Feature derivation failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
