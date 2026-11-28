<?php

declare(strict_types=1);

namespace Matsvep\Simulator;

use PDO;
use RuntimeException;

final class CatalogueClone
{
    private const TABLES = [
        'source', 'ingredient', 'dish_group', 'ingredient_unknown', 'recipe',
        'recipe_ingredient', 'recipe_ingredient_part', 'recipe_step', 'recipe_tag',
        'recipe_source_link', 'engine_config',
    ];

    public function __construct(private readonly PDO $source, private readonly PDO $target)
    {
    }

    public function acquireCrawlerLock(): void
    {
        $query = $this->source->prepare('SELECT GET_LOCK(?, 0)');
        $query->execute(['matsvep:crawl:koket']);
        if ((int) $query->fetchColumn() !== 1) {
            throw new RuntimeException('The crawler lock is held; wait for crawling to finish.');
        }
    }

    public function releaseCrawlerLock(): void
    {
        $query = $this->source->prepare('SELECT RELEASE_LOCK(?)');
        $query->execute(['matsvep:crawl:koket']);
    }

    /** @return array<string,int> */
    public function copy(): array
    {
        if ($this->source->inTransaction()) {
            throw new RuntimeException('The source connection must not have an active transaction.');
        }
        $this->source->prepare('SET TRANSACTION READ ONLY')->execute();
        $this->source->beginTransaction();
        $this->target->prepare('SET FOREIGN_KEY_CHECKS = 0')->execute();
        try {
            $this->target->prepare('DELETE FROM source')->execute();
            $counts = [];
            foreach (self::TABLES as $table) {
                $counts[$table] = $this->copyTable($table);
            }
            return $counts;
        } finally {
            $this->target->prepare('SET FOREIGN_KEY_CHECKS = 1')->execute();
            $this->source->rollBack();
        }
    }

    private function copyTable(string $table): int
    {
        $schema = $this->source->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA NOT LIKE ? ORDER BY ORDINAL_POSITION');
        $schema->execute([$table, '%GENERATED%']);
        $columns = $schema->fetchAll(PDO::FETCH_COLUMN);
        if ($columns === []) {
            throw new RuntimeException('Missing source catalogue table: ' . $table);
        }
        foreach ($columns as $column) {
            if (!is_string($column) || preg_match('/^[a-z_][a-z0-9_]*$/', $column) !== 1) {
                throw new RuntimeException('Invalid source catalogue column.');
            }
        }
        $names = implode(', ', array_map(static fn (string $name): string => '`' . $name . '`', $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $read = $this->source->prepare('SELECT ' . $names . ' FROM `' . $table . '`');
        $read->execute();
        $write = $this->target->prepare('INSERT INTO `' . $table . '` (' . $names . ') VALUES (' . $placeholders . ')'
            . ($table === 'engine_config' ? ' ON DUPLICATE KEY UPDATE '
                . '`value` = VALUES(`value`), `type` = VALUES(`type`), '
                . '`description` = VALUES(`description`), `version` = VALUES(`version`)' : ''));
        $count = 0;
        while (($row = $read->fetch(PDO::FETCH_NUM)) !== false) {
            $write->execute($row);
            $count++;
        }
        return $count;
    }
}
