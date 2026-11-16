<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Adapter;

use DateTimeImmutable;
use RuntimeException;

final class KoketPilotSampler
{
    private const SEED = 'matsvep-koket-pilot-v1';

    /**
     * @param array<string, DateTimeImmutable|null> $candidates
     * @return list<array{url: string, modified_at: DateTimeImmutable|null}>
     */
    public function sample(array $candidates, int $size): array
    {
        if ($size < 1 || $size > count($candidates)) {
            throw new RuntimeException('Sample size must be between 1 and the number of sitemap candidates.');
        }

        $root = [];
        $nested = [];
        foreach ($candidates as $url => $modifiedAt) {
            $path = parse_url($url, PHP_URL_PATH);
            $entry = ['url' => $url, 'modified_at' => $modifiedAt];
            if (is_string($path) && substr_count(trim($path, '/'), '/') === 0) {
                $root[] = $entry;
            } else {
                $nested[] = $entry;
            }
        }

        $this->sortBySeed($root);
        $this->sortBySeed($nested);
        $rootSize = (int) round($size * count($root) / count($candidates));
        if ($size >= 2 && $root !== [] && $nested !== []) {
            $rootSize = max(1, min($size - 1, $rootSize));
        }
        $rootSize = max($size - count($nested), min(count($root), $rootSize));
        $selected = array_merge(array_slice($root, 0, $rootSize), array_slice($nested, 0, $size - $rootSize));
        $this->sortBySeed($selected);
        return $selected;
    }

    /**
     * @param list<array{url: string, modified_at: DateTimeImmutable|null}> $entries
     */
    private function sortBySeed(array &$entries): void
    {
        usort($entries, static function (array $left, array $right): int {
            $leftHash = hash('sha256', self::SEED . ':' . $left['url']);
            $rightHash = hash('sha256', self::SEED . ':' . $right['url']);
            return strcmp($leftHash, $rightHash) ?: strcmp($left['url'], $right['url']);
        });
    }
}
