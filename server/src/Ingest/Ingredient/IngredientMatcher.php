<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Ingredient;

use PDO;
use RuntimeException;

final class IngredientMatcher
{
    /** @var array<string, int|null> */
    private array $matches = [];

    /** @var list<array{id: int, alias: string}>|null */
    private ?array $candidates = null;

    private readonly float $minimumSimilarity;

    private readonly int $minimumAffixLength;

    public function __construct(private readonly PDO $db)
    {
        $query = $db->prepare('SELECT `key`, value FROM engine_config WHERE `key` IN (?, ?)');
        $query->execute(['normalise.suggestion_min_similarity', 'normalise.suggestion_min_affix_length']);
        $config = $query->fetchAll(PDO::FETCH_KEY_PAIR);
        $similarity = $config['normalise.suggestion_min_similarity'] ?? null;
        $affixLength = $config['normalise.suggestion_min_affix_length'] ?? null;
        if (!is_numeric($similarity) || (float) $similarity < 0 || (float) $similarity > 1
            || !is_numeric($affixLength) || (int) $affixLength < 1) {
            throw new RuntimeException('Invalid ingredient suggestion configuration.');
        }
        $this->minimumSimilarity = (float) $similarity;
        $this->minimumAffixLength = (int) $affixLength;
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
        $prefixId = null;
        $prefixLength = 0;
        $tokenLength = mb_strlen($token);
        foreach ($this->candidates as $candidate) {
            $alias = $candidate['alias'];
            $aliasLength = mb_strlen($alias);
            if ($aliasLength >= $this->minimumAffixLength && $aliasLength < $tokenLength
                && str_ends_with($token, $alias) && $aliasLength > $suffixLength) {
                $suffixId = $candidate['id'];
                $suffixLength = $aliasLength;
            }
            if ($tokenLength >= $this->minimumAffixLength && $aliasLength > $tokenLength
                && str_starts_with($alias, $token) && $aliasLength > $prefixLength) {
                $prefixId = $candidate['id'];
                $prefixLength = $aliasLength;
            }
        }
        if ($suffixId !== null || $prefixId !== null) {
            return $suffixId ?? $prefixId;
        }
        $nearestId = null;
        $nearestSimilarity = 0.0;
        foreach ($this->candidates as $candidate) {
            $alias = $candidate['alias'];
            $aliasLength = mb_strlen($alias);
            if ($aliasLength === 0) {
                continue;
            }
            if (1 - abs($aliasLength - $tokenLength) / max($aliasLength, $tokenLength)
                < $this->minimumSimilarity) {
                continue;
            }
            $similarity = $this->similarity($token, $alias);
            if ($similarity > $nearestSimilarity) {
                $nearestSimilarity = $similarity;
                $nearestId = $candidate['id'];
            }
        }
        return $nearestSimilarity >= $this->minimumSimilarity ? $nearestId : null;
    }

    private function similarity(string $left, string $right): float
    {
        $a = mb_str_split($left);
        $b = mb_str_split($right);
        $previous = range(0, count($b));
        foreach ($a as $index => $character) {
            $current = [$index + 1];
            foreach ($b as $otherIndex => $otherCharacter) {
                $current[] = min(
                    $current[$otherIndex] + 1,
                    $previous[$otherIndex + 1] + 1,
                    $previous[$otherIndex] + ($character === $otherCharacter ? 0 : 1),
                );
            }
            $previous = $current;
        }
        $length = max(count($a), count($b));
        return $length === 0 ? 1.0 : 1 - $previous[count($b)] / $length;
    }

    public static function token(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)), 'UTF-8');
    }
}
