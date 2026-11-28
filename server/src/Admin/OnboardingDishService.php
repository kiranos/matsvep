<?php

declare(strict_types=1);

namespace Matsvep\Admin;

use Matsvep\Database\WriteTransaction;
use PDO;
use RuntimeException;

final class OnboardingDishService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $query = $this->db->prepare('SELECT d.recipe_id, d.position, d.active, r.title, r.url, '
            . 'r.active AS recipe_active, r.is_dinner_candidate, s.name AS source_name '
            . 'FROM onboarding_dish d JOIN recipe r ON r.id = d.recipe_id '
            . 'JOIN source s ON s.id = r.source_id ORDER BY d.active DESC, d.position, d.recipe_id');
        $query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function search(string $title): array
    {
        if (trim($title) === '') {
            return [];
        }
        $limit = $this->configInteger('admin.result_limit');
        $query = $this->db->prepare('SELECT r.id, r.title, r.url, r.active, r.is_dinner_candidate, '
            . 's.name AS source_name, d.active AS selected '
            . 'FROM recipe r JOIN source s ON s.id = r.source_id '
            . 'LEFT JOIN onboarding_dish d ON d.recipe_id = r.id '
            . 'WHERE r.title LIKE ? ORDER BY r.id LIMIT ?');
        $query->bindValue(1, '%' . addcslashes($title, '%_\\') . '%');
        $query->bindValue(2, $limit, PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{count:int, minimum:int, maximum:int} */
    public function countStatus(): array
    {
        $query = $this->db->prepare('SELECT COUNT(*) FROM onboarding_dish d JOIN recipe r ON r.id = d.recipe_id '
            . 'WHERE d.active = TRUE AND r.active = TRUE AND r.is_dinner_candidate = TRUE');
        $query->execute();
        return [
            'count' => (int) $query->fetchColumn(),
            'minimum' => $this->configInteger('onboarding.dish_count_min'),
            'maximum' => $this->configInteger('onboarding.dish_count_max'),
        ];
    }

    public function add(int $actor, int $recipeId): void
    {
        WriteTransaction::run($this->db, function () use ($actor, $recipeId): void {
            $recipe = $this->db->prepare('SELECT active, is_dinner_candidate FROM recipe WHERE id = ? FOR UPDATE');
            $recipe->execute([$recipeId]);
            $row = $recipe->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (int) $row['active'] !== 1 || (int) $row['is_dinner_candidate'] !== 1) {
                throw new RuntimeException('Select an active dinner candidate.');
            }
            $entry = $this->db->prepare('SELECT active, position FROM onboarding_dish WHERE recipe_id = ? FOR UPDATE');
            $entry->execute([$recipeId]);
            $before = $entry->fetch(PDO::FETCH_ASSOC);
            if (is_array($before) && (int) $before['active'] === 1) {
                return;
            }
            $next = $this->db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM onboarding_dish');
            $next->execute();
            $position = (int) $next->fetchColumn();
            $write = $this->db->prepare('INSERT INTO onboarding_dish (recipe_id, position, active) VALUES (?, ?, TRUE) '
                . 'ON DUPLICATE KEY UPDATE position = VALUES(position), active = TRUE');
            $write->execute([$recipeId, $position]);
            $this->audit(
                $actor,
                'onboarding_dish.add',
                $recipeId,
                is_array($before) ? $before : null,
                ['active' => true, 'position' => $position]
            );
        });
    }

    public function remove(int $actor, int $recipeId): void
    {
        WriteTransaction::run($this->db, function () use ($actor, $recipeId): void {
            $read = $this->db->prepare('SELECT active, position FROM onboarding_dish WHERE recipe_id = ? FOR UPDATE');
            $read->execute([$recipeId]);
            $before = $read->fetch(PDO::FETCH_ASSOC);
            if (!is_array($before) || (int) $before['active'] !== 1) {
                throw new RuntimeException('Onboarding dish is not active.');
            }
            $write = $this->db->prepare('UPDATE onboarding_dish SET active = FALSE WHERE recipe_id = ?');
            $write->execute([$recipeId]);
            $this->audit(
                $actor,
                'onboarding_dish.remove',
                $recipeId,
                $before,
                ['active' => false, 'position' => (int) $before['position']]
            );
        });
    }

    public function move(int $actor, int $recipeId, string $direction): void
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new RuntimeException('Invalid move direction.');
        }
        WriteTransaction::run($this->db, function () use ($actor, $recipeId, $direction): void {
            $read = $this->db->prepare('SELECT recipe_id, position FROM onboarding_dish WHERE active = TRUE '
                . 'ORDER BY position, recipe_id FOR UPDATE');
            $read->execute();
            $rows = $read->fetchAll(PDO::FETCH_ASSOC);
            $index = null;
            foreach ($rows as $offset => $row) {
                if ((int) $row['recipe_id'] === $recipeId) {
                    $index = $offset;
                    break;
                }
            }
            if ($index === null) {
                throw new RuntimeException('Onboarding dish is not active.');
            }
            $otherIndex = $direction === 'up' ? $index - 1 : $index + 1;
            if (!isset($rows[$otherIndex])) {
                return;
            }
            $otherId = (int) $rows[$otherIndex]['recipe_id'];
            $position = (int) $rows[$index]['position'];
            $otherPosition = (int) $rows[$otherIndex]['position'];
            if ($position === $otherPosition) {
                $position = $index + 1;
                $otherPosition = $otherIndex + 1;
            }
            $write = $this->db->prepare('UPDATE onboarding_dish SET position = ? WHERE recipe_id = ?');
            $write->execute([$otherPosition, $recipeId]);
            $write->execute([$position, $otherId]);
            $this->audit(
                $actor,
                'onboarding_dish.move',
                $recipeId,
                ['position' => $position],
                ['position' => $otherPosition]
            );
        });
    }

    private function configInteger(string $key): int
    {
        $query = $this->db->prepare('SELECT value FROM engine_config WHERE `key` = ?');
        $query->execute([$key]);
        $value = $query->fetchColumn();
        if (!is_numeric($value) || (int) $value < 1) {
            throw new RuntimeException('Invalid onboarding configuration.');
        }
        return (int) $value;
    }

    /** @param array<string, mixed>|null $before
     *  @param array<string, mixed>|null $after */
    private function audit(int $actor, string $action, int $recipeId, ?array $before, ?array $after): void
    {
        $query = $this->db->prepare('INSERT INTO admin_audit '
            . '(user_id, action, target_type, target_id, before_json, after_json) VALUES (?, ?, ?, ?, ?, ?)');
        $query->execute([$actor, $action, 'onboarding_dish', $recipeId,
            $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR)]);
    }
}
