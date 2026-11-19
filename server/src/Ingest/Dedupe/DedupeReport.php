<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Dedupe;

use PDO;
use RuntimeException;

final class DedupeReport
{
    private readonly DedupeConfig $config;

    public function __construct(private readonly PDO $db)
    {
        $this->config = new DedupeConfig($db);
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $counts = $this->db->prepare(
            'SELECT (SELECT COUNT(*) FROM recipe WHERE merged_into_id IS NOT NULL AND active = FALSE) AS merged, ' .
            '(SELECT COUNT(*) FROM duplicate_candidate WHERE status = ?) AS review'
        );
        $counts->execute(['pending']);
        $row = $counts->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_numeric($row['merged'] ?? null)
            || !is_numeric($row['review'] ?? null)) {
            throw new RuntimeException('Cannot read deduplication counts.');
        }
        $groups = $this->db->prepare(
            'SELECT g.id, g.core_name, g.main_protein, COUNT(r.id) AS size ' .
            'FROM dish_group g JOIN recipe r ON r.dish_group_id = g.id AND r.active = TRUE ' .
            'GROUP BY g.id, g.core_name, g.main_protein HAVING COUNT(r.id) > 1 ' .
            'ORDER BY size DESC, g.id LIMIT ?'
        );
        $groups->bindValue(1, $this->config->integer('dedupe.report_top_groups'), PDO::PARAM_INT);
        $groups->execute();
        $top = [];
        foreach ($groups->fetchAll(PDO::FETCH_ASSOC) as $group) {
            if (!is_array($group) || !is_numeric($group['id'] ?? null)
                || !is_numeric($group['size'] ?? null)) {
                throw new RuntimeException('Invalid dish group report row.');
            }
            $titles = $this->db->prepare(
                'SELECT title FROM recipe WHERE dish_group_id = ? AND active = TRUE ORDER BY id'
            );
            $titles->execute([$group['id']]);
            $top[] = [
                'id' => (int) $group['id'],
                'core_name' => $group['core_name'],
                'main_protein' => $group['main_protein'],
                'size' => (int) $group['size'],
                'titles' => $titles->fetchAll(PDO::FETCH_COLUMN),
            ];
        }
        $groupCount = $this->db->prepare(
            'SELECT COUNT(*) FROM (SELECT dish_group_id FROM recipe WHERE active = TRUE ' .
            'AND dish_group_id IS NOT NULL GROUP BY dish_group_id HAVING COUNT(*) > 1) grouped'
        );
        $groupCount->execute();
        $count = $groupCount->fetchColumn();
        if (!is_numeric($count)) {
            throw new RuntimeException('Cannot read dish group count.');
        }
        return [
            'merged_copies' => (int) $row['merged'],
            'pending_duplicate_reviews' => (int) $row['review'],
            'dish_groups' => (int) $count,
            'largest_groups' => $top,
        ];
    }

    public function save(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, recursive: true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create deduplication report directory.');
        }
        $json = json_encode($this->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json . PHP_EOL) === false) {
            throw new RuntimeException('Cannot save deduplication report.');
        }
    }
}
