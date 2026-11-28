<?php

declare(strict_types=1);

namespace Matsvep\Engine;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Matsvep\Api\ApiError;
use Matsvep\Api\DbValue;
use Matsvep\Api\RecipeReader;
use Matsvep\Database\WriteTransaction;
use PDO;

final class PlanningService
{
    private readonly PlanManager $manager;
    private readonly PlanningWindow $window;

    public function __construct(
        private readonly PDO $db,
        private readonly EngineConfig $config,
        private readonly RecipeReader $recipes,
        private readonly HouseholdRecipeManager $householdRecipes,
        private readonly ?DateTimeImmutable $now = null
    ) {
        $this->manager = new PlanManager($db, $config, null, $now);
        $this->window = new PlanningWindow($now);
    }

    /** @return array{items:list<array<string,mixed>>,next_cursor:?string} */
    public function list(int $householdId, ?string $cursor): array
    {
        $after = $this->cursor($cursor);
        $limit = $this->config->integer('api.search_page_size');
        $query = $this->db->prepare('SELECT id FROM plan WHERE household_id = ? AND id > ? ORDER BY id LIMIT ?');
        $query->bindValue(1, $householdId, PDO::PARAM_INT);
        $query->bindValue(2, $after, PDO::PARAM_INT);
        $query->bindValue(3, $limit + 1, PDO::PARAM_INT);
        $query->execute();
        $ids = array_map(DbValue::integer(...), $query->fetchAll(PDO::FETCH_COLUMN));
        $more = count($ids) > $limit;
        $ids = array_slice($ids, 0, $limit);
        return ['items' => array_values(array_map(fn (int $id): array => $this->get($householdId, $id), $ids)),
            'next_cursor' => $more ? base64_encode((string) end($ids)) : null];
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed> */
    public function create(int $householdId, array $input): array
    {
        if (!is_string($input['week_start'] ?? null) || !is_int($input['part'] ?? null)) {
            throw new ApiError(422, 'validation_failed');
        }
        $context = $this->context($householdId);
        $dates = $this->window->dates(
            $context['timezone'],
            $input['week_start'],
            $input['part'],
            $context['mode'],
            $context['day_count']
        );
        try {
            $result = $this->manager->prepare($householdId, $input['week_start'], $input['part'], $dates);
        } catch (InvalidArgumentException $error) {
            throw new ApiError(422, 'validation_failed');
        }
        return $this->get($householdId, $result['plan_id']);
    }

    /** @return array<string,mixed> */
    public function get(int $householdId, int $planId): array
    {
        $plan = $this->requirePlan($householdId, $planId);
        $query = $this->db->prepare('SELECT * FROM plan_slot WHERE plan_id = ? AND household_id = ? ORDER BY date');
        $query->execute([$planId, $householdId]);
        $slots = [];
        foreach (DbValue::rows($query->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $slots[] = $this->slot($row);
        }
        $carryovers = $this->db->prepare('SELECT c.id, c.recipe_id, c.from_slot_id, s.date AS from_date '
            . 'FROM carryover c JOIN plan_slot s ON s.id = c.from_slot_id AND s.household_id = c.household_id '
            . "WHERE c.household_id = ? AND c.target_week = ? AND c.target_part = ? AND c.status = 'pending' "
            . 'ORDER BY c.id');
        $carryovers->execute([$householdId, $plan['week_start'], $plan['part']]);
        $pending = [];
        foreach (DbValue::rows($carryovers->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $pending[] = ['id' => DbValue::integer($row['id']), 'recipe_id' => DbValue::integer($row['recipe_id']),
                'from_slot_id' => DbValue::integer($row['from_slot_id']), 'from_date' => DbValue::text($row['from_date'])];
        }
        return ['id' => $planId, 'week_start' => $plan['week_start'], 'part' => DbValue::integer($plan['part']),
            'days' => json_decode(DbValue::text($plan['days']), true, 512, JSON_THROW_ON_ERROR),
            'status' => $plan['status'], 'version' => DbValue::integer($plan['version']),
            'config_version' => DbValue::integer($plan['config_version']), 'slots' => $slots,
            'pending_carryovers' => $pending];
    }

    /** @return array<string,mixed> */
    public function deck(int $householdId, int $planId, string $date, ?string $cursor): array
    {
        $plan = $this->openPlan($householdId, $planId);
        if (!in_array($date, $this->planDays($plan), true)) {
            throw new ApiError(404, 'not_found');
        }
        $offset = $this->cursor($cursor);
        $result = $this->manager->view($planId);
        $cards = $result['decks'][$date] ?? [];
        $pageSize = $this->config->integer('deck.size');
        $page = array_slice($cards, $offset, $pageSize);
        $summaries = $this->recipes->summaries(array_column($page, 'recipe_id'));
        $items = [];
        foreach ($page as $card) {
            if (!isset($summaries[$card['recipe_id']])) {
                continue;
            }
            $items[] = ['recipe' => $summaries[$card['recipe_id']], 'kind' => $card['kind'],
                'reasons' => $card['explanation']['reasons'], 'breakdown' => $card['explanation']['breakdown']];
        }
        $next = count($cards) > $offset + $pageSize ? base64_encode((string) ($offset + $pageSize)) : null;
        return ['plan_id' => $planId, 'date' => $date, 'version' => $result['version'],
            'items' => $items, 'next_cursor' => $next];
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed> */
    public function change(int $householdId, int $planId, ?int $slotId, string $action, array $input): array
    {
        return WriteTransaction::run($this->db, fn (): array => $this->changeInTransaction(
            $householdId,
            $planId,
            $slotId,
            $action,
            $input
        ));
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed> */
    private function changeInTransaction(int $householdId, int $planId, ?int $slotId, string $action, array $input): array
    {
        $this->openPlan($householdId, $planId);
        $version = $input['expected_version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new ApiError(422, 'validation_failed');
        }
        $date = null;
        $recipeId = null;
        if ($slotId !== null) {
            $slot = $this->requireSlot($householdId, $planId, $slotId);
            $date = DbValue::text($slot['date']);
            $recipeId = $input['recipe_id'] ?? null;
            if (!is_int($recipeId) || $recipeId < 1) {
                throw new ApiError(422, 'validation_failed');
            }
        }
        try {
            $result = match ($action) {
                'choose' => $this->manager->choose($planId, $version, (string) $date, (int) $recipeId),
                'replace' => $this->manager->replace($planId, $version, (string) $date, (int) $recipeId),
                'autofill' => $this->manager->autofill($planId, $version),
                'shuffle' => $this->manager->shuffle($planId, $version),
                default => throw new InvalidArgumentException('Unknown planning action.'),
            };
        } catch (PlanVersionConflict $error) {
            throw new ApiError(409, 'plan_version_conflict');
        } catch (InvalidArgumentException $error) {
            throw new ApiError(422, 'validation_failed');
        }
        if ($action === 'choose' && isset($input['swipe'])) {
            $swipe = $input['swipe'];
            if (!is_array($swipe)) {
                throw new ApiError(422, 'validation_failed');
            }
            $this->insertSwipe($householdId, $planId, (string) $date, (int) $recipeId, DbValue::map($swipe), 'pick');
        }
        $event = match ($action) {
            'choose' => 'recipe_chosen',
            'replace' => 'recipe_replaced',
            'autofill' => 'plan_autofilled',
            default => 'plan_shuffled',
        };
        return ['version' => $result['version'], 'plan' => $this->get($householdId, $planId),
            'event' => self::event($event)];
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed> */
    public function swipe(int $householdId, int $planId, array $input): array
    {
        return WriteTransaction::run($this->db, fn (): array => $this->swipeInTransaction($householdId, $planId, $input));
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed> */
    private function swipeInTransaction(int $householdId, int $planId, array $input): array
    {
        $this->openPlan($householdId, $planId);
        $date = $input['date'] ?? null;
        $recipeId = $input['recipe_id'] ?? null;
        $action = $input['action'] ?? null;
        if (!is_string($date) || !is_int($recipeId) || !in_array($action, ['next', 'undo'], true)
            || !is_string($input['session_id'] ?? null) || !is_int($input['position'] ?? null)) {
            throw new ApiError(422, 'validation_failed');
        }
        $plan = $this->requirePlan($householdId, $planId);
        if (!in_array($date, $this->planDays($plan), true)) {
            throw new ApiError(404, 'not_found');
        }
        $candidate = $this->db->prepare('SELECT 1 FROM plan_candidate WHERE plan_id = ? AND recipe_id = ?');
        $candidate->execute([$planId, $recipeId]);
        if ($candidate->fetchColumn() === false) {
            throw new ApiError(404, 'not_found');
        }
        if ($action === 'undo') {
            $latest = $this->db->prepare('SELECT recipe_id, position, action FROM swipe_event WHERE household_id = ? AND plan_id = ? AND date = ? AND session_id = ? ORDER BY id DESC LIMIT 1');
            $latest->execute([$householdId, $planId, $date, $input['session_id']]);
            $previous = $latest->fetch(PDO::FETCH_ASSOC);
            if (!is_array($previous) || $previous['action'] !== 'next'
                || DbValue::integer($previous['recipe_id']) !== $recipeId
                || DbValue::integer($previous['position']) !== $input['position']) {
                throw new ApiError(422, 'validation_failed');
            }
        }
        $id = $this->insertSwipe($householdId, $planId, $date, $recipeId, $input, $action);
        $count = $this->db->prepare('SELECT COUNT(*) FROM ('
            . 'SELECT session_id, MAX(id) AS last_id FROM swipe_event '
            . "WHERE household_id = ? AND recipe_id = ? AND action IN ('next', 'undo') GROUP BY session_id"
            . ") recent JOIN swipe_event e ON e.id = recent.last_id WHERE e.action = 'next'");
        $count->execute([$householdId, $recipeId]);
        $activeSessions = DbValue::integer($count->fetchColumn());
        if ($activeSessions >= $this->config->integer('swipe.reject_sessions')) {
            if ($action === 'next') {
                $skip = $this->db->prepare("INSERT INTO slot_skip (household_id, recipe_id, reason, expires_at) SELECT ?, ?, 'swipe_rejects', DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY) WHERE NOT EXISTS (SELECT 1 FROM slot_skip WHERE household_id = ? AND recipe_id = ? AND reason = 'swipe_rejects' AND expires_at > UTC_TIMESTAMP())");
                $skip->execute([$householdId, $recipeId, $this->config->integer('swipe.reject_days'), $householdId, $recipeId]);
            }
        } elseif ($action === 'undo') {
            $delete = $this->db->prepare("DELETE FROM slot_skip WHERE household_id = ? AND recipe_id = ? AND reason = 'swipe_rejects' AND expires_at > UTC_TIMESTAMP()");
            $delete->execute([$householdId, $recipeId]);
        }
        return ['id' => $id, 'event' => self::event('swipe_recorded')];
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed> */
    public function answer(int $householdId, int $planId, int $slotId, array $input): array
    {
        return WriteTransaction::run($this->db, fn (): array => $this->answerInTransaction(
            $householdId,
            $planId,
            $slotId,
            $input
        ));
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed> */
    private function answerInTransaction(int $householdId, int $planId, int $slotId, array $input): array
    {
        $this->requirePlan($householdId, $planId);
        $slot = $this->requireSlot($householdId, $planId, $slotId, true);
        $today = ($this->now ?? new DateTimeImmutable('now'))
            ->setTimezone(new DateTimeZone($this->context($householdId)['timezone']))->format('Y-m-d');
        if (DbValue::text($slot['date']) > $today) {
            throw new ApiError(409, 'slot_not_open');
        }
        if ($slot['status'] !== 'chosen') {
            throw new ApiError(409, 'already_answered');
        }
        $outcome = $input['outcome'] ?? null;
        if (!in_array($outcome, ['cooked', 'not_cooked'], true)) {
            throw new ApiError(422, 'validation_failed');
        }
        $recipeId = DbValue::integer($slot['recipe_id']);
        if ($outcome === 'cooked') {
            $rating = $input['rating'] ?? null;
            $query = $this->db->prepare('SELECT rating_source FROM household_recipe WHERE household_id = ? AND recipe_id = ?');
            $query->execute([$householdId, $recipeId]);
            $state = $query->fetch(PDO::FETCH_ASSOC);
            $needsRating = !is_array($state) || $state['rating_source'] !== 'user';
            if ($rating !== null && (!is_int($rating) || $rating < 1 || $rating > 5)) {
                throw new ApiError(422, 'validation_failed');
            }
            if (($needsRating && $rating === null) || (!$needsRating && $rating !== null)) {
                throw new ApiError(422, 'validation_failed');
            }
            $insert = $this->db->prepare('INSERT INTO meal_log (household_id, recipe_id, cooked_on, plan_slot_id) VALUES (?, ?, ?, ?)');
            $insert->execute([$householdId, $recipeId, $slot['date'], $slotId]);
            $update = $this->db->prepare('INSERT INTO household_recipe (household_id, recipe_id, times_cooked, last_cooked_at, wishlist) VALUES (?, ?, 1, ?, FALSE) ON DUPLICATE KEY UPDATE times_cooked = times_cooked + 1, last_cooked_at = VALUES(last_cooked_at), wishlist = FALSE');
            $update->execute([$householdId, $recipeId, $slot['date']]);
            if ($rating !== null) {
                $this->householdRecipes->rate($householdId, $recipeId, $rating);
            }
        } else {
            $choice = $input['not_cooked_action'] ?? 'carryover';
            if (!in_array($choice, ['carryover', 'wishlist', 'remove'], true)) {
                throw new ApiError(422, 'validation_failed');
            }
            if ($choice === 'carryover') {
                $this->carryOver($householdId, $planId, $slotId, $recipeId);
            } elseif ($choice === 'wishlist') {
                $this->householdRecipes->wishlist($householdId, $recipeId, true);
            } else {
                $this->householdRecipes->libraryStatus($householdId, $recipeId, 'removed');
            }
        }
        $update = $this->db->prepare('UPDATE plan_slot SET status = ? WHERE id = ? AND household_id = ?');
        $update->execute([$outcome, $slotId, $householdId]);
        return ['slot' => $this->slot($this->requireSlot($householdId, $planId, $slotId)),
            'event' => self::event($outcome === 'cooked' ? 'dinner_confirmed' : 'dinner_not_cooked')];
    }

    /** @return array{timezone:string,mode:string,day_count:int} */
    private function context(int $householdId): array
    {
        $query = $this->db->prepare('SELECT timezone FROM household WHERE id = ?');
        $query->execute([$householdId]);
        $timezone = $query->fetchColumn();
        if (!is_string($timezone)) {
            throw new ApiError(404, 'not_found');
        }
        $query = $this->db->prepare('SELECT `key`, value FROM household_setting WHERE household_id = ?');
        $query->execute([$householdId]);
        $settings = [];
        foreach (DbValue::rows($query->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $settings[DbValue::text($row['key'])] = json_decode(DbValue::text($row['value']), true, 512, JSON_THROW_ON_ERROR);
        }
        $mode = $settings['planning_mode'] ?? 'whole_week';
        $days = $settings['plan_days'] ?? null;
        if (!is_string($mode) || ($days !== null && !is_string($days))) {
            throw new ApiError(500, 'internal_error');
        }
        return ['timezone' => $timezone, 'mode' => $mode,
            'day_count' => $days === null ? $this->config->integer('builder.default_plan_days')
                : $this->config->integer('builder.plan_days.' . $days)];
    }

    /** @return array<string,mixed> */
    private function openPlan(int $householdId, int $planId): array
    {
        $plan = $this->requirePlan($householdId, $planId);
        $context = $this->context($householdId);
        $this->window->dates(
            $context['timezone'],
            DbValue::text($plan['week_start']),
            DbValue::integer($plan['part']),
            $context['mode'],
            $context['day_count']
        );
        return $plan;
    }

    /** @return array<string,mixed> */
    private function requirePlan(int $householdId, int $planId): array
    {
        $query = $this->db->prepare('SELECT * FROM plan WHERE id = ? AND household_id = ?');
        $query->execute([$planId, $householdId]);
        $plan = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($plan)) {
            throw new ApiError(404, 'not_found');
        }
        return $plan;
    }

    /** @return array<string,mixed> */
    private function requireSlot(int $householdId, int $planId, int $slotId, bool $forUpdate = false): array
    {
        $query = $this->db->prepare('SELECT * FROM plan_slot WHERE id = ? AND plan_id = ? AND household_id = ?'
            . ($forUpdate ? ' FOR UPDATE' : ''));
        $query->execute([$slotId, $planId, $householdId]);
        $slot = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($slot)) {
            throw new ApiError(404, 'not_found');
        }
        return $slot;
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed> */
    private function slot(array $row): array
    {
        $explanation = $row['explanation_json'] === null ? null
            : json_decode(DbValue::text($row['explanation_json']), true, 512, JSON_THROW_ON_ERROR);
        if ($explanation !== null && !is_array($explanation)) {
            throw new ApiError(500, 'internal_error');
        }
        $slot = ['id' => DbValue::integer($row['id']), 'date' => $row['date'],
            'day_profile' => $row['day_profile'], 'status' => $row['status'],
            'recipe_id' => $row['recipe_id'] === null ? null : DbValue::integer($row['recipe_id']),
            'reasons' => is_array($explanation) ? ($explanation['reasons'] ?? []) : []];
        if ($row['chosen_by'] !== null) {
            $slot['chosen_by'] = $row['chosen_by'];
        }
        if ($row['kind'] !== null) {
            $slot['kind'] = $row['kind'];
        }
        if ($row['score'] !== null) {
            $slot['score'] = DbValue::decimal($row['score']);
        }
        if ($row['predicted_pref'] !== null) {
            $slot['predicted_pref'] = DbValue::decimal($row['predicted_pref']);
        }
        if (is_array($explanation)) {
            $slot['breakdown'] = $explanation['breakdown'];
        }
        return $slot;
    }

    /** @param array<string,mixed> $swipe */
    private function insertSwipe(int $householdId, int $planId, string $date, int $recipeId, array $swipe, string $action): int
    {
        $session = $swipe['session_id'] ?? null;
        $position = $swipe['position'] ?? null;
        if (!is_string($session) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $session) !== 1
            || !is_int($position) || $position < 0) {
            throw new ApiError(422, 'validation_failed');
        }
        $query = $this->db->prepare('INSERT INTO swipe_event (household_id, plan_id, date, recipe_id, position, action, session_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $query->execute([$householdId, $planId, $date, $recipeId, $position, $action, $session]);
        return DbValue::integer($this->db->lastInsertId());
    }

    private function carryOver(int $householdId, int $planId, int $slotId, int $recipeId): void
    {
        $plan = $this->requirePlan($householdId, $planId);
        $context = $this->context($householdId);
        $part = $context['mode'] === 'split_week' && DbValue::integer($plan['part']) === 1 ? 2 : 1;
        $start = new DateTimeImmutable(DbValue::text($plan['week_start']), new DateTimeZone($context['timezone']));
        $target = $part === 2 ? $start : $start->modify('+7 days');
        $query = $this->db->prepare('INSERT INTO carryover (household_id, recipe_id, from_slot_id, target_week, target_part) VALUES (?, ?, ?, ?, ?)');
        $query->execute([$householdId, $recipeId, $slotId, $target->format('Y-m-d'), $part]);
    }

    private function cursor(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }
        $value = base64_decode($cursor, true);
        if ($value === false || preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1) {
            throw new ApiError(400, 'invalid_cursor');
        }
        return (int) $value;
    }

    /** @param array<string,mixed> $plan
     *  @return list<string> */
    private function planDays(array $plan): array
    {
        $days = json_decode(DbValue::text($plan['days']), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($days) || !array_is_list($days)) {
            throw new ApiError(500, 'internal_error');
        }
        $result = [];
        foreach ($days as $day) {
            if (!is_string($day)) {
                throw new ApiError(500, 'internal_error');
            }
            $result[] = $day;
        }
        return $result;
    }

    /** @return array{code:string,params:array<string,mixed>} */
    private static function event(string $code): array
    {
        return ['code' => $code, 'params' => []];
    }
}
