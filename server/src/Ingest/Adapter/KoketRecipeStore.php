<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Adapter;

use Matsvep\Ingest\Http\HttpResponse;
use PDO;
use RuntimeException;
use Throwable;

final class KoketRecipeStore
{
    public function __construct(
        private readonly PDO $db,
        private readonly IngestUrlRepository $queue,
        private readonly int $sourceId,
    ) {
    }

    public function save(
        int $ingestId,
        string $url,
        ?int $existingRecipeId,
        ParsedRecipe $recipe,
        HttpResponse $response,
    ): int {
        $compressed = gzencode($response->body);
        if ($compressed === false) {
            throw new RuntimeException('Cannot compress recipe snapshot.');
        }
        $this->db->beginTransaction();
        try {
            $recipeId = $existingRecipeId;
            if ($recipeId === null) {
                $insert = $this->db->prepare(
                    'INSERT INTO recipe (source_id, url, title, image_url, total_time_min, servings, ' .
                    'source_status, parsed_at, parser_version, active) ' .
                    "VALUES (?, ?, ?, ?, ?, ?, 'ok', UTC_TIMESTAMP(6), 'koket_v2', TRUE)"
                );
                $insert->execute([
                    $this->sourceId, $url, $recipe->title, $recipe->imageUrl, $recipe->totalTimeMinutes,
                    $recipe->servings,
                ]);
                $recipeId = (int) $this->db->lastInsertId();
            } else {
                $update = $this->db->prepare(
                    'UPDATE recipe SET title = ?, image_url = ?, total_time_min = ?, servings = ?, ' .
                    "source_status = 'ok', parsed_at = UTC_TIMESTAMP(6), parser_version = 'koket_v2' " .
                    'WHERE id = ? AND source_id = ?'
                );
                $update->execute([
                    $recipe->title, $recipe->imageUrl, $recipe->totalTimeMinutes, $recipe->servings,
                    $recipeId, $this->sourceId,
                ]);
                $this->db->prepare('DELETE FROM recipe_ingredient WHERE recipe_id = ?')->execute([$recipeId]);
                $this->db->prepare('DELETE FROM recipe_step WHERE recipe_id = ?')->execute([$recipeId]);
                $this->db->prepare('DELETE FROM recipe_tag WHERE recipe_id = ?')->execute([$recipeId]);
            }

            $ingredient = $this->db->prepare(
                'INSERT INTO recipe_ingredient (recipe_id, position, group_name, raw_text) VALUES (?, ?, ?, ?)'
            );
            foreach ($recipe->ingredients as $index => $line) {
                $ingredient->execute([$recipeId, $index + 1, $line['group_name'], $line['text']]);
            }
            $step = $this->db->prepare('INSERT INTO recipe_step (recipe_id, position, group_name, text) VALUES (?, ?, ?, ?)');
            foreach ($recipe->steps as $index => $line) {
                $step->execute([$recipeId, $index + 1, $line['group_name'], $line['text']]);
            }
            $tag = $this->db->prepare('INSERT IGNORE INTO recipe_tag (recipe_id, tag) VALUES (?, ?)');
            foreach ($recipe->tags as $value) {
                $tag->execute([$recipeId, $value]);
            }

            $link = $this->db->prepare(
                'INSERT IGNORE INTO recipe_source_link (recipe_id, source_id, url, url_hash, is_primary) ' .
                'VALUES (?, ?, ?, UNHEX(?), TRUE)'
            );
            $link->execute([$recipeId, $this->sourceId, $url, hash('sha256', $url)]);

            $raw = $this->db->prepare(
                'INSERT INTO recipe_raw (recipe_id, fetched_at, etag, format, payload_gz) ' .
                'VALUES (?, UTC_TIMESTAMP(6), ?, ?, ?)'
            );
            $raw->execute([$recipeId, $response->header('etag'), $recipe->format, $compressed]);
            $this->queue->markFetched(
                $ingestId,
                $recipeId,
                $response->status,
                $response->header('etag'),
                $response->header('last-modified'),
            );
            $this->db->commit();
            return $recipeId;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }
}
