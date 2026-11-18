<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Feature;

use PDO;
use RuntimeException;

final class FeatureReport
{
    private readonly FeatureConfig $config;

    public function __construct(private readonly PDO $db)
    {
        $this->config = new FeatureConfig($db);
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $query = $this->db->prepare(
            'SELECT id, url, title, main_protein, carb_base, cuisine, meal_type, is_vegetarian, ' .
            'leftover_score, festive_score, effort FROM recipe WHERE active = TRUE ORDER BY id'
        );
        $query->execute();
        $recipes = $query->fetchAll(PDO::FETCH_ASSOC);
        $buckets = $this->config->integer('feature.report_leftover_buckets');
        $sampleSize = $this->config->integer('feature.report_sample_size');
        $sampleSeed = $this->config->string('feature.report_sample_seed');
        if ($buckets < 1 || $sampleSize < 1) {
            throw new RuntimeException('Invalid feature report configuration.');
        }
        $proteins = [];
        $cuisines = [];
        $mealTypes = [];
        $leftovers = [];
        $sample = [];
        foreach ($recipes as $row) {
            if (!is_array($row) || !is_numeric($row['id'] ?? null)
                || !is_string($row['url'] ?? null) || !is_string($row['title'] ?? null)) {
                throw new RuntimeException('Invalid stored recipe in feature report.');
            }
            $this->increment($proteins, $row['main_protein'] ?? null);
            $this->increment($cuisines, $row['cuisine'] ?? null);
            $this->increment($mealTypes, $row['meal_type'] ?? null);
            if (is_numeric($row['leftover_score'] ?? null)) {
                $score = (float) $row['leftover_score'];
                $bucket = min($buckets - 1, (int) floor($score * $buckets));
                $label = sprintf('%.2f-%.2f', $bucket / $buckets, ($bucket + 1) / $buckets);
                $leftovers[$label] = ($leftovers[$label] ?? 0) + 1;
            } else {
                $leftovers['unclassified'] = ($leftovers['unclassified'] ?? 0) + 1;
            }
            $row['is_vegetarian'] = $row['is_vegetarian'] === null
                ? null : (bool) $row['is_vegetarian'];
            $row['leftover_score'] = is_numeric($row['leftover_score'])
                ? (float) $row['leftover_score'] : null;
            $row['festive_score'] = is_numeric($row['festive_score'])
                ? (float) $row['festive_score'] : null;
            $row['effort'] = is_numeric($row['effort']) ? (int) $row['effort'] : null;
            $sample[] = ['sort' => hash('sha256', $sampleSeed . ':' . $row['id']), 'recipe' => $row];
        }
        ksort($proteins);
        ksort($cuisines);
        ksort($mealTypes);
        ksort($leftovers);
        usort($sample, static fn (array $a, array $b): int => strcmp($a['sort'], $b['sort']));
        $mainQuery = $this->db->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(normalise_status = 'matched'), 0) AS matched " .
            'FROM recipe_ingredient WHERE is_main = 1'
        );
        $mainQuery->execute();
        $mainCounts = $mainQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($mainCounts) || !is_numeric($mainCounts['total'] ?? null)
            || !is_numeric($mainCounts['matched'] ?? null)) {
            throw new RuntimeException('Cannot read main ingredient coverage.');
        }
        $mainTotal = (int) $mainCounts['total'];
        $mainMatched = (int) $mainCounts['matched'];
        return [
            'recipes' => count($recipes),
            'main_ingredient_lines' => $mainTotal,
            'matched_main_ingredient_lines' => $mainMatched,
            'matched_main_ingredient_share_pct' => $mainTotal === 0
                ? 0.0 : round(100 * $mainMatched / $mainTotal, 1),
            'main_protein_distribution' => $proteins,
            'cuisine_distribution' => $cuisines,
            'meal_type_distribution' => $mealTypes,
            'leftover_score_distribution' => $leftovers,
            'spot_check' => array_column(array_slice($sample, 0, $sampleSize), 'recipe'),
        ];
    }

    /** @param array<string, int> $distribution */
    private function increment(array &$distribution, mixed $value): void
    {
        $key = is_string($value) ? $value : 'unclassified';
        $distribution[$key] = ($distribution[$key] ?? 0) + 1;
    }

    public function save(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, recursive: true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create feature report directory.');
        }
        $json = json_encode($this->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $temporary = tempnam($directory, 'feature-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create temporary feature report.');
        }
        try {
            if (file_put_contents($temporary, $json . PHP_EOL) === false || !rename($temporary, $path)) {
                throw new RuntimeException('Cannot save feature report.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
