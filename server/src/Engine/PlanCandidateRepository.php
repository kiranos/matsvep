<?php

declare(strict_types=1);

namespace Matsvep\Engine;

use DateTimeImmutable;
use Generator;
use PDO;
use PDOStatement;
use RuntimeException;

final class PlanCandidateRepository
{
    public function __construct(private readonly PDO $db, private readonly EngineConfig $config)
    {
    }

    /** @param list<int> $alwaysInclude
     *  @param list<int> $requestedInclude
     *  @return list<PlanCandidate>
     */
    public function load(int $householdId, DateTimeImmutable $today, bool $blockVegetarian, array $alwaysInclude = [], ?int $planId = null, array $requestedInclude = []): array
    {
        $date = $today->format('Y-m-d');
        $includeClause = $alwaysInclude === [] ? '' : ' OR r.id IN (' . implode(',', array_fill(0, count($alwaysInclude), '?')) . ')';
        $planJoin = $planId === null ? '' : ' JOIN plan_candidate pc ON pc.plan_id = ? AND pc.recipe_id = r.id ';
        $query = $this->db->prepare(
            'SELECT r.id, COALESCE(r.dish_group_id, -r.id) AS group_id, r.total_time_min, '
            . 'r.leftover_score, r.festive_score, r.effort, r.main_protein, r.cuisine, r.carb_base, '
            . 'r.meal_type, r.is_vegetarian, hr.rating, hr.wishlist '
            . 'FROM recipe r ' . $planJoin
            . 'LEFT JOIN household_recipe hr ON hr.household_id = ? AND hr.recipe_id = r.id '
            . 'WHERE ((r.active = TRUE AND r.is_dinner_candidate = TRUE '
            . "AND (hr.status IS NULL OR hr.status = 'library') "
            . 'AND (hr.rating IS NULL OR hr.rating > ?) '
            . 'AND (? = FALSE OR r.is_vegetarian IS NULL OR r.is_vegetarian = FALSE) '
            . 'AND NOT EXISTS (SELECT 1 FROM household_recipe cooked '
            . 'JOIN recipe cooked_recipe ON cooked_recipe.id = cooked.recipe_id '
            . 'WHERE cooked.household_id = ? AND cooked.last_cooked_at IS NOT NULL '
            . 'AND (cooked.recipe_id = r.id OR (r.dish_group_id IS NOT NULL '
            . 'AND cooked_recipe.dish_group_id = r.dish_group_id)) '
            . 'AND DATEDIFF(?, cooked.last_cooked_at) < CASE '
            . 'WHEN cooked.rating = 5 THEN ? WHEN cooked.rating = 3 THEN ? ELSE ? END))'
            . $includeClause . ') '
            . 'ORDER BY r.id'
        );
        $query->execute(array_merge($planId === null ? [] : [$planId], [
            $householdId,
            $this->config->integer('filter.hide_at_or_below'),
            $blockVegetarian ? 1 : 0,
            $householdId,
            $date,
            $this->config->integer('cooldown.days.5'),
            $this->config->integer('cooldown.days.3'),
            $this->config->integer('cooldown.days.4'),
        ], $alwaysInclude));
        /** @var array<int, array<string, int|float|string|null>> $rows */
        $rows = [];
        foreach ($this->rows($query) as $row) {
            $rows[(int) $row['id']] = $row;
        }
        if ($rows === []) {
            return [];
        }
        $affinities = $this->affinities($householdId);
        $tags = $this->tags($rows, $planId !== null);
        $ingredients = $this->ingredients($rows, $planId !== null);
        $siblings = $this->siblings($householdId);
        $skips = $this->skips($householdId, $date);
        $groupCookedDays = $this->groupCookedDays($householdId, $date);
        $all = [];
        foreach ($rows as $id => $row) {
            $features = [];
            foreach (['protein' => 'main_protein', 'cuisine' => 'cuisine', 'carb' => 'carb_base',
                'meal_type' => 'meal_type'] as $type => $column) {
                $value = $row[$column];
                if (is_string($value) && isset($affinities[$type][$value])) {
                    $features[$type][] = $affinities[$type][$value];
                }
            }
            foreach ($ingredients[$id] ?? [] as $ingredientId) {
                $affinity = $affinities['ingredient'][(string) $ingredientId] ?? null;
                if ($affinity !== null) {
                    $features['ingredients'][] = $affinity;
                }
            }
            $meanConfidence = 0.0;
            $featureCount = 0;
            foreach ($features as $items) {
                foreach ($items as $item) {
                    $meanConfidence += $item['confidence'];
                    $featureCount++;
                }
            }
            $group = (int) $row['group_id'];
            $sibling = $siblings[$group] ?? null;
            $all[] = new PlanCandidate(
                $id,
                $group,
                $row['rating'] === null ? null : (int) $row['rating'],
                $sibling['mean_rating'] ?? null,
                null,
                $row['total_time_min'] === null ? null : (int) $row['total_time_min'],
                (float) ($row['leftover_score'] ?? 0.5),
                (float) ($row['festive_score'] ?? 0.5),
                (int) ($row['effort'] ?? 2),
                $tags[$id] ?? [],
                $features,
                $groupCookedDays[$group] ?? null,
                $featureCount === 0 ? 0.0 : $meanConfidence / $featureCount,
                is_string($row['main_protein']) ? $row['main_protein'] : null,
                is_string($row['cuisine']) ? $row['cuisine'] : null,
                is_string($row['carb_base']) ? $row['carb_base'] : null,
                $ingredients[$id] ?? [],
                $row['is_vegetarian'] === null ? null : (bool) $row['is_vegetarian'],
                (bool) ($row['wishlist'] ?? false),
                $skips[$id]['days_since_replaced'] ?? null,
                $skips[$id]['rejected_sessions'] ?? 0,
                $skips[$id]['days_since_rejected'] ?? null,
                null,
                is_string($row['meal_type']) ? $row['meal_type'] : null,
            );
        }
        $kept = [];
        foreach ((new PlanCandidatePool($this->config))->select($all) as $candidate) {
            $kept[$candidate->id] = $candidate;
        }
        foreach ($all as $candidate) {
            if (in_array($candidate->id, $alwaysInclude, true)
                || in_array($candidate->id, $requestedInclude, true)) {
                $kept[$candidate->id] = $candidate;
            }
        }
        ksort($kept, SORT_NUMERIC);
        return array_values($kept);
    }

    /** @return array<string, array<int|string, array{affinity: float, confidence: float}>> */
    private function affinities(int $householdId): array
    {
        $query = $this->db->prepare('SELECT feature_type, feature_value, score, weight_sum FROM affinity WHERE household_id = ?');
        $query->execute([$householdId]);
        $values = [];
        $k0 = $this->config->decimal('affinity.conf_k0');
        foreach ($this->rows($query) as $row) {
            $weight = (float) $row['weight_sum'];
            $values[(string) $row['feature_type']][(string) $row['feature_value']] = [
                'affinity' => (float) $row['score'], 'confidence' => $weight / ($weight + $k0),
            ];
        }
        return $values;
    }

    /** @param array<int, array<string, int|float|string|null>> $rows
     *  @return array<int, list<string>>
     */
    private function tags(array $rows, bool $shortlist): array
    {
        $where = $shortlist ? ' WHERE recipe_id IN (' . implode(',', array_fill(0, count($rows), '?')) . ')' : '';
        $query = $this->db->prepare('SELECT recipe_id, tag FROM recipe_tag' . $where . ' ORDER BY recipe_id, tag');
        $query->execute($shortlist ? array_keys($rows) : []);
        $tags = [];
        foreach ($this->rows($query) as $row) {
            $id = (int) $row['recipe_id'];
            if (isset($rows[$id])) {
                $tags[$id][] = (string) $row['tag'];
            }
        }
        return $tags;
    }

    /** @param array<int, array<string, int|float|string|null>> $rows
     *  @return array<int, list<int>>
     */
    private function ingredients(array $rows, bool $shortlist): array
    {
        $where = $shortlist ? ' AND recipe_id IN (' . implode(',', array_fill(0, count($rows), '?')) . ')' : '';
        $query = $this->db->prepare('SELECT recipe_id, ingredient_id FROM recipe_ingredient '
            . 'WHERE is_main = TRUE AND ingredient_id IS NOT NULL' . $where . ' ORDER BY recipe_id, position');
        $query->execute($shortlist ? array_keys($rows) : []);
        $ingredients = [];
        foreach ($this->rows($query) as $row) {
            $id = (int) $row['recipe_id'];
            if (isset($rows[$id])) {
                $ingredients[$id][] = (int) $row['ingredient_id'];
            }
        }
        return $ingredients;
    }

    /** @return array<int, array{mean_rating: float, recipe_id: int}> */
    private function siblings(int $householdId): array
    {
        $query = $this->db->prepare('SELECT r.dish_group_id, AVG(hr.rating) AS mean_rating, MIN(r.id) AS recipe_id '
            . 'FROM household_recipe hr JOIN recipe r ON r.id = hr.recipe_id '
            . 'WHERE hr.household_id = ? AND hr.rating IS NOT NULL AND r.dish_group_id IS NOT NULL '
            . 'GROUP BY r.dish_group_id');
        $query->execute([$householdId]);
        $siblings = [];
        foreach ($this->rows($query) as $row) {
            $siblings[(int) $row['dish_group_id']] = [
                'mean_rating' => (float) $row['mean_rating'], 'recipe_id' => (int) $row['recipe_id'],
            ];
        }
        return $siblings;
    }

    /** @return array<int, array{days_since_replaced: ?int, rejected_sessions: int, days_since_rejected: ?int}> */
    private function skips(int $householdId, string $date): array
    {
        $query = $this->db->prepare('SELECT recipe_id, reason, DATEDIFF(?, MAX(skipped_at)) AS days_since, '
            . 'COUNT(*) AS occurrences FROM slot_skip WHERE household_id = ? AND expires_at >= ? '
            . 'GROUP BY recipe_id, reason');
        $query->execute([$date, $householdId, $date]);
        $skips = [];
        foreach ($this->rows($query) as $row) {
            $id = (int) $row['recipe_id'];
            $skips[$id] ??= ['days_since_replaced' => null, 'rejected_sessions' => 0, 'days_since_rejected' => null];
            if ($row['reason'] === 'replaced') {
                $skips[$id]['days_since_replaced'] = (int) $row['days_since'];
            } else {
                $skips[$id]['rejected_sessions'] = (int) $row['occurrences'];
                $skips[$id]['days_since_rejected'] = (int) $row['days_since'];
            }
        }
        return $skips;
    }

    /** @return array<int, int> */
    private function groupCookedDays(int $householdId, string $date): array
    {
        $query = $this->db->prepare('SELECT COALESCE(r.dish_group_id, -r.id) AS group_id, '
            . 'DATEDIFF(?, MAX(hr.last_cooked_at)) AS days_since FROM household_recipe hr '
            . 'JOIN recipe r ON r.id = hr.recipe_id WHERE hr.household_id = ? '
            . 'AND hr.last_cooked_at IS NOT NULL GROUP BY group_id');
        $query->execute([$date, $householdId]);
        $days = [];
        foreach ($this->rows($query) as $row) {
            $days[(int) $row['group_id']] = (int) $row['days_since'];
        }
        return $days;
    }

    /** @return Generator<int, array<string, int|float|string|null>> */
    private function rows(PDOStatement $query): Generator
    {
        while (($raw = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = [];
            foreach ($raw as $key => $value) {
                if (!is_int($value) && !is_float($value)
                    && !is_string($value) && $value !== null) {
                    throw new RuntimeException('Invalid planning query value.');
                }
                $row[$key] = $value;
            }
            yield $row;
        }
    }
}
