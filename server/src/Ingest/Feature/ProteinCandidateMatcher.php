<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Feature;

use PDO;
use RuntimeException;

final class ProteinCandidateMatcher
{
    private readonly string $pattern;

    public function __construct(PDO $db, string $seedDirectory)
    {
        $query = $db->prepare(
            "SELECT a.alias FROM ingredient_alias a JOIN ingredient i ON i.id = a.ingredient_id " .
            "WHERE i.category IN ('protein', 'legume')"
        );
        $query->execute();
        $terms = [];
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $alias) {
            if (is_string($alias)) {
                $terms[] = mb_strtolower($alias);
            }
        }
        foreach (CsvTable::read($seedDirectory . '/ingredient_protein_terms.csv', ['token']) as $row) {
            if ($row['token'] === '') {
                throw new RuntimeException('Invalid protein term seed row.');
            }
            $terms[] = mb_strtolower($row['token']);
        }
        if ($terms === []) {
            throw new RuntimeException('Missing protein candidate vocabulary.');
        }
        $this->pattern = '/(?<!\p{L})(?:' . implode('|', array_map(
            static fn (string $term): string => preg_quote($term, '/'),
            array_unique($terms),
        )) . ')/u';
    }

    public function matches(string $text): bool
    {
        return preg_match($this->pattern, mb_strtolower($text)) === 1;
    }
}
