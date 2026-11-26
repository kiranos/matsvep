<?php

declare(strict_types=1);

namespace Matsvep\Api;

use Matsvep\Engine\EngineConfig;
use PDO;

final class RecipeReader
{
    public function __construct(private readonly PDO $db, private readonly EngineConfig $config)
    {
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        $query = $this->db->prepare('SELECT r.*, s.id AS s_id, s.name AS s_name, s.base_url AS s_base_url, s.display_mode AS s_display_mode, g.id AS g_id, g.core_name AS g_core_name, g.main_protein AS g_main_protein, g.manual_override AS g_manual_override FROM recipe r JOIN source s ON s.id = r.source_id LEFT JOIN dish_group g ON g.id = r.dish_group_id WHERE r.id = ? AND r.active = TRUE');
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiError(404, 'not_found');
        }
        $recipe = $this->summary($row);
        foreach (['servings', 'cuisine', 'meal_type', 'main_protein', 'carb_base', 'is_dinner_candidate', 'is_vegetarian', 'leftover_score', 'festive_score', 'effort', 'source_status'] as $name) {
            $recipe[$name] = $row[$name];
        }
        foreach (['servings', 'leftover_score', 'festive_score'] as $name) {
            $recipe[$name] = $row[$name] === null ? null : (float) $row[$name];
        }
        foreach (['is_dinner_candidate', 'is_vegetarian'] as $name) {
            $recipe[$name] = $row[$name] === null ? null : (bool) $row[$name];
        }
        $recipe['effort'] = $row['effort'] === null ? null : (int) $row['effort'];
        $links = $this->db->prepare('SELECT l.url, l.is_primary, s.id, s.name, s.base_url, s.display_mode FROM recipe_source_link l JOIN source s ON s.id = l.source_id WHERE l.recipe_id = ? ORDER BY l.is_primary DESC, l.id');
        $links->execute([$id]);
        $recipe['source_links'] = [];
        foreach ($links->fetchAll(PDO::FETCH_ASSOC) as $link) {
            $recipe['source_links'][] = ['source' => $this->source($link), 'url' => $link['url'], 'is_primary' => (bool) $link['is_primary']];
        }
        $ingredients = $this->db->prepare('SELECT ri.position, ri.group_name, ri.raw_text, ri.quantity, ri.unit, ri.is_main, i.id AS ingredient_id, i.canonical_name, i.category, i.is_staple FROM recipe_ingredient ri LEFT JOIN ingredient i ON i.id = ri.ingredient_id WHERE ri.recipe_id = ? ORDER BY ri.position');
        $ingredients->execute([$id]);
        $recipe['ingredients'] = [];
        foreach ($ingredients->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $recipe['ingredients'][] = ['position' => (int) $item['position'], 'group_name' => $item['group_name'], 'raw_text' => $item['raw_text'], 'quantity' => $item['quantity'] === null ? null : (float) $item['quantity'], 'unit' => $item['unit'], 'is_main' => (bool) $item['is_main'], 'canonical_ingredient' => $item['ingredient_id'] === null ? null : ['id' => (int) $item['ingredient_id'], 'canonical_name' => $item['canonical_name'], 'category' => $item['category'], 'is_staple' => (bool) $item['is_staple']]];
        }
        $recipe['steps'] = [];
        if ($row['s_display_mode'] === 'full') {
            $steps = $this->db->prepare('SELECT position, group_name, text FROM recipe_step WHERE recipe_id = ? ORDER BY position');
            $steps->execute([$id]);
            foreach ($steps->fetchAll(PDO::FETCH_ASSOC) as $step) {
                $recipe['steps'][] = ['position' => (int) $step['position'], 'group_name' => $step['group_name'], 'text' => $step['text']];
            }
        }
        $tags = $this->db->prepare('SELECT tag FROM recipe_tag WHERE recipe_id = ? ORDER BY tag');
        $tags->execute([$id]);
        $recipe['tags'] = $tags->fetchAll(PDO::FETCH_COLUMN);
        return $recipe;
    }

    /** @return array{items:list<array<string,mixed>>,next_cursor:?string} */
    public function search(string $text, ?int $sourceId, ?string $cursor): array
    {
        if (trim($text) === '') {
            throw new ApiError(400, 'invalid_request');
        }
        $after = 0;
        if ($cursor !== null) {
            $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
            if ($decoded === false || preg_match('/^[1-9][0-9]*$/', $decoded) !== 1) {
                throw new ApiError(400, 'invalid_cursor');
            }
            $after = (int) $decoded;
        }
        $pageSize = $this->config->integer('api.search_page_size');
        $query = $this->db->prepare('SELECT r.*, s.id AS s_id, s.name AS s_name, s.base_url AS s_base_url, s.display_mode AS s_display_mode, g.id AS g_id, g.core_name AS g_core_name, g.main_protein AS g_main_protein, g.manual_override AS g_manual_override FROM recipe r JOIN source s ON s.id = r.source_id LEFT JOIN dish_group g ON g.id = r.dish_group_id WHERE r.active = TRUE AND r.id > ? AND (? IS NULL OR r.source_id = ?) AND (r.title LIKE ? OR EXISTS (SELECT 1 FROM recipe_ingredient ri WHERE ri.recipe_id = r.id AND ri.raw_text LIKE ?)) ORDER BY r.id LIMIT ?');
        $needle = '%' . addcslashes($text, '%_\\') . '%';
        $query->bindValue(1, $after, PDO::PARAM_INT);
        $query->bindValue(2, $sourceId, $sourceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $query->bindValue(3, $sourceId, $sourceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $query->bindValue(4, $needle);
        $query->bindValue(5, $needle);
        $query->bindValue(6, $pageSize + 1, PDO::PARAM_INT);
        $query->execute();
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > $pageSize;
        $rows = array_slice($rows, 0, $pageSize);
        $items = array_map($this->summary(...), $rows);
        $next = $more && $rows !== [] ? rtrim(strtr(base64_encode((string) end($rows)['id']), '+/', '-_'), '=') : null;
        return ['items' => $items, 'next_cursor' => $next];
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed> */
    private function summary(array $row): array
    {
        return ['id' => (int) $row['id'], 'title' => $row['title'], 'image_url' => $row['image_url'], 'total_time_min' => $row['total_time_min'] === null ? null : (int) $row['total_time_min'], 'source' => ['id' => (int) $row['s_id'], 'name' => $row['s_name'], 'base_url' => $row['s_base_url'], 'display_mode' => $row['s_display_mode']], 'source_url' => $row['url'], 'dish_group' => $row['g_id'] === null ? null : ['id' => (int) $row['g_id'], 'core_name' => $row['g_core_name'], 'main_protein' => $row['g_main_protein'], 'manual_override' => (bool) $row['g_manual_override']], 'active' => (bool) $row['active']];
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed> */
    private function source(array $row): array
    {
        return ['id' => (int) $row['id'], 'name' => $row['name'], 'base_url' => $row['base_url'], 'display_mode' => $row['display_mode']];
    }
}
