<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Ingest\Http\HttpResponse;
use Matsvep\Ingest\Http\PoliteFetcher;
use Matsvep\Ingest\Http\RobotsDenied;
use PHPUnit\Framework\TestCase;

final class PoliteFetcherTest extends TestCase
{
    public function testRobotsAndDelayApplyToEveryRequest(): void
    {
        $client = new FakeHttpClient();
        $robots = file_get_contents(__DIR__ . '/fixtures/koket/robots.txt');
        self::assertNotFalse($robots);
        $client->responses['https://www.koket.se/robots.txt'] = new HttpResponse(200, [], $robots);
        $client->responses['https://www.koket.se/recept/oven-curry'] = new HttpResponse(200, [], 'recipe');
        $time = 0.0;
        $waits = [];
        $fetcher = new PoliteFetcher(
            $client,
            'https://www.koket.se',
            'MatsvepCrawler/1.0',
            5,
            static function () use (&$time): float {
                return $time;
            },
            static function (float $seconds) use (&$time, &$waits): void {
                $waits[] = $seconds;
                $time += $seconds;
            },
        );
        self::assertSame(200, $fetcher->get('https://www.koket.se/recept/oven-curry')->status);
        self::assertSame([5.0], $waits);
        self::assertCount(2, $client->requests);
        self::assertSame('MatsvepCrawler/1.0', $client->requests[1]['headers']['User-Agent']);

        $this->expectException(RobotsDenied::class);
        $fetcher->get('https://www.koket.se/blocked/secret');
    }
}
