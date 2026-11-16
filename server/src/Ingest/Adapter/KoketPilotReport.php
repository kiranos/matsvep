<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Adapter;

use PDO;
use RuntimeException;

final class KoketPilotReport
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $sourceId,
        private readonly int $maxAttempts,
        private readonly int $delaySeconds,
    ) {
    }

    /**
     * @return array{
     *   status_counts: array<string, int>,
     *   classification: array{recipes: int, not_recipe: int, recipe_share: float|null},
     *   recipe_paths: array{root: int, nested: int, root_share: float|null, nested_share: float|null},
     *   nested_root_duplicates: int|null,
     *   completeness: array{recipes: int, total_time_share: float|null, servings_share: float|null,
     *     image_share: float|null, ingredients: array{min: int|null, median: float|null, max: int|null},
     *     steps: array{min: int|null, median: float|null, max: int|null}, low_quality_examples: list<string>}|null,
     *   discovery_complete: bool,
     *   not_recipe_prefixes: array<array-key, int>,
     *   failure_http_statuses: array<array-key, int>,
     *   failure_examples: array<string, list<string>>,
     *   pending_retries: list<array{url: string, next_attempt_at: string}>,
     *   queue: array{total: int, done: int, remaining: int, due: int, next_retry_at: string|null, delay_s: int}
     * }
     */
    public function build(bool $includeQuality = true): array
    {
        $clockQuery = $this->db->prepare('SELECT UTC_TIMESTAMP(6)');
        $clockQuery->execute();
        $now = $clockQuery->fetchColumn();
        if (!is_string($now)) {
            throw new RuntimeException('Cannot read database clock.');
        }
        $query = $this->db->prepare(
            'SELECT url, status, http_status, attempts, next_attempt_at FROM ingest_url WHERE source_id = ? ORDER BY id'
        );
        $query->execute([$this->sourceId]);

        $statuses = array_fill_keys(['queued', 'fetched', 'failed', 'skipped_robots', 'gone', 'not_recipe', 'duplicate'], 0);
        $prefixes = [];
        $httpStatuses = [];
        $examples = [];
        $pendingRetries = [];
        $rootRecipes = 0;
        $nestedRecipes = 0;
        $remaining = 0;
        $due = 0;
        $nextRetryAt = null;
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !is_string($row['url'] ?? null) || !is_string($row['status'] ?? null)
                || !is_numeric($row['attempts'] ?? null)) {
                throw new RuntimeException('Invalid ingest URL report row.');
            }
            $url = $row['url'];
            $status = $row['status'];
            if (!array_key_exists($status, $statuses)) {
                throw new RuntimeException('Unknown ingest URL status.');
            }
            $statuses[$status]++;
            $path = parse_url($url, PHP_URL_PATH);
            $segments = is_string($path) ? explode('/', trim($path, '/')) : [];
            $isRoot = count($segments) === 1;
            if ($status === 'fetched') {
                if ($isRoot) {
                    $rootRecipes++;
                } else {
                    $nestedRecipes++;
                }
            } elseif ($status === 'not_recipe') {
                $prefix = $isRoot ? '(root)' : '/' . ($segments[0] ?? '');
                $prefixes[$prefix] = ($prefixes[$prefix] ?? 0) + 1;
            }

            $httpStatus = $row['http_status'] ?? null;
            if (in_array($status, ['failed', 'gone'], true)) {
                if (is_numeric($httpStatus)) {
                    $key = (string) $httpStatus;
                    $httpStatuses[$key] = ($httpStatuses[$key] ?? 0) + 1;
                    $this->addExample($examples, 'http_' . $key, $url);
                } else {
                    $this->addExample($examples, 'transport', $url);
                }
            } elseif ($status === 'skipped_robots') {
                $this->addExample($examples, 'skipped_robots', $url);
            }

            $attempts = (int) $row['attempts'];
            $retryAt = is_string($row['next_attempt_at'] ?? null) ? $row['next_attempt_at'] : null;
            if ($status === 'queued' && $attempts < $this->maxAttempts) {
                $remaining++;
                $due++;
            } elseif ($status === 'failed' && $retryAt !== null && $attempts < $this->maxAttempts) {
                $remaining++;
                if ($retryAt <= $now) {
                    $due++;
                } elseif ($nextRetryAt === null || $retryAt < $nextRetryAt) {
                    $nextRetryAt = $retryAt;
                }
                $pendingRetries[] = ['url' => $url, 'next_attempt_at' => $this->asUtcIso($retryAt)];
            }
        }

        usort($pendingRetries, static fn (array $a, array $b): int =>
            strcmp($a['next_attempt_at'], $b['next_attempt_at']) ?: strcmp($a['url'], $b['url']));
        $topStatuses = $this->rankCounts($httpStatuses, 10);
        $selectedExamples = [];
        foreach ($topStatuses as $httpStatus => $_count) {
            $selectedExamples['http_' . $httpStatus] = $examples['http_' . $httpStatus] ?? [];
        }
        foreach (['transport', 'skipped_robots'] as $type) {
            if (isset($examples[$type])) {
                $selectedExamples[$type] = $examples[$type];
            }
        }

        $classified = $statuses['fetched'] + $statuses['not_recipe'];
        return [
            'discovery_complete' => $this->discoveryComplete(),
            'status_counts' => $statuses,
            'classification' => [
                'recipes' => $statuses['fetched'],
                'not_recipe' => $statuses['not_recipe'],
                'recipe_share' => $classified > 0 ? round($statuses['fetched'] / $classified, 4) : null,
            ],
            'recipe_paths' => [
                'root' => $rootRecipes,
                'nested' => $nestedRecipes,
                'root_share' => $statuses['fetched'] > 0 ? round($rootRecipes / $statuses['fetched'], 4) : null,
                'nested_share' => $statuses['fetched'] > 0 ? round($nestedRecipes / $statuses['fetched'], 4) : null,
            ],
            'nested_root_duplicates' => $includeQuality ? $this->nestedRootDuplicates() : null,
            'completeness' => $includeQuality ? $this->completeness() : null,
            'not_recipe_prefixes' => $this->rankCounts($prefixes, 20),
            'failure_http_statuses' => $topStatuses,
            'failure_examples' => $selectedExamples,
            'pending_retries' => $pendingRetries,
            'queue' => [
                'total' => array_sum($statuses),
                'done' => array_sum($statuses) - $remaining,
                'remaining' => $remaining,
                'due' => $due,
                'next_retry_at' => $nextRetryAt !== null ? $this->asUtcIso($nextRetryAt) : null,
                'delay_s' => $this->delaySeconds,
            ],
        ];
    }

    private function discoveryComplete(): bool
    {
        $query = $this->db->prepare('SELECT COUNT(*) FROM crawl_discovery WHERE source_id = ?');
        $query->execute([$this->sourceId]);
        return (int) $query->fetchColumn() > 0;
    }

    private function nestedRootDuplicates(): int
    {
        $query = $this->db->prepare(
            "SELECT i.url AS fetched_url, r.url AS stored_url, raw.payload_gz FROM ingest_url i " .
            'JOIN recipe r ON r.id = i.recipe_id ' .
            'LEFT JOIN recipe_raw raw ON raw.id = (SELECT MAX(id) FROM recipe_raw WHERE recipe_id = r.id) ' .
            'AND r.url LIKE ? ' .
            "WHERE i.source_id = ? AND i.status = 'fetched' AND i.url LIKE ?"
        );
        $query->execute(['https://www.koket.se/%/%', $this->sourceId, 'https://www.koket.se/%/%']);
        $parser = new KoketRecipeParser();
        $count = 0;
        while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
            $fetchedUrl = $row['fetched_url'];
            $storedUrl = $row['stored_url'];
            if (!is_string($fetchedUrl) || !is_string($storedUrl) || $this->isRoot($fetchedUrl)) {
                continue;
            }
            $canonicalUrl = $storedUrl;
            if (!$this->isRoot($storedUrl) && is_string($row['payload_gz'])) {
                $html = gzdecode($row['payload_gz']);
                if ($html !== false) {
                    $canonicalUrl = $parser->canonicalUrl($html, $fetchedUrl);
                }
            }
            if ($this->isRoot($canonicalUrl) && $canonicalUrl !== $fetchedUrl) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return array{recipes: int, total_time_share: float|null, servings_share: float|null,
     *   image_share: float|null, ingredients: array{min: int|null, median: float|null, max: int|null},
     *   steps: array{min: int|null, median: float|null, max: int|null}, low_quality_examples: list<string>}
     */
    private function completeness(): array
    {
        $query = $this->db->prepare(
            'SELECT r.url, r.total_time_min, r.servings, r.image_url, ' .
            'COALESCE(ingredients.count, 0) AS ingredient_count, COALESCE(steps.count, 0) AS step_count ' .
            'FROM recipe r LEFT JOIN (SELECT recipe_id, COUNT(*) AS count FROM recipe_ingredient GROUP BY recipe_id) ' .
            'ingredients ON ingredients.recipe_id = r.id ' .
            'LEFT JOIN (SELECT recipe_id, COUNT(*) AS count FROM recipe_step GROUP BY recipe_id) ' .
            'steps ON steps.recipe_id = r.id WHERE r.source_id = ? AND r.active = TRUE'
        );
        $query->execute([$this->sourceId]);
        $total = 0;
        $time = 0;
        $servings = 0;
        $image = 0;
        $ingredientCounts = [];
        $stepCounts = [];
        $examples = [];
        while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
            $total++;
            $time += $row['total_time_min'] !== null ? 1 : 0;
            $servings += $row['servings'] !== null ? 1 : 0;
            $image += is_string($row['image_url']) && $row['image_url'] !== '' ? 1 : 0;
            if (!is_numeric($row['ingredient_count']) || !is_numeric($row['step_count'])) {
                throw new RuntimeException('Invalid recipe count in report.');
            }
            $ingredients = (int) $row['ingredient_count'];
            $steps = (int) $row['step_count'];
            $ingredientCounts[] = $ingredients;
            $stepCounts[] = $steps;
            if (($ingredients < 3 || $steps === 0) && count($examples) < 10 && is_string($row['url'])) {
                $examples[] = $row['url'];
            }
        }
        return [
            'recipes' => $total,
            'total_time_share' => $total > 0 ? round($time / $total, 4) : null,
            'servings_share' => $total > 0 ? round($servings / $total, 4) : null,
            'image_share' => $total > 0 ? round($image / $total, 4) : null,
            'ingredients' => $this->range($ingredientCounts),
            'steps' => $this->range($stepCounts),
            'low_quality_examples' => $examples,
        ];
    }

    /**
     * @param list<int> $values
     * @return array{min: int|null, median: float|null, max: int|null}
     */
    private function range(array $values): array
    {
        if ($values === []) {
            return ['min' => null, 'median' => null, 'max' => null];
        }
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);
        return [
            'min' => $values[0],
            'median' => $count % 2 === 1 ? (float) $values[$middle]
                : ($values[$middle - 1] + $values[$middle]) / 2,
            'max' => $values[$count - 1],
        ];
    }

    private function isRoot(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path) && substr_count(trim($path, '/'), '/') === 0 && trim($path, '/') !== '';
    }

    /** @param array<string, list<string>> $examples */
    private function addExample(array &$examples, string $type, string $url): void
    {
        if (count($examples[$type] ?? []) < 10) {
            $examples[$type][] = $url;
        }
    }

    /**
     * @param array<array-key, int> $counts
     * @return array<array-key, int>
     */
    private function rankCounts(array $counts, int $limit): array
    {
        uksort($counts, static fn (int|string $a, int|string $b): int =>
            $counts[$b] <=> $counts[$a] ?: strcmp((string) $a, (string) $b));
        return array_slice($counts, 0, $limit, true);
    }

    private function asUtcIso(string $databaseTime): string
    {
        return str_replace(' ', 'T', $databaseTime) . 'Z';
    }
}
