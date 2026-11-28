<?php

declare(strict_types=1);

namespace Matsvep\Simulator;

use Matsvep\Engine\EngineConfig;
use PDO;

final class PersonaTaste
{
    /** @var array<int,array{title:string,ingredients:list<string>}> */
    private array $recipes = [];

    /** @param array<string,mixed> $persona */
    public function __construct(
        private readonly PDO $db,
        private readonly EngineConfig $config,
        private readonly array $persona
    ) {
    }

    public function utility(int $recipeId, int $week, SeededRandom $random): float
    {
        return $this->score($recipeId, $week, $random);
    }

    public function noiseFreeUtility(int $recipeId, int $week): float
    {
        return $this->score($recipeId, $week, null);
    }

    private function score(int $recipeId, int $week, ?SeededRandom $random): float
    {
        $recipe = $this->recipe($recipeId);
        $title = mb_strtolower($recipe['title']);
        $ingredients = implode(' ', $recipe['ingredients']);
        $score = $this->config->decimal('simulator.taste.base_stars');
        foreach ($this->terms('liked_dishes') as $term) {
            if (str_contains($title, $term)) {
                $score += $this->config->decimal('simulator.taste.dish_bonus');
            }
        }
        foreach ($this->terms('disliked_dishes') as $term) {
            if (str_contains($title, $term)) {
                $score -= $this->config->decimal('simulator.taste.dish_penalty');
            }
        }
        $liked = 0;
        foreach ($this->terms('liked_ingredients') as $term) {
            $liked += (int) str_contains($ingredients, $term);
        }
        $disliked = 0;
        foreach ($this->terms('disliked_ingredients') as $term) {
            $disliked += (int) str_contains($ingredients, $term);
        }
        $score += sqrt($liked) * $this->config->decimal('simulator.taste.ingredient_bonus');
        $score -= sqrt($disliked) * $this->config->decimal('simulator.taste.ingredient_penalty');
        foreach ($this->pairs() as [$left, $right]) {
            if (str_contains($ingredients, $left) && str_contains($ingredients, $right)) {
                $score += $this->config->decimal('simulator.taste.pair_bonus');
            }
        }
        $code = $this->persona['code'] ?? null;
        if (!is_string($code)) {
            throw new \RuntimeException('Invalid persona code.');
        }
        $phase = hexdec(substr(hash('sha256', $code . ':' . $recipeId), 0, 8)) / 4294967296;
        $period = $this->config->integer('simulator.taste.drift_weeks');
        $score += sin(2 * M_PI * ($week / $period + $phase)) * $this->config->decimal('simulator.taste.drift');
        if ($random !== null) {
            $score += ($random->fraction() * 2 - 1) * $this->config->decimal('simulator.taste.noise');
        }
        return max(1.0, min(5.0, $score));
    }

    public function rating(int $recipeId, int $week, SeededRandom $random): int
    {
        return (int) round($this->utility($recipeId, $week, $random));
    }

    /** @return array{title:string,ingredients:list<string>} */
    private function recipe(int $recipeId): array
    {
        if (isset($this->recipes[$recipeId])) {
            return $this->recipes[$recipeId];
        }
        $query = $this->db->prepare('SELECT title FROM recipe WHERE id = ?');
        $query->execute([$recipeId]);
        $title = $query->fetchColumn();
        if (!is_string($title)) {
            throw new \RuntimeException('Persona recipe is missing.');
        }
        $query = $this->db->prepare('SELECT COALESCE(i.canonical_name, ri.ingredient_text, ri.raw_text) '
            . 'FROM recipe_ingredient ri LEFT JOIN ingredient i ON i.id = ri.ingredient_id '
            . 'WHERE ri.recipe_id = ? ORDER BY ri.position');
        $query->execute([$recipeId]);
        $ingredients = [];
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $value) {
            if (is_string($value)) {
                $ingredients[] = mb_strtolower($value);
            }
        }
        return $this->recipes[$recipeId] = ['title' => $title, 'ingredients' => $ingredients];
    }

    /** @return list<string> */
    private function terms(string $key): array
    {
        $terms = $this->persona[$key] ?? null;
        if (!is_array($terms) || !array_is_list($terms)) {
            throw new \RuntimeException('Invalid persona taste terms.');
        }
        $result = [];
        foreach ($terms as $term) {
            if (!is_string($term)) {
                throw new \RuntimeException('Invalid persona taste term.');
            }
            $result[] = mb_strtolower($term);
        }
        return $result;
    }

    /** @return list<array{string,string}> */
    private function pairs(): array
    {
        $pairs = $this->persona['liked_pairs'] ?? null;
        if (!is_array($pairs)) {
            throw new \RuntimeException('Invalid persona taste pairs.');
        }
        $result = [];
        foreach ($pairs as $pair) {
            if (!is_array($pair) || count($pair) !== 2 || !is_string($pair[0]) || !is_string($pair[1])) {
                throw new \RuntimeException('Invalid persona taste pair.');
            }
            $result[] = [mb_strtolower($pair[0]), mb_strtolower($pair[1])];
        }
        return $result;
    }
}
