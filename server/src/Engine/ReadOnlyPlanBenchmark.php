<?php

declare(strict_types=1);

namespace Matsvep\Engine;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class ReadOnlyPlanBenchmark
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @param Closure(int): array{total_ms: float}|null $finishPhase
     *  @return array<string, mixed>
     */
    public function run(?int $householdId = null, ?Closure $finishPhase = null): array
    {
        if ($householdId !== null && $householdId < 1) {
            throw new \InvalidArgumentException('Household ID must be positive.');
        }
        $this->db->exec('SET TRANSACTION READ ONLY');
        $this->db->beginTransaction();
        try {
            $started = hrtime(true);
            $config = EngineConfig::fromDatabase($this->db);
            $timezone = new DateTimeZone('Europe/Stockholm');
            $settings = [];
            if ($householdId !== null) {
                $query = $this->db->prepare('SELECT timezone FROM household WHERE id = ?');
                $query->execute([$householdId]);
                $zone = $query->fetchColumn();
                if (!is_string($zone)) {
                    throw new RuntimeException('Household was not found.');
                }
                $timezone = new DateTimeZone($zone);
                $query = $this->db->prepare('SELECT `key`, value FROM household_setting WHERE household_id = ?');
                $query->execute([$householdId]);
                while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $settings[(string) $row['key']] = json_decode((string) $row['value'], true, 512, JSON_THROW_ON_ERROR);
                }
            }
            $today = new DateTimeImmutable('now', $timezone);
            $weekStart = $today->modify('next sunday')->format('Y-m-d');
            $planDaySetting = $settings['plan_days'] ?? null;
            if ($planDaySetting !== null && !in_array($planDaySetting, ['all_seven', 'sunday_thursday'], true)) {
                throw new RuntimeException('Invalid plan day setting.');
            }
            $dayCount = $planDaySetting === null ? $config->integer('builder.default_plan_days')
                : $config->integer('builder.plan_days.' . $planDaySetting);
            $query = $this->db->prepare('SELECT d.weekday, COALESCE(p.profile_code, d.profile_code) AS profile '
                . 'FROM day_profile_default d LEFT JOIN day_profile p '
                . 'ON p.household_id = ? AND p.weekday = d.weekday');
            $query->execute([$householdId ?? 0]);
            $profiles = [];
            while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
                $profiles[(int) $row['weekday']] = (string) $row['profile'];
            }
            $days = [];
            for ($offset = 0; $offset < $dayCount; $offset++) {
                $date = (new DateTimeImmutable($weekStart, $timezone))->modify('+' . $offset . ' days');
                $weekday = (int) $date->format('w');
                if (!isset($profiles[$weekday])) {
                    throw new RuntimeException('A default day profile is missing.');
                }
                $days[] = ['date' => $date->format('Y-m-d'), 'profile' => $profiles[$weekday]];
            }
            $count = $this->db->prepare('SELECT COUNT(*) FROM recipe WHERE active = TRUE AND is_dinner_candidate = TRUE');
            $count->execute();
            $activeDinnerCandidates = (int) $count->fetchColumn();
            $share = $settings['favourite_share'] ?? $config->decimal('builder.default_favourite_share');
            $blockVegetarian = $settings['block_vegetarian'] ?? false;
            if (!is_numeric($share) || !is_bool($blockVegetarian)) {
                throw new RuntimeException('Invalid household planning settings.');
            }
            $repository = new PlanCandidateRepository($this->db, $config);
            $shortlist = $repository->load($householdId ?? 0, $today, $blockVegetarian);
            $loadedCandidates = $repository->lastLoadedCount();
            if ($shortlist === []) {
                throw new RuntimeException('No eligible dinner candidates were found.');
            }
            $seed = PlanBuilder::seed($householdId ?? 0, $weekStart, 1, 0);
            $builder = new PlanBuilder($config);
            $initial = $builder->build($days, $shortlist, (float) $share, $seed,
                blockVegetarian: $blockVegetarian, weekDayCount: $dayCount);
            $full = $finishPhase === null ? self::elapsed($started) : $finishPhase($started);

            $pickDate = $days[0]['date'];
            $pick = $initial['decks'][$pickDate][0]['recipe_id'] ?? null;
            if (!is_int($pick)) {
                throw new RuntimeException('The first planning day has no pick.');
            }
            $started = hrtime(true);
            $shortlistIds = array_map(static fn (PlanCandidate $candidate): int => $candidate->id, $shortlist);
            $rerankCandidates = $repository->load($householdId ?? 0, $today, $blockVegetarian, [], null, [], $shortlistIds);
            (new PlanBuilder($config))->build($days, $rerankCandidates, (float) $share, $seed,
                fixed: [$pickDate => $pick], blockVegetarian: $blockVegetarian, weekDayCount: $dayCount);
            $rerank = $finishPhase === null ? self::elapsed($started) : $finishPhase($started);

            return [
                'database' => 'existing_read_only',
                'household_id' => $householdId,
                'planning_days' => $dayCount,
                'active_dinner_candidates' => $activeDinnerCandidates,
                'eligible_dinner_candidates_loaded' => $loadedCandidates,
                'shortlist_count' => count($shortlist),
                'rerank_shortlist_count' => count($rerankCandidates),
                'full_plan' => $full,
                'rerank_after_pick' => $rerank,
                'rerank_target_met' => $rerank['total_ms'] < 100,
            ];
        } finally {
            $this->db->rollBack();
        }
    }

    /** @return array{total_ms: float} */
    private static function elapsed(int $started): array
    {
        return ['total_ms' => round((hrtime(true) - $started) / 1_000_000, 2)];
    }
}
