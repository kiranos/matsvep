<?php

declare(strict_types=1);

namespace Matsvep\Engine;

final class PlanCandidatePool
{
    private readonly Scorer $scorer;

    public function __construct(private readonly EngineConfig $config)
    {
        $this->scorer = new Scorer($config);
    }

    /** @param list<PlanCandidate> $candidates
     *  @return list<PlanCandidate>
     */
    public function select(array $candidates): array
    {
        $discovery = [];
        $kept = [];
        foreach ($candidates as $candidate) {
            if ($candidate->rating !== null || $candidate->wishlisted) {
                $kept[$candidate->id] = $candidate;
            } else {
                $discovery[] = ['recipe' => $candidate,
                    'content' => $this->scorer->content($candidate->affinities)];
            }
        }
        usort($discovery, static fn (array $a, array $b): int =>
            ($b['content'] <=> $a['content']) ?: ($a['recipe']->id <=> $b['recipe']->id));
        foreach (array_slice($discovery, 0, $this->config->integer('builder.discovery_prefilter')) as $item) {
            $kept[$item['recipe']->id] = $item['recipe'];
        }
        ksort($kept, SORT_NUMERIC);
        return array_values($kept);
    }
}
