<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Ingest\Feature\ProteinStemMatcher;
use PHPUnit\Framework\TestCase;

final class ProteinStemMatcherTest extends TestCase
{
    public function testSeededCompoundsAndCollisions(): void
    {
        $cases = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/protein_stem_cases.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($cases);
        $matcher = new ProteinStemMatcher(__DIR__ . '/../seeds');
        foreach ($cases as $case) {
            self::assertIsArray($case);
            self::assertIsString($case['text'] ?? null);
            $matches = $matcher->match($case['text'], ($case['allow_roe'] ?? false) === true);
            if (!isset($case['stem'])) {
                self::assertSame([], $matches, $case['text']);
                continue;
            }
            self::assertNotEmpty($matches, $case['text']);
            self::assertSame($case['stem'], $matches[0]['stem'], $case['text']);
            self::assertSame($case['protein'] ?? null, $matches[0]['protein_code'], $case['text']);
            self::assertSame($case['kind'], $matches[0]['kind'], $case['text']);
            foreach ($matches as $match) {
                if ($matches[0]['kind'] === 'vegetarian') {
                    self::assertSame('vegetarian', $match['kind'], $case['text']);
                }
            }
        }
    }
}
