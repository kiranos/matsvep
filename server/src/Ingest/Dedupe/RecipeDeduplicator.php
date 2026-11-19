<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Dedupe;

use PDO;
use RuntimeException;
use Throwable;

/** @phpstan-type RecipeRow array{id:int,url:string,title:string,adapter:string,total_time_min:?int,servings:?float,image_url:?string,ingredient_count:int,step_count:int,ingredients:array<string,true>,inactive:bool} */
final class RecipeDeduplicator
{
    private readonly DedupeConfig $config;

    public function __construct(private readonly PDO $db)
    {
        $this->config = new DedupeConfig($db);
    }

    /** @return array{merged: int, queued_for_review: int} */
    public function run(): array
    {
        $recipes = $this->recipes();
        $buckets = [];
        foreach ($recipes as $id => $recipe) {
            $buckets['title:' . $this->titleKey($recipe['title'])][] = $id;
            $buckets['url:' . $this->urlKey($recipe['url'])][] = $id;
        }
        $seen = [];
        $inactive = [];
        $merged = 0;
        $review = 0;
        foreach ($buckets as $ids) {
            sort($ids);
            foreach ($ids as $position => $a) {
                if (isset($inactive[$a])) {
                    continue;
                }
                foreach (array_slice($ids, $position + 1) as $b) {
                    if (isset($inactive[$b])) {
                        continue;
                    }
                    $pair = $a . ':' . $b;
                    if (isset($seen[$pair])) {
                        continue;
                    }
                    $seen[$pair] = true;
                    $canonical = $this->urlKey($recipes[$a]['url']) === $this->urlKey($recipes[$b]['url']);
                    $sameTitle = $this->titleKey($recipes[$a]['title']) === $this->titleKey($recipes[$b]['title']);
                    if (!$canonical && !$sameTitle) {
                        continue;
                    }
                    $similarity = $canonical ? 1.0 : $this->overlap($recipes[$a]['ingredients'], $recipes[$b]['ingredients']);
                    if ($similarity < $this->config->decimal('dedupe.review')) {
                        continue;
                    }
                    $status = $this->candidateStatus($a, $b);
                    if ($status === 'not_duplicate') {
                        continue;
                    }
                    if ($canonical || ($similarity >= $this->config->decimal('dedupe.auto_merge')
                        && $this->similarMetadata($recipes[$a], $recipes[$b]))) {
                        [$winner, $loser] = $this->preferred($recipes[$a], $recipes[$b]);
                        $this->merge($winner, $loser, $a, $b, $similarity);
                        $inactive[$loser] = true;
                        $merged++;
                    } else {
                        $this->recordCandidate($a, $b, $similarity, 'pending');
                        if ($status === null) {
                            $review++;
                        }
                    }
                }
            }
        }
        return ['merged' => $merged, 'queued_for_review' => $review];
    }

    /** @return array<int, RecipeRow> */
    private function recipes(): array
    {
        $query = $this->db->prepare(
            'SELECT r.id, r.url, r.title, r.total_time_min, r.servings, r.image_url, s.adapter, ' .
            '(SELECT COUNT(*) FROM recipe_ingredient i WHERE i.recipe_id = r.id) AS ingredient_count, ' .
            '(SELECT COUNT(*) FROM recipe_step st WHERE st.recipe_id = r.id) AS step_count ' .
            'FROM recipe r JOIN source s ON s.id = r.source_id WHERE r.active = TRUE ORDER BY r.id'
        );
        $query->execute();
        $recipes = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !is_numeric($row['id'] ?? null) || !is_string($row['url'] ?? null)
                || !is_string($row['title'] ?? null) || !is_string($row['adapter'] ?? null)) {
                throw new RuntimeException('Invalid recipe for deduplication.');
            }
            foreach (['ingredient_count', 'step_count'] as $count) {
                if (!is_numeric($row[$count] ?? null)) {
                    throw new RuntimeException('Invalid recipe completeness count.');
                }
            }
            $id = (int) $row['id'];
            $recipes[$id] = [
                'id' => $id,
                'url' => $row['url'],
                'title' => $row['title'],
                'adapter' => $row['adapter'],
                'total_time_min' => is_numeric($row['total_time_min']) ? (int) $row['total_time_min'] : null,
                'servings' => is_numeric($row['servings']) ? (float) $row['servings'] : null,
                'image_url' => is_string($row['image_url']) ? $row['image_url'] : null,
                'ingredient_count' => (int) $row['ingredient_count'],
                'step_count' => (int) $row['step_count'],
                'ingredients' => [],
                'inactive' => false,
            ];
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
                throw new RuntimeException('Invalid main ingredient for deduplication.');
            }
            $id = (int) $row['recipe_id'];
            if (!isset($recipes[$id])) {
                throw new RuntimeException('Ingredient references an inactive recipe.');
            }
            $recipes[$id]['ingredients'][mb_strtolower($row['canonical_name'])] = true;
        }
        return $recipes;
    }

    private function titleKey(string $title): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title)));
    }

    private function urlKey(string $url): string
    {
        return $url;
    }

    /**
     * @param RecipeRow $a
     * @param RecipeRow $b
     */
    private function similarMetadata(array $a, array $b): bool
    {
        if (is_numeric($a['total_time_min']) && is_numeric($b['total_time_min'])
            && abs((int) $a['total_time_min'] - (int) $b['total_time_min'])
                > $this->config->integer('dedupe.time_tolerance_min')) {
            return false;
        }
        return !is_numeric($a['servings']) || !is_numeric($b['servings'])
            || abs((float) $a['servings'] - (float) $b['servings'])
                <= $this->config->decimal('dedupe.servings_tolerance');
    }

    /**
     * @param array<string, true> $a
     * @param array<string, true> $b
     */
    public function overlap(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $intersection = count(array_intersect_key($a, $b));
        return $intersection / (count($a) + count($b) - $intersection);
    }

    private function candidateStatus(int $a, int $b): ?string
    {
        $query = $this->db->prepare('SELECT status FROM duplicate_candidate WHERE recipe_a = ? AND recipe_b = ?');
        $query->execute([$a, $b]);
        $status = $query->fetchColumn();
        return is_string($status) ? $status : null;
    }

    private function recordCandidate(int $a, int $b, float $similarity, string $status): void
    {
        $query = $this->db->prepare(
            'INSERT INTO duplicate_candidate (recipe_a, recipe_b, similarity, status) VALUES (?, ?, ?, ?) ' .
            "ON DUPLICATE KEY UPDATE similarity = IF(status = 'pending', VALUES(similarity), similarity), " .
            "status = IF(status = 'pending', VALUES(status), status)"
        );
        $query->execute([$a, $b, $similarity, $status]);
    }

    /**
     * @param RecipeRow $a
     * @param RecipeRow $b
     * @return array{int, int}
     */
    private function preferred(array $a, array $b): array
    {
        $scoreA = $a['ingredient_count'] + $a['step_count']
            + (int) ($a['image_url'] !== null) + (int) ($a['total_time_min'] !== null)
            + (int) ($a['servings'] !== null);
        $scoreB = $b['ingredient_count'] + $b['step_count']
            + (int) ($b['image_url'] !== null) + (int) ($b['total_time_min'] !== null)
            + (int) ($b['servings'] !== null);
        $priority = $this->config->sourcePriority();
        $positionA = array_search($a['adapter'], $priority, true);
        $positionB = array_search($b['adapter'], $priority, true);
        $rankA = $positionA === false ? count($priority) : $positionA;
        $rankB = $positionB === false ? count($priority) : $positionB;
        $winner = $scoreA > $scoreB ? $a : ($scoreB > $scoreA ? $b
            : ($rankA < $rankB ? $a : ($rankB < $rankA ? $b
                : ($a['id'] < $b['id'] ? $a : $b))));
        return [$winner['id'], $winner === $a ? $b['id'] : $a['id']];
    }

    private function merge(int $winner, int $loser, int $a, int $b, float $similarity): void
    {
        $this->db->beginTransaction();
        try {
            $links = $this->db->prepare('SELECT id, source_id, url_hash FROM recipe_source_link WHERE recipe_id = ?');
            $links->execute([$loser]);
            $existing = $this->db->prepare('SELECT id FROM recipe_source_link WHERE source_id = ? AND url_hash = ?');
            $move = $this->db->prepare('UPDATE recipe_source_link SET recipe_id = ?, is_primary = FALSE WHERE id = ?');
            foreach ($links->fetchAll(PDO::FETCH_ASSOC) as $link) {
                if (!is_array($link) || !is_numeric($link['id'] ?? null) || !is_numeric($link['source_id'] ?? null)) {
                    throw new RuntimeException('Invalid source link during merge.');
                }
                $existing->execute([$link['source_id'], $link['url_hash']]);
                $owner = $existing->fetchColumn();
                if ($owner !== false && (int) $owner !== (int) $link['id']) {
                    $delete = $this->db->prepare('DELETE FROM recipe_source_link WHERE id = ?');
                    $delete->execute([$link['id']]);
                } else {
                    $move->execute([$winner, $link['id']]);
                }
            }
            $ingest = $this->db->prepare('UPDATE ingest_url SET recipe_id = ? WHERE recipe_id = ?');
            $ingest->execute([$winner, $loser]);
            $inactive = $this->db->prepare(
                'UPDATE recipe SET active = FALSE, merged_into_id = ?, dish_group_id = NULL WHERE id = ? AND active = TRUE'
            );
            $inactive->execute([$winner, $loser]);
            $this->recordCandidate($a, $b, $similarity, 'merged');
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }
}
