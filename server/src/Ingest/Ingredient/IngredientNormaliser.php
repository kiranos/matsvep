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
        $updateSuggestion = $this->db->prepare(
            "UPDATE ingredient_unknown SET suggested_ingredient_id = ? WHERE id = ? AND status = 'pending'"
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
                if ($this->parser->isIgnored($parsed->ingredientText)) {
                    $update->execute([$raw, null, null, null, null, null, null, 'ignored', $recipe, $position]);
                    $lines++;
                    continue;
                }
                $parts = $parsed->unsafeSplit ? [$parsed->ingredientText] : $parsed->parts;
                $forceUnsafe = $parsed->unsafeSplit;
                $partCount = 0;
                $ignoredCount = 0;
                $matchedCount = 0;
                $singleId = null;
                $notes = $parsed->note === null ? [] : [$parsed->note];
                if ($parsed->alternative) {
                    $parts = [];
                    foreach ($parsed->parts as $alternative) {
                        $choice = $this->resolve($alternative);
                        if ($choice['id'] !== null) {
                            $parts = [$choice['text']];
                            array_push($notes, ...$choice['notes']);
                            break;
                        }
                    }
                    $forceUnsafe = $parts === [];
                }
                $unparsedUnit = null;
                $unit = $parsed->unit;
                $cleanParts = [];
                foreach ($parts as $part) {
                    $resolved = $this->resolve($part);
                    $text = $resolved['text'];
                    $id = $resolved['id'];
                    array_push($notes, ...$resolved['notes']);
                    if ($id === null && $parsed->quantity !== null && !$parsed->explicitUnit
                        && count($parts) === 1
                        && preg_match('/^(\S+)\s+(.+)$/u', $text, $word) === 1) {
                        $candidate = $this->resolve($word[2]);
                        if (!$this->parser->isDescriptor($word[1]) && $candidate['id'] !== null) {
                            $unparsedUnit = mb_substr($word[1], 0, 80);
                            $text = $candidate['text'];
                            $id = $candidate['id'];
                            array_push($notes, ...$candidate['notes']);
                            $unit = null;
                        }
                    }
                    $unknownId = null;
                    if ($id === null && $text !== '') {
                        $token = mb_substr(IngredientMatcher::token($text), 0, 255);
                        $findUnknown->execute([$token]);
                        $unknown = $findUnknown->fetch(PDO::FETCH_ASSOC);
                        if (is_array($unknown) && $unknown['status'] === 'ignored') {
                            $ignoredCount++;
                            continue;
                        }
                        if (is_array($unknown) && in_array($unknown['status'], ['mapped', 'staple'], true)
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
                                $updateSuggestion->execute([$this->matcher->suggest($text), $unknownId]);
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
                $status = $ignoredCount > 0 && $partCount === 0 ? 'ignored'
                    : ($forceUnsafe ? 'unsafe_split'
                    : ($matchedCount === $partCount && $partCount > 0 ? 'matched'
                        : ($matchedCount > 0 ? 'partial' : 'unknown')));
                $update->execute([
                    mb_substr($cleanParts === [] ? $parsed->ingredientText : implode(' + ', $cleanParts), 0, 255),
                    $notes === [] ? null : implode('; ', array_unique($notes)),
                    $parsed->quantity,
                    $parsed->quantityMax,
                    $unit,
                    $unparsedUnit,
                    $forceUnsafe ? null : $singleId,
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

    /** @return array{text: string, id: ?int, notes: list<string>} */
    private function resolve(string $text): array
    {
        $id = $this->matcher->match($text);
        if ($id !== null) {
            return ['text' => $text, 'id' => $id, 'notes' => []];
        }
        foreach ($this->parser->stripCandidates($text) as $candidate) {
            $id = $this->matcher->match($candidate['text']);
            if ($id !== null) {
                return ['text' => $candidate['text'], 'id' => $id, 'notes' => $candidate['notes']];
            }
        }
        return ['text' => $text, 'id' => null, 'notes' => []];
    }
}
