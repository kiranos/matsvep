<?php

declare(strict_types=1);

namespace Matsvep\Engine;

use PDO;
use RuntimeException;

final class EngineConfig
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values)
    {
    }

    public static function fromDatabase(PDO $db): self
    {
        $query = $db->prepare('SELECT `key`, value FROM engine_config');
        $query->execute();
        $values = [];
        while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_string($row['key'] ?? null) || !is_string($row['value'] ?? null)) {
                throw new RuntimeException('Invalid engine configuration row.');
            }
            $values[$row['key']] = $row['value'];
        }
        return new self($values);
    }

    public function decimal(string $key): float
    {
        $value = $this->values[$key] ?? null;
        if (!is_string($value) || !is_numeric($value)) {
            throw new RuntimeException('Missing decimal engine configuration: ' . $key);
        }
        return (float) $value;
    }

    public function integer(string $key): int
    {
        $value = $this->values[$key] ?? null;
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1) {
            throw new RuntimeException('Missing integer engine configuration: ' . $key);
        }
        return (int) $value;
    }

    /** @return list<string> */
    public function stringList(string $key): array
    {
        $value = $this->values[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('Missing list engine configuration: ' . $key);
        }
        $list = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($list) || !array_is_list($list)) {
            throw new RuntimeException('Invalid list engine configuration: ' . $key);
        }
        foreach ($list as $item) {
            if (!is_string($item)) {
                throw new RuntimeException('Invalid list engine configuration: ' . $key);
            }
        }
        return $list;
    }
}
