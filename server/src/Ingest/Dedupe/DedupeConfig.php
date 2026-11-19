<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Dedupe;

use PDO;
use RuntimeException;

final class DedupeConfig
{
    /** @var array<string, string> */
    private array $values = [];

    public function __construct(PDO $db)
    {
        $query = $db->prepare("SELECT `key`, value FROM engine_config WHERE `key` LIKE 'dedupe.%'");
        $query->execute();
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !is_string($row['key'] ?? null) || !is_string($row['value'] ?? null)) {
                throw new RuntimeException('Invalid deduplication configuration.');
            }
            $this->values[$row['key']] = $row['value'];
        }
    }

    public function decimal(string $key): float
    {
        $value = $this->values[$key] ?? null;
        if (!is_string($value) || !is_numeric($value)) {
            throw new RuntimeException('Missing decimal deduplication configuration: ' . $key);
        }
        return (float) $value;
    }

    public function integer(string $key): int
    {
        $value = $this->values[$key] ?? null;
        if (!is_string($value) || !ctype_digit($value)) {
            throw new RuntimeException('Missing integer deduplication configuration: ' . $key);
        }
        return (int) $value;
    }

    /** @return list<string> */
    public function sourcePriority(): array
    {
        $value = $this->values['dedupe.source_priority'] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('Missing source priority configuration.');
        }
        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException('Invalid source priority configuration.');
        }
        foreach ($decoded as $source) {
            if (!is_string($source)) {
                throw new RuntimeException('Invalid source priority configuration.');
            }
        }
        return $decoded;
    }
}
