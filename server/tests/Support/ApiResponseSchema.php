<?php

declare(strict_types=1);

namespace Matsvep\Tests\Support;

use Matsvep\Api\DbValue;
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
        return new self(DbValue::map(json_decode($contents, true, 512, JSON_THROW_ON_ERROR)));
    }

    /** @param array<string,mixed> $response */
    public function assertResponse(string $path, string $method, int $status, array $response): void
    {
        $schema = $this->lookup($this->document, ['paths', $path, strtolower($method), 'responses', (string) $status, 'content', 'application/json', 'schema']);
        $this->assertValue($schema, $response, "$method $path");
    }

    /** @param array<string,mixed> $root
     *  @param list<string> $keys */
    private function lookup(array $root, array $keys): mixed
    {
        $value = $root;
        foreach ($keys as $key) {
            $map = DbValue::map($value);
            Assert::assertArrayHasKey($key, $map);
            $value = $map[$key];
        }
        return $value;
    }

    private function assertValue(mixed $definition, mixed $value, string $path): void
    {
        $schema = DbValue::map($definition);
        if ($value === null && ($schema['nullable'] ?? false) === true) {
            return;
        }
        if (isset($schema['$ref'])) {
            $reference = DbValue::text($schema['$ref']);
            Assert::assertStringStartsWith('#/', $reference);
            $keys = array_map(static fn (string $key): string => str_replace(['~1', '~0'], ['/', '~'], $key), explode('/', substr($reference, 2)));
            $this->assertValue($this->lookup($this->document, $keys), $value, $path);
            return;
        }
        foreach ($this->values($schema['allOf'] ?? []) as $part) {
            $this->assertValue($part, $value, $path);
        }
        if (isset($schema['oneOf'])) {
            $matched = 0;
            foreach ($this->values($schema['oneOf']) as $part) {
                try {
                    $this->assertValue($part, $value, $path);
                    $matched++;
                } catch (\PHPUnit\Framework\AssertionFailedError) {
                    // Another branch may describe the value.
                }
            }
            Assert::assertSame(1, $matched, "$path must match one schema branch");
        }
        if (isset($schema['enum'])) {
            Assert::assertContains($value, $this->values($schema['enum']), "$path enum");
        }
        $type = $schema['type'] ?? null;
        if ($type === 'object') {
            $object = DbValue::map($value);
            foreach ($this->values($schema['required'] ?? []) as $name) {
                Assert::assertIsString($name);
                Assert::assertArrayHasKey($name, $object, $path);
            }
            $properties = DbValue::map($schema['properties'] ?? []);
            foreach ($object as $name => $item) {
                if (isset($properties[$name])) {
                    $this->assertValue($properties[$name], $item, "$path.$name");
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    Assert::fail("Unexpected property $path.$name");
                }
            }
        } elseif ($type === 'array') {
            foreach ($this->values($value) as $index => $item) {
                $this->assertValue($schema['items'] ?? [], $item, "$path.$index");
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

    /** @return list<mixed> */
    private function values(mixed $value): array
    {
        Assert::assertIsArray($value);
        Assert::assertTrue(array_is_list($value));
        return array_values($value);
    }
}
