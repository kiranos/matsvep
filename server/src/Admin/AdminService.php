<?php

declare(strict_types=1);

namespace Matsvep\Admin;

use Matsvep\Ingest\Feature\FeatureDeriver;
use Matsvep\Ingest\Ingredient\IngredientLineParser;
use Matsvep\Ingest\Ingredient\IngredientNormaliser;
use PDO;
use RuntimeException;
use Throwable;

final class AdminService
{
    public function __construct(private readonly PDO $db, private readonly string $seedDirectory)
    {
    }

    /** @return array<string, int|float> */
    public function overview(): array
    {
        $sql = "SELECT COUNT(*) AS total, SUM(normalise_status = 'matched') AS matched " .
            "FROM recipe_ingredient WHERE normalise_status <> 'ignored'";
        $lines = $this->readAll($sql)[0] ?? [];
        if (!is_numeric($lines['total'] ?? null) || ($lines['matched'] !== null && !is_numeric($lines['matched']))) {
            throw new RuntimeException('Invalid ingredient count.');
        }
        $total = (int) $lines['total'];
        $dinnerCount = $this->count('SELECT COUNT(*) FROM recipe WHERE active = TRUE AND is_dinner_candidate = TRUE');
        $gapCount = $this->count('SELECT COUNT(*) FROM recipe WHERE active = TRUE AND is_dinner_candidate = TRUE AND main_protein IS NULL');
        return [
            'ingredient_match_share_pct' => $total === 0 ? 0.0 : round(100 * (int) $lines['matched'] / $total, 1),
            'protein_gap' => $gapCount,
            'protein_gap_share_pct' => $dinnerCount === 0 ? 0.0 : round(100 * $gapCount / $dinnerCount, 1),
            'dinner_candidates' => $dinnerCount,
            'unknown_queue' => $this->count("SELECT COUNT(*) FROM ingredient_unknown WHERE status = 'pending'"),
            'duplicate_queue' => $this->count("SELECT COUNT(*) FROM duplicate_candidate d JOIN recipe a ON a.id = d.recipe_a JOIN recipe b ON b.id = d.recipe_b WHERE d.status = 'pending' AND a.active = TRUE AND b.active = TRUE"),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function unknowns(): array
    {
        return $this->readLimited("SELECT u.id, u.normalised_token, u.raw_text, u.occurrences, u.suggested_ingredient_id, i.canonical_name AS suggestion " .
            "FROM ingredient_unknown u LEFT JOIN ingredient i ON i.id = u.suggested_ingredient_id " .
            "WHERE u.status = 'pending' ORDER BY u.occurrences DESC, u.id", 'admin.queue_page_size');
    }

    /** @return list<array<string, mixed>> */
    public function ingredients(): array
    {
        return $this->readAll('SELECT id, canonical_name FROM ingredient ORDER BY canonical_name');
    }

    /** @return list<array<string, mixed>> */
    public function duplicates(): array
    {
        return $this->readLimited("SELECT d.recipe_a, d.recipe_b, d.similarity, a.title AS title_a, a.url AS url_a, " .
            "b.title AS title_b, b.url AS url_b FROM duplicate_candidate d " .
            "JOIN recipe a ON a.id = d.recipe_a JOIN recipe b ON b.id = d.recipe_b " .
            "WHERE d.status = 'pending' AND a.active = TRUE AND b.active = TRUE " .
            "ORDER BY d.similarity DESC, d.recipe_a", 'admin.queue_page_size');
    }

    /** @return list<array<string, mixed>> */
    public function recipes(string $query): array
    {
        $statement = $this->db->prepare('SELECT id, title, url, main_protein, carb_base, cuisine, meal_type, ' .
            'is_dinner_candidate, leftover_score FROM recipe WHERE active = TRUE AND title LIKE ? ORDER BY id LIMIT ?');
        $statement->bindValue(1, '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%');
        $statement->bindValue(2, $this->configInteger('admin.result_limit'), PDO::PARAM_INT);
        $statement->execute();
        return $this->rows($statement);
    }

    /** @return array<string, mixed>|null */
    public function override(int $recipeId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM recipe_feature_override WHERE recipe_id = ?');
        $statement->execute([$recipeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function groups(): array
    {
        return $this->readLimited('SELECT g.id, g.core_name, g.main_protein, g.manual_override, COUNT(r.id) AS recipe_count ' .
            'FROM dish_group g JOIN recipe r ON r.dish_group_id = g.id AND r.active = TRUE ' .
            'GROUP BY g.id ORDER BY recipe_count DESC, g.id', 'admin.result_limit');
    }

    /** @return list<array<string, mixed>> */
    public function groupRecipes(int $groupId): array
    {
        $statement = $this->db->prepare('SELECT id, title, url FROM recipe WHERE dish_group_id = ? AND active = TRUE ORDER BY id');
        $statement->execute([$groupId]);
        return $this->rows($statement);
    }

    /** @return list<array<string, mixed>> */
    public function recipeLines(int $recipeId): array
    {
        $statement = $this->db->prepare('SELECT raw_text FROM recipe_ingredient WHERE recipe_id = ? ORDER BY position');
        $statement->execute([$recipeId]);
        return $this->rows($statement);
    }

    /** @return list<array<string, mixed>> */
    public function recipeSteps(int $recipeId): array
    {
        $statement = $this->db->prepare('SELECT text FROM recipe_step WHERE recipe_id = ? ORDER BY position');
        $statement->execute([$recipeId]);
        return $this->rows($statement);
    }

    public function resolveUnknown(int $actor, int $unknownId, string $action, ?int $ingredientId = null, ?string $name = null, ?string $category = null): void
    {
        if (!in_array($action, ['map', 'create', 'staple', 'ignore'], true)) {
            throw new RuntimeException('Invalid unknown ingredient action.');
        }
        $affectedQuery = $this->db->prepare('SELECT DISTINCT recipe_id FROM recipe_ingredient_part WHERE ingredient_unknown_id = ?');
        $affectedQuery->execute([$unknownId]);
        $affected = $affectedQuery->fetchAll(PDO::FETCH_COLUMN);
        $this->db->beginTransaction();
        try {
            $read = $this->db->prepare('SELECT * FROM ingredient_unknown WHERE id = ? FOR UPDATE');
            $read->execute([$unknownId]);
            $before = $read->fetch(PDO::FETCH_ASSOC);
            if (!is_array($before) || $before['status'] !== 'pending'
                || !is_string($before['normalised_token'] ?? null)) {
                throw new RuntimeException('Unknown ingredient is no longer pending.');
            }
            if ($action === 'create' || $action === 'staple') {
                $name = trim((string) ($name ?: $before['normalised_token']));
                $category = trim((string) ($category ?: 'pantry'));
                if ($name === '' || preg_match('/^[a-z][a-z0-9_]*$/', $category) !== 1
                    || mb_strlen($name) > 255 || mb_strlen($category) > 120) {
                    throw new RuntimeException('Enter a valid ingredient name and category.');
                }
                $create = $this->db->prepare('INSERT INTO ingredient (canonical_name, category, is_staple) VALUES (?, ?, ?)');
                $create->execute([$name, $category, $action === 'staple' ? 1 : 0]);
                $ingredientId = (int) $this->db->lastInsertId();
            }
            if ($action !== 'ignore') {
                $exists = $this->db->prepare('SELECT id FROM ingredient WHERE id = ?');
                $exists->execute([$ingredientId]);
                if ($exists->fetchColumn() === false) {
                    throw new RuntimeException('Ingredient does not exist.');
                }
                $alias = $this->db->prepare('SELECT ingredient_id FROM ingredient_alias WHERE alias = ?');
                $alias->execute([$before['normalised_token']]);
                $owner = $alias->fetchColumn();
                if ($owner !== false && (int) $owner !== $ingredientId) {
                    throw new RuntimeException('Alias already belongs to another ingredient.');
                }
                if ($owner === false) {
                    $addAlias = $this->db->prepare('INSERT INTO ingredient_alias (alias, ingredient_id) VALUES (?, ?)');
                    $addAlias->execute([$before['normalised_token'], $ingredientId]);
                }
            }
            $status = $action === 'ignore' ? 'ignored' : ($action === 'staple' ? 'staple' : 'mapped');
            $update = $this->db->prepare('UPDATE ingredient_unknown SET status = ?, mapped_ingredient_id = ? WHERE id = ?');
            $update->execute([$status, $ingredientId, $unknownId]);
            $this->audit(
                $actor,
                'unknown.' . $action,
                'ingredient_unknown',
                $unknownId,
                $before,
                ['status' => $status, 'mapped_ingredient_id' => $ingredientId]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        $normaliser = new IngredientNormaliser($this->db, new IngredientLineParser($this->seedDirectory));
        foreach ($affected as $recipeId) {
            if (!is_numeric($recipeId)) {
                throw new RuntimeException('Invalid affected recipe.');
            }
            $normaliser->run((int) $recipeId);
        }
    }

    public function reviewDuplicate(int $actor, int $a, int $b, string $action, ?int $keepId = null): void
    {
        if ($a >= $b || !in_array($action, ['merge', 'not_duplicate'], true)) {
            throw new RuntimeException('Invalid duplicate review.');
        }
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('SELECT * FROM duplicate_candidate WHERE recipe_a = ? AND recipe_b = ? FOR UPDATE');
            $query->execute([$a, $b]);
            $before = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($before) || $before['status'] !== 'pending') {
                throw new RuntimeException('Duplicate is no longer pending.');
            }
            if ($action === 'merge') {
                if ($keepId !== $a && $keepId !== $b) {
                    throw new RuntimeException('Choose one recipe to keep.');
                }
                $loser = $keepId === $a ? $b : $a;
                $recipe = $this->db->prepare('SELECT id FROM recipe WHERE id IN (?, ?) AND active = TRUE FOR UPDATE');
                $recipe->execute([$a, $b]);
                if (count($recipe->fetchAll(PDO::FETCH_COLUMN)) !== 2) {
                    throw new RuntimeException('Both recipes must be active.');
                }
                $links = $this->db->prepare('UPDATE recipe_source_link SET recipe_id = ?, is_primary = FALSE WHERE recipe_id = ?');
                $links->execute([$keepId, $loser]);
                $ingest = $this->db->prepare('UPDATE ingest_url SET recipe_id = ? WHERE recipe_id = ?');
                $ingest->execute([$keepId, $loser]);
                $inactive = $this->db->prepare('UPDATE recipe SET active = FALSE, merged_into_id = ?, dish_group_id = NULL WHERE id = ?');
                $inactive->execute([$keepId, $loser]);
            }
            $status = $action === 'merge' ? 'merged' : 'not_duplicate';
            $update = $this->db->prepare('UPDATE duplicate_candidate SET status = ? WHERE recipe_a = ? AND recipe_b = ?');
            $update->execute([$status, $a, $b]);
            $this->audit(
                $actor,
                'duplicate.' . $action,
                'duplicate_candidate',
                $a,
                $before,
                ['recipe_a' => $a, 'recipe_b' => $b, 'status' => $status, 'kept_recipe_id' => $keepId]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    /** @param array<string, string> $values */
    public function setFeatureOverride(int $actor, int $recipeId, array $values): void
    {
        $fields = ['main_protein', 'carb_base', 'cuisine', 'meal_type', 'is_dinner_candidate', 'leftover_score'];
        $clean = [];
        foreach ($fields as $field) {
            $value = trim($values[$field] ?? '');
            if ($value !== '') {
                if ($field === 'is_dinner_candidate' && !in_array($value, ['0', '1'], true)) {
                    throw new RuntimeException('Invalid dinner candidate override.');
                }
                if ($field === 'leftover_score' && (!is_numeric($value) || (float) $value < 0 || (float) $value > 1)) {
                    throw new RuntimeException('Invalid leftover score override.');
                }
                if (!in_array($field, ['is_dinner_candidate', 'leftover_score'], true)
                    && preg_match('/^[a-z][a-z0-9_]*$/', $value) !== 1) {
                    throw new RuntimeException('Feature codes must be lower snake case.');
                }
            }
            $clean[$field] = $value === '' ? null : $value;
        }
        $this->db->beginTransaction();
        try {
            $recipe = $this->db->prepare('SELECT id FROM recipe WHERE id = ? AND active = TRUE FOR UPDATE');
            $recipe->execute([$recipeId]);
            if ($recipe->fetchColumn() === false) {
                throw new RuntimeException('Recipe is not active.');
            }
            $before = $this->override($recipeId);
            $upsert = $this->db->prepare('INSERT INTO recipe_feature_override ' .
                '(recipe_id, main_protein, carb_base, cuisine, meal_type, is_dinner_candidate, leftover_score, updated_by) ' .
                'VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE main_protein = VALUES(main_protein), ' .
                'carb_base = VALUES(carb_base), cuisine = VALUES(cuisine), meal_type = VALUES(meal_type), ' .
                'is_dinner_candidate = VALUES(is_dinner_candidate), leftover_score = VALUES(leftover_score), updated_by = VALUES(updated_by)');
            $upsert->execute([$recipeId, ...array_values($clean), $actor]);
            $this->audit($actor, 'recipe.features', 'recipe', $recipeId, $before, $clean);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        (new FeatureDeriver($this->db, $this->seedDirectory))->run($recipeId);
    }

    public function splitGroup(int $actor, int $groupId, int $recipeId): void
    {
        $this->db->beginTransaction();
        try {
            $recipe = $this->db->prepare('SELECT id, title, main_protein, dish_group_id FROM recipe ' .
                'WHERE id = ? AND dish_group_id = ? AND active = TRUE FOR UPDATE');
            $recipe->execute([$recipeId, $groupId]);
            $before = $recipe->fetch(PDO::FETCH_ASSOC);
            if (!is_array($before) || !is_string($before['title'] ?? null)) {
                throw new RuntimeException('Recipe is not in this group.');
            }
            $insert = $this->db->prepare('INSERT INTO dish_group (core_name, main_protein, manual_override) VALUES (?, ?, TRUE)');
            $insert->execute([mb_substr($before['title'], 0, 255), $before['main_protein']]);
            $newId = (int) $this->db->lastInsertId();
            $update = $this->db->prepare('UPDATE recipe SET dish_group_id = ?, dish_group_manual = TRUE WHERE id = ?');
            $update->execute([$newId, $recipeId]);
            $this->db->prepare('UPDATE dish_group SET manual_override = TRUE WHERE id = ?')->execute([$groupId]);
            $this->audit(
                $actor,
                'group.split',
                'dish_group',
                $groupId,
                $before,
                ['recipe_id' => $recipeId, 'new_group_id' => $newId]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function mergeGroups(int $actor, int $sourceId, int $targetId): void
    {
        if ($sourceId === $targetId) {
            throw new RuntimeException('Select two different groups.');
        }
        $this->db->beginTransaction();
        try {
            $groups = $this->db->prepare('SELECT id FROM dish_group WHERE id IN (?, ?) FOR UPDATE');
            $groups->execute([$sourceId, $targetId]);
            if (count($groups->fetchAll(PDO::FETCH_COLUMN)) !== 2) {
                throw new RuntimeException('Both groups must exist.');
            }
            $move = $this->db->prepare('UPDATE recipe SET dish_group_id = ?, dish_group_manual = TRUE WHERE dish_group_id = ?');
            $move->execute([$targetId, $sourceId]);
            $this->db->prepare('UPDATE dish_group SET manual_override = TRUE WHERE id = ?')->execute([$targetId]);
            $this->db->prepare('DELETE FROM dish_group WHERE id = ?')->execute([$sourceId]);
            $this->audit(
                $actor,
                'group.merge',
                'dish_group',
                $targetId,
                ['source_group_id' => $sourceId],
                ['target_group_id' => $targetId]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    /** @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function audit(int $actor, string $action, string $type, int $id, ?array $before, ?array $after): void
    {
        $query = $this->db->prepare('INSERT INTO admin_audit ' .
            '(user_id, action, target_type, target_id, before_json, after_json) VALUES (?, ?, ?, ?, ?, ?)');
        $query->execute([$actor, $action, $type, $id,
            $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }

    /** @return list<array<string, mixed>> */
    private function readLimited(string $sql, string $limitKey): array
    {
        $query = $this->db->prepare($sql . ' LIMIT ?');
        $query->bindValue(1, $this->configInteger($limitKey), PDO::PARAM_INT);
        $query->execute();
        return $this->rows($query);
    }

    private function configInteger(string $key): int
    {
        $query = $this->db->prepare('SELECT value FROM engine_config WHERE `key` = ?');
        $query->execute([$key]);
        $value = $query->fetchColumn();
        if (!is_numeric($value) || (int) $value < 1) {
            throw new RuntimeException('Invalid admin configuration.');
        }
        return (int) $value;
    }

    /** @return list<array<string, mixed>> */
    private function readAll(string $sql): array
    {
        $query = $this->db->prepare($sql);
        $query->execute();
        return $this->rows($query);
    }

    /** @return list<array<string, mixed>> */
    private function rows(\PDOStatement $query): array
    {
        $rows = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Invalid admin query result.');
            }
            $record = [];
            foreach ($row as $key => $value) {
                if (!is_string($key)) {
                    throw new RuntimeException('Invalid admin query column.');
                }
                $record[$key] = $value;
            }
            $rows[] = $record;
        }
        return $rows;
    }

    private function count(string $sql): int
    {
        $query = $this->db->prepare($sql);
        $query->execute();
        $value = $query->fetchColumn();
        if (!is_numeric($value)) {
            throw new RuntimeException('Invalid admin count.');
        }
        return (int) $value;
    }
}
