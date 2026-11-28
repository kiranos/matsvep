<?php

declare(strict_types=1);

namespace Matsvep\Engine;

use Matsvep\Database\WriteTransaction;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;

/** @phpstan-type PlanCard array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}
 *  @phpstan-type PlanResult array{plan_id: int, version: int, slots: array<string, PlanCard>, decks: array<string, list<PlanCard>>}
 */
final class PlanManager
{
    private PlanBuilder $builder;

    private PlanCandidateRepository $candidates;

    public function __construct(
        private readonly PDO $db,
        private EngineConfig $config,
        private readonly ?Closure $observer = null,
        private readonly ?DateTimeImmutable $now = null
    ) {
        $this->builder = new PlanBuilder($config, $observer);
        $this->candidates = new PlanCandidateRepository($db, $config);
    }

    /** @param list<string> $dates
     *  @return PlanResult
     */
    public function prepare(int $householdId, string $weekStart, int $part, array $dates): array
    {
        if ($part < 1 || $part > 2 || $dates === [] || count(array_unique($dates)) !== count($dates)) {
            throw new InvalidArgumentException('Invalid planning days.');
        }
        $writeStarted = 0;
        $plan = WriteTransaction::run($this->db, function () use ($householdId, $weekStart, $part, $dates, &$writeStarted): array {
            $query = $this->db->prepare('SELECT id FROM plan WHERE household_id = ? AND week_start = ? AND part = ? FOR UPDATE');
            $query->execute([$householdId, $weekStart, $part]);
            $planId = $query->fetchColumn();
            if ($planId === false) {
                $seed = PlanBuilder::seed($householdId, $weekStart, $part, 0);
                $version = $this->configVersion();
                $insert = $this->db->prepare('INSERT INTO plan '
                    . '(household_id, week_start, part, days, seed, config_version, status) '
                    . "VALUES (?, ?, ?, ?, ?, ?, 'ready')");
                $insert->execute([$householdId, $weekStart, $part, json_encode($dates, JSON_THROW_ON_ERROR), $seed, $version]);
                $planId = self::integer($this->db->lastInsertId());
            }
            $started = hrtime(true);
            $state = $this->loadState(self::integer($planId));
            $this->observe('load_state', $started);
            $started = hrtime(true);
            $result = $this->build($state);
            $this->observe('build', $started);
            $writeStarted = (int) hrtime(true);
            if ($state['slots'] === []) {
                $this->writeOpenSlots(self::integer($planId), $householdId, $result, [], 'proposed');
            }
            if ($state['slots'] === []) {
                $this->markCarryoversPlaced($householdId, $weekStart, $part, $state['carryovers'], $result);
            }
            return ['plan_id' => self::integer($planId),
                'version' => self::intField($state['plan'], 'version')] + $result;
        });
        if ($writeStarted > 0) {
            $this->observe('write_and_commit', $writeStarted);
        }
        return $plan;
    }

    /** @return PlanResult */
    public function choose(int $planId, int $expectedVersion, string $date, int $recipeId): array
    {
        return $this->change('choose', $planId, $expectedVersion, $date, $recipeId);
    }

    /** @return PlanResult */
    public function replace(int $planId, int $expectedVersion, string $date, int $recipeId): array
    {
        return $this->change('replace', $planId, $expectedVersion, $date, $recipeId);
    }

    /** @return PlanResult */
    public function autofill(int $planId, int $expectedVersion): array
    {
        return $this->change('autofill', $planId, $expectedVersion);
    }

    /** @return PlanResult */
    public function shuffle(int $planId, int $expectedVersion): array
    {
        return $this->change('shuffle', $planId, $expectedVersion);
    }

    /** @return PlanResult */
    public function view(int $planId): array
    {
        return WriteTransaction::run($this->db, function () use ($planId): array {
            $state = $this->loadState($planId);
            return ['plan_id' => $planId, 'version' => self::intField($state['plan'], 'version')]
                + $this->build($state);
        });
    }

    /** @return PlanResult */
    private function change(string $action, int $planId, int $expectedVersion, ?string $date = null, ?int $recipeId = null): array
    {
        $writeStarted = 0;
        $changed = WriteTransaction::run($this->db, function () use ($action, $planId, $expectedVersion, $date, $recipeId, &$writeStarted): array {
            $started = hrtime(true);
            $state = $this->loadState($planId, ($action === 'choose' || $action === 'replace') ? $recipeId : null);
            $this->observe('load_state', $started);
            $plan = $state['plan'];
            if (self::intField($plan, 'version') !== $expectedVersion) {
                throw new PlanVersionConflict();
            }
            $original = $state['slots'];
            $fixed = $this->fixed($original);
            if ($action === 'choose' || $action === 'replace') {
                if ($date === null || $recipeId === null || !in_array($date, $state['dates'], true)) {
                    throw new InvalidArgumentException('Unknown planning date or recipe.');
                }
                $wasChosen = isset($fixed[$date]);
                if (($action === 'choose' && $wasChosen) || ($action === 'replace' && !$wasChosen)) {
                    throw new InvalidArgumentException('Planning slot has the wrong status.');
                }
                if ($action === 'replace' && $fixed[$date] === $recipeId) {
                    throw new InvalidArgumentException('Replacement must change the recipe.');
                }
                unset($fixed[$date]);
                $available = [];
                foreach ($state['recipes'] as $candidate) {
                    $available[$candidate->id] = $candidate;
                }
                if (!isset($available[$recipeId])) {
                    throw new InvalidArgumentException('Recipe does not pass planning filters.');
                }
                foreach ($fixed as $fixedId) {
                    if ($available[$fixedId]->groupId === $available[$recipeId]->groupId) {
                        throw new InvalidArgumentException('Dish group is already chosen.');
                    }
                }
                $fixed[$date] = $recipeId;
            }
            $reshuffles = self::intField($plan, 'reshuffle_count');
            if ($action === 'shuffle') {
                $reshuffles++;
                $state['seed'] = PlanBuilder::seed(
                    self::intField($plan, 'household_id'),
                    self::stringField($plan, 'week_start'),
                    self::intField($plan, 'part'),
                    $reshuffles
                );
            }
            $replacedFrom = [];
            if ($action === 'replace' && $date !== null && $recipeId !== null) {
                $oldRecipeId = self::intField($original[$date], 'recipe_id');
                $replacedFrom[$date] = $oldRecipeId;
                $skip = $this->db->prepare('INSERT INTO slot_skip (household_id, recipe_id, reason, expires_at) '
                    . "VALUES (?, ?, 'replaced', DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY))");
                $skip->execute([self::intField($plan, 'household_id'), $oldRecipeId,
                    $this->config->integer('skip.replace_days')]);
                foreach ($state['recipes'] as $index => $candidate) {
                    if ($candidate->id === $oldRecipeId) {
                        $state['recipes'][$index] = $candidate->withReplacementAge(0);
                        break;
                    }
                }
            }
            $state['fixed'] = $fixed;
            $started = hrtime(true);
            $result = $this->build($state);
            $this->observe('build', $started);
            $writeStarted = (int) hrtime(true);
            $chosenDates = [];
            if (($action === 'choose' || $action === 'replace') && $date !== null) {
                $chosenDates[$date] = 'family';
            } elseif ($action === 'autofill') {
                foreach ($result['slots'] as $slotDate => $_slot) {
                    if (!isset($fixed[$slotDate])) {
                        $chosenDates[$slotDate] = 'engine';
                    }
                }
            }
            $this->writeOpenSlots(
                $planId,
                self::intField($plan, 'household_id'),
                $result,
                $this->fixed($original),
                'proposed',
                $chosenDates,
                $replacedFrom
            );
            $this->markChosenCarryoversPlaced(
                $planId,
                self::intField($plan, 'household_id'),
                self::stringField($plan, 'week_start'),
                self::intField($plan, 'part')
            );
            $update = $this->db->prepare('UPDATE plan SET version = version + 1, reshuffle_count = ?, seed = ? '
                . 'WHERE id = ? AND version = ?');
            $update->execute([$reshuffles,
                $state['seed'], $planId, $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new PlanVersionConflict();
            }
            return ['plan_id' => $planId, 'version' => $expectedVersion + 1] + $result;
        });
        if ($writeStarted > 0) {
            $this->observe('write_and_commit', $writeStarted);
        }
        return $changed;
    }

    /** @return array{plan: array<string, int|float|string|null>, dates: list<string>, days: list<array{date: string, profile: string}>, slots: array<string, array<string, int|float|string|null>>, fixed: array<string, int>, recipes: list<PlanCandidate>, carryovers: list<array{recipe_id: int, from_date: string}>, earlier: list<array{date: string, recipe_id: int, kind: string}>, history: list<array{date: string, recipe_id: int}>, share: float, block_vegetarian: bool, seed: string} */
    private function loadState(int $planId, ?int $requestedRecipeId = null): array
    {
        $query = $this->db->prepare('SELECT * FROM plan WHERE id = ? FOR UPDATE');
        $query->execute([$planId]);
        $plan = $this->row($query);
        if ($plan === false) {
            throw new InvalidArgumentException('Unknown plan.');
        }
        $dates = json_decode(self::stringField($plan, 'days'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($dates) || !array_is_list($dates)) {
            throw new RuntimeException('Invalid planning days.');
        }
        $validDates = [];
        foreach ($dates as $date) {
            if (!is_string($date)) {
                throw new RuntimeException('Invalid planning date.');
            }
            $validDates[] = $date;
        }
        $days = $this->dayProfiles(self::intField($plan, 'household_id'), $validDates);
        $query = $this->db->prepare('SELECT date, recipe_id, status FROM plan_slot WHERE plan_id = ? ORDER BY date');
        $query->execute([$planId]);
        $slots = [];
        while (($row = $this->row($query)) !== false) {
            $slots[self::stringField($row, 'date')] = $row;
        }
        $fixed = $this->fixed($slots);
        $query = $this->db->prepare('SELECT c.recipe_id, s.date AS from_date FROM carryover c '
            . 'JOIN plan_slot s ON s.id = c.from_slot_id '
            . "WHERE c.household_id = ? AND c.target_week = ? AND c.target_part = ? AND c.status IN ('pending', 'placed') "
            . 'ORDER BY c.id');
        $query->execute([self::intField($plan, 'household_id'),
            self::stringField($plan, 'week_start'), self::intField($plan, 'part')]);
        $carryovers = [];
        while (($row = $this->row($query)) !== false) {
            $carryovers[] = ['recipe_id' => self::intField($row, 'recipe_id'),
                'from_date' => self::stringField($row, 'from_date')];
        }
        $query = $this->db->prepare('SELECT s.date, s.recipe_id, s.kind FROM plan_slot s '
            . 'JOIN plan p ON p.id = s.plan_id WHERE p.household_id = ? AND p.week_start = ? '
            . "AND p.part < ? AND s.status IN ('chosen', 'cooked', 'not_cooked', 'unconfirmed') "
            . 'AND s.recipe_id IS NOT NULL ORDER BY s.date');
        $query->execute([self::intField($plan, 'household_id'),
            self::stringField($plan, 'week_start'), self::intField($plan, 'part')]);
        $earlier = [];
        while (($row = $this->row($query)) !== false) {
            $earlier[] = ['date' => self::stringField($row, 'date'),
                'recipe_id' => self::intField($row, 'recipe_id'),
                'kind' => self::stringField($row, 'kind')];
        }
        $query = $this->db->prepare('SELECT cooked_on, recipe_id FROM meal_log WHERE household_id = ? '
            . 'AND cooked_on < ? AND cooked_on >= DATE_SUB(?, INTERVAL ? DAY) ORDER BY cooked_on, recipe_id');
        $query->execute([self::intField($plan, 'household_id'), self::stringField($plan, 'week_start'),
            self::stringField($plan, 'week_start'), $this->config->integer('variety.history_days')]);
        $history = [];
        while (($row = $this->row($query)) !== false) {
            $history[] = ['date' => self::stringField($row, 'cooked_on'),
                'recipe_id' => self::intField($row, 'recipe_id')];
        }
        $settings = $this->settings(self::intField($plan, 'household_id'));
        $share = is_numeric($settings['favourite_share'] ?? null) ? (float) $settings['favourite_share']
            : $this->config->decimal('builder.default_favourite_share');
        $blockVegetarian = $settings['block_vegetarian'] ?? false;
        if (!is_bool($blockVegetarian)) {
            throw new RuntimeException('Invalid vegetarian setting.');
        }
        $query = $this->db->prepare('SELECT timezone, state_version FROM household WHERE id = ? FOR UPDATE');
        $query->execute([self::intField($plan, 'household_id')]);
        $household = $this->row($query);
        if ($household === false) {
            throw new RuntimeException('Household is missing.');
        }
        $timezone = self::stringField($household, 'timezone');
        $stateVersion = self::intField($household, 'state_version');
        $today = ($this->now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone($timezone));
        $include = array_values(array_unique(array_merge(
            array_values($fixed),
            array_column($carryovers, 'recipe_id'),
            array_column($earlier, 'recipe_id'),
            array_column($history, 'recipe_id')
        )));
        $configVersion = $this->configVersion();
        $shortlist = $this->shortlistExists($planId)
            && self::nullableInteger($plan['shortlist_state_version'] ?? null) === $stateVersion
            && self::nullableInteger($plan['shortlist_config_version'] ?? null) === $configVersion;
        if (!$shortlist) {
            if (self::nullableInteger($plan['shortlist_config_version'] ?? null) !== $configVersion) {
                $this->config = EngineConfig::fromDatabase($this->db);
                $this->builder = new PlanBuilder($this->config, $this->observer);
                $this->candidates = new PlanCandidateRepository($this->db, $this->config);
            }
            $delete = $this->db->prepare('DELETE FROM plan_candidate WHERE plan_id = ?');
            $delete->execute([$planId]);
        } else {
            $this->saveCandidates($planId, $include);
            if ($requestedRecipeId !== null) {
                $this->saveCandidates($planId, [$requestedRecipeId]);
            }
        }
        $started = hrtime(true);
        $recipes = $this->candidates->load(
            self::intField($plan, 'household_id'),
            $today,
            $blockVegetarian,
            $include,
            $shortlist ? $planId : null,
            $requestedRecipeId === null ? [] : [$requestedRecipeId]
        );
        $this->observe('load_candidates', $started);
        if (!$shortlist) {
            $this->saveCandidates($planId, array_map(
                static fn (PlanCandidate $candidate): int => $candidate->id,
                $recipes
            ));
            $stamp = $this->db->prepare('UPDATE plan SET shortlist_state_version = ?, '
                . 'shortlist_config_version = ?, config_version = ? WHERE id = ?');
            $stamp->execute([$stateVersion, $configVersion, $configVersion, $planId]);
        }
        return ['plan' => $plan, 'dates' => $validDates, 'days' => $days, 'slots' => $slots, 'fixed' => $fixed,
            'recipes' => $recipes, 'carryovers' => $carryovers, 'earlier' => $earlier,
            'history' => $history, 'share' => $share,
            'block_vegetarian' => $blockVegetarian, 'seed' => self::stringField($plan, 'seed')];
    }

    /** @param array<string, array<string, int|float|string|null>> $slots
     *  @return array<string, int>
     */
    private function fixed(array $slots): array
    {
        $fixed = [];
        foreach ($slots as $date => $slot) {
            if (in_array($slot['status'], ['chosen', 'cooked', 'not_cooked', 'unconfirmed'], true)
                && $slot['recipe_id'] !== null) {
                $fixed[$date] = self::intField($slot, 'recipe_id');
            }
        }
        return $fixed;
    }

    /** @param array{plan: array<string, int|float|string|null>, days: list<array{date: string, profile: string}>, recipes: list<PlanCandidate>, share: float, seed: string, fixed: array<string, int>, carryovers: list<array{recipe_id: int, from_date: string}>, earlier: list<array{date: string, recipe_id: int, kind: string}>, history: list<array{date: string, recipe_id: int}>, block_vegetarian: bool} $state
     *  @return array{slots: array<string, array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}>, decks: array<string, list<array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}>>}
     */
    private function build(array $state): array
    {
        $setting = $this->settings(self::intField($state['plan'], 'household_id'))['plan_days'] ?? null;
        if ($setting !== null && !in_array($setting, ['all_seven', 'sunday_thursday'], true)) {
            throw new RuntimeException('Invalid plan day setting.');
        }
        $weekDays = $setting === null ? $this->config->integer('builder.default_plan_days')
            : $this->config->integer('builder.plan_days.' . $setting);
        return $this->builder->build(
            $state['days'],
            $state['recipes'],
            $state['share'],
            $state['seed'],
            $state['fixed'],
            $state['carryovers'],
            $state['earlier'],
            $state['block_vegetarian'],
            $weekDays,
            $state['history']
        );
    }

    /** @param list<string> $dates
     *  @return list<array{date: string, profile: string}>
     */
    private function dayProfiles(int $householdId, array $dates): array
    {
        $query = $this->db->prepare('SELECT d.weekday, COALESCE(p.profile_code, d.profile_code) AS profile '
            . 'FROM day_profile_default d LEFT JOIN day_profile p '
            . 'ON p.household_id = ? AND p.weekday = d.weekday');
        $query->execute([$householdId]);
        $profiles = [];
        while (($row = $this->row($query)) !== false) {
            $profiles[self::intField($row, 'weekday')] = self::stringField($row, 'profile');
        }
        $days = [];
        foreach ($dates as $date) {
            $weekday = (int) (new DateTimeImmutable($date))->format('w');
            $days[] = ['date' => $date, 'profile' => $profiles[$weekday]];
        }
        return $days;
    }

    /** @return array<string, mixed> */
    private function settings(int $householdId): array
    {
        $query = $this->db->prepare('SELECT `key`, value FROM household_setting WHERE household_id = ?');
        $query->execute([$householdId]);
        $settings = [];
        while (($row = $this->row($query)) !== false) {
            $settings[self::stringField($row, 'key')] = json_decode(
                self::stringField($row, 'value'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }
        return $settings;
    }

    /** @param array{slots: array<string, array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}>} $result
     *  @param array<string, int> $originalFixed
     *  @param array<string, string> $chosenDates
     *  @param array<string, int> $replacedFrom
     */
    private function writeOpenSlots(int $planId, int $householdId, array $result, array $originalFixed, string $defaultStatus, array $chosenDates = [], array $replacedFrom = []): void
    {
        $query = $this->db->prepare('INSERT INTO plan_slot '
            . '(plan_id, household_id, date, day_profile, recipe_id, chosen_by, status, kind, score, predicted_pref, explanation_json, replaced_from_recipe_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE '
            . 'recipe_id = VALUES(recipe_id), chosen_by = VALUES(chosen_by), status = VALUES(status), '
            . 'kind = VALUES(kind), score = VALUES(score), predicted_pref = VALUES(predicted_pref), '
            . 'explanation_json = VALUES(explanation_json), '
            . 'replaced_from_recipe_id = VALUES(replaced_from_recipe_id)');
        $profiles = $this->dayProfiles($householdId, array_keys($result['slots']));
        $profileByDate = [];
        foreach ($profiles as $profile) {
            $profileByDate[$profile['date']] = $profile['profile'];
        }
        foreach ($result['slots'] as $date => $slot) {
            if (isset($originalFixed[$date]) && !isset($chosenDates[$date])) {
                continue;
            }
            $chosenBy = $chosenDates[$date] ?? null;
            $breakdown = $slot['explanation']['breakdown'] ?? null;
            if (!is_array($breakdown) || !is_numeric($breakdown['pref'] ?? null)) {
                throw new RuntimeException('Planning score breakdown is missing.');
            }
            $query->execute([$planId, $householdId, $date, $profileByDate[$date], $slot['recipe_id'],
                $chosenBy, $chosenBy === null ? $defaultStatus : 'chosen', $slot['kind'], $slot['score'],
                (float) $breakdown['pref'],
                $chosenBy === null ? null : json_encode($slot['explanation'], JSON_THROW_ON_ERROR),
                $replacedFrom[$date] ?? null]);
        }
    }

    /** @param list<array{recipe_id: int, from_date: string}> $carryovers
     *  @param array{slots: array<string, array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}>} $result
     */
    private function markCarryoversPlaced(int $householdId, string $weekStart, int $part, array $carryovers, array $result): void
    {
        if ($carryovers === []) {
            return;
        }
        $placed = array_column(array_values($result['slots']), 'recipe_id');
        $query = $this->db->prepare("UPDATE carryover SET status = 'placed' WHERE household_id = ? "
            . "AND target_week = ? AND target_part = ? AND recipe_id = ? AND status = 'pending'");
        foreach ($carryovers as $carryover) {
            if (in_array($carryover['recipe_id'], $placed, true)) {
                $query->execute([$householdId, $weekStart, $part, $carryover['recipe_id']]);
            }
        }
    }

    private function markChosenCarryoversPlaced(int $planId, int $householdId, string $weekStart, int $part): void
    {
        $query = $this->db->prepare("UPDATE carryover c JOIN plan_slot s "
            . "ON s.household_id = c.household_id AND s.recipe_id = c.recipe_id "
            . "AND s.plan_id = ? AND s.status = 'chosen' "
            . "SET c.status = 'placed' WHERE c.household_id = ? AND c.target_week = ? "
            . "AND c.target_part = ? AND c.status = 'pending'");
        $query->execute([$planId, $householdId, $weekStart, $part]);
    }

    private function configVersion(): int
    {
        $query = $this->db->prepare('SELECT COALESCE(SUM(version), 1) FROM engine_config');
        $query->execute();
        return self::integer($query->fetchColumn());
    }

    private function observe(string $phase, int $started): void
    {
        if ($this->observer !== null) {
            ($this->observer)($phase, (hrtime(true) - $started) / 1_000_000);
        }
    }

    private function shortlistExists(int $planId): bool
    {
        $query = $this->db->prepare('SELECT recipe_id FROM plan_candidate WHERE plan_id = ? LIMIT 1');
        $query->execute([$planId]);
        return $query->fetchColumn() !== false;
    }

    /** @param list<int> $recipeIds */
    private function saveCandidates(int $planId, array $recipeIds): void
    {
        if ($recipeIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($recipeIds), '(?, ?)'));
        $parameters = [];
        foreach ($recipeIds as $recipeId) {
            $parameters[] = $planId;
            $parameters[] = $recipeId;
        }
        $query = $this->db->prepare('INSERT IGNORE INTO plan_candidate (plan_id, recipe_id) VALUES ' . $placeholders);
        $query->execute($parameters);
    }

    /** @return array<string, int|float|string|null>|false */
    private function row(PDOStatement $query): array|false
    {
        $raw = $query->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return false;
        }
        $row = [];
        foreach ($raw as $key => $value) {
            if (!is_int($value) && !is_float($value)
                && !is_string($value) && $value !== null) {
                throw new RuntimeException('Invalid planning query value.');
            }
            $row[$key] = $value;
        }
        return $row;
    }

    /** @param array<string, int|float|string|null> $row */
    private static function intField(array $row, string $key): int
    {
        return self::integer($row[$key] ?? null);
    }

    private static function integer(mixed $value): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1)) {
            return (int) $value;
        }
        throw new RuntimeException('Invalid planning integer.');
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return $value === null ? null : self::integer($value);
    }

    /** @param array<string, int|float|string|null> $row */
    private static function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('Invalid planning text field.');
        }
        return $value;
    }
}
