<?php

declare(strict_types=1);

use Matsvep\Ingest\Feature\FeatureDeriver;
use Matsvep\Ingest\Feature\FeatureDiagnosticSample;
use Matsvep\Ingest\Feature\FeatureReport;
use Matsvep\Ingest\Feature\RecipeTagReport;
use Matsvep\Ingest\Ingredient\DatabaseConnection;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $arguments = $_SERVER['argv'] ?? null;
    $usage = 'Usage: php bin/derive-features.php [--all|--recipe=ID|--report|--tag-report|--protein-gap-sample=N|--vegetarian-sample=N] [--seed=N]';
    if (!is_array($arguments) || count($arguments) > 3 || !is_string($arguments[1] ?? '--report')) {
        throw new RuntimeException($usage);
    }
    $option = $arguments[1] ?? '--report';
    $seedOption = $arguments[2] ?? null;
    if ($option === '--tag-report' && $seedOption === null) {
        $db = DatabaseConnection::fromEnvironment(dirname(__DIR__));
        fwrite(STDOUT, json_encode(
            (new RecipeTagReport($db))->build(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . PHP_EOL);
        exit(0);
    }
    $sample = preg_match('/^--(protein-gap|vegetarian)-sample=([1-9][0-9]*)$/', $option, $match) === 1;
    if ($sample) {
        if ($seedOption !== null && (!is_string($seedOption)
            || preg_match('/^--seed=([0-9]+)$/', $seedOption, $seedMatch) !== 1)) {
            throw new RuntimeException($usage);
        }
        $limit = (int) $match[2];
        $seed = $seedOption === null ? random_int(0, PHP_INT_MAX) : filter_var(
            $seedMatch[1],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );
        if ($limit < 1 || $limit > 100 || $seed === false) {
            throw new RuntimeException($usage);
        }
        $db = DatabaseConnection::fromEnvironment(dirname(__DIR__));
        $result = (new FeatureDiagnosticSample($db))->build(
            $match[1] === 'protein-gap' ? 'protein_gap' : 'vegetarian',
            $limit,
            $seed,
        );
        fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }
    if (!in_array($option, ['--all', '--report'], true)
        && preg_match('/^--recipe=[1-9][0-9]*$/', $option) !== 1 || $seedOption !== null) {
        throw new RuntimeException($usage);
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
