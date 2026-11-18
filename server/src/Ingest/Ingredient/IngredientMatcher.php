<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Ingredient;

use PDO;

final class IngredientMatcher
{
    /** @var array<string, int|null> */
    private array $matches = [];

    /** @var list<array{id: int, alias: string}>|null */
    private ?array $candidates = null;

    public function __construct(private readonly PDO $db)
    {
    }

    public function match(string $text): ?int
    {
        $token = self::token($text);
        if (array_key_exists($token, $this->matches)) {
            return $this->matches[$token];
        }
        $query = $this->db->prepare('SELECT ingredient_id FROM ingredient_alias WHERE alias = ?');
        $query->execute([$text]);
        $value = $query->fetchColumn();
        return $this->matches[$token] = $value === false ? null : (int) $value;
    }

    public function suggest(string $text): ?int
    {
        $token = self::token($text);
        if ($token === '') {
            return null;
        }
        if ($this->candidates === null) {
            $this->candidates = [];
            $query = $this->db->query('SELECT alias, ingredient_id FROM ingredient_alias');
            if ($query === false) {
                return null;
            }
            foreach ($query as $row) {
                if (is_array($row) && is_string($row['alias'] ?? null)
                    && is_numeric($row['ingredient_id'] ?? null)) {
                    $this->candidates[] = ['alias' => self::token($row['alias']), 'id' => (int) $row['ingredient_id']];
                }
            }
        }
        $suffixId = null;
        $suffixLength = 0;
        $nearestId = null;
        $nearestDistance = PHP_INT_MAX;
        foreach ($this->candidates as $candidate) {
            $alias = $candidate['alias'];
            if ($alias !== '' && strlen($alias) < strlen($token) && str_ends_with($token, $alias)
                && mb_strlen($alias) > $suffixLength) {
                $suffixId = $candidate['id'];
                $suffixLength = mb_strlen($alias);
            }
            if (abs(strlen($alias) - strlen($token)) > $nearestDistance) {
                continue;
            }
            $distance = levenshtein($token, $alias);
            if ($distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearestId = $candidate['id'];
            }
        }
        return $suffixId ?? $nearestId;
    }

    public static function token(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)), 'UTF-8');
    }
}
