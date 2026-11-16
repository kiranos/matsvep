<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Adapter;

final class ParsedRecipe
{
    /**
     * @param list<array{text: string, group_name: string|null}> $ingredients
     * @param list<array{text: string, group_name: string|null}> $steps
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $title,
        public readonly array $ingredients,
        public readonly array $steps,
        public readonly array $tags,
        public readonly ?string $imageUrl,
        public readonly ?int $totalTimeMinutes,
        public readonly ?float $servings,
        public readonly string $format,
        public readonly ?string $jsonLdPayload,
    ) {
    }
}
