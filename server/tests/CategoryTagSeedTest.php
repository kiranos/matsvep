<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Ingest\Feature\CsvTable;
use PHPUnit\Framework\TestCase;

final class CategoryTagSeedTest extends TestCase
{
    public function testProductionTagsHaveSeededRolesAndCodes(): void
    {
        $expected = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/koket_production_tag_expectations.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($expected);
        $rows = CsvTable::read(
            __DIR__ . '/../seeds/feature_tag_map.csv',
            ['tag', 'cuisine_code', 'meal_type_code', 'festive', 'fest_bonus', 'cuisine_scope', 'meal_tag_role'],
        );
        $byTag = [];
        foreach ($rows as $row) {
            self::assertArrayNotHasKey($row['tag'], $byTag);
            $byTag[$row['tag']] = $row;
        }
        $productionTags = [];
        self::assertIsArray($expected['meal_tag_roles'] ?? null);
        foreach ($expected['meal_tag_roles'] as $tag => $role) {
            self::assertIsString($tag);
            self::assertIsString($role);
            self::assertSame($role, $byTag[$tag]['meal_tag_role'] ?? null, $tag);
            $productionTags[] = $tag;
        }
        self::assertIsArray($expected['occasion_tags'] ?? null);
        foreach ($expected['occasion_tags'] as $tag) {
            self::assertIsString($tag);
            self::assertSame('none', $byTag[$tag]['meal_tag_role'] ?? null, $tag);
            $productionTags[] = $tag;
        }
        $festTag = $expected['occasion_tags'][0] ?? null;
        $otherOccasionTag = $expected['occasion_tags'][1] ?? null;
        self::assertIsString($festTag);
        self::assertIsString($otherOccasionTag);
        self::assertSame('1', $byTag[$festTag]['fest_bonus'] ?? null);
        self::assertSame('0', $byTag[$festTag]['festive'] ?? null);
        self::assertSame('0', $byTag[$otherOccasionTag]['fest_bonus'] ?? null);
        self::assertIsArray($expected['cuisine_tags'] ?? null);
        foreach ($expected['cuisine_tags'] as $tag => $code) {
            self::assertIsString($tag);
            self::assertIsString($code);
            self::assertSame($code, $byTag[$tag]['cuisine_code'] ?? null, $tag);
            self::assertSame('none', $byTag[$tag]['meal_tag_role'] ?? null, $tag);
            $productionTags[] = $tag;
        }
        self::assertIsArray($expected['broad_cuisine_tags'] ?? null);
        foreach ($expected['broad_cuisine_tags'] as $tag) {
            self::assertIsString($tag);
            self::assertSame('broad', $byTag[$tag]['cuisine_scope'] ?? null, $tag);
        }
        self::assertIsArray($expected['specific_cuisine_examples'] ?? null);
        foreach ($expected['specific_cuisine_examples'] as $tag) {
            self::assertIsString($tag);
            self::assertSame('specific', $byTag[$tag]['cuisine_scope'] ?? null, $tag);
        }
        self::assertCount(31, array_unique($productionTags));
    }
}
