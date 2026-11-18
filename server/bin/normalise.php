<?php

declare(strict_types=1);

use Matsvep\Ingest\Ingredient\DatabaseConnection;
use Matsvep\Ingest\Ingredient\IngredientLineParser;
use Matsvep\Ingest\Ingredient\IngredientNormaliser;
use Matsvep\Ingest\Ingredient\IngredientReport;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $rawArguments = $_SERVER['argv'] ?? null;
    if (!is_array($rawArguments)) {
        throw new RuntimeException('Missing CLI arguments.');
    }
    $arguments = array_map(static function (mixed $argument): string {
        if (!is_string($argument)) {
            throw new RuntimeException('Invalid CLI argument.');
        }
        return $argument;
    }, array_values($rawArguments));
    $option = $arguments[1] ?? '--report';
    if (count($arguments) > 2 || !in_array($option, ['--all', '--report'], true)
        && !preg_match('/^--recipe=[1-9][0-9]*$/', $option)) {
        throw new RuntimeException('Usage: php bin/normalise.php [--recipe=ID|--all|--report]');
    }
    $db = DatabaseConnection::fromEnvironment(dirname(__DIR__));
    if ($option !== '--report') {
        $recipeId = str_starts_with($option, '--recipe=') ? (int) substr($option, 9) : null;
        $normaliser = new IngredientNormaliser($db, new IngredientLineParser(__DIR__ . '/../seeds'));
        $result = $normaliser->run($recipeId);
        fwrite(STDERR, sprintf(
            "Normalised %d lines; %d matched; %d unsafe splits.\n",
            $result['lines'],
            $result['matched'],
            $result['unsafe_split'],
        ));
    }
    echo json_encode((new IngredientReport($db))->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    echo PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Ingredient normalisation failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
