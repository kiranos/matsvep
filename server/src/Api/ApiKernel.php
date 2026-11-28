<?php

declare(strict_types=1);

namespace Matsvep\Api;

use Matsvep\Engine\EngineConfig;
use Matsvep\Engine\HouseholdManager;
use Matsvep\Engine\HouseholdRecipeManager;
use Matsvep\Engine\Learner;
use Matsvep\Engine\OnboardingManager;
use Matsvep\Engine\PlanningService;
use DateTimeImmutable;
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
    private PlanningService $planning;

    public function __construct(PDO $db, ?DateTimeImmutable $now = null)
    {
        $config = EngineConfig::fromDatabase($db);
        $this->auth = new AppAuth($db, $config);
        $this->recipes = new RecipeReader($db, $config);
        $this->idempotency = new Idempotency($db, $config);
        $learner = new Learner($db, $config, $now);
        $this->households = new HouseholdManager($db, $config);
        $this->householdRecipes = new HouseholdRecipeManager($db, $learner);
        $this->onboarding = new OnboardingManager($db, $learner, $now);
        $this->planning = new PlanningService($db, $config, $this->recipes, $this->householdRecipes, $now);
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
        $matched = self::matchRoute($method, $route);
        $definition = $matched[0] ?? null;
        $pathParams = $matched[1] ?? [];
        if ($definition !== null && $definition[2] === 'health') {
            return $this->publicAction('health', [], $ip);
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
        if ($definition !== null && !$definition[3]) {
            return $this->publicAction($definition[2], $body, $ip);
        }
        $authorization = $this->header($headers, 'authorization');
        if ($authorization === null || preg_match('/^Bearer ([a-f0-9]{64})$/i', $authorization, $token) !== 1) {
            throw new ApiError(401, 'access_token_expired');
        }
        if ($definition !== null && $definition[2] === 'revoke') {
            $revokedUser = $this->auth->revokedUserForReplay($token[1]);
            if ($revokedUser !== null) {
                $replayKey = $this->header($headers, 'idempotency-key');
                if ($replayKey === null) {
                    throw new ApiError(400, 'invalid_request');
                }
                return $this->idempotency->replayOnly($revokedUser, $method . ' ' . $route, $replayKey, $rawBody);
            }
        }
        $actor = $this->auth->authenticate($token[1]);
        if ($definition === null) {
            throw new ApiError(404, 'not_found');
        }
        $householdId = $definition[4] ? $this->householdId($actor) : 0;
        $action = fn (): array => $this->privateAction($definition[2], $body, $actor, $householdId, $pathParams, $uri);
        if ($definition[5]) {
            return $this->memberWrite($actor, $method, $route, $rawBody, $headers, $action);
        }
        return $action();
    }

    /** @return list<array{0:string,1:string,2:string,3:bool,4:bool,5:bool}> */
    private static function routeTable(): array
    {
        // Method, path pattern, handler, authentication, household, idempotency.
        return [
            ['GET', '#^/health$#', 'health', false, false, false],
            ['POST', '#^/auth/register$#', 'register', false, false, false],
            ['POST', '#^/auth/token$#', 'token', false, false, false],
            ['POST', '#^/auth/refresh$#', 'refresh', false, false, false],
            ['POST', '#^/auth/revoke$#', 'revoke', true, false, true],
            ['POST', '#^/auth/logout$#', 'logout', true, false, false],
            ['GET', '#^/household$#', 'household_get', true, true, false],
            ['PATCH', '#^/household$#', 'household_update', true, true, true],
            ['POST', '#^/household/invites$#', 'invite_create', true, true, true],
            ['GET', '#^/household/settings$#', 'settings_get', true, true, false],
            ['PATCH', '#^/household/settings$#', 'settings_update', true, true, true],
            ['GET', '#^/household/day-profiles$#', 'profiles_get', true, true, false],
            ['PUT', '#^/household/day-profiles$#', 'profiles_update', true, true, true],
            ['PATCH', '#^/devices/current$#', 'device_update', true, true, true],
            ['GET', '#^/onboarding/questions$#', 'questions_get', true, true, false],
            ['GET', '#^/onboarding/answers$#', 'answers_get', true, true, false],
            ['PUT', '#^/onboarding/answers/([a-z_]+)$#', 'answer_update', true, true, true],
            ['POST', '#^/onboarding/complete$#', 'onboarding_complete', true, true, true],
            ['PUT', '#^/recipes/([1-9][0-9]*)/rating$#', 'rating_update', true, true, true],
            ['PUT', '#^/recipes/([1-9][0-9]*)/wishlist$#', 'wishlist_update', true, true, true],
            ['PUT', '#^/recipes/([1-9][0-9]*)/library-status$#', 'library_update', true, true, true],
            ['GET', '#^/wishlist$#', 'wishlist_get', true, true, false],
            ['GET', '#^/recipes/search$#', 'search', true, true, false],
            ['GET', '#^/plans$#', 'plans_list', true, true, false],
            ['POST', '#^/plans$#', 'plan_create', true, true, true],
            ['GET', '#^/plans/([1-9][0-9]*)$#', 'plan_get', true, true, false],
            ['GET', '#^/plans/([1-9][0-9]*)/deck$#', 'deck_get', true, true, false],
            ['POST', '#^/plans/([1-9][0-9]*)/slots/([1-9][0-9]*)/choose$#', 'plan_choose', true, true, true],
            ['POST', '#^/plans/([1-9][0-9]*)/slots/([1-9][0-9]*)/replace$#', 'plan_replace', true, true, true],
            ['POST', '#^/plans/([1-9][0-9]*)/autofill$#', 'plan_autofill', true, true, true],
            ['POST', '#^/plans/([1-9][0-9]*)/shuffle$#', 'plan_shuffle', true, true, true],
            ['POST', '#^/plans/([1-9][0-9]*)/swipes$#', 'plan_swipe', true, true, true],
            ['POST', '#^/plans/([1-9][0-9]*)/slots/([1-9][0-9]*)/answer$#', 'plan_answer', true, true, true],
            ['GET', '#^/recipes/([1-9][0-9]*)$#', 'recipe_get', true, false, false],
        ];
    }

    /** @return array{0:array{0:string,1:string,2:string,3:bool,4:bool,5:bool},1:list<string>}|null */
    private static function matchRoute(string $method, string $path): ?array
    {
        foreach (self::routeTable() as $definition) {
            if ($definition[0] === $method && preg_match($definition[1], $path, $matches) === 1) {
                return [$definition, array_values($matches)];
            }
        }
        return null;
    }

    /** @param array<string,mixed> $body
     *  @return array{0:int,1:array<string,mixed>} */
    private function publicAction(string $handler, array $body, string $ip): array
    {
        return match ($handler) {
            'health' => [200, ['status' => 'ok']],
            'register' => [201, $this->auth->register($body)],
            'token' => [200, $this->auth->login($body, $ip)],
            'refresh' => [200, $this->auth->refresh($this->requiredString($body, 'refresh_token'))],
            default => throw new \LogicException('Unknown public API route.'),
        };
    }

    /** @param array<string,mixed> $body
     *  @param array{user_id:int,device_id:int,role:string,household_id:?int} $actor
     *  @param list<string> $pathParams
     *  @return array{0:int,1:array<string,mixed>} */
    private function privateAction(
        string $handler,
        array $body,
        array $actor,
        int $householdId,
        array $pathParams,
        string $uri
    ): array {
        return match ($handler) {
            'revoke' => $this->revoke($body, $actor['user_id']),
            'logout' => $this->logout($actor['device_id']),
            'household_get' => [200, $this->households->get($householdId)],
            'household_update' => [200, $this->households->update($householdId, $body)],
            'invite_create' => [201, $this->households->createInvite($householdId, $actor['user_id'])],
            'settings_get' => [200, $this->households->settings($householdId)],
            'settings_update' => [200, $this->households->updateSettings($householdId, $body)],
            'profiles_get' => [200, $this->households->dayProfiles($householdId)],
            'profiles_update' => [200, $this->households->setDayProfiles($householdId, $body)],
            'device_update' => [200, $this->households->updateDevice($householdId, $actor['user_id'], $actor['device_id'], $body)],
            'questions_get' => [200, $this->onboarding->questions()],
            'answers_get' => [200, $this->onboarding->answers($householdId)],
            'answer_update' => [200, $this->onboarding->saveAnswer($householdId, $pathParams[1], $body)],
            'onboarding_complete' => [200, $this->onboarding->complete($householdId)],
            'rating_update' => $this->rate($householdId, (int) $pathParams[1], $body),
            'wishlist_update' => $this->setWishlist($householdId, (int) $pathParams[1], $body),
            'library_update' => [200, $this->householdRecipes->libraryStatus($householdId, (int) $pathParams[1], $this->requiredString($body, 'status'))],
            'wishlist_get' => $this->getWishlist($householdId, $uri),
            'search' => $this->search($householdId, $uri),
            'plans_list' => [200, $this->planning->list($householdId, $this->queryString($uri, 'cursor'))],
            'plan_create' => [201, $this->planning->create($householdId, $body)],
            'plan_get' => [200, $this->planning->get($householdId, (int) $pathParams[1])],
            'deck_get' => $this->getDeck($householdId, (int) $pathParams[1], $uri),
            'plan_choose' => [200, $this->planning->change($householdId, (int) $pathParams[1], (int) $pathParams[2], 'choose', $body)],
            'plan_replace' => [200, $this->planning->change($householdId, (int) $pathParams[1], (int) $pathParams[2], 'replace', $body)],
            'plan_autofill' => [200, $this->planning->change($householdId, (int) $pathParams[1], null, 'autofill', $body)],
            'plan_shuffle' => [200, $this->planning->change($householdId, (int) $pathParams[1], null, 'shuffle', $body)],
            'plan_swipe' => [201, $this->planning->swipe($householdId, (int) $pathParams[1], $body)],
            'plan_answer' => [200, $this->planning->answer($householdId, (int) $pathParams[1], (int) $pathParams[2], $body)],
            'recipe_get' => [200, $this->recipes->get((int) $pathParams[1])],
            default => throw new \LogicException('Unknown authenticated API route.'),
        };
    }

    /** @param array<string,mixed> $body
     *  @return array{0:int,1:array<string,mixed>} */
    private function revoke(array $body, int $userId): array
    {
        $this->auth->revoke($this->requiredString($body, 'refresh_token'), $userId);
        return [200, ['event' => ['code' => 'token_revoked', 'params' => []]]];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function logout(int $deviceId): array
    {
        $this->auth->revokeDevice($deviceId);
        return [200, ['event' => ['code' => 'token_revoked', 'params' => []]]];
    }

    /** @param array<string,mixed> $body
     *  @return array{0:int,1:array<string,mixed>} */
    private function rate(int $householdId, int $recipeId, array $body): array
    {
        $rating = $body['rating'] ?? null;
        if (!is_int($rating) || $rating < 1 || $rating > 5) {
            throw new ApiError(422, 'validation_failed');
        }
        return [200, $this->householdRecipes->rate($householdId, $recipeId, $rating)];
    }

    /** @param array<string,mixed> $body
     *  @return array{0:int,1:array<string,mixed>} */
    private function setWishlist(int $householdId, int $recipeId, array $body): array
    {
        $wishlisted = $body['wishlist'] ?? null;
        if (!is_bool($wishlisted)) {
            throw new ApiError(422, 'validation_failed');
        }
        return [200, $this->householdRecipes->wishlist($householdId, $recipeId, $wishlisted)];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function getWishlist(int $householdId, string $uri): array
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $cursor = $query['cursor'] ?? null;
        if ($cursor !== null && !is_string($cursor)) {
            throw new ApiError(400, 'invalid_request');
        }
        return [200, $this->recipes->wishlist($householdId, $cursor)];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function search(int $householdId, string $uri): array
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $q = $query['q'] ?? null;
        $source = $query['source_id'] ?? null;
        $cursor = $query['cursor'] ?? null;
        if (!is_string($q) || ($source !== null && (!is_string($source) || preg_match('/^[1-9][0-9]*$/', $source) !== 1)) || ($cursor !== null && !is_string($cursor))) {
            throw new ApiError(400, 'invalid_request');
        }
        return [200, $this->recipes->search($householdId, $q, $source === null ? null : (int) $source, $cursor)];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function getDeck(int $householdId, int $planId, string $uri): array
    {
        $date = $this->queryString($uri, 'date');
        if ($date === null) {
            throw new ApiError(400, 'invalid_request');
        }
        return [200, $this->planning->deck($householdId, $planId, $date, $this->queryString($uri, 'cursor'))];
    }

    private function queryString(string $uri, string $key): ?string
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $value = $query[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new ApiError(400, 'invalid_request');
        }
        return $value;
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
