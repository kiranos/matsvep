<?php

declare(strict_types=1);

namespace Matsvep\Api;

use Matsvep\Engine\EngineConfig;
use Matsvep\Engine\HouseholdManager;
use Matsvep\Engine\HouseholdRecipeManager;
use Matsvep\Engine\Learner;
use Matsvep\Engine\OnboardingManager;
use PDO;
use Throwable;

final class ApiKernel
{
    private AppAuth $auth;
    private RecipeReader $recipes;
    private Idempotency $idempotency;
    private HouseholdManager $households;
    private HouseholdRecipeManager $householdRecipes;
    private OnboardingManager $onboarding;

    public function __construct(PDO $db)
    {
        $config = EngineConfig::fromDatabase($db);
        $this->auth = new AppAuth($db, $config);
        $this->recipes = new RecipeReader($db, $config);
        $this->idempotency = new Idempotency($db, $config);
        $learner = new Learner($db, $config);
        $this->households = new HouseholdManager($db, $config);
        $this->householdRecipes = new HouseholdRecipeManager($db, $learner);
        $this->onboarding = new OnboardingManager($db, $learner);
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
            return [500, ['code' => 'internal_error', 'params' => []]];
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
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)
            && !($rawBody === '' && in_array($route, ['/auth/logout', '/onboarding/complete'], true))) {
            $decoded = json_decode($rawBody);
            if (!$decoded instanceof \stdClass) {
                throw new ApiError(400, 'invalid_request');
            }
            $body = DbValue::map(json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR));
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
        if ($route === '/auth/revoke' && $method === 'POST') {
            $revokedUser = $this->auth->revokedUserForReplay($matches[1]);
            if ($revokedUser !== null) {
                $replayKey = $this->header($headers, 'idempotency-key');
                if ($replayKey === null) {
                    throw new ApiError(400, 'invalid_request');
                }
                return $this->idempotency->replayOnly($revokedUser, $method . ' ' . $route, $replayKey, $rawBody);
            }
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
            if ($key === null) {
                throw new ApiError(400, 'invalid_request');
            }
            return $this->idempotency->execute($actor['user_id'], $method . ' ' . $route, $key, $rawBody, $action);
        }
        if ($route === '/household' && $method === 'GET') {
            return [200, $this->households->get($this->householdId($actor))];
        }
        if ($route === '/household' && $method === 'PATCH') {
            $householdId = $this->householdId($actor);
            return $this->memberWrite(
                $actor,
                $method,
                $route,
                $rawBody,
                $headers,
                fn (): array => [200, $this->households->update($householdId, $body)]
            );
        }
        if ($route === '/household/invites' && $method === 'POST') {
            $householdId = $this->householdId($actor);
            return $this->memberWrite(
                $actor,
                $method,
                $route,
                $rawBody,
                $headers,
                fn (): array => [201, $this->households->createInvite($householdId, $actor['user_id'])]
            );
        }
        if ($route === '/household/settings' && $method === 'GET') {
            return [200, $this->households->settings($this->householdId($actor))];
        }
        if ($route === '/household/settings' && $method === 'PATCH') {
            $householdId = $this->householdId($actor);
            return $this->memberWrite(
                $actor,
                $method,
                $route,
                $rawBody,
                $headers,
                fn (): array => [200, $this->households->updateSettings($householdId, $body)]
            );
        }
        if ($route === '/household/day-profiles' && $method === 'GET') {
            return [200, $this->households->dayProfiles($this->householdId($actor))];
        }
        if ($route === '/household/day-profiles' && $method === 'PUT') {
            $householdId = $this->householdId($actor);
            return $this->memberWrite(
                $actor,
                $method,
                $route,
                $rawBody,
                $headers,
                fn (): array => [200, $this->households->setDayProfiles($householdId, $body)]
            );
        }
        if ($route === '/devices/current' && $method === 'PATCH') {
            $householdId = $this->householdId($actor);
            return $this->memberWrite(
                $actor,
                $method,
                $route,
                $rawBody,
                $headers,
                fn (): array => [200, $this->households->updateDevice($householdId, $actor['user_id'], $actor['device_id'], $body)]
            );
        }
        if ($route === '/onboarding/questions' && $method === 'GET') {
            $this->householdId($actor);
            return [200, $this->onboarding->questions()];
        }
        if ($route === '/onboarding/answers' && $method === 'GET') {
            return [200, $this->onboarding->answers($this->householdId($actor))];
        }
        if (preg_match('#^/onboarding/answers/([a-z_]+)$#', $route, $matches) === 1 && $method === 'PUT') {
            $householdId = $this->householdId($actor);
            return $this->memberWrite(
                $actor,
                $method,
                $route,
                $rawBody,
                $headers,
                fn (): array => [200, $this->onboarding->saveAnswer($householdId, $matches[1], $body)]
            );
        }
        if ($route === '/onboarding/complete' && $method === 'POST') {
            $householdId = $this->householdId($actor);
            return $this->memberWrite(
                $actor,
                $method,
                $route,
                $rawBody,
                $headers,
                fn (): array => [200, $this->onboarding->complete($householdId)]
            );
        }
        if (preg_match('#^/recipes/([1-9][0-9]*)/rating$#', $route, $matches) === 1 && $method === 'PUT') {
            $householdId = $this->householdId($actor);
            $recipeId = (int) $matches[1];
            return $this->memberWrite(
                $actor,
                $method,
                $route,
                $rawBody,
                $headers,
                function () use ($householdId, $recipeId, $body): array {
                    $rating = $body['rating'] ?? null;
                    if (!is_int($rating) || $rating < 1 || $rating > 5) {
                        throw new ApiError(422, 'validation_failed');
                    }
                    return [200, $this->householdRecipes->rate($householdId, $recipeId, $rating)];
                }
            );
        }
        if (preg_match('#^/recipes/([1-9][0-9]*)/wishlist$#', $route, $matches) === 1 && $method === 'PUT') {
            $householdId = $this->householdId($actor);
            $recipeId = (int) $matches[1];
            return $this->memberWrite(
                $actor,
                $method,
                $route,
                $rawBody,
                $headers,
                function () use ($householdId, $recipeId, $body): array {
                    $wishlisted = $body['wishlist'] ?? null;
                    if (!is_bool($wishlisted)) {
                        throw new ApiError(422, 'validation_failed');
                    }
                    return [200, $this->householdRecipes->wishlist($householdId, $recipeId, $wishlisted)];
                }
            );
        }
        if (preg_match('#^/recipes/([1-9][0-9]*)/library-status$#', $route, $matches) === 1 && $method === 'PUT') {
            $householdId = $this->householdId($actor);
            $recipeId = (int) $matches[1];
            return $this->memberWrite(
                $actor,
                $method,
                $route,
                $rawBody,
                $headers,
                fn (): array => [200, $this->householdRecipes->libraryStatus($householdId, $recipeId, $this->requiredString($body, 'status'))]
            );
        }
        if ($route === '/wishlist' && $method === 'GET') {
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
            $cursor = $query['cursor'] ?? null;
            if ($cursor !== null && !is_string($cursor)) {
                throw new ApiError(400, 'invalid_request');
            }
            return [200, $this->recipes->wishlist($this->householdId($actor), $cursor)];
        }
        if ($route === '/recipes/search' && $method === 'GET') {
            $householdId = $this->householdId($actor);
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
            $q = $query['q'] ?? null;
            $source = $query['source_id'] ?? null;
            $cursor = $query['cursor'] ?? null;
            if (!is_string($q) || ($source !== null && (!is_string($source) || preg_match('/^[1-9][0-9]*$/', $source) !== 1)) || ($cursor !== null && !is_string($cursor))) {
                throw new ApiError(400, 'invalid_request');
            }
            return [200, $this->recipes->search($householdId, $q, $source === null ? null : (int) $source, $cursor)];
        }
        if (preg_match('#^/recipes/([1-9][0-9]*)$#', $route, $matches) === 1 && $method === 'GET') {
            return [200, $this->recipes->get((int) $matches[1])];
        }
        throw new ApiError(404, 'not_found');
    }

    /** @param array{user_id:int,device_id:int,role:string,household_id:?int} $actor */
    private function householdId(array $actor): int
    {
        if ($actor['household_id'] === null) {
            throw new ApiError(403, 'household_required');
        }
        return $actor['household_id'];
    }

    /** @param array{user_id:int,device_id:int,role:string,household_id:?int} $actor
     *  @param array<string,string> $headers
     *  @param callable():array{0:int,1:array<string,mixed>} $action
     *  @return array{0:int,1:array<string,mixed>} */
    private function memberWrite(array $actor, string $method, string $route, string $rawBody, array $headers, callable $action): array
    {
        $key = $this->header($headers, 'idempotency-key');
        if ($key === null) {
            throw new ApiError(400, 'invalid_request');
        }
        return $this->idempotency->execute($actor['user_id'], $method . ' ' . $route, $key, $rawBody, $action);
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
