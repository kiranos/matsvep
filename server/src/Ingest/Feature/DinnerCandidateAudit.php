<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Feature;

use PDO;
use RuntimeException;

final class DinnerCandidateAudit
{
    private readonly FeatureConfig $config;

    /** @var list<string> */
    private readonly array $auditTerms;

    public function __construct(private readonly PDO $db, string $seedDirectory)
    {
        $this->config = new FeatureConfig($db);
        $terms = CsvTable::read($seedDirectory . '/feature_dinner_audit_titles.csv', ['term']);
        $this->auditTerms = array_column($terms, 'term');
    }

    /** @return array<int, string> */
    public function candidates(): array
    {
        $query = $this->db->prepare(
            'SELECT id, title FROM recipe WHERE active = TRUE AND is_dinner_candidate = TRUE ORDER BY id',
        );
        $query->execute();
        $result = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !is_numeric($row['id'] ?? null) || !is_string($row['title'] ?? null)) {
                throw new RuntimeException('Invalid dinner candidate for audit.');
            }
            $result[(int) $row['id']] = $row['title'];
        }
        return $result;
    }

    /** @param array<int, string> $before
     * @param array<int, string> $after
     * @return array<string, mixed>
     */
    public function build(array $before, array $after): array
    {
        $removed = array_diff_key($before, $after);
        $added = array_diff_key($after, $before);
        $flagged = [];
        foreach ($removed as $id => $title) {
            foreach ($this->auditTerms as $term) {
                if (mb_stripos($title, $term) !== false) {
                    $flagged[] = ['id' => $id, 'title' => $title];
                    break;
                }
            }
        }
        return [
            'before' => count($before),
            'after' => count($after),
            'removed_count' => count($removed),
            'added_count' => count($added),
            'removed_titles' => $this->sample($removed),
            'remaining_titles' => $this->sample($after),
            'savory_title_removed' => $flagged,
        ];
    }

    /** @param array<int, string> $titles
     * @return list<array{id: int, title: string}>
     */
    private function sample(array $titles): array
    {
        $seed = $this->config->string('feature.report_sample_seed');
        $limit = $this->config->integer('feature.report_dinner_audit_size');
        if ($limit < 1) {
            throw new RuntimeException('Invalid dinner audit sample size.');
        }
        $items = [];
        foreach ($titles as $id => $title) {
            $items[] = ['sort' => hash('sha256', $seed . ':' . $id), 'id' => $id, 'title' => $title];
        }
        usort($items, static fn (array $a, array $b): int => strcmp($a['sort'], $b['sort']));
        return array_map(
            static fn (array $row): array => ['id' => $row['id'], 'title' => $row['title']],
            array_slice($items, 0, $limit),
        );
    }
}
