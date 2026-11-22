<?php

declare(strict_types=1);

namespace Matsvep\Engine;

final class Filters
{
    public function __construct(private readonly EngineConfig $config)
    {
    }

    /** @return list<string> English reason codes */
    public function reasons(FilterInput $input): array
    {
        $reasons = [];
        if (!$input->active) {
            $reasons[] = 'inactive_recipe';
        }
        if (!$input->dinnerCandidate) {
            $reasons[] = 'not_dinner_candidate';
        }
        if (in_array($input->libraryStatus, ['blocked', 'removed'], true)) {
            $reasons[] = $input->libraryStatus;
        }
        if ($input->blockVegetarian && $input->vegetarian === true) {
            $reasons[] = 'vegetarian_blocked';
        }
        if ($input->rating !== null && $input->rating <= $this->config->integer('filter.hide_at_or_below')) {
            $reasons[] = 'low_rating';
        }
        if ($input->daysSinceGroupCooked !== null) {
            $rating = $input->rating === 5 ? 5 : ($input->rating === 3 ? 3 : 4);
            if ($input->daysSinceGroupCooked < $this->config->integer('cooldown.days.' . $rating)) {
                $reasons[] = 'cooldown';
            }
        }
        if ($input->recipeChosen || $input->groupChosen) {
            $reasons[] = 'already_chosen';
        }
        return $reasons;
    }

    public function passes(FilterInput $input): bool
    {
        return $this->reasons($input) === [];
    }
}
