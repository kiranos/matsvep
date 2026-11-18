<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Feature;

use PDO;
use RuntimeException;

final class FeatureConfig
{
    /** @var array<string, string> */
    private array $values = [];

    public function __construct(PDO $db)
    {
        $query = $db->prepare("SELECT `key`, value FROM engine_config WHERE `key` LIKE 'feature.%'");
        $query->execute();
        while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_string($row['key'] ?? null) || !is_string($row['value'] ?? null)) {
                throw new RuntimeException('Invalid feature configuration row.');
            }
            $this->values[$row['key']] = $row['value'];
        }
    }

    public function decimal(string $key): float
    {
        $value = $this->values[$key] ?? null;
        if (!is_string($value) || !is_numeric($value)) {
            throw new RuntimeException('Missing decimal feature configuration: ' . $key);
        }
        return (float) $value;
    }

    public function integer(string $key): int
    {
        $value = $this->values[$key] ?? null;
        if (!is_string($value) || !ctype_digit($value)) {
            throw new RuntimeException('Missing integer feature configuration: ' . $key);
        }
        return (int) $value;
    }

    public function string(string $key): string
    {
        $value = $this->values[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Missing string feature configuration: ' . $key);
        }
        return $value;
    }

    /** @return list<string> */
    public function stringList(string $key): array
    {
        $decoded = json_decode($this->string($key), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException('Invalid feature configuration list: ' . $key);
        }
        foreach ($decoded as $value) {
            if (!is_string($value)) {
                throw new RuntimeException('Invalid feature configuration list: ' . $key);
            }
        }
        return $decoded;
    }
}
