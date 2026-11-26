<?php

declare(strict_types=1);

namespace Matsvep\Api;

use RuntimeException;

final class DbValue
{
    public static function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }
        throw new RuntimeException('Invalid database integer.');
    }

    public static function decimal(mixed $value): float
    {
        if (is_int($value) || is_float($value) || is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        throw new RuntimeException('Invalid database decimal.');
    }

    public static function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('Invalid database text.');
        }
        return $value;
    }

    /** @return array<string,mixed> */
    public static function map(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Invalid object value.');
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new RuntimeException('Invalid object key.');
            }
        }
        /** @var array<string,mixed> $value */
        return $value;
    }

    /** @return list<array<string,mixed>> */
    public static function rows(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Invalid database rows.');
        }
        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Invalid database row.');
            }
            foreach (array_keys($row) as $key) {
                if (!is_string($key)) {
                    throw new RuntimeException('Invalid database row key.');
                }
            }
            /** @var array<string,mixed> $row */
            $rows[] = $row;
        }
        return $rows;
    }
}
