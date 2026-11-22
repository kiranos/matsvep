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
$find = $db->prepare('SELECT id, role FROM `user` WHERE email = ?');
$find->execute([$arguments[1]]);
$existing = $find->fetch(PDO::FETCH_ASSOC);
if (is_array($existing) && $existing['role'] !== 'admin') {
    throw new RuntimeException('A member account cannot be used as an admin account.');
}
$hash = password_hash($password, PASSWORD_DEFAULT);
unset($password);
if (is_array($existing)) {
    $id = $existing['id'];
    $query = $db->prepare('UPDATE `user` SET password_hash = ?, failed_attempts = 0, locked_until = NULL '
        . "WHERE id = ? AND role = 'admin'");
    $query->execute([$hash, $id]);
    if ($query->rowCount() !== 1) {
        throw new RuntimeException('Cannot update admin account.');
    }
} else {
    $query = $db->prepare("INSERT INTO `user` (email, password_hash, role) VALUES (?, ?, 'admin')");
    $query->execute([$arguments[1], $hash]);
    $id = $db->lastInsertId();
}
unset($hash);
if (!is_numeric($id)) {
    throw new RuntimeException('Cannot find saved admin account.');
}
$audit = $db->prepare('INSERT INTO admin_audit (user_id, action, target_type, target_id) VALUES (?, ?, ?, ?)');
$audit->execute([(int) $id, 'auth.account_saved', 'user', (int) $id]);
fwrite(STDOUT, "Admin account saved.\n");
