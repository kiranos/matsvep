<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Http;

use RuntimeException;

final class StreamHttpClient implements HttpClient
{
    /** @param array<string, string> $headers */
    public function get(string $url, array $headers): HttpResponse
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            if (!preg_match('/^[A-Za-z0-9-]+$/', $name) || strpbrk($value, "\r\n") !== false) {
                throw new RuntimeException('Invalid HTTP request header.');
            }
            $lines[] = $name . ': ' . $value;
        }
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $lines),
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException('HTTP request failed.');
        }
        try {
            $body = stream_get_contents($stream);
            $metadata = stream_get_meta_data($stream);
        } finally {
            fclose($stream);
        }
        if ($body === false) {
            throw new RuntimeException('Cannot read HTTP response.');
        }
        $rawHeaders = $metadata['wrapper_data'] ?? [];
        if (!is_array($rawHeaders)) {
            throw new RuntimeException('Missing HTTP response headers.');
        }
        $status = 0;
        $responseHeaders = [];
        foreach ($rawHeaders as $line) {
            if (!is_string($line)) {
                continue;
            }
            if (preg_match('/^HTTP\/\S+ ([0-9]{3})/', $line, $matches)) {
                $status = (int) $matches[1];
                $responseHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }
        if ($status === 0) {
            throw new RuntimeException('Missing HTTP response status.');
        }
        return new HttpResponse($status, $responseHeaders, $body);
    }
}
