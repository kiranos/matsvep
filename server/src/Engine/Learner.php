<?php

declare(strict_types=1);

namespace Matsvep\Engine;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class Learner
{
    private readonly AffinityCalculator $calculator;

    public function __construct(private readonly PDO $db, private readonly EngineConfig $config)
    {
        $this->calculator = new AffinityCalculator($config);
    }

    public function setRating(int $householdId, int $recipeId, int $rating, ?DateTimeImmutable $asOf = null): void
    {
        if ($rating < 1 || $rating > 5) {
            throw new InvalidArgumentException('Rating must be between one and five.');
        }
        $now = $this->utc($asOf);
        $this->transaction(function () use ($householdId, $recipeId, $rating, $now): void {
            $this->lockHousehold($householdId);
            if ($this->writeRating($householdId, $recipeId, $rating, 'user', $now)) {
                $this->rebuildLocked($householdId, $now);
            }
        });
    }

    /** @return array{ratings_changed: int, affinities: int} */
    public function applyOnboarding(int $householdId, ?DateTimeImmutable $asOf = null): array
    {
        $now = $this->utc($asOf);
        return $this->transaction(function () use ($householdId, $now): array {
            $this->lockHousehold($householdId);
            $answers = $this->answers($householdId);
            $desired = $this->dishRatings($answers);
            $existing = $this->db->prepare("SELECT recipe_id, rating FROM household_recipe "
                . "WHERE household_id = ? AND rating_source = 'onboarding' FOR UPDATE");
            $existing->execute([$householdId]);
            $changed = 0;
            while (($row = $existing->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (!is_numeric($row['recipe_id'] ?? null) || !is_numeric($row['rating'] ?? null)) {
                    throw new RuntimeException('Invalid onboarding rating row.');
                }
                $recipeId = (int) $row['recipe_id'];
                if (isset($desired[$recipeId])) {
                    continue;
                }
                $clear = $this->db->prepare('UPDATE household_recipe '
                    . 'SET rating = NULL, rating_source = NULL, rated_at = NULL '
                    . "WHERE household_id = ? AND recipe_id = ? AND rating_source = 'onboarding'");
                $clear->execute([$householdId, $recipeId]);
                $this->recordHistory($householdId, $recipeId, (int) $row['rating'], null, 'onboarding', $now);
                $changed++;
            }
            foreach ($desired as $recipeId => $rating) {
                if ($this->writeRating($householdId, $recipeId, $rating, 'onboarding', $now)) {
                    $changed++;
                }
            }
            return ['ratings_changed' => $changed, 'affinities' => $this->rebuildLocked($householdId, $now)];
        });
    }

    public function rebuildHousehold(int $householdId, ?DateTimeImmutable $asOf = null): int
    {
        $now = $this->utc($asOf);
        return $this->transaction(function () use ($householdId, $now): int {
            $this->lockHousehold($householdId);
            return $this->rebuildLocked($householdId, $now);
        });
    }

    /** @return list<array{feature_type: string, feature_value: string, score: float, weight_sum: float, confidence: float}> */
    public function affinitiesForHousehold(int $householdId): array
    {
        $query = $this->db->prepare('SELECT feature_type, feature_value, score, weight_sum FROM affinity '
            . 'WHERE household_id = ? ORDER BY feature_type, feature_value');
        $query->execute([$householdId]);
        $result = [];
        while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_string($row['feature_type'] ?? null) || !is_string($row['feature_value'] ?? null)
                || !is_numeric($row['score'] ?? null) || !is_numeric($row['weight_sum'] ?? null)) {
                throw new RuntimeException('Invalid stored affinity row.');
            }
            $weight = (float) $row['weight_sum'];
            $result[] = ['feature_type' => $row['feature_type'], 'feature_value' => $row['feature_value'],
                'score' => (float) $row['score'], 'weight_sum' => $weight,
                'confidence' => $this->calculator->confidence($weight)];
        }
        return $result;
    }

    /** @return array{households: int, affinities: int} */
    public function rebuildAll(?DateTimeImmutable $asOf = null): array
    {
        $now = $this->utc($asOf);
        $households = $this->db->prepare('SELECT id FROM household ORDER BY id');
        $households->execute();
        $count = 0;
        $affinities = 0;
        while (($id = $households->fetchColumn()) !== false) {
            if (!is_numeric($id)) {
                throw new RuntimeException('Invalid household ID.');
            }
            $affinities += $this->rebuildHousehold((int) $id, $now);
            $count++;
        }
        return ['households' => $count, 'affinities' => $affinities];
    }

    private function rebuildLocked(int $householdId, DateTimeImmutable $now): int
    {
        $answers = $this->answers($householdId);
        $seeds = $this->seeds($answers);
        $query = $this->db->prepare('SELECT hr.recipe_id, hr.rating, hr.rated_at '
            . 'FROM household_recipe hr WHERE hr.household_id = ? AND hr.rating IS NOT NULL '
            . 'ORDER BY hr.rated_at, hr.recipe_id');
        $query->execute([$householdId]);
        $ratings = [];
        while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_numeric($row['recipe_id'] ?? null) || !is_numeric($row['rating'] ?? null)
                || !is_string($row['rated_at'] ?? null)) {
                throw new RuntimeException('Invalid stored recipe rating.');
            }
            $recipeId = (int) $row['recipe_id'];
            $ratings[] = ['recipe_id' => $recipeId, 'rating' => (int) $row['rating'],
                'rated_at' => $row['rated_at'], 'features' => $this->recipeFeatures($recipeId)];
        }
        $affinities = $this->calculator->calculate($seeds, $ratings, $now);
        $current = $this->db->prepare('SELECT feature_type, feature_value, score, weight_sum '
            . 'FROM affinity WHERE household_id = ? FOR UPDATE');
        $current->execute([$householdId]);
        $existing = [];
        while (($row = $current->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_string($row['feature_type'] ?? null) || !is_string($row['feature_value'] ?? null)
                || !is_string($row['score'] ?? null) || !is_string($row['weight_sum'] ?? null)) {
                throw new RuntimeException('Invalid existing affinity row.');
            }
            $existing[$row['feature_type'] . "\0" . $row['feature_value']] = $row;
        }
        $insert = $this->db->prepare('INSERT INTO affinity '
            . '(household_id, feature_type, feature_value, score, weight_sum) VALUES (?, ?, ?, ?, ?)');
        $update = $this->db->prepare('UPDATE affinity SET score = ?, weight_sum = ? '
            . 'WHERE household_id = ? AND feature_type = ? AND feature_value = ?');
        $delete = $this->db->prepare('DELETE FROM affinity '
            . 'WHERE household_id = ? AND feature_type = ? AND feature_value = ?');
        foreach ($affinities as $row) {
            $score = number_format($row['score'], 5, '.', '');
            if ((float) $score === 0.0) {
                $score = '0.00000';
            }
            $weight = number_format($row['weight_sum'], 6, '.', '');
            $key = $row['type'] . "\0" . $row['value'];
            if (isset($existing[$key])) {
                if ($existing[$key]['score'] !== $score || $existing[$key]['weight_sum'] !== $weight) {
                    $update->execute([$score, $weight, $householdId, $row['type'], $row['value']]);
                }
                unset($existing[$key]);
            } else {
                $insert->execute([$householdId, $row['type'], $row['value'], $score, $weight]);
            }
        }
        foreach ($existing as $row) {
            $delete->execute([$householdId, $row['feature_type'], $row['feature_value']]);
        }
        return count($affinities);
    }

    /** @return list<array{type: string, value: string, eta: float}> */
    private function recipeFeatures(int $recipeId): array
    {
        $recipe = $this->db->prepare('SELECT main_protein, cuisine, meal_type, carb_base FROM recipe WHERE id = ?');
        $recipe->execute([$recipeId]);
        $row = $recipe->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Rated recipe does not exist.');
        }
        $features = [];
        foreach (['main_protein' => 'protein', 'cuisine' => 'cuisine', 'meal_type' => 'meal_type',
            'carb_base' => 'carb'] as $column => $type) {
            $value = $row[$column] ?? null;
            if (is_string($value) && $value !== '') {
                $features[] = ['type' => $type, 'value' => $value, 'eta' => 1.0];
            }
        }
        $ingredients = $this->db->prepare('SELECT DISTINCT ingredient_id FROM ('
            . 'SELECT ri.ingredient_id FROM recipe_ingredient ri WHERE ri.recipe_id = ? AND ri.is_main = TRUE '
            . 'UNION ALL SELECT rip.ingredient_id FROM recipe_ingredient_part rip '
            . 'JOIN recipe_ingredient ri ON ri.recipe_id = rip.recipe_id AND ri.position = rip.position '
            . 'WHERE rip.recipe_id = ? AND ri.is_main = TRUE) AS main_ingredient '
            . 'JOIN ingredient i ON i.id = main_ingredient.ingredient_id '
            . 'WHERE i.is_staple = FALSE ORDER BY ingredient_id');
        $ingredients->execute([$recipeId, $recipeId]);
        $ids = $ingredients->fetchAll(PDO::FETCH_COLUMN);
        $count = count($ids);
        if ($count > 0) {
            $eta = 1 / sqrt($count);
            foreach ($ids as $id) {
                if (!is_numeric($id)) {
                    throw new RuntimeException('Invalid main ingredient ID.');
                }
                $features[] = ['type' => 'ingredient', 'value' => (string) $id, 'eta' => $eta];
            }
        }
        return $features;
    }

    /** @return array<string, array<string, mixed>> */
    private function answers(int $householdId): array
    {
        $query = $this->db->prepare('SELECT question_id, answer FROM onboarding_answer WHERE household_id = ?');
        $query->execute([$householdId]);
        $answers = [];
        while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_string($row['question_id'] ?? null) || !is_string($row['answer'] ?? null)) {
                throw new RuntimeException('Invalid onboarding answer row.');
            }
            $answer = json_decode($row['answer'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($answer) || array_is_list($answer)) {
                throw new RuntimeException('Invalid onboarding answer value.');
            }
            $validated = [];
            foreach ($answer as $key => $value) {
                if (!is_string($key)) {
                    throw new RuntimeException('Invalid onboarding answer key.');
                }
                $validated[$key] = $value;
            }
            $answers[$row['question_id']] = $validated;
        }
        return $answers;
    }

    /** @param array<string, array<string, mixed>> $answers
     *  @return array<int, int>
     */
    private function dishRatings(array $answers): array
    {
        $ratings = [];
        foreach ($this->items($answers, 'dish_rating', 'ratings') as $item) {
            if (!is_array($item) || !is_int($item['recipe_id'] ?? null) || $item['recipe_id'] < 1
                || !is_string($item['choice'] ?? null) || isset($ratings[$item['recipe_id']])) {
                throw new InvalidArgumentException('Invalid dish onboarding rating.');
            }
            if (!in_array($item['choice'], ['favourite', 'ok', 'not_for_us'], true)) {
                throw new InvalidArgumentException('Invalid dish onboarding choice.');
            }
            $rating = $this->config->integer('onboarding.rating.' . $item['choice']);
            if ($rating < 1 || $rating > 5) {
                throw new InvalidArgumentException('Invalid onboarding rating configuration.');
            }
            $ratings[$item['recipe_id']] = $rating;
        }
        ksort($ratings, SORT_NUMERIC);
        return $ratings;
    }

    /** @param array<string, array<string, mixed>> $answers
     *  @return list<array{type: string, value: string, score: float}>
     */
    private function seeds(array $answers): array
    {
        $seeds = [];
        foreach (['cuisine_rating' => 'cuisine', 'protein_rating' => 'protein'] as $question => $type) {
            $seen = [];
            foreach ($this->items($answers, $question, 'ratings') as $item) {
                if (!is_array($item) || !is_string($item['code'] ?? null)
                    || preg_match('/^[a-z][a-z0-9_]*$/', $item['code']) !== 1
                    || !is_int($item['rating'] ?? null) || $item['rating'] < 1 || $item['rating'] > 5
                    || isset($seen[$item['code']])) {
                    throw new InvalidArgumentException('Invalid feature onboarding rating.');
                }
                $seen[$item['code']] = true;
                $seeds[] = ['type' => $type, 'value' => $item['code'],
                    'score' => ($item['rating'] - 3) / 2];
            }
        }
        $seen = [];
        foreach ($this->items($answers, 'disliked_ingredients', 'ingredient_ids') as $id) {
            if (!is_int($id) || $id < 1 || isset($seen[$id])) {
                throw new InvalidArgumentException('Invalid disliked ingredient ID.');
            }
            $seen[$id] = true;
            $seeds[] = ['type' => 'ingredient', 'value' => (string) $id,
                'score' => $this->config->decimal('affinity.disliked_ingredient_score')];
        }
        return $seeds;
    }

    /** @param array<string, array<string, mixed>> $answers
     *  @return list<mixed>
     */
    private function items(array $answers, string $question, string $key): array
    {
        if (!isset($answers[$question])) {
            return [];
        }
        $items = $answers[$question][$key] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new InvalidArgumentException('Invalid onboarding answer: ' . $question);
        }
        return $items;
    }

    private function writeRating(
        int $householdId,
        int $recipeId,
        int $rating,
        string $source,
        DateTimeImmutable $now
    ): bool {
        $query = $this->db->prepare('SELECT rating, rating_source FROM household_recipe '
            . 'WHERE household_id = ? AND recipe_id = ? FOR UPDATE');
        $query->execute([$householdId, $recipeId]);
        $current = $query->fetch(PDO::FETCH_ASSOC);
        if (is_array($current) && $source === 'onboarding' && $current['rating_source'] === 'user') {
            return false;
        }
        $old = is_array($current) && is_numeric($current['rating'] ?? null) ? (int) $current['rating'] : null;
        $sameSource = is_array($current) && $current['rating_source'] === $source;
        if ($old === $rating && $sameSource) {
            return false;
        }
        $date = $now->format('Y-m-d H:i:s.u');
        if (is_array($current)) {
            $update = $this->db->prepare('UPDATE household_recipe SET rating = ?, rating_source = ?, rated_at = ? '
                . 'WHERE household_id = ? AND recipe_id = ?');
            $update->execute([$rating, $source, $date, $householdId, $recipeId]);
        } else {
            $insert = $this->db->prepare('INSERT INTO household_recipe '
                . '(household_id, recipe_id, rating, rating_source, rated_at) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$householdId, $recipeId, $rating, $source, $date]);
        }
        $this->recordHistory($householdId, $recipeId, $old, $rating, $source, $now);
        return true;
    }

    private function recordHistory(
        int $householdId,
        int $recipeId,
        ?int $old,
        ?int $new,
        string $source,
        DateTimeImmutable $now
    ): void {
        $query = $this->db->prepare('INSERT INTO rating_history '
            . '(household_id, recipe_id, old_rating, new_rating, source, changed_at) VALUES (?, ?, ?, ?, ?, ?)');
        $query->execute([$householdId, $recipeId, $old, $new, $source, $now->format('Y-m-d H:i:s.u')]);
    }

    private function lockHousehold(int $householdId): void
    {
        $query = $this->db->prepare('SELECT id FROM household WHERE id = ? FOR UPDATE');
        $query->execute([$householdId]);
        if ($query->fetchColumn() === false) {
            throw new InvalidArgumentException('Household does not exist.');
        }
    }

    private function utc(?DateTimeImmutable $asOf): DateTimeImmutable
    {
        return ($asOf ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    /** @template T
     *  @param callable(): T $operation
     *  @return T
     */
    private function transaction(callable $operation): mixed
    {
        $owned = !$this->db->inTransaction();
        if ($owned) {
            $this->db->beginTransaction();
        }
        try {
            $result = $operation();
            if ($owned) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($owned) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
