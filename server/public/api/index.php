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
    /** @var array<string,string> $headers */
    $headers = [];
    foreach (getallheaders() as $name => $value) {
        if (is_string($name) && is_string($value)) {
            $headers[$name] = $value;
        }
    }
    if (is_string($_SERVER['HTTP_AUTHORIZATION'] ?? null)) {
        $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    [$status, $response] = $kernel->handle(
        is_string($method) ? $method : 'GET',
        is_string($uri) ? $uri : '',
        file_get_contents('php://input') ?: '',
        $headers,
        is_string($ip) ? $ip : '',
    );
} catch (Throwable $error) {
    error_log('API bootstrap failed: ' . get_class($error));
    $status = 500;
    $response = ['code' => 'internal_error', 'params' => []];
}
http_response_code($status);
echo json_encode($response, JSON_THROW_ON_ERROR);
