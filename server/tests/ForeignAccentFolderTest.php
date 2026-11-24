<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Ingest\Feature\ForeignAccentFolder;
use PHPUnit\Framework\TestCase;

final class ForeignAccentFolderTest extends TestCase
{
    public function testForeignAccentsFoldButSwedishLettersStayDistinct(): void
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/fixtures/foreign_accent_cases.json'), true);
        self::assertIsArray($cases);
        self::assertIsArray($cases['folds']);
        foreach ($cases['folds'] as $input => $expected) {
            self::assertIsString($input);
            self::assertSame($expected, ForeignAccentFolder::fold($input));
        }
        self::assertIsArray($cases['distinct']);
        foreach ($cases['distinct'] as $pair) {
            self::assertIsArray($pair);
            self::assertCount(2, $pair);
            self::assertIsString($pair[0]);
            self::assertIsString($pair[1]);
            self::assertNotSame(ForeignAccentFolder::fold($pair[0]), ForeignAccentFolder::fold($pair[1]));
        }
    }
}
