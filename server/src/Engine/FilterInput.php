<?php

declare(strict_types=1);

namespace Matsvep\Engine;

final class FilterInput
{
    public function __construct(
        public readonly bool $active,
        public readonly bool $dinnerCandidate,
        public readonly string $sourceStatus,
        public readonly string $libraryStatus,
        public readonly ?bool $vegetarian,
        public readonly bool $blockVegetarian,
        public readonly ?int $rating,
        public readonly ?int $daysSinceGroupCooked,
        public readonly bool $recipeChosen,
        public readonly bool $groupChosen,
    ) {
    }
}
