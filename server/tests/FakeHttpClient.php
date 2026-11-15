<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Ingest\Http\HttpClient;
use Matsvep\Ingest\Http\HttpResponse;
use RuntimeException;

final class FakeHttpClient implements HttpClient
{
    /** @var array<string, HttpResponse> */
    public array $responses = [];

    /** @var list<array{url: string, headers: array<string, string>}> */
    public array $requests = [];

    /** @param array<string, string> $headers */
    public function get(string $url, array $headers): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers];
        return $this->responses[$url] ?? throw new RuntimeException("No fixture response for $url");
    }
}
