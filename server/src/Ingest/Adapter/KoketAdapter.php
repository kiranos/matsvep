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
        private readonly int $retryBaseSeconds,
        private readonly int $retryMaxSeconds,
        private readonly int $maxAttempts,
    ) {
        if ($retryBaseSeconds < 1 || $retryMaxSeconds < $retryBaseSeconds || $maxAttempts < 1) {
            throw new RuntimeException('Invalid crawl retry configuration.');
        }
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

    /** @return array{fetched: int, unchanged: int, gone: int, redirected: int, not_recipe: int, failed: int, skipped_robots: int} */
    public function fetchDue(int $limit): array
    {
        $counts = ['fetched' => 0, 'unchanged' => 0, 'gone' => 0, 'redirected' => 0, 'not_recipe' => 0,
            'failed' => 0, 'skipped_robots' => 0];
        foreach ($this->queue->due($limit, $this->maxAttempts) as $item) {
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
                $this->recordFailure($item['id'], $item['attempts'], null, null);
                $counts['failed']++;
                continue;
            }

            if (in_array($response->status, [301, 302, 307, 308], true)) {
                $target = $this->discovery->canonicalUrl($response->header('location') ?? '', $item['url']);
                if ($target === null || $target === $item['url']) {
                    $this->recordFailure($item['id'], $item['attempts'], $response->status, null);
                    $counts['failed']++;
                } else {
                    $this->queue->enqueue($target, $item['discovered_via']);
                    $this->queue->markGone($item['id'], $response->status);
                    $counts['redirected']++;
                }
            } elseif ($response->status === 404 || $response->status === 410) {
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
                    if (!$this->parser->isRecipe($response->body)) {
                        $this->queue->markNotRecipe($item['id'], $response->status);
                        $counts['not_recipe']++;
                        continue;
                    }
                    $recipe = $this->parser->parse($response->body);
                    $this->store->save($item['id'], $item['url'], $item['recipe_id'], $recipe, $response);
                    $counts['fetched']++;
                } catch (RuntimeException) {
                    $this->recordFailure($item['id'], $item['attempts'], $response->status, null);
                    $counts['failed']++;
                }
            } else {
                $this->recordFailure(
                    $item['id'],
                    $item['attempts'],
                    $response->status,
                    $response->header('retry-after')
                );
                $counts['failed']++;
            }
        }
        return $counts;
    }

    private function recordFailure(int $id, int $attempts, ?int $status, ?string $retryAfter): void
    {
        $nextAttempts = $attempts + 1;
        $delay = $nextAttempts >= $this->maxAttempts ? null : $this->retryDelay($nextAttempts, $retryAfter);
        $this->queue->markFailed($id, $status, $delay);
    }

    private function retryDelay(int $nextAttempts, ?string $retryAfter): int
    {
        $delay = $this->retryBaseSeconds;
        for ($attempt = 1; $attempt < $nextAttempts; $attempt++) {
            $delay = $delay > intdiv($this->retryMaxSeconds, 2)
                ? $this->retryMaxSeconds : $delay * 2;
        }
        if ($retryAfter !== null && ctype_digit($retryAfter)) {
            $delay = max($delay, (int) $retryAfter);
        } elseif ($retryAfter !== null) {
            $timestamp = strtotime($retryAfter);
            if ($timestamp !== false) {
                $delay = max($delay, $timestamp - time());
            }
        }
        return $delay;
    }
}
