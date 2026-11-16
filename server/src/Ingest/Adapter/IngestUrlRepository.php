<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Adapter;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class IngestUrlRepository
{
    public function __construct(private readonly PDO $db, private readonly int $sourceId)
    {
    }

    public function enqueue(string $url, string $via, ?DateTimeImmutable $modifiedAt = null): bool
    {
        if (!in_array($via, ['sitemap', 'category'], true)) {
            throw new RuntimeException('Invalid discovery method.');
        }
        $hash = hash('sha256', $url);
        $insert = $this->db->prepare(
            'INSERT IGNORE INTO ingest_url (source_id, url, url_hash, discovered_via) VALUES (?, ?, UNHEX(?), ?)'
        );
        $insert->execute([$this->sourceId, $url, $hash, $via]);
        if ($insert->rowCount() === 1) {
            return true;
        }
        if ($modifiedAt !== null) {
            $requeue = $this->db->prepare(
                "UPDATE ingest_url SET status = 'queued', next_attempt_at = NULL " .
                "WHERE source_id = ? AND url_hash = UNHEX(?) AND status = 'fetched' " .
                'AND last_fetched_at IS NOT NULL AND last_fetched_at < ?'
            );
            $requeue->execute([
                $this->sourceId,
                $hash,
                $modifiedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            ]);
            return $requeue->rowCount() === 1;
        }
        return false;
    }

    /** @return list<array{id: int, url: string, discovered_via: string, etag: string|null, last_modified: string|null, attempts: int, recipe_id: int|null}> */
    public function due(int $limit, int $maxAttempts): array
    {
        if ($limit < 1 || $maxAttempts < 1) {
            throw new RuntimeException('Fetch limit and maximum attempts must be positive.');
        }
        $query = $this->db->prepare(
            'SELECT id, url, discovered_via, etag, last_modified, attempts, recipe_id FROM ingest_url ' .
            "WHERE source_id = ? AND attempts < ? AND (status = 'queued' OR " .
            "(status = 'failed' AND next_attempt_at IS NOT NULL AND next_attempt_at <= UTC_TIMESTAMP(6))) " .
            'ORDER BY id LIMIT ?'
        );
        $query->bindValue(1, $this->sourceId, PDO::PARAM_INT);
        $query->bindValue(2, $maxAttempts, PDO::PARAM_INT);
        $query->bindValue(3, $limit, PDO::PARAM_INT);
        $query->execute();
        $rows = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !isset($row['id'], $row['url'], $row['discovered_via'], $row['attempts'])
                || !is_numeric($row['id']) || !is_string($row['url'])
                || !is_string($row['discovered_via']) || !is_numeric($row['attempts'])) {
                throw new RuntimeException('Invalid ingest URL row.');
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'url' => $row['url'],
                'discovered_via' => $row['discovered_via'],
                'etag' => is_string($row['etag'] ?? null) ? $row['etag'] : null,
                'last_modified' => is_string($row['last_modified'] ?? null) ? $row['last_modified'] : null,
                'attempts' => (int) $row['attempts'],
                'recipe_id' => is_numeric($row['recipe_id'] ?? null) ? (int) $row['recipe_id'] : null,
            ];
        }
        return $rows;
    }

    public function markSkippedRobots(int $id): void
    {
        $this->updateSimple($id, 'skipped_robots', null);
    }

    public function markGone(int $id, int $status): void
    {
        $this->updateSimple($id, 'gone', $status);
    }

    public function markNotModified(int $id, int $status): void
    {
        $query = $this->db->prepare(
            "UPDATE ingest_url SET status = 'fetched', http_status = ?, attempts = 0, " .
            'last_fetched_at = UTC_TIMESTAMP(6), next_attempt_at = NULL WHERE id = ? AND source_id = ?'
        );
        $query->execute([$status, $id, $this->sourceId]);
    }

    public function markFailed(int $id, ?int $status, ?int $delaySeconds): void
    {
        $query = $this->db->prepare(
            "UPDATE ingest_url SET status = 'failed', http_status = ?, attempts = attempts + 1, " .
            'next_attempt_at = IF(? IS NULL, NULL, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND)) ' .
            'WHERE id = ? AND source_id = ?'
        );
        $query->execute([$status, $delaySeconds, $delaySeconds, $id, $this->sourceId]);
    }

    public function markFetched(int $id, int $recipeId, int $status, ?string $etag, ?string $lastModified): void
    {
        $query = $this->db->prepare(
            "UPDATE ingest_url SET status = 'fetched', recipe_id = ?, http_status = ?, " .
            'etag = ?, last_modified = ?, attempts = 0, ' .
            'last_fetched_at = UTC_TIMESTAMP(6), next_attempt_at = NULL WHERE id = ? AND source_id = ?'
        );
        $query->execute([$recipeId, $status, $etag, $lastModified, $id, $this->sourceId]);
    }

    public function markNotRecipe(int $id, int $status): void
    {
        $query = $this->db->prepare(
            "UPDATE ingest_url SET status = 'not_recipe', http_status = ?, attempts = 0, " .
            'last_fetched_at = UTC_TIMESTAMP(6), next_attempt_at = NULL WHERE id = ? AND source_id = ?'
        );
        $query->execute([$status, $id, $this->sourceId]);
    }

    private function updateSimple(int $id, string $status, ?int $httpStatus): void
    {
        $query = $this->db->prepare(
            'UPDATE ingest_url SET status = ?, http_status = ?, attempts = attempts + 1, ' .
            'last_fetched_at = UTC_TIMESTAMP(6), next_attempt_at = NULL WHERE id = ? AND source_id = ?'
        );
        $query->execute([$status, $httpStatus, $id, $this->sourceId]);
    }
}
