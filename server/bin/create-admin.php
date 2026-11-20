<?php

declare(strict_types=1);

use Matsvep\Ingest\Ingredient\DatabaseConnection;

require __DIR__ . '/../vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? null;
if (PHP_SAPI !== 'cli' || !is_array($arguments) || count($arguments) !== 2
    || !is_string($arguments[1] ?? null) || filter_var($arguments[1], FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php bin/create-admin.php email@example.com\n");
    exit(1);
}

$hidden = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec');
try {
    fwrite(STDERR, 'Admin password: ');
    if ($hidden) {
        shell_exec('stty -echo');
    }
    $password = rtrim((string) fgets(STDIN), "\r\n");
} finally {
    if ($hidden) {
        shell_exec('stty echo');
        fwrite(STDERR, "\n");
    }
}
$db = DatabaseConnection::fromEnvironment(dirname(__DIR__));
$lengthQuery = $db->prepare('SELECT value FROM engine_config WHERE `key` = ?');
$lengthQuery->execute(['admin.password_min_length']);
$minimum = (int) $lengthQuery->fetchColumn();
if ($minimum < 1 || mb_strlen($password) < $minimum) {
    fwrite(STDERR, "Password is too short.\n");
    exit(1);
}
$query = $db->prepare('INSERT INTO `user` (email, password_hash) VALUES (?, ?) ' .
    'ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), failed_attempts = 0, locked_until = NULL');
$query->execute([$arguments[1], password_hash($password, PASSWORD_DEFAULT)]);
unset($password);
$find = $db->prepare('SELECT id FROM `user` WHERE email = ?');
$find->execute([$arguments[1]]);
$id = $find->fetchColumn();
if (!is_numeric($id)) {
    throw new RuntimeException('Cannot find saved admin account.');
}
$audit = $db->prepare('INSERT INTO admin_audit (user_id, action, target_type, target_id) VALUES (?, ?, ?, ?)');
$audit->execute([(int) $id, 'auth.account_saved', 'user', (int) $id]);
fwrite(STDOUT, "Admin account saved.\n");
