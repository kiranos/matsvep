<?php

declare(strict_types=1);

namespace Matsvep\Engine;

use Matsvep\Api\ApiError;
use Matsvep\Api\DbValue;
use Matsvep\Database\WriteTransaction;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class HouseholdRecipeManager
{
    public function __construct(
        private readonly PDO $db,
        private readonly Learner $learner,
        private readonly EngineConfig $config,
        private readonly ?DateTimeImmutable $now = null
    )
    {
    }

    /** @return array<string,mixed> */
    public function rate(int $householdId, int $recipeId, int $rating): array
    {
        return WriteTransaction::run($this->db, function () use ($householdId, $recipeId, $rating): array {
            $this->requireRecipe($recipeId);
            $this->learner->setRating($householdId, $recipeId, $rating);
            return $this->state($householdId, $recipeId, 'rating_saved');
        });
    }

    /** @return array<string,mixed> */
    public function wishlist(int $householdId, int $recipeId, bool $wishlisted): array
    {
        return WriteTransaction::run($this->db, function () use ($householdId, $recipeId, $wishlisted): array {
            $this->requireRecipe($recipeId);
            $plans = $wishlisted ? $this->openPlans($householdId) : [];
            $write = $this->db->prepare('INSERT INTO household_recipe (household_id, recipe_id, wishlist) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE wishlist = VALUES(wishlist)');
            $write->execute([$householdId, $recipeId, $wishlisted ? 1 : 0]);
            if ($plans !== []) {
                $this->addEligibleWishToPlans($householdId, $recipeId, $plans);
            }
            return $this->state($householdId, $recipeId, 'wishlist_updated');
        });
    }

    /** @return list<int> */
    private function openPlans(int $householdId): array
    {
        $query = $this->db->prepare('SELECT timezone FROM household WHERE id = ?');
        $query->execute([$householdId]);
        $timezone = $query->fetchColumn();
        if (!is_string($timezone)) {
            throw new RuntimeException('Household timezone is missing.');
        }
        $today = ($this->now ?? new DateTimeImmutable('now'))
            ->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
        $query = $this->db->prepare('SELECT p.id FROM plan p WHERE p.household_id = ? '
            . "AND p.status = 'ready' AND EXISTS (SELECT 1 FROM plan_slot s "
            . "WHERE s.plan_id = p.id AND s.status = 'proposed' AND s.date >= ?) "
            . 'ORDER BY p.id FOR UPDATE');
        $query->execute([$householdId, $today]);
        $plans = [];
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $planId) {
            $plans[] = DbValue::integer($planId);
        }
        return $plans;
    }

    /** @param list<int> $plans */
    private function addEligibleWishToPlans(int $householdId, int $recipeId, array $plans): void
    {
        $settings = (new HouseholdManager($this->db, $this->config))->settings($householdId);
        $blockVegetarian = $settings['block_vegetarian'] ?? null;
        if (!is_bool($blockVegetarian)) {
            throw new RuntimeException('Invalid vegetarian setting.');
        }
        $timezoneQuery = $this->db->prepare('SELECT timezone FROM household WHERE id = ?');
        $timezoneQuery->execute([$householdId]);
        $timezone = $timezoneQuery->fetchColumn();
        if (!is_string($timezone)) {
            throw new RuntimeException('Household timezone is missing.');
        }
        $today = ($this->now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone($timezone));
        $candidates = (new PlanCandidateRepository($this->db, $this->config))->load(
            $householdId,
            $today,
            $blockVegetarian,
            [],
            null,
            [],
            [$recipeId]
        );
        if ($candidates === [] || !$candidates[0]->eligible) {
            return;
        }
        $insert = $this->db->prepare('INSERT IGNORE INTO plan_candidate (plan_id, recipe_id) VALUES (?, ?)');
        foreach ($plans as $planId) {
            $insert->execute([$planId, $recipeId]);
        }
    }

    /** @return array<string,mixed> */
    public function libraryStatus(int $householdId, int $recipeId, string $status): array
    {
        if (!in_array($status, ['library', 'removed', 'blocked'], true)) {
            throw new ApiError(422, 'validation_failed');
        }
        return WriteTransaction::run($this->db, function () use ($householdId, $recipeId, $status): array {
            $this->requireRecipe($recipeId);
            $write = $this->db->prepare('INSERT INTO household_recipe (household_id, recipe_id, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)');
            $write->execute([$householdId, $recipeId, $status]);
            return $this->state($householdId, $recipeId, 'library_status_updated');
        });
    }

    private function requireRecipe(int $recipeId): void
    {
        $query = $this->db->prepare('SELECT 1 FROM recipe WHERE id = ? AND active = TRUE');
        $query->execute([$recipeId]);
        if ($query->fetchColumn() === false) {
            throw new ApiError(404, 'not_found');
        }
    }

    /** @return array<string,mixed> */
    private function state(int $householdId, int $recipeId, string $event): array
    {
        $query = $this->db->prepare('SELECT status, wishlist, rating, rating_source FROM household_recipe WHERE household_id = ? AND recipe_id = ?');
        $query->execute([$householdId, $recipeId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiError(404, 'not_found');
        }
        $state = ['recipe_id' => $recipeId, 'status' => $row['status'], 'wishlist' => DbValue::integer($row['wishlist']) === 1,
            'rating' => $row['rating'] === null ? null : DbValue::integer($row['rating']),
            'event' => ['code' => $event, 'params' => []]];
        if ($row['rating_source'] !== null) {
            $state['rating_source'] = $row['rating_source'];
        }
        return $state;
    }
}
