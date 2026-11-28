<?php

declare(strict_types=1);

use Matsvep\Database\MigrationRunner;
use Matsvep\Ingest\Ingredient\DatabaseConnection;
use Matsvep\Simulator\CatalogueClone;
use Matsvep\Simulator\PersonaSimulation;
use Matsvep\Simulator\SimulatorSettings;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$source = null;
$admin = null;
$guard = null;
$database = null;
$createdDatabase = false;
$exitCode = 0;
try {
    $options = ['persona' => 'all', 'weeks' => '52', 'seed' => null];
    /** @var list<string> $arguments */
    $arguments = $_SERVER['argv'] ?? [];
    foreach (array_slice($arguments, 1) as $argument) {
        if (preg_match('/^--(persona|weeks|seed)=(.+)$/', $argument, $parts) !== 1
            || !array_key_exists($parts[1], $options)) {
            throw new RuntimeException('Usage: php simulator/run.php --persona=all --weeks=52 --seed=N');
        }
        $options[$parts[1]] = $parts[2];
    }
    if (!is_string($options['seed']) || preg_match('/^[0-9]+$/', $options['seed']) !== 1
        || preg_match('/^[1-9][0-9]*$/', $options['weeks']) !== 1) {
        throw new RuntimeException('A positive --weeks and numeric --seed are required.');
    }
    $envFile = getenv('MATS_SIM_ENV_FILE') ?: __DIR__ . '/../.env.simulator';
    $settings = is_file($envFile) ? parse_ini_file($envFile, false, INI_SCANNER_RAW) : false;
    if (!is_array($settings)) {
        throw new RuntimeException('Copy server/.env.simulator.example to uncommitted server/.env.simulator.');
    }
    foreach (['SOURCE_HOST', 'SOURCE_PORT', 'SOURCE_DB', 'SOURCE_USER', 'SOURCE_PASSWORD',
        'TARGET_HOST', 'TARGET_PORT', 'TARGET_USER', 'TARGET_PASSWORD', 'TARGET_DB_PREFIX'] as $key) {
        if (!is_string($settings[$key] ?? null)) {
            throw new RuntimeException('Missing simulator configuration key: ' . $key);
        }
    }
    foreach (['SOURCE_HOST', 'SOURCE_DB', 'SOURCE_USER', 'TARGET_HOST', 'TARGET_USER'] as $key) {
        if ($settings[$key] === '') {
            throw new RuntimeException('Empty simulator configuration key: ' . $key);
        }
    }
    foreach (['SOURCE_PORT', 'TARGET_PORT'] as $key) {
        $port = $settings[$key] ?? null;
        if (!is_string($port) || preg_match('/^[0-9]+$/', $port) !== 1
            || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('Invalid simulator port: ' . $key);
        }
    }
    foreach (['SOURCE_DB', 'TARGET_DB_PREFIX'] as $key) {
        $name = $settings[$key] ?? null;
        if (!is_string($name) || preg_match('/^[a-zA-Z0-9_]+$/', $name) !== 1) {
            throw new RuntimeException('Invalid simulator database name: ' . $key);
        }
    }
    if ($settings['SOURCE_USER'] === 'matsvep_app' || $settings['TARGET_USER'] === 'matsvep_app') {
        throw new RuntimeException('The simulator must not use the matsvep_app account.');
    }
    $profiles = json_decode(
        (string) file_get_contents(__DIR__ . '/../seeds/simulator_personas.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($profiles) || !array_is_list($profiles)) {
        throw new RuntimeException('Invalid simulator persona data.');
    }
    $selected = [];
    foreach ($profiles as $profile) {
        if (!is_array($profile)) {
            throw new RuntimeException('Invalid simulator persona data.');
        }
        /** @var array<string,mixed> $profile */
        if ($options['persona'] === 'all' || ($profile['code'] ?? null) === $options['persona']) {
            $selected[] = $profile;
        }
    }
    if ($selected === []) {
        throw new RuntimeException('Unknown simulator persona.');
    }
    $pdoOptions = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false, \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false];
    $sourceDsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $settings['SOURCE_HOST'],
        $settings['SOURCE_PORT'],
        $settings['SOURCE_DB']
    );
    $source = new PDO($sourceDsn, $settings['SOURCE_USER'], $settings['SOURCE_PASSWORD'], $pdoOptions);
    DatabaseConnection::setUtc($source);
    $targetDsn = sprintf(
        'mysql:host=%s;port=%s;charset=utf8mb4',
        $settings['TARGET_HOST'],
        $settings['TARGET_PORT']
    );
    $admin = new PDO($targetDsn, $settings['TARGET_USER'], $settings['TARGET_PASSWORD'], $pdoOptions);
    DatabaseConnection::setUtc($admin);
    foreach ([$source, $admin] as $connection) {
        $account = $connection->prepare('SELECT CURRENT_USER()');
        $account->execute();
        if (str_starts_with((string) $account->fetchColumn(), 'matsvep_app@')) {
            throw new RuntimeException('The simulator must not use the matsvep_app account.');
        }
    }
    $simulatorSettings = new SimulatorSettings(__DIR__ . '/../seeds/simulator_config.json');
    $batchRows = $simulatorSettings->integer('simulator.clone_batch_rows');
    $guard = new CatalogueClone($source, $admin, $batchRows);
    $guard->acquireCrawlerLock();
    $database = $settings['TARGET_DB_PREFIX'] . '_' . bin2hex(random_bytes(6));
    if (strlen($database) > 64) {
        throw new RuntimeException('Simulator database name is too long.');
    }
    $create = $admin->prepare('CREATE DATABASE `' . $database
        . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_sv_0900_ai_ci');
    $create->execute();
    $createdDatabase = true;
    $target = new PDO(
        $targetDsn . ';dbname=' . $database,
        $settings['TARGET_USER'],
        $settings['TARGET_PASSWORD'],
        $pdoOptions
    );
    DatabaseConnection::setUtc($target);
    (new MigrationRunner($target, __DIR__ . '/../migrations', $database))->run();
    $copyStarted = hrtime(true);
    $counts = (new CatalogueClone($source, $target, $batchRows))->copy();
    $copyMs = round((hrtime(true) - $copyStarted) / 1_000_000, 3);
    $guard->releaseCrawlerLock();
    $guard = null;
    $simulatorSettings->seed($target);
    $simulation = (new PersonaSimulation($target))->run($selected, (int) $options['weeks'], (int) $options['seed']);
    $simulation['catalogue_counts'] = $counts;
    $simulation['catalogue_copy_ms'] = $copyMs;
    $reportDirectory = __DIR__ . '/../var';
    if (!is_dir($reportDirectory) && !mkdir($reportDirectory, 0750, true)) {
        throw new RuntimeException('Cannot create simulator report directory.');
    }
    $report = $reportDirectory . '/simulator-report.json';
    $temporary = $report . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, json_encode($simulation, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false
        || !rename($temporary, $report)) {
        throw new RuntimeException('Cannot write simulator report.');
    }
    echo 'Simulator report: ' . $report, PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Simulator failed: ' . $error->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    try {
        if ($guard instanceof CatalogueClone) {
            $guard->releaseCrawlerLock();
        }
        if ($admin instanceof PDO && $createdDatabase && is_string($database)) {
            $drop = $admin->prepare('DROP DATABASE IF EXISTS `' . $database . '`');
            $drop->execute();
        }
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, 'Simulator cleanup failed: ' . get_class($cleanupError) . PHP_EOL);
        $exitCode = 1;
    }
}
exit($exitCode);
