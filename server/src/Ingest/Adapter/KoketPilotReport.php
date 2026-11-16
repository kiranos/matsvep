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
     *   not_recipe_prefixes: array<array-key, int>,
     *   failure_http_statuses: array<array-key, int>,
     *   failure_examples: array<string, list<string>>,
     *   pending_retries: list<array{url: string, next_attempt_at: string}>,
     *   queue: array{total: int, done: int, remaining: int, due: int, next_retry_at: string|null, delay_s: int}
     * }
     */
    public function build(): array
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

        $statuses = array_fill_keys(['queued', 'fetched', 'failed', 'skipped_robots', 'gone', 'not_recipe'], 0);
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
