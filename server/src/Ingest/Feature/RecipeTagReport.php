<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Feature;

use PDO;
use RuntimeException;

final class RecipeTagReport
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array{tag: string, recipes: int, active_recipes: int, dinner_candidates: int}> */
    public function build(): array
    {
        $query = $this->db->prepare(
            'SELECT t.tag, COUNT(*) AS recipes, '
            . 'COALESCE(SUM(r.active = TRUE), 0) AS active_recipes, '
            . 'COALESCE(SUM(r.active = TRUE AND r.is_dinner_candidate = TRUE), 0) AS dinner_candidates '
            . 'FROM recipe_tag t JOIN recipe r ON r.id = t.recipe_id '
            . 'GROUP BY t.tag ORDER BY recipes DESC, t.tag',
        );
        $query->execute();
        $result = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !is_string($row['tag'] ?? null)
                || !is_numeric($row['recipes'] ?? null)
                || !is_numeric($row['active_recipes'] ?? null)
                || !is_numeric($row['dinner_candidates'] ?? null)) {
                throw new RuntimeException('Invalid recipe tag report row.');
            }
            $result[] = [
                'tag' => $row['tag'],
                'recipes' => (int) $row['recipes'],
                'active_recipes' => (int) $row['active_recipes'],
                'dinner_candidates' => (int) $row['dinner_candidates'],
            ];
        }
        return $result;
    }
}
