<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Processing;

use Matsvep\Engine\EngineConfig;
use Matsvep\Engine\Learner;
use Matsvep\Ingest\Dedupe\DedupeReport;
use Matsvep\Ingest\Dedupe\DishGrouper;
use Matsvep\Ingest\Dedupe\RecipeDeduplicator;
use Matsvep\Ingest\Feature\FeatureDeriver;
use Matsvep\Ingest\Feature\DinnerCandidateAudit;
use Matsvep\Ingest\Feature\FeatureReport;
use Matsvep\Ingest\Feature\FeatureSeeder;
use Matsvep\Ingest\Ingredient\IngredientLineParser;
use Matsvep\Ingest\Ingredient\IngredientNormaliser;
use Matsvep\Ingest\Ingredient\IngredientReport;
use Matsvep\Ingest\Ingredient\IngredientSeeder;
use PDO;
use RuntimeException;

final class RecipeProcessor
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $seedDirectory,
        private readonly string $reportDirectory,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $lock = $this->db->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute(['matsvep:crawl:koket']);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Koket crawler or another processing run is active.');
        }
        try {
            return $this->process();
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute(['matsvep:crawl:koket']);
        }
    }

    /** @return array<string, mixed> */
    private function process(): array
    {
        $before = $this->timeCoverage();
        $ingredients = (new IngredientSeeder($this->db))->seed($this->seedDirectory . '/ingredients.csv');
        $keywords = (new FeatureSeeder($this->db))->seed($this->seedDirectory);
        $normalised = (new IngredientNormaliser(
            $this->db,
            new IngredientLineParser($this->seedDirectory),
        ))->run();
        $featuresBefore = $this->featureCounts();
        $dinnerAudit = new DinnerCandidateAudit($this->db, $this->seedDirectory);
        $dinnerBefore = $dinnerAudit->candidates();
        $deriver = new FeatureDeriver($this->db, $this->seedDirectory);
        $derived = $deriver->run();
        $featuresAfter = $this->featureCounts();
        $dinnerChanges = $dinnerAudit->build($dinnerBefore, $dinnerAudit->candidates());
        $this->saveJson('ingredient-report.json', (new IngredientReport($this->db))->build());
        $after = $this->timeCoverage();
        $duplicates = (new RecipeDeduplicator($this->db))->run();
        $grouped = (new DishGrouper($this->db, $this->seedDirectory))->run();
        (new FeatureReport($this->db))->save($this->reportDirectory . '/feature-report.json');
        (new DedupeReport($this->db))->save($this->reportDirectory . '/dedupe-report.json');
        $affinityRebuild = (new Learner($this->db, EngineConfig::fromDatabase($this->db)))->rebuildAll();

        $result = [
            'seeded_ingredients' => $ingredients,
            'seeded_feature_keywords' => $keywords,
            'normalisation' => $normalised,
            'derived_recipes' => $derived,
            'time_estimates_changed' => $deriver->changedTimeEstimates(),
            'deduplication' => $duplicates + ['group_memberships_assigned' => $grouped],
            'time_coverage_before' => $before,
            'time_coverage_after' => $after,
            'features_before_derivation' => $featuresBefore,
            'features_after_derivation' => $featuresAfter,
            'dinner_candidate_changes' => $dinnerChanges,
            'affinity_rebuild' => $affinityRebuild,
        ];
        $this->saveJson('processing-report.json', $result);
        return $result;
    }

    /** @return array{vegetarian_true: int, vegetarian_false: int, vegetarian_unknown: int, dinner_candidates: int, dinner_candidates_without_main_protein: int} */
    private function featureCounts(): array
    {
        $query = $this->db->prepare(
            'SELECT COALESCE(SUM(is_vegetarian = TRUE), 0) AS vegetarian_true, '
            . 'COALESCE(SUM(is_vegetarian = FALSE), 0) AS vegetarian_false, '
            . 'COALESCE(SUM(is_vegetarian IS NULL), 0) AS vegetarian_unknown, '
            . 'COALESCE(SUM(is_dinner_candidate = TRUE), 0) AS dinner_candidates, '
            . 'COALESCE(SUM(is_dinner_candidate = TRUE AND main_protein IS NULL), 0) '
            . 'AS dinner_candidates_without_main_protein FROM recipe WHERE active = TRUE'
        );
        $query->execute();
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Cannot read feature counts.');
        }
        $result = [];
        foreach (['vegetarian_true', 'vegetarian_false', 'vegetarian_unknown',
            'dinner_candidates', 'dinner_candidates_without_main_protein'] as $key) {
            if (!is_numeric($row[$key] ?? null)) {
                throw new RuntimeException('Invalid feature count.');
            }
            $result[$key] = (int) $row[$key];
        }
        return $result;
    }

    /** @return array{total:int,given:int,estimated:int,unknown:int,covered_share_pct:float} */
    private function timeCoverage(): array
    {
        $query = $this->db->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(total_time_source = 'given'), 0) AS given, " .
            "COALESCE(SUM(total_time_source = 'estimated'), 0) AS estimated " .
            'FROM recipe WHERE active = TRUE'
        );
        $query->execute();
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_numeric($row['total'] ?? null)
            || !is_numeric($row['given'] ?? null) || !is_numeric($row['estimated'] ?? null)) {
            throw new RuntimeException('Cannot read recipe time coverage.');
        }
        $total = (int) $row['total'];
        $given = (int) $row['given'];
        $estimated = (int) $row['estimated'];
        return [
            'total' => $total,
            'given' => $given,
            'estimated' => $estimated,
            'unknown' => $total - $given - $estimated,
            'covered_share_pct' => $total === 0 ? 0.0 : round(100 * ($given + $estimated) / $total, 1),
        ];
    }

    /** @param array<string, mixed> $report */
    private function saveJson(string $name, array $report): void
    {
        if (!is_dir($this->reportDirectory)
            && !mkdir($this->reportDirectory, 0770, true) && !is_dir($this->reportDirectory)) {
            throw new RuntimeException('Cannot create processing report directory.');
        }
        $temporary = tempnam($this->reportDirectory, 'process-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create temporary processing report.');
        }
        try {
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json . PHP_EOL) === false
                || !chmod($temporary, 0640)
                || !rename($temporary, $this->reportDirectory . '/' . $name)) {
                throw new RuntimeException('Cannot save processing report.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
