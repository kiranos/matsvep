<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Adapter;

final class ParsedRecipe
{
    /**
     * @param list<string> $ingredients
     * @param list<string> $steps
     */
    public function __construct(
        public readonly string $title,
        public readonly array $ingredients,
        public readonly array $steps,
        public readonly ?string $imageUrl,
        public readonly ?int $totalTimeMinutes,
        public readonly ?float $servings,
        public readonly ?string $cuisine,
        public readonly ?string $mealType,
        public readonly string $format,
    ) {
    }
}
