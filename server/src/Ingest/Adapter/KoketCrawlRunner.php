<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Adapter;

use Closure;
use PDO;
use RuntimeException;

final class KoketCrawlRunner
{
    private readonly Closure $clock;
    private readonly Closure $sleep;
    private readonly Closure $shouldStop;

    /** @param Closure():float|null $clock @param Closure(int):void|null $sleep @param Closure():bool|null $shouldStop */
    public function __construct(
        private readonly PDO $db,
        private readonly int $sourceId,
        private readonly KoketAdapter $adapter,
        private readonly KoketPilotReport $report,
        private readonly string $sitemapUrl,
        private readonly string $reportPath,
        private readonly int $discoveryMaxAgeDays,
        private readonly int $batchSize,
        private readonly int $maxRetryWaitSeconds,
        private readonly int $delaySeconds,
        ?Closure $clock = null,
        ?Closure $sleep = null,
        ?Closure $shouldStop = null,
    ) {
        if ($discoveryMaxAgeDays < 1 || $batchSize < 1 || $maxRetryWaitSeconds < 0 || $delaySeconds < 1) {
            throw new RuntimeException('Invalid crawl runner configuration.');
        }
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->shouldStop = $shouldStop ?? static fn (): bool => false;
    }

    /** @return array{discovered: bool, batches: int, report_path: string} */
    public function run(float $hours): array
    {
        if (!is_finite($hours) || $hours <= 0) {
            throw new RuntimeException('--hours requires a positive number.');
        }
        $deadline = ($this->clock)() + $hours * 3600;
        $discovered = false;
        $batches = 0;
        try {
            if ($this->discoveryDue() && !($this->shouldStop)()) {
                $result = $this->adapter->discover($this->sitemapUrl, []);
                $mark = $this->db->prepare(
                    'INSERT INTO crawl_discovery (source_id, sitemap_hash, completed_at) ' .
                    'VALUES (?, UNHEX(?), UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE ' .
                    'sitemap_hash = VALUES(sitemap_hash), completed_at = VALUES(completed_at)'
                );
                $mark->execute([$this->sourceId, $result['sitemap_hash']]);
                $discovered = true;
            }
            $state = $this->report->build(false);
            while ($state['queue']['remaining'] > 0 && ($this->clock)() < $deadline && !($this->shouldStop)()) {
                if ($state['queue']['due'] > 0) {
                    $secondsLeft = $deadline - ($this->clock)();
                    $timeBoundedLimit = max(1, (int) floor($secondsLeft / $this->delaySeconds));
                    $limit = min($this->batchSize, $state['queue']['due'], $timeBoundedLimit);
                    $this->adapter->fetchDue($limit, $this->shouldStop);
                    $batches++;
                    $state = $this->report->build(false);
                    fprintf(
                        STDOUT,
                        "Done %d/%d; remaining %d.\n",
                        $state['queue']['done'],
                        $state['queue']['total'],
                        $state['queue']['remaining'],
                    );
                    continue;
                }
                $retryAt = $state['queue']['next_retry_at'];
                if ($retryAt === null) {
                    throw new RuntimeException('Queue has remaining work without a due URL or scheduled retry.');
                }
                $wait = max(0, strtotime($retryAt) - time());
                if ($wait > $this->maxRetryWaitSeconds) {
                    break;
                }
                $remaining = max(0, (int) ceil($deadline - ($this->clock)()));
                if ($remaining === 0) {
                    break;
                }
                ($this->sleep)(min(max(1, $wait), $remaining));
                $state = $this->report->build(false);
            }
        } finally {
            $this->writeReport();
        }
        return ['discovered' => $discovered, 'batches' => $batches, 'report_path' => $this->reportPath];
    }

    private function discoveryDue(): bool
    {
        $queue = $this->db->prepare('SELECT COUNT(*) FROM ingest_url WHERE source_id = ?');
        $queue->execute([$this->sourceId]);
        if ((int) $queue->fetchColumn() === 0) {
            return true;
        }
        $query = $this->db->prepare(
            'SELECT completed_at IS NULL OR completed_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? DAY) ' .
            'FROM crawl_discovery WHERE source_id = ?'
        );
        $query->execute([$this->discoveryMaxAgeDays, $this->sourceId]);
        $value = $query->fetchColumn();
        return $value === false || (int) $value === 1;
    }

    private function writeReport(): void
    {
        $directory = dirname($this->reportPath);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create crawler report directory.');
        }
        $payload = json_encode($this->report->build(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $temporary = tempnam($directory, 'crawl-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create crawler report file.');
        }
        try {
            if (file_put_contents($temporary, $payload . PHP_EOL) === false
                || !chmod($temporary, 0640)
                || !rename($temporary, $this->reportPath)) {
                throw new RuntimeException('Cannot write crawler report.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        fwrite(STDOUT, "Crawl report saved to {$this->reportPath}\n");
    }
}
