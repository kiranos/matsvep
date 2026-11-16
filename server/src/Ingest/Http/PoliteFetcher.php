<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Http;

use Closure;
use RuntimeException;

final class PoliteFetcher
{
    private ?RobotsPolicy $robots = null;
    private ?float $lastRequestAt = null;
    private readonly Closure $clock;
    private readonly Closure $sleep;
    private readonly string $host;

    /** @param Closure():float|null $clock @param Closure(float):void|null $sleep */
    public function __construct(
        private readonly HttpClient $client,
        private readonly string $baseUrl,
        private readonly string $userAgent,
        private readonly int $delaySeconds,
        ?Closure $clock = null,
        ?Closure $sleep = null,
    ) {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($host) || parse_url($baseUrl, PHP_URL_SCHEME) !== 'https' || $delaySeconds < 0) {
            throw new RuntimeException('Invalid crawl source configuration.');
        }
        $this->host = strtolower($host);
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ceil($seconds * 1000000));
        };
    }

    /** @param array<string, string> $headers */
    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->assertSourceUrl($url);
        $this->loadRobots();
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $target = (is_string($path) ? $path : '/') . (is_string($query) ? '?' . $query : '');
        if (!$this->robots?->allows($this->userAgent, $target)) {
            throw new RobotsDenied("robots.txt disallows $target");
        }
        return $this->request($url, $headers);
    }

    private function loadRobots(): void
    {
        if ($this->robots !== null) {
            return;
        }
        $response = $this->request(rtrim($this->baseUrl, '/') . '/robots.txt', []);
        if ($response->status === 404) {
            $this->robots = new RobotsPolicy('');
        } elseif ($response->status === 200) {
            $this->robots = new RobotsPolicy($response->body);
        } else {
            throw new RuntimeException('Cannot verify robots.txt.');
        }
    }

    /** @param array<string, string> $headers */
    private function request(string $url, array $headers): HttpResponse
    {
        if ($this->lastRequestAt !== null) {
            $wait = $this->delaySeconds - (($this->clock)() - $this->lastRequestAt);
            if ($wait > 0) {
                ($this->sleep)($wait);
            }
        }
        $this->lastRequestAt = ($this->clock)();
        return $this->client->get($url, ['User-Agent' => $this->userAgent, 'Accept-Encoding' => 'identity'] + $headers);
    }

    private function assertSourceUrl(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !is_string($host) || strtolower($host) !== $this->host) {
            throw new RuntimeException('Crawl URL is outside the source host.');
        }
    }
}
