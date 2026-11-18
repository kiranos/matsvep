<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Ingredient;

final class ParsedIngredientLine
{
    /** @param list<string> $parts */
    public function __construct(
        public readonly ?float $quantity,
        public readonly ?float $quantityMax,
        public readonly ?string $unit,
        public readonly bool $explicitUnit,
        public readonly string $ingredientText,
        public readonly ?string $note,
        public readonly array $parts,
        public readonly bool $unsafeSplit,
    ) {
    }
}
