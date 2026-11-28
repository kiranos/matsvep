<?php

declare(strict_types=1);

namespace Matsvep\Simulator;

use DateTimeImmutable;
use DateTimeZone;
use Matsvep\Api\ApiKernel;
use Matsvep\Api\DbValue;
use Matsvep\Engine\EngineConfig;
use Matsvep\Engine\PlanningMaintenance;
use PDO;
use RuntimeException;

final class PersonaSimulation
{
    private readonly EngineConfig $config;

    /** @var array<string,list<float>> */
    private array $timings = [];

    private int $requestNumber = 0;

    private string $currentPolicy = 'unknown';

    private string $lastRequestMethod = 'unknown';

    private string $lastRequestRoute = 'unknown';

    public function __construct(private readonly PDO $db)
    {
        $this->config = EngineConfig::fromDatabase($db);
    }

    /** @param list<array<string,mixed>> $personas
     *  @return array<string,mixed> */
    public function run(array $personas, int $weeks, int $seed): array
    {
        if ($weeks < 1) {
            throw new RuntimeException('Simulation weeks must be positive.');
        }
        $start = new DateTimeImmutable(
            $this->config->string('simulator.start_date'),
            new DateTimeZone('Europe/Stockholm')
        );
        if ($start->format('w') !== '0') {
            throw new RuntimeException('Simulator start date must be a Sunday.');
        }
        $results = [];
        foreach ($personas as $persona) {
            $code = $persona['code'] ?? null;
            if (!is_string($code) || preg_match('/^[a-z_]+$/', $code) !== 1) {
                throw new RuntimeException('Invalid persona code.');
            }
            $policies = [];
            foreach (['engine', 'random', 'favourites_only'] as $policy) {
                $policies[$policy] = $this->runPolicy(
                    $persona,
                    $policy,
                    $start,
                    $weeks,
                    new SeededRandom($seed . ':' . $code . ':' . $policy)
                );
            }
            $results[$code] = $policies;
        }
        return ['seed' => $seed, 'weeks' => $weeks, 'start_date' => $start->format('Y-m-d'),
            'personas' => $results, 'timings_ms' => $this->timingReport()];
    }

    /** @param array<string,mixed> $persona
     *  @return array<string,int|float|null> */
    private function runPolicy(
        array $persona,
        string $policy,
        DateTimeImmutable $start,
        int $weeks,
        SeededRandom $random
    ): array {
        $this->currentPolicy = $policy;
        $code = DbValue::text($persona['code']);
        $behavior = DbValue::text($persona['behavior']);
        $taste = new PersonaTaste($this->db, $this->config, $persona);
        $password = bin2hex(random_bytes(16));
        $email = $code . '-' . $policy . '@simulator.test';
        $insert = $this->db->prepare('INSERT INTO household (name, timezone) VALUES (?, ?)');
        $insert->execute(['Simulator ' . $code . ' ' . $policy, 'Europe/Stockholm']);
        $householdId = (int) $this->db->lastInsertId();
        $insert = $this->db->prepare("INSERT INTO `user` (email, password_hash, role, household_id) VALUES (?, ?, 'member', ?)");
        $insert->execute([$email, password_hash($password, PASSWORD_DEFAULT), $householdId]);
        $token = DbValue::text($this->expect(200, $this->call(
            $start,
            'POST',
            '/auth/token',
            ['email' => $email, 'password' => $password, 'platform' => 'windows'],
            null,
            'token'
        ))['access_token']);
        $settings = [];
        if ($persona['split_week'] === true) {
            $settings['planning_mode'] = 'split_week';
        }
        if ($persona['block_vegetarian'] === true) {
            $settings['block_vegetarian'] = true;
        }
        if ($settings !== []) {
            $this->expect(200, $this->call($start, 'PATCH', '/household/settings', $settings, $token, 'settings'));
        }
        $this->onboard($start, $token, $taste, $random);
        $metrics = ['picks' => 0, 'swipes' => 0, 'top_three' => 0, 'replacements' => 0,
            'cooked' => 0, 'hit' => 0, 'discovery_rated' => 0, 'discovery_hit' => 0,
            'rating_errors' => 0.0, 'first_ratings' => 0, 'empty_decks' => 0,
            'baseline_fallbacks' => 0, 'baseline_outside_shortlist_picks' => 0, 'unconfirmed' => 0,
            'hard_filter_breaches' => 0,
            'hidden_utility_sum' => 0.0, 'hidden_utility_4_plus' => 0];
        /** @var array<string,array<string,mixed>> $pending */
        $pending = [];
        for ($day = 0; $day < $weeks * 7; $day++) {
            $today = $start->modify('+' . $day . ' days')->setTime(12, 0);
            $week = intdiv($day, 7);
            $maintenance = (new PlanningMaintenance($this->db, $this->config, $today))->run();
            $metrics['unconfirmed'] += $maintenance['unconfirmed'];
            if ($day > 0) {
                $yesterday = $today->modify('-1 day')->format('Y-m-d');
                if (isset($pending[$yesterday])) {
                    $this->answer(
                        $today,
                        $token,
                        $householdId,
                        $pending[$yesterday],
                        $taste,
                        $week,
                        $behavior,
                        $random,
                        $metrics
                    );
                    unset($pending[$yesterday]);
                }
            }
            $weekday = $day % 7;
            if ($weekday !== 0 && !($weekday === 3 && $persona['split_week'] === true)) {
                continue;
            }
            $weekStart = $start->modify('+' . ($week * 7) . ' days')->format('Y-m-d');
            $part = $weekday === 3 ? 2 : 1;
            $plan = $this->expect(201, $this->call(
                $today,
                'POST',
                '/plans',
                ['week_start' => $weekStart, 'part' => $part],
                $token,
                'plan'
            ));
            $this->choosePart(
                $today,
                $token,
                $householdId,
                $plan,
                $policy,
                $behavior,
                $taste,
                $week,
                $random,
                $pending,
                $metrics
            );
        }
        $last = $start->modify('+' . ($weeks * 7) . ' days')->setTime(12, 0);
        $lastDate = $last->modify('-1 day')->format('Y-m-d');
        if (isset($pending[$lastDate])) {
            $this->answer(
                $last,
                $token,
                $householdId,
                $pending[$lastDate],
                $taste,
                $weeks - 1,
                $behavior,
                $random,
                $metrics
            );
        }
        for ($offset = 1; $offset <= $this->config->integer('after_dinner.unconfirmed_days') + 1; $offset++) {
            $maintenance = (new PlanningMaintenance(
                $this->db,
                $this->config,
                $last->modify('+' . $offset . ' days')
            ))->run();
            $metrics['unconfirmed'] += $maintenance['unconfirmed'];
        }
        return $this->summarize($householdId, $metrics);
    }

    private function onboard(DateTimeImmutable $now, string $token, PersonaTaste $taste, SeededRandom $random): void
    {
        $questions = $this->expect(200, $this->call($now, 'GET', '/onboarding/questions', null, $token, 'onboarding'));
        $choices = [];
        foreach (DbValue::rows($questions['items']) as $item) {
            if ($item['question_id'] !== 'dish_rating') {
                continue;
            }
            foreach (array_slice(
                DbValue::rows($item['options']),
                0,
                $this->config->integer('simulator.onboarding_count')
            ) as $option) {
                $recipeId = DbValue::integer($option['recipe_id']);
                $rating = $taste->rating($recipeId, 0, $random);
                $choices[] = ['recipe_id' => $recipeId, 'choice' => $rating >= 5 ? 'favourite'
                    : ($rating >= 3 ? 'ok' : 'not_for_us')];
            }
        }
        $this->expect(200, $this->call(
            $now,
            'PUT',
            '/onboarding/answers/dish_rating',
            ['answer' => ['ratings' => $choices]],
            $token,
            'onboarding'
        ));
        $this->expect(200, $this->call($now, 'POST', '/onboarding/complete', [], $token, 'onboarding'));
    }

    /** @param array<string,mixed> $plan
     *  @param array<string,array<string,mixed>> $pending
     *  @param array<string,int|float> $metrics */
    private function choosePart(
        DateTimeImmutable $now,
        string $token,
        int $householdId,
        array $plan,
        string $policy,
        string $behavior,
        PersonaTaste $taste,
        int $week,
        SeededRandom $random,
        array &$pending,
        array &$metrics
    ): void {
        $planId = DbValue::integer($plan['id']);
        $version = DbValue::integer($plan['version']);
        foreach (DbValue::rows($plan['slots']) as $slot) {
            $date = DbValue::text($slot['date']);
            if ($slot['status'] !== 'proposed') {
                continue;
            }
            $slotId = DbValue::integer($slot['id']);
            $deck = $this->expect(200, $this->call($now, 'GET', '/plans/' . $planId
                . '/deck?date=' . $date, null, $token, 'deck'));
            $cards = DbValue::rows($deck['items']);
            if ($cards === [] && $policy === 'engine') {
                $metrics['empty_decks']++;
                continue;
            }
            $ids = $this->candidateIds($householdId, $policy, $random);
            if ($ids === [] && $policy !== 'engine') {
                $ids = array_map(static fn (array $card): int => DbValue::integer(
                    DbValue::map($card['recipe'])['id']
                ), $cards);
                $metrics['baseline_fallbacks']++;
            }
            if ($policy === 'engine') {
                $ids = array_map(static fn (array $card): int => DbValue::integer(
                    DbValue::map($card['recipe'])['id']
                ), $cards);
            }
            $ids = array_slice($ids, 0, $this->config->integer('simulator.behavior.swipe_limit'));
            $threshold = $this->config->decimal('simulator.behavior.accept.'
                . (in_array($behavior, ['picky', 'adventurous'], true) ? $behavior : 'default'));
            $session = $this->uuid();
            $chosen = null;
            foreach ($ids as $position => $recipeId) {
                $last = $position === count($ids) - 1;
                if ($taste->utility($recipeId, $week, $random) < $threshold && !$last) {
                    if ($policy === 'engine') {
                        $this->expect(201, $this->call(
                            $now,
                            'POST',
                            '/plans/' . $planId . '/swipes',
                            ['date' => $date, 'recipe_id' => $recipeId, 'position' => $position,
                                'action' => 'next', 'session_id' => $session],
                            $token,
                            'swipe'
                        ));
                    }
                    $metrics['swipes']++;
                    continue;
                }
                $outsideShortlist = false;
                if ($policy !== 'engine') {
                    $candidate = $this->db->prepare('SELECT 1 FROM plan_candidate WHERE plan_id = ? AND recipe_id = ?');
                    $candidate->execute([$planId, $recipeId]);
                    $outsideShortlist = $candidate->fetchColumn() === false;
                }
                $cooldownBlocked = $this->cooldownBlockedAtChoice($householdId, $planId, $recipeId, $now);
                $choice = ['expected_version' => $version, 'recipe_id' => $recipeId];
                if ($policy === 'engine') {
                    $choice['swipe'] = ['position' => $position, 'session_id' => $session];
                }
                [$status, $response] = $this->call($now, 'POST', '/plans/' . $planId
                    . '/slots/' . $slotId . '/choose', $choice, $token, 'choose');
                if ($status === 422) {
                    continue;
                }
                $chosen = $this->expect(200, [$status, $response]);
                $metrics['picks']++;
                $metrics['hard_filter_breaches'] += (int) $cooldownBlocked;
                $metrics['baseline_outside_shortlist_picks'] += (int) $outsideShortlist;
                $metrics['swipes']++;
                $metrics['top_three'] += (int) ($position < 3);
                break;
            }
            if ($chosen === null) {
                $metrics['empty_decks']++;
                continue;
            }
            $version = DbValue::integer($chosen['version']);
            $chosenSlot = $this->slotForDate(DbValue::rows(DbValue::map($chosen['plan'])['slots']), $date);
            $replacementRate = $this->config->decimal('simulator.behavior.replace.'
                . ($behavior === 'chaotic' ? 'chaotic' : 'default'));
            if ($random->fraction() < $replacementRate) {
                foreach ($cards as $alternate) {
                    $alternateId = DbValue::integer(DbValue::map($alternate['recipe'])['id']);
                    if ($alternateId === $chosenSlot['recipe_id']) {
                        continue;
                    }
                    [$status, $response] = $this->call($now, 'POST', '/plans/' . $planId
                        . '/slots/' . $slotId . '/replace', ['expected_version' => $version,
                            'recipe_id' => $alternateId], $token, 'replace');
                    if ($status === 422) {
                        continue;
                    }
                    $replaced = $this->expect(200, [$status, $response]);
                    $version = DbValue::integer($replaced['version']);
                    $chosenSlot = $this->slotForDate(DbValue::rows(DbValue::map($replaced['plan'])['slots']), $date);
                    $metrics['replacements']++;
                    break;
                }
            }
            $pending[$date] = ['plan_id' => $planId, 'slot_id' => $slotId,
                'recipe_id' => $chosenSlot['recipe_id'], 'kind' => $chosenSlot['kind'] ?? null,
                'predicted_pref' => $chosenSlot['predicted_pref'] ?? null];
        }
    }

    /** @return list<int> */
    private function candidateIds(int $householdId, string $policy, SeededRandom $random): array
    {
        if ($policy === 'engine') {
            return [];
        }
        $query = $this->db->prepare('SELECT r.id FROM recipe r '
            . 'LEFT JOIN household_recipe hr ON hr.household_id = ? AND hr.recipe_id = r.id '
            . 'WHERE r.active = TRUE AND r.is_dinner_candidate = TRUE '
            . 'AND (hr.status IS NULL OR hr.status = ?) AND (hr.rating IS NULL OR hr.rating > ?) '
            . ($policy === 'favourites_only' ? 'AND hr.rating >= ? ' : '')
            . 'ORDER BY r.id');
        $args = [$householdId, 'library', $this->config->integer('filter.hide_at_or_below')];
        if ($policy === 'favourites_only') {
            $args[] = $this->config->integer('simulator.favourite_min_rating');
        }
        $query->execute($args);
        $ids = array_values(array_map(DbValue::integer(...), $query->fetchAll(PDO::FETCH_COLUMN)));
        return array_slice($random->shuffled($ids), 0, $this->config->integer('simulator.random_sample_size'));
    }

    private function cooldownBlockedAtChoice(
        int $householdId,
        int $planId,
        int $recipeId,
        DateTimeImmutable $now
    ): bool {
        $query = $this->db->prepare('SELECT 1 FROM carryover c JOIN plan p '
            . 'ON p.household_id = c.household_id AND p.week_start = c.target_week '
            . 'AND p.part = c.target_part WHERE p.id = ? AND c.recipe_id = ? '
            . "AND c.status IN ('pending', 'placed') LIMIT 1");
        $query->execute([$planId, $recipeId]);
        if ($query->fetchColumn() !== false) {
            return false;
        }
        $query = $this->db->prepare('SELECT hr.rating, MAX(CASE WHEN cooked_recipe.id IS NOT NULL '
            . 'THEN m.cooked_on END) AS last_cooked FROM recipe r '
            . 'LEFT JOIN household_recipe hr ON hr.household_id = ? AND hr.recipe_id = r.id '
            . 'LEFT JOIN meal_log m ON m.household_id = ? AND m.cooked_on <= ? '
            . 'LEFT JOIN recipe cooked_recipe ON cooked_recipe.id = m.recipe_id '
            . 'AND (cooked_recipe.id = r.id OR (r.dish_group_id IS NOT NULL '
            . 'AND cooked_recipe.dish_group_id = r.dish_group_id)) '
            . 'WHERE r.id = ? GROUP BY hr.rating');
        $query->execute([$householdId, $householdId, $now->format('Y-m-d'), $recipeId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['last_cooked'] === null) {
            return false;
        }
        $rating = $row['rating'] === null ? 4 : DbValue::integer($row['rating']);
        $rating = in_array($rating, [3, 5], true) ? $rating : 4;
        $days = (new DateTimeImmutable(DbValue::text($row['last_cooked'])))
            ->diff(new DateTimeImmutable($now->format('Y-m-d')))->days;
        return $days < $this->config->integer('cooldown.days.' . $rating);
    }

    /** @param array<string,mixed> $choice
     *  @param array<string,int|float> $metrics */
    private function answer(
        DateTimeImmutable $now,
        string $token,
        int $householdId,
        array $choice,
        PersonaTaste $taste,
        int $week,
        string $behavior,
        SeededRandom $random,
        array &$metrics
    ): void {
        $noAnswer = $this->config->decimal('simulator.behavior.no_answer.'
            . ($behavior === 'chaotic' ? 'chaotic' : 'default'));
        if ($random->fraction() < $noAnswer) {
            return;
        }
        $route = '/plans/' . DbValue::integer($choice['plan_id']) . '/slots/'
            . DbValue::integer($choice['slot_id']) . '/answer';
        $cookRate = $this->config->decimal('simulator.behavior.cook.'
            . ($behavior === 'chaotic' ? 'chaotic' : 'default'));
        if ($random->fraction() >= $cookRate) {
            $this->expect(200, $this->call($now, 'POST', $route, ['outcome' => 'not_cooked'], $token, 'answer'));
            return;
        }
        $recipeId = DbValue::integer($choice['recipe_id']);
        $query = $this->db->prepare('SELECT rating_source FROM household_recipe WHERE household_id = ? AND recipe_id = ?');
        $query->execute([$householdId, $recipeId]);
        $rated = $query->fetchColumn() === 'user';
        $body = ['outcome' => 'cooked'];
        if (!$rated) {
            $rating = $taste->rating($recipeId, $week, $random);
            $body['rating'] = $rating;
        }
        $this->expect(200, $this->call($now, 'POST', $route, $body, $token, 'answer'));
        $metrics['cooked']++;
        $hiddenUtility = $taste->noiseFreeUtility($recipeId, $week);
        $metrics['hidden_utility_sum'] += $hiddenUtility;
        $metrics['hidden_utility_4_plus'] += (int) ($hiddenUtility >= 4.0);
        if (!$rated) {
            $metrics['first_ratings']++;
            $metrics['hit'] += (int) ($rating >= 4);
            if ($choice['kind'] === 'discovery') {
                $metrics['discovery_rated']++;
                $metrics['discovery_hit'] += (int) ($rating >= 4);
            }
            if ($choice['predicted_pref'] !== null) {
                $metrics['rating_errors'] += abs(1 + 4 * DbValue::decimal($choice['predicted_pref']) - $rating);
            }
        }
    }

    /** @param array<string,int|float> $metrics
     *  @return array<string,int|float|null> */
    private function summarize(int $householdId, array $metrics): array
    {
        $slots = $this->db->prepare('SELECT s.date, s.recipe_id, s.kind, r.dish_group_id, r.main_protein '
            . 'FROM plan_slot s JOIN recipe r ON r.id = s.recipe_id '
            . 'WHERE s.household_id = ? AND s.status <> ? ORDER BY s.date, s.id');
        $slots->execute([$householdId, 'proposed']);
        $seenRecipes = [];
        $weeklyProteins = [];
        $repeats = 0;
        $varietyViolations = 0;
        $favourites = 0;
        $discoveries = 0;
        foreach (DbValue::rows($slots->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $recipeId = DbValue::integer($row['recipe_id']);
            $date = DbValue::text($row['date']);
            $repeats += (int) isset($seenRecipes[$recipeId]);
            $seenRecipes[$recipeId] = true;
            if ($row['main_protein'] !== null) {
                $dateValue = new DateTimeImmutable($date);
                $week = $dateValue->modify('-' . $dateValue->format('w') . ' days')->format('Y-m-d');
                $protein = DbValue::text($row['main_protein']);
                $weeklyProteins[$week][$protein] = ($weeklyProteins[$week][$protein] ?? 0) + 1;
                $varietyViolations += (int) ($weeklyProteins[$week][$protein]
                    > $this->config->integer('variety.max_protein_week'));
            }
            $favourites += (int) ($row['kind'] === 'favourite');
            $discoveries += (int) ($row['kind'] === 'discovery');
        }
        $query = $this->db->prepare('SELECT m.cooked_on, m.recipe_id, '
            . 'COALESCE(r.dish_group_id, -r.id) AS group_id, hr.rating, '
            . 'EXISTS (SELECT 1 FROM carryover c JOIN plan p '
            . 'ON p.household_id = c.household_id AND p.week_start = c.target_week '
            . 'AND p.part = c.target_part WHERE p.id = s.plan_id AND c.recipe_id = m.recipe_id '
            . "AND c.status = 'placed') AS placed_carryover "
            . 'FROM meal_log m JOIN plan_slot s ON s.id = m.plan_slot_id '
            . 'JOIN recipe r ON r.id = m.recipe_id '
            . 'LEFT JOIN household_recipe hr ON hr.household_id = m.household_id '
            . 'AND hr.recipe_id = m.recipe_id WHERE m.household_id = ? '
            . "AND s.status IN ('cooked', 'unconfirmed') ORDER BY m.cooked_on, m.id");
        $query->execute([$householdId]);
        $meals = [];
        foreach (DbValue::rows($query->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $meals[] = ['date' => DbValue::text($row['cooked_on']),
                'recipe_id' => DbValue::integer($row['recipe_id']),
                'group_id' => DbValue::integer($row['group_id']),
                'rating' => $row['rating'] === null ? null : DbValue::integer($row['rating']),
                'placed_carryover' => DbValue::integer($row['placed_carryover']) === 1];
        }
        $cooldown = (new CooldownReport($this->config))->summarize($meals);
        $picks = (int) $metrics['picks'];
        $ratings = (int) $metrics['first_ratings'];
        return ['picks' => $picks, 'cooked' => (int) $metrics['cooked'],
            'mae' => $ratings === 0 ? null : round($metrics['rating_errors'] / $ratings, 4),
            'hit_rate' => $ratings === 0 ? null : round($metrics['hit'] / $ratings, 4),
            'mean_hidden_utility' => $metrics['cooked'] === 0 ? null
                : round($metrics['hidden_utility_sum'] / $metrics['cooked'], 4),
            'hidden_utility_4_plus_share' => $metrics['cooked'] === 0 ? null
                : round($metrics['hidden_utility_4_plus'] / $metrics['cooked'], 4),
            'discovery_success' => $metrics['discovery_rated'] === 0 ? null
                : round($metrics['discovery_hit'] / $metrics['discovery_rated'], 4),
            'replace_rate' => $picks === 0 ? null : round($metrics['replacements'] / $picks, 4),
            'swipes_to_pick' => $picks === 0 ? null : round($metrics['swipes'] / $picks, 4),
            'top_3_picks' => $picks === 0 ? null : round($metrics['top_three'] / $picks, 4),
            'variety_violations' => $varietyViolations,
            'cooldown_violations' => $cooldown['cooldown_violations'],
            'hard_filter_breaches' => (int) $metrics['hard_filter_breaches'],
            'distinct_recipes_cooked' => $cooldown['distinct_recipes_cooked'],
            'distinct_dish_groups_cooked' => $cooldown['distinct_dish_groups_cooked'],
            'repeat_rate' => $picks === 0 ? null : round($repeats / $picks, 4),
            'favourite_share' => $picks === 0 ? null : round($favourites / $picks, 4),
            'discovery_share' => $picks === 0 ? null : round($discoveries / $picks, 4),
            'empty_decks' => (int) $metrics['empty_decks'],
            'unconfirmed' => (int) $metrics['unconfirmed'],
            'baseline_outside_shortlist_picks' => (int) $metrics['baseline_outside_shortlist_picks'],
            'baseline_fallbacks' => (int) $metrics['baseline_fallbacks']];
    }

    /** @param array<string,mixed>|null $body
     *  @return array{int,array<string,mixed>} */
    private function call(
        DateTimeImmutable $now,
        string $method,
        string $route,
        ?array $body,
        ?string $token,
        string $timing
    ): array {
        $this->lastRequestMethod = $method;
        $this->lastRequestRoute = '/api/v1' . $route;
        $headers = $token === null ? [] : ['Authorization' => 'Bearer ' . $token];
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && $token !== null) {
            $headers['Idempotency-Key'] = $this->uuid();
        }
        $started = hrtime(true);
        $result = (new ApiKernel($this->db, $now))->handle(
            $method,
            '/api/v1' . $route,
            $body === null ? '' : json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR),
            $headers,
            '127.0.0.1'
        );
        $this->timings[$timing][] = (hrtime(true) - $started) / 1_000_000;
        return $result;
    }

    /** @param array{int,array<string,mixed>} $result
     *  @return array<string,mixed> */
    private function expect(int $status, array $result): array
    {
        if ($result[0] !== $status) {
            $code = $result[1]['code'] ?? 'unknown';
            throw new RuntimeException('Simulation API request failed with status ' . $result[0]
                . ' and code ' . (is_string($code) ? $code : 'unknown') . ' for '
                . $this->lastRequestMethod . ' ' . $this->lastRequestRoute
                . ' (policy ' . $this->currentPolicy . ').');
        }
        return $result[1];
    }

    /** @param list<array<string,mixed>> $slots
     *  @return array<string,mixed> */
    private function slotForDate(array $slots, string $date): array
    {
        foreach ($slots as $slot) {
            if ($slot['date'] === $date) {
                return $slot;
            }
        }
        throw new RuntimeException('Chosen simulation slot is missing.');
    }

    private function uuid(): string
    {
        $hex = substr(hash('sha256', 'simulator:' . $this->requestNumber++), 0, 32);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
            . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    /** @return array<string,array{count:int,mean:float,p95:float}> */
    private function timingReport(): array
    {
        $result = [];
        foreach ($this->timings as $name => $samples) {
            sort($samples, SORT_NUMERIC);
            $count = count($samples);
            $result[$name] = ['count' => $count, 'mean' => round(array_sum($samples) / $count, 3),
                'p95' => round($samples[min($count - 1, (int) ceil($count * 0.95) - 1)], 3)];
        }
        return $result;
    }
}
