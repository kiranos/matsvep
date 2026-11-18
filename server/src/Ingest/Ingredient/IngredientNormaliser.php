<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Ingredient;

use PDO;
use RuntimeException;
use Throwable;

final class IngredientNormaliser
{
    private readonly IngredientMatcher $matcher;

    public function __construct(private readonly PDO $db, private readonly IngredientLineParser $parser)
    {
        $this->matcher = new IngredientMatcher($db);
    }

    /** @return array{lines: int, matched: int, unsafe_split: int} */
    public function run(?int $recipeId = null): array
    {
        $seedCount = $this->db->query('SELECT COUNT(*) FROM ingredient');
        if ($seedCount === false || (int) $seedCount->fetchColumn() === 0) {
            throw new RuntimeException('Seed ingredients before normalising recipes.');
        }
        $sql = 'SELECT recipe_id, position, raw_text FROM recipe_ingredient';
        $parameters = [];
        if ($recipeId !== null) {
            $sql .= ' WHERE recipe_id = ?';
            $parameters[] = $recipeId;
        }
        $sql .= ' ORDER BY recipe_id, position';
        $query = $this->db->prepare($sql);
        $query->execute($parameters);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $delete = $this->db->prepare(
            $recipeId === null ? 'DELETE FROM recipe_ingredient_part' : 'DELETE FROM recipe_ingredient_part WHERE recipe_id = ?'
        );
        $insertPart = $this->db->prepare(
            'INSERT INTO recipe_ingredient_part ' .
            '(recipe_id, position, part_position, ingredient_text, ingredient_id, ingredient_unknown_id) ' .
            'VALUES (?, ?, ?, ?, ?, ?)'
        );
        $update = $this->db->prepare(
            'UPDATE recipe_ingredient SET ingredient_text = ?, note = ?, quantity = ?, quantity_max = ?, ' .
            'unit = ?, unparsed_unit = ?, ingredient_id = ?, normalise_status = ? ' .
            'WHERE recipe_id = ? AND position = ?'
        );
        $findUnknown = $this->db->prepare(
            'SELECT id, status, mapped_ingredient_id FROM ingredient_unknown WHERE normalised_token = ?'
        );
        $insertUnknown = $this->db->prepare(
            'INSERT INTO ingredient_unknown ' .
            '(raw_text, normalised_token, occurrences, first_seen_at, suggested_ingredient_id) ' .
            'VALUES (?, ?, 1, UTC_TIMESTAMP(6), ?)'
        );
        $lines = 0;
        $matched = 0;
        $unsafe = 0;
        $this->db->beginTransaction();
        try {
            $delete->execute($recipeId === null ? [] : [$recipeId]);
            foreach ($rows as $row) {
                if (!is_array($row) || !is_numeric($row['recipe_id'] ?? null)
                    || !is_numeric($row['position'] ?? null) || !is_string($row['raw_text'] ?? null)) {
                    throw new RuntimeException('Invalid stored ingredient line.');
                }
                $recipe = (int) $row['recipe_id'];
                $position = (int) $row['position'];
                $raw = $row['raw_text'];
                $parsed = $this->parser->parse($raw);
                $parts = $parsed->unsafeSplit ? [$parsed->ingredientText] : $parsed->parts;
                $partCount = 0;
                $matchedCount = 0;
                $singleId = null;
                $notes = $parsed->note === null ? [] : [$parsed->note];
                $unparsedUnit = null;
                $unit = $parsed->unit;
                $cleanParts = [];
                foreach ($parts as $part) {
                    $text = $part;
                    $id = $this->matcher->match($text);
                    if ($id === null) {
                        [$cleaned, $descriptors] = $this->parser->stripDescriptors($text);
                        if ($cleaned !== '' && $cleaned !== $text) {
                            $candidate = $this->matcher->match($cleaned);
                            if ($candidate !== null) {
                                $text = $cleaned;
                                $id = $candidate;
                                array_push($notes, ...$descriptors);
                            }
                        }
                    }
                    if ($id === null && $parsed->quantity !== null && !$parsed->explicitUnit
                        && count($parts) === 1
                        && preg_match('/^(\S+)\s+(.+)$/u', $text, $word) === 1) {
                        $candidate = $this->matcher->match($word[2]);
                        if ($candidate !== null) {
                            $unparsedUnit = mb_substr($word[1], 0, 80);
                            $text = $word[2];
                            $id = $candidate;
                            $unit = null;
                        }
                    }
                    $unknownId = null;
                    if ($id === null && $text !== '') {
                        $token = mb_substr(IngredientMatcher::token($text), 0, 255);
                        $findUnknown->execute([$token]);
                        $unknown = $findUnknown->fetch(PDO::FETCH_ASSOC);
                        if (is_array($unknown) && $unknown['status'] === 'mapped'
                            && is_numeric($unknown['mapped_ingredient_id'])) {
                            $id = (int) $unknown['mapped_ingredient_id'];
                        } else {
                            if (!is_array($unknown)) {
                                $insertUnknown->execute([$raw, $token, $this->matcher->suggest($text)]);
                                $unknownId = (int) $this->db->lastInsertId();
                            } else {
                                if (!is_numeric($unknown['id'] ?? null)) {
                                    throw new RuntimeException('Invalid unknown ingredient reference.');
                                }
                                $unknownId = (int) $unknown['id'];
                            }
                        }
                    }
                    $partCount++;
                    $cleanParts[] = $text;
                    if ($id !== null) {
                        $matchedCount++;
                    }
                    if (count($parts) === 1) {
                        $singleId = $id;
                    }
                    $insertPart->execute([$recipe, $position, $partCount, mb_substr($text, 0, 255), $id, $unknownId]);
                }
                $status = $parsed->unsafeSplit ? 'unsafe_split'
                    : ($matchedCount === $partCount && $partCount > 0 ? 'matched'
                        : ($matchedCount > 0 ? 'partial' : 'unknown'));
                $update->execute([
                    mb_substr(implode(' + ', $cleanParts), 0, 255),
                    $notes === [] ? null : implode('; ', array_unique($notes)),
                    $parsed->quantity,
                    $parsed->quantityMax,
                    $unit,
                    $unparsedUnit,
                    $parsed->unsafeSplit ? null : $singleId,
                    $status,
                    $recipe,
                    $position,
                ]);
                $lines++;
                $matched += $status === 'matched' ? 1 : 0;
                $unsafe += $status === 'unsafe_split' ? 1 : 0;
            }
            $this->db->exec(
                'UPDATE ingredient_unknown u LEFT JOIN ' .
                '(SELECT ingredient_unknown_id, COUNT(*) AS n FROM recipe_ingredient_part ' .
                'WHERE ingredient_unknown_id IS NOT NULL GROUP BY ingredient_unknown_id) p ' .
                'ON p.ingredient_unknown_id = u.id SET u.occurrences = COALESCE(p.n, 0)'
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        return ['lines' => $lines, 'matched' => $matched, 'unsafe_split' => $unsafe];
    }
}
