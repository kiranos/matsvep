<?php

declare(strict_types=1);

namespace Matsvep\Simulator;

use PDO;
use RuntimeException;

final class SimulatorSettings
{
    /** @var array<string,array{value:string,type:string,description:string}> */
    private readonly array $values;

    public function __construct(string $file)
    {
        $parsed = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($parsed)) {
            throw new RuntimeException('Invalid simulator settings.');
        }
        $values = [];
        foreach ($parsed as $key => $entry) {
            if (!is_string($key) || preg_match('/^simulator\.[a-z_]+(?:\.[a-z_]+)*$/', $key) !== 1
                || !is_array($entry) || !is_string($entry['value'] ?? null)
                || !is_string($entry['type'] ?? null) || !is_string($entry['description'] ?? null)
                || !in_array($entry['type'], ['integer', 'decimal', 'string'], true)) {
                throw new RuntimeException('Invalid simulator setting.');
            }
            $values[$key] = ['value' => $entry['value'], 'type' => $entry['type'],
                'description' => $entry['description']];
        }
        $this->values = $values;
    }

    public function integer(string $key): int
    {
        $entry = $this->values[$key] ?? null;
        if ($entry === null || $entry['type'] !== 'integer' || preg_match('/^[1-9][0-9]*$/', $entry['value']) !== 1) {
            throw new RuntimeException('Invalid positive simulator integer: ' . $key);
        }
        return (int) $entry['value'];
    }

    public function seed(PDO $target): void
    {
        $write = $target->prepare('INSERT INTO engine_config (`key`, value, type, description, version) '
            . 'VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE value = VALUES(value), '
            . 'type = VALUES(type), description = VALUES(description)');
        foreach ($this->values as $key => $entry) {
            $write->execute([$key, $entry['value'], $entry['type'], $entry['description']]);
        }
    }
}
