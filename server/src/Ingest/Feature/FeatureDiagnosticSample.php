<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Feature;

use PDO;
use RuntimeException;

final class FeatureDiagnosticSample
{
    /** @var array<string, true> */
    private readonly array $mealTags;

    public function __construct(private readonly PDO $db, ?string $seedDirectory = null)
    {
        $mealTags = [];
        foreach (CsvTable::read(
            ($seedDirectory ?? dirname(__DIR__, 3) . '/seeds') . '/feature_tag_map.csv',
            ['tag', 'cuisine_code', 'meal_type_code', 'festive', 'fest_bonus', 'cuisine_scope', 'meal_tag_role'],
        ) as $row) {
            if (in_array($row['meal_tag_role'], ['main', 'exclude'], true)) {
                $mealTags[mb_strtolower($row['tag'])] = true;
            }
        }
        $this->mealTags = $mealTags;
    }

    /** @return array<string, mixed> */
    public function build(string $kind, int $limit, int $seed): array
    {
        if (!in_array($kind, ['protein_gap', 'vegetarian', 'dinner'], true) || $limit < 1 || $limit > 100) {
            throw new RuntimeException('Invalid diagnostic sample request.');
        }
        $condition = match ($kind) {
            'protein_gap' => ' AND main_protein IS NULL',
            'vegetarian' => ' AND is_vegetarian = TRUE',
            default => '',
        };
        $where = 'active = TRUE AND is_dinner_candidate = TRUE' . $condition;
        $countQuery = $this->db->query('SELECT COUNT(*) FROM recipe WHERE ' . $where);
        $count = $countQuery === false ? false : $countQuery->fetchColumn();
        if (!is_numeric($count)) {
            throw new RuntimeException('Cannot count diagnostic recipes.');
        }
        $recipes = $this->db->prepare(
            'SELECT id, url, title, main_protein, cuisine, is_vegetarian FROM recipe WHERE ' . $where
            . ' ORDER BY SHA2(CONCAT(:seed, ":", id), 256), id LIMIT :limit'
        );
        $recipes->bindValue(':seed', (string) $seed);
        $recipes->bindValue(':limit', $limit, PDO::PARAM_INT);
        $recipes->execute();
        $lines = $this->db->prepare(
            'SELECT ri.position, ri.raw_text, ri.normalise_status, '
            . 'COUNT(p.part_position) AS part_count, '
            . 'COALESCE(SUM(p.part_position IS NOT NULL AND p.ingredient_id IS NULL), 0) AS unresolved_parts, '
            . 'GROUP_CONCAT(i.category ORDER BY p.part_position SEPARATOR ",") AS categories '
            . 'FROM recipe_ingredient ri '
            . 'LEFT JOIN recipe_ingredient_part p ON p.recipe_id = ri.recipe_id AND p.position = ri.position '
            . 'LEFT JOIN ingredient i ON i.id = p.ingredient_id '
            . 'WHERE ri.recipe_id = ? AND ri.is_main = TRUE '
            . 'GROUP BY ri.recipe_id, ri.position, ri.raw_text, ri.normalise_status ORDER BY ri.position'
        );
        $tags = $this->db->prepare(
            'SELECT tag FROM recipe_tag WHERE recipe_id = ? ORDER BY tag'
        );
        $examples = [];
        foreach ($recipes->fetchAll(PDO::FETCH_ASSOC) as $recipe) {
            if (!is_array($recipe) || !is_numeric($recipe['id'] ?? null)) {
                throw new RuntimeException('Invalid diagnostic recipe.');
            }
            $lines->execute([(int) $recipe['id']]);
            $mainLines = [];
            $unmatched = false;
            $mixed = false;
            foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
                if (!is_array($line) || !is_string($line['raw_text'] ?? null)
                    || !is_string($line['normalise_status'] ?? null)
                    || !is_numeric($line['part_count'] ?? null)
                    || !is_numeric($line['unresolved_parts'] ?? null)
                    || ($line['categories'] !== null && !is_string($line['categories']))) {
                    throw new RuntimeException('Invalid diagnostic ingredient line.');
                }
                $mainLines[] = [
                    'raw_text' => $line['raw_text'],
                    'normalise_status' => $line['normalise_status'],
                    'categories' => $line['categories'] === null ? [] : explode(',', $line['categories']),
                ];
                $unmatched = $unmatched || $line['normalise_status'] !== 'matched'
                    || (int) $line['unresolved_parts'] > 0;
                $mixed = $mixed || (int) $line['part_count'] > 1;
            }
            $example = ['url' => $recipe['url'], 'title' => $recipe['title'], 'main_ingredients' => $mainLines];
            if ($kind === 'dinner') {
                $tags->execute([(int) $recipe['id']]);
                $example['meal_type_tags'] = array_values(array_filter(
                    $tags->fetchAll(PDO::FETCH_COLUMN),
                    fn (mixed $tag): bool => is_string($tag) && isset($this->mealTags[mb_strtolower($tag)]),
                ));
                $example['main_protein'] = $recipe['main_protein'];
                $example['cuisine'] = $recipe['cuisine'];
                $example['is_vegetarian'] = $recipe['is_vegetarian'] === null
                    ? null : (bool) $recipe['is_vegetarian'];
            }
            if ($kind === 'protein_gap') {
                $example['reason'] = $unmatched ? 'unmatched_ingredient'
                    : ($mixed ? 'mixed' : 'missing_protein_category');
            }
            $examples[] = $example;
        }
        return [
            'kind' => $kind,
            'seed' => $seed,
            'requested' => $limit,
            'eligible' => (int) $count,
            'returned' => count($examples),
            'examples' => $examples,
        ];
    }
}
