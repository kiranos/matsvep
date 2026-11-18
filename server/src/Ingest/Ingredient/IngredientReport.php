<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Ingredient;

use Matsvep\Ingest\Feature\ProteinCandidateMatcher;
use PDO;
use RuntimeException;

final class IngredientReport
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $summary = $this->db->query(
            'SELECT COUNT(*) AS total, ' .
            "COALESCE(SUM(normalise_status = 'matched'), 0) AS matched, " .
            'COALESCE(SUM(quantity IS NOT NULL), 0) AS quantity_lines, ' .
            'COALESCE(SUM(unit IS NOT NULL), 0) AS unit_lines, ' .
            'COALESCE(SUM(quantity IS NOT NULL AND unit IS NOT NULL), 0) AS quantity_and_unit_lines, ' .
            "COALESCE(SUM(normalise_status = 'unsafe_split'), 0) AS unsafe_split, " .
            "COALESCE(SUM(normalise_status = 'ignored'), 0) AS ignored, " .
            'COALESCE(SUM(is_main = 1), 0) AS main_lines, ' .
            "COALESCE(SUM(is_main = 1 AND normalise_status = 'matched'), 0) AS matched_main " .
            'FROM recipe_ingredient'
        );
        if ($summary === false) {
            throw new RuntimeException('Cannot build ingredient report.');
        }
        $counts = $summary->fetch(PDO::FETCH_ASSOC);
        if (!is_array($counts)) {
            throw new RuntimeException('Cannot read ingredient report counts.');
        }
        $total = $this->count($counts, 'total');
        $unknown = $this->db->query(
            'SELECT u.normalised_token AS token, u.occurrences, i.canonical_name AS suggestion ' .
            'FROM ingredient_unknown u LEFT JOIN ingredient i ON i.id = u.suggested_ingredient_id ' .
            'WHERE u.occurrences > 0 ORDER BY u.occurrences DESC, u.normalised_token LIMIT 50'
        );
        $units = $this->db->query(
            'SELECT unparsed_unit AS word, COUNT(*) AS occurrences FROM recipe_ingredient ' .
            'WHERE unparsed_unit IS NOT NULL GROUP BY unparsed_unit ' .
            'ORDER BY occurrences DESC, unparsed_unit LIMIT 20'
        );
        $unsafe = $this->db->query(
            "SELECT r.url, ri.raw_text FROM recipe_ingredient ri JOIN recipe r ON r.id = ri.recipe_id " .
            "WHERE ri.normalise_status = 'unsafe_split' ORDER BY r.id, ri.position LIMIT 20"
        );
        if ($unknown === false || $units === false || $unsafe === false) {
            throw new RuntimeException('Cannot read ingredient report details.');
        }
        [$proteinLines, $matchedProteinLines] = $this->proteinCoverage();
        return [
            'lines' => $total,
            'matched_lines' => $this->count($counts, 'matched'),
            'matched_share_pct' => $this->share($this->count($counts, 'matched'), $total),
            'quantity_lines' => $this->count($counts, 'quantity_lines'),
            'quantity_share_pct' => $this->share($this->count($counts, 'quantity_lines'), $total),
            'unit_lines' => $this->count($counts, 'unit_lines'),
            'unit_share_pct' => $this->share($this->count($counts, 'unit_lines'), $total),
            'quantity_and_unit_lines' => $this->count($counts, 'quantity_and_unit_lines'),
            'quantity_and_unit_share_pct' => $this->share($this->count($counts, 'quantity_and_unit_lines'), $total),
            'unsafe_split_lines' => $this->count($counts, 'unsafe_split'),
            'ignored_lines' => $this->count($counts, 'ignored'),
            'main_lines' => $this->count($counts, 'main_lines'),
            'matched_main_lines' => $this->count($counts, 'matched_main'),
            'matched_main_share_pct' => $this->share(
                $this->count($counts, 'matched_main'),
                $this->count($counts, 'main_lines'),
            ),
            'protein_candidate_lines' => $proteinLines,
            'protein_matched_lines' => $matchedProteinLines,
            'protein_matched_share_pct' => $this->share($matchedProteinLines, $proteinLines),
            'top_unknown_tokens' => $unknown->fetchAll(PDO::FETCH_ASSOC),
            'top_unparsed_unit_words' => $units->fetchAll(PDO::FETCH_ASSOC),
            'unsafe_split_examples' => $unsafe->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /** @return array{int, int} */
    private function proteinCoverage(): array
    {
        $matcher = new ProteinCandidateMatcher($this->db, dirname(__DIR__, 3) . '/seeds');
        $lines = $this->db->prepare(
            'SELECT ingredient_text, normalise_status FROM recipe_ingredient WHERE ingredient_text IS NOT NULL'
        );
        $lines->execute();
        $candidateCount = 0;
        $matchedCount = 0;
        while (($row = $lines->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_string($row['ingredient_text'] ?? null)) {
                throw new RuntimeException('Invalid stored ingredient text.');
            }
            if ($matcher->matches($row['ingredient_text'])) {
                $candidateCount++;
                $matchedCount += $row['normalise_status'] === 'matched' ? 1 : 0;
            }
        }
        return [$candidateCount, $matchedCount];
    }

    private function share(int $count, int $total): float
    {
        return $total === 0 ? 0.0 : round(100 * $count / $total, 1);
    }

    /** @param array<string, mixed> $row */
    private function count(array $row, string $key): int
    {
        if (!is_numeric($row[$key] ?? null)) {
            throw new RuntimeException('Invalid ingredient report count.');
        }
        return (int) $row[$key];
    }
}
