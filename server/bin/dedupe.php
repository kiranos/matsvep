<?php

declare(strict_types=1);

use Matsvep\Ingest\Dedupe\DedupeReport;
use Matsvep\Ingest\Dedupe\DishGrouper;
use Matsvep\Ingest\Dedupe\RecipeDeduplicator;
use Matsvep\Ingest\Ingredient\DatabaseConnection;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $arguments = $_SERVER['argv'] ?? null;
    if (!is_array($arguments) || count($arguments) > 2 || !is_string($arguments[1] ?? '--report')) {
        throw new RuntimeException('Usage: php bin/dedupe.php [--all|--report]');
    }
    $option = $arguments[1] ?? '--report';
    if (!in_array($option, ['--all', '--report'], true)) {
        throw new RuntimeException('Usage: php bin/dedupe.php [--all|--report]');
    }
    $db = DatabaseConnection::fromEnvironment(dirname(__DIR__));
    if ($option === '--all') {
        $copies = (new RecipeDeduplicator($db))->run();
        $grouped = (new DishGrouper($db, __DIR__ . '/../seeds'))->run();
        fwrite(STDERR, sprintf(
            "Merged %d copies; queued %d review pairs; assigned %d group memberships.\n",
            $copies['merged'],
            $copies['queued_for_review'],
            $grouped,
        ));
    }
    $path = __DIR__ . '/../var/dedupe-report.json';
    (new DedupeReport($db))->save($path);
    fwrite(STDOUT, 'Deduplication report saved to ' . realpath($path) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Deduplication failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
