<?php

declare(strict_types=1);

use Matsvep\Api\ApiKernel;
use Matsvep\Ingest\Ingredient\DatabaseConnection;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $db = DatabaseConnection::fromEnvironment(dirname(__DIR__, 2));
    $kernel = new ApiKernel($db);
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (!is_array($headers)) {
        $headers = [];
    }
    if (is_string($_SERVER['HTTP_AUTHORIZATION'] ?? null)) {
        $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
    }
    [$status, $response] = $kernel->handle(
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        (string) ($_SERVER['REQUEST_URI'] ?? ''),
        file_get_contents('php://input') ?: '',
        $headers,
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    );
} catch (Throwable $error) {
    error_log('API bootstrap failed: ' . get_class($error));
    $status = 500;
    $response = ['code' => 'invalid_request', 'params' => []];
}
http_response_code($status);
echo json_encode($response, JSON_THROW_ON_ERROR);
