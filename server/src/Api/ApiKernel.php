<?php

declare(strict_types=1);

namespace Matsvep\Api;

use Matsvep\Engine\EngineConfig;
use PDO;
use Throwable;

final class ApiKernel
{
    private AppAuth $auth;
    private RecipeReader $recipes;
    private Idempotency $idempotency;

    public function __construct(PDO $db)
    {
        $config = EngineConfig::fromDatabase($db);
        $this->auth = new AppAuth($db, $config);
        $this->recipes = new RecipeReader($db, $config);
        $this->idempotency = new Idempotency($db, $config);
    }

    /** @param array<string,string> $headers
     *  @return array{0:int,1:array<string,mixed>} */
    public function handle(string $method, string $uri, string $rawBody, array $headers, string $ip): array
    {
        try {
            return $this->dispatch($method, $uri, $rawBody, $headers, $ip);
        } catch (ApiError $error) {
            return [$error->status, ['code' => $error->errorCode, 'params' => $error->params]];
        } catch (Throwable $error) {
            error_log('API request failed: ' . get_class($error));
            return [500, ['code' => 'invalid_request', 'params' => []]];
        }
    }

    /** @param array<string,string> $headers
     *  @return array{0:int,1:array<string,mixed>} */
    private function dispatch(string $method, string $uri, string $rawBody, array $headers, string $ip): array
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/api/v1/')) {
            throw new ApiError(404, 'not_found');
        }
        $route = substr($path, strlen('/api/v1'));
        if ($route === '/health' && $method === 'GET') {
            return [200, ['status' => 'ok']];
        }
        $body = [];
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $decoded = json_decode($rawBody);
            if (!$decoded instanceof \stdClass) {
                throw new ApiError(400, 'invalid_request');
            }
            $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        }
        if ($route === '/auth/register' && $method === 'POST') {
            return [201, $this->auth->register($body)];
        }
        if ($route === '/auth/token' && $method === 'POST') {
            return [200, $this->auth->login($body, $ip)];
        }
        if ($route === '/auth/refresh' && $method === 'POST') {
            return [200, $this->auth->refresh($this->requiredString($body, 'refresh_token'))];
        }
        $authorization = $this->header($headers, 'authorization');
        if ($authorization === null || preg_match('/^Bearer ([a-f0-9]{64})$/i', $authorization, $matches) !== 1) {
            throw new ApiError(401, 'access_token_expired');
        }
        $actor = $this->auth->authenticate($matches[1]);
        if ($route === '/auth/logout' && $method === 'POST') {
            $this->auth->revokeDevice($actor['device_id']);
            return [200, ['event' => ['code' => 'token_revoked', 'params' => []]]];
        }
        if ($route === '/auth/revoke' && $method === 'POST') {
            $action = function () use ($body, $actor): array {
                $this->auth->revoke($this->requiredString($body, 'refresh_token'), $actor['user_id']);
                return [200, ['event' => ['code' => 'token_revoked', 'params' => []]]];
            };
            $key = $this->header($headers, 'idempotency-key');
            return $key === null ? $action() : $this->idempotency->execute($actor['user_id'], $method . ' ' . $route, $key, $rawBody, $action);
        }
        if ($route === '/recipes/search' && $method === 'GET') {
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
            $q = $query['q'] ?? null;
            $source = $query['source_id'] ?? null;
            $cursor = $query['cursor'] ?? null;
            if (!is_string($q) || ($source !== null && (!is_string($source) || preg_match('/^[1-9][0-9]*$/', $source) !== 1)) || ($cursor !== null && !is_string($cursor))) {
                throw new ApiError(400, 'invalid_request');
            }
            return [200, $this->recipes->search($q, $source === null ? null : (int) $source, $cursor)];
        }
        if (preg_match('#^/recipes/([1-9][0-9]*)$#', $route, $matches) === 1 && $method === 'GET') {
            return [200, $this->recipes->get((int) $matches[1])];
        }
        throw new ApiError(404, 'not_found');
    }

    /** @param array<string,string> $headers */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $name) {
                return $value;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $body */
    private function requiredString(array $body, string $key): string
    {
        if (!is_string($body[$key] ?? null) || $body[$key] === '') {
            throw new ApiError(400, 'invalid_request');
        }
        return $body[$key];
    }
}
