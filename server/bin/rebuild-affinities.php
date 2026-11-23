<?php

declare(strict_types=1);

use Matsvep\Engine\EngineConfig;
use Matsvep\Engine\Learner;
use Matsvep\Ingest\Ingredient\DatabaseConnection;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $arguments = $_SERVER['argv'] ?? null;
    if (!is_array($arguments) || count($arguments) !== 1) {
        throw new RuntimeException('Usage: php bin/rebuild-affinities.php');
    }
    $db = DatabaseConnection::fromEnvironment(dirname(__DIR__));
    $lock = $db->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute(['matsvep:crawl:koket']);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Koket crawler or recipe processing is active.');
    }
    try {
        $result = (new Learner($db, EngineConfig::fromDatabase($db)))->rebuildAll();
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute(['matsvep:crawl:koket']);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Affinity rebuild failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
