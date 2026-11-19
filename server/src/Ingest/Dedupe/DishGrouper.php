<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Dedupe;

use Matsvep\Ingest\Feature\CsvTable;
use PDO;
use RuntimeException;

/** @phpstan-type GroupRecipe array{id:int,title:string,main_protein:?string,dish_group_id:?int,manual_override:bool,ingredients:array<string,true>} */
final class DishGrouper
{
    private readonly DedupeConfig $config;

    /** @var list<array{term: string, mode: string}> */
    private array $reductions = [];

    public function __construct(private readonly PDO $db, string $seedDirectory)
    {
        $this->config = new DedupeConfig($db);
        foreach (CsvTable::read($seedDirectory . '/title_reduction.csv', ['term', 'mode']) as $row) {
            if ($row['term'] === '' || !in_array($row['mode'], ['remove_prefix', 'truncate_after'], true)) {
                throw new RuntimeException('Invalid title reduction seed row.');
            }
            $this->reductions[] = ['term' => $row['term'], 'mode' => $row['mode']];
        }
    }

    public function run(): int
    {
        $query = $this->db->prepare(
            'SELECT r.id, r.title, r.main_protein, r.dish_group_id, r.dish_group_manual, g.manual_override ' .
            'FROM recipe r LEFT JOIN dish_group g ON g.id = r.dish_group_id WHERE r.active = TRUE ORDER BY r.id'
        );
        $query->execute();
        $recipes = [];
        $buckets = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !is_numeric($row['id'] ?? null) || !is_string($row['title'] ?? null)) {
                throw new RuntimeException('Invalid recipe for dish grouping.');
            }
            $id = (int) $row['id'];
            $protein = is_string($row['main_protein'] ?? null) ? $row['main_protein'] : null;
            $groupId = is_numeric($row['dish_group_id'] ?? null) ? (int) $row['dish_group_id'] : null;
            $manual = (is_numeric($row['manual_override'] ?? null) && (int) $row['manual_override'] === 1)
                || (is_numeric($row['dish_group_manual'] ?? null) && (int) $row['dish_group_manual'] === 1);
            $recipes[$id] = [
                'id' => $id,
                'title' => $row['title'],
                'main_protein' => $protein,
                'dish_group_id' => $groupId,
                'manual_override' => $manual,
                'ingredients' => [],
            ];
            if ($protein !== null && !$manual) {
                $core = $this->coreName($row['title']);
                if ($core !== '') {
                    $buckets[$core . ':' . $protein]['core'] = $core;
                    $buckets[$core . ':' . $protein]['ids'][] = $id;
                }
            }
        }
        $ingredients = $this->db->prepare(
            'SELECT p.recipe_id, i.canonical_name FROM recipe_ingredient_part p ' .
            'JOIN recipe_ingredient line ON line.recipe_id = p.recipe_id AND line.position = p.position ' .
            'JOIN ingredient i ON i.id = p.ingredient_id JOIN recipe r ON r.id = p.recipe_id ' .
            'WHERE r.active = TRUE AND line.is_main = TRUE AND i.is_staple = FALSE'
        );
        $ingredients->execute();
        foreach ($ingredients->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !is_numeric($row['recipe_id'] ?? null)
                || !is_string($row['canonical_name'] ?? null)) {
                throw new RuntimeException('Invalid group ingredient.');
            }
            $id = (int) $row['recipe_id'];
            if (!isset($recipes[$id])) {
                throw new RuntimeException('Group ingredient references an inactive recipe.');
            }
            $recipes[$id]['ingredients'][mb_strtolower($row['canonical_name'])] = true;
        }
        $overlap = new RecipeDeduplicator($this->db);
        $insert = $this->db->prepare(
            'INSERT INTO dish_group (core_name, main_protein, manual_override) VALUES (?, ?, FALSE)'
        );
        $assign = $this->db->prepare('UPDATE recipe SET dish_group_id = ? WHERE id = ? AND active = TRUE');
        $assigned = 0;
        foreach ($buckets as $bucket) {
            $ids = $bucket['ids'];
            $parent = array_combine($ids, $ids);
            foreach ($ids as $position => $a) {
                foreach (array_slice($ids, $position + 1) as $b) {
                    if ($overlap->overlap($recipes[$a]['ingredients'], $recipes[$b]['ingredients'])
                        < $this->config->decimal('dedupe.group_overlap')) {
                        continue;
                    }
                    $rootA = $this->root($parent, $a);
                    $rootB = $this->root($parent, $b);
                    $parent[$rootB] = $rootA;
                }
            }
            $components = [];
            foreach ($ids as $id) {
                $components[$this->root($parent, $id)][] = $id;
            }
            foreach ($components as $component) {
                if (count($component) < 2) {
                    continue;
                }
                $existing = [];
                foreach ($component as $id) {
                    if ($recipes[$id]['dish_group_id'] !== null) {
                        $existing[] = $recipes[$id]['dish_group_id'];
                    }
                }
                if ($existing === []) {
                    $first = $recipes[$component[0]];
                    $insert->execute([$bucket['core'], $first['main_protein']]);
                    $groupId = (int) $this->db->lastInsertId();
                } else {
                    $groupId = min($existing);
                }
                foreach ($component as $id) {
                    if ((int) ($recipes[$id]['dish_group_id'] ?? 0) !== $groupId) {
                        $assign->execute([$groupId, $id]);
                        $assigned++;
                    }
                }
            }
        }
        return $assigned;
    }

    /** @param array<int, int> $parent */
    private function root(array $parent, int $id): int
    {
        while ($parent[$id] !== $id) {
            $id = $parent[$id];
        }
        return $id;
    }

    public function coreName(string $title): string
    {
        $core = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title)));
        foreach ($this->reductions as $rule) {
            $term = mb_strtolower($rule['term']);
            if ($rule['mode'] === 'remove_prefix' && str_starts_with($core, $term . ' ')) {
                $core = mb_substr($core, mb_strlen($term) + 1);
            } elseif ($rule['mode'] === 'truncate_after') {
                $position = mb_strpos(' ' . $core . ' ', ' ' . $term . ' ');
                if ($position !== false && $position > 0) {
                    $core = trim(mb_substr($core, 0, $position - 1));
                }
            }
        }
        return $core;
    }
}
