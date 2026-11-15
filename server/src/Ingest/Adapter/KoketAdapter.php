<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Adapter;

use Matsvep\Ingest\Http\PoliteFetcher;
use Matsvep\Ingest\Http\RobotsDenied;
use PDO;
use RuntimeException;

final class KoketAdapter
{
    private readonly IngestUrlRepository $queue;
    private readonly KoketRecipeStore $store;
    private readonly KoketDiscovery $discovery;
    private readonly KoketRecipeParser $parser;

    public function __construct(
        private readonly PDO $db,
        private readonly int $sourceId,
        private readonly PoliteFetcher $fetcher,
        private readonly int $delaySeconds,
    ) {
        $this->queue = new IngestUrlRepository($db, $sourceId);
        $this->store = new KoketRecipeStore($db, $this->queue, $sourceId);
        $this->discovery = new KoketDiscovery();
        $this->parser = new KoketRecipeParser();
    }

    /**
     * @param list<string> $categoryUrls
     * @return array{sitemap: int, category: int}
     */
    public function discover(string $sitemapUrl, array $categoryUrls): array
    {
        $counts = ['sitemap' => 0, 'category' => 0];
        $pendingSitemaps = [$sitemapUrl];
        $visited = [];
        while ($pendingSitemaps !== []) {
            $url = array_shift($pendingSitemaps);
            if (isset($visited[$url])) {
                continue;
            }
            $visited[$url] = true;
            $response = $this->fetcher->get($url);
            if ($response->status !== 200) {
                throw new RuntimeException("Sitemap fetch failed with HTTP {$response->status}.");
            }
            $found = $this->discovery->sitemap($response->body, $url);
            foreach ($found['recipes'] as $recipeUrl => $modifiedAt) {
                if ($this->queue->enqueue($recipeUrl, 'sitemap', $modifiedAt)) {
                    $counts['sitemap']++;
                }
            }
            array_push($pendingSitemaps, ...$found['sitemaps']);
        }

        $pendingPages = $categoryUrls;
        $visited = [];
        while ($pendingPages !== []) {
            $url = array_shift($pendingPages);
            if (isset($visited[$url])) {
                continue;
            }
            $visited[$url] = true;
            $response = $this->fetcher->get($url);
            if ($response->status !== 200) {
                throw new RuntimeException("Category fetch failed with HTTP {$response->status}.");
            }
            $found = $this->discovery->category($response->body, $url);
            foreach ($found['recipes'] as $recipeUrl) {
                if ($this->queue->enqueue($recipeUrl, 'category')) {
                    $counts['category']++;
                }
            }
            array_push($pendingPages, ...$found['pages']);
        }
        return $counts;
    }

    /** @return array{fetched: int, unchanged: int, gone: int, failed: int, skipped_robots: int} */
    public function fetchDue(int $limit): array
    {
        $counts = ['fetched' => 0, 'unchanged' => 0, 'gone' => 0, 'failed' => 0, 'skipped_robots' => 0];
        foreach ($this->queue->due($limit) as $item) {
            $headers = [];
            if ($item['etag'] !== null) {
                $headers['If-None-Match'] = $item['etag'];
            }
            if ($item['last_modified'] !== null) {
                $headers['If-Modified-Since'] = $item['last_modified'];
            }
            try {
                $response = $this->fetcher->get($item['url'], $headers);
            } catch (RobotsDenied) {
                $this->queue->markSkippedRobots($item['id']);
                $counts['skipped_robots']++;
                continue;
            } catch (RuntimeException) {
                $this->queue->markFailed($item['id'], null, $this->retryDelay($item['attempts'], null));
                $counts['failed']++;
                continue;
            }

            if ($response->status === 404 || $response->status === 410) {
                $this->queue->markGone($item['id'], $response->status);
                if ($item['recipe_id'] !== null) {
                    $update = $this->db->prepare("UPDATE recipe SET source_status = 'source_gone' WHERE id = ?");
                    $update->execute([$item['recipe_id']]);
                }
                $counts['gone']++;
            } elseif ($response->status === 304 && $item['recipe_id'] !== null) {
                $this->queue->markNotModified($item['id'], $response->status);
                $counts['unchanged']++;
            } elseif ($response->status === 200) {
                try {
                    $recipe = $this->parser->parse($response->body);
                    $this->store->save($item['id'], $item['url'], $item['recipe_id'], $recipe, $response);
                    $counts['fetched']++;
                } catch (RuntimeException) {
                    $this->queue->markFailed(
                        $item['id'],
                        $response->status,
                        $this->retryDelay($item['attempts'], null)
                    );
                    $counts['failed']++;
                }
            } else {
                $this->queue->markFailed(
                    $item['id'],
                    $response->status,
                    $this->retryDelay($item['attempts'], $response->header('retry-after')),
                );
                $counts['failed']++;
            }
        }
        return $counts;
    }

    private function retryDelay(int $attempts, ?string $retryAfter): int
    {
        $delay = $this->delaySeconds * ($attempts + 1);
        if ($retryAfter !== null && ctype_digit($retryAfter)) {
            $delay = max($delay, (int) $retryAfter);
        }
        return $delay;
    }
}
