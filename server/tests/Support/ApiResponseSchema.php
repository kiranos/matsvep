<?php

declare(strict_types=1);

namespace Matsvep\Tests\Support;

use PHPUnit\Framework\Assert;

final class ApiResponseSchema
{
    /** @param array<string,mixed> $document */
    public function __construct(private readonly array $document)
    {
    }

    public static function fromBundle(string $file): self
    {
        $contents = file_get_contents($file);
        Assert::assertIsString($contents);
        $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($document);
        return new self($document);
    }

    /** @param array<string,mixed> $response */
    public function assertResponse(string $path, string $method, int $status, array $response): void
    {
        $definition = $this->document['paths'][$path][strtolower($method)]['responses'][(string) $status]['content']['application/json']['schema'] ?? null;
        Assert::assertIsArray($definition, "Missing $method $path response $status in OpenAPI");
        $this->assertValue($definition, $response, "$method $path");
    }

    /** @param array<string,mixed> $schema */
    private function assertValue(array $schema, mixed $value, string $path): void
    {
        if ($value === null && ($schema['nullable'] ?? false) === true) {
            return;
        }
        if (isset($schema['$ref'])) {
            $reference = $schema['$ref'];
            Assert::assertIsString($reference);
            Assert::assertStringStartsWith('#/', $reference);
            $target = $this->document;
            foreach (explode('/', substr($reference, 2)) as $key) {
                $key = str_replace(['~1', '~0'], ['/', '~'], $key);
                Assert::assertIsArray($target);
                Assert::assertArrayHasKey($key, $target);
                $target = $target[$key];
            }
            Assert::assertIsArray($target);
            $this->assertValue($target, $value, $path);
            return;
        }
        foreach ($schema['allOf'] ?? [] as $part) {
            Assert::assertIsArray($part);
            $this->assertValue($part, $value, $path);
        }
        if (isset($schema['oneOf'])) {
            $matched = 0;
            foreach ($schema['oneOf'] as $part) {
                try {
                    $this->assertValue($part, $value, $path);
                    $matched++;
                } catch (\PHPUnit\Framework\AssertionFailedError) {
                    // Another branch may describe the response.
                }
            }
            Assert::assertSame(1, $matched, "$path must match one schema branch");
        }
        if (isset($schema['enum'])) {
            Assert::assertContains($value, $schema['enum'], "$path enum");
        }
        $type = $schema['type'] ?? null;
        if ($type === 'object') {
            Assert::assertIsArray($value, $path);
            foreach ($schema['required'] ?? [] as $name) {
                Assert::assertArrayHasKey($name, $value, $path);
            }
            foreach ($value as $name => $item) {
                if (isset($schema['properties'][$name])) {
                    $this->assertValue($schema['properties'][$name], $item, "$path.$name");
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    Assert::fail("Unexpected property $path.$name");
                }
            }
        } elseif ($type === 'array') {
            Assert::assertIsArray($value, $path);
            Assert::assertTrue(array_is_list($value), $path);
            foreach ($value as $index => $item) {
                $this->assertValue($schema['items'], $item, "$path.$index");
            }
        } elseif ($type === 'integer') {
            Assert::assertIsInt($value, $path);
        } elseif ($type === 'number') {
            Assert::assertTrue(is_int($value) || is_float($value), $path);
        } elseif ($type === 'boolean') {
            Assert::assertIsBool($value, $path);
        } elseif ($type === 'string') {
            Assert::assertIsString($value, $path);
        }
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum'])) {
                Assert::assertGreaterThanOrEqual($schema['minimum'], $value, $path);
            }
            if (isset($schema['maximum'])) {
                Assert::assertLessThanOrEqual($schema['maximum'], $value, $path);
            }
        }
        if (is_string($value)) {
            if (isset($schema['minLength'])) {
                Assert::assertGreaterThanOrEqual($schema['minLength'], mb_strlen($value), $path);
            }
            if (($schema['format'] ?? null) === 'uri') {
                Assert::assertNotFalse(filter_var($value, FILTER_VALIDATE_URL), $path);
            } elseif (($schema['format'] ?? null) === 'email') {
                Assert::assertNotFalse(filter_var($value, FILTER_VALIDATE_EMAIL), $path);
            } elseif (($schema['format'] ?? null) === 'date-time') {
                Assert::assertNotFalse(strtotime($value), $path);
            }
        }
    }
}
