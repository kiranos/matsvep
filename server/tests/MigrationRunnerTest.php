<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigrationRunnerTest extends TestCase
{
    public function testSplitStatementsPreservesQuotedSemicolons(): void
    {
        $sql = <<<'SQL'
-- first; comment
SELECT 'a; b', "x; y", `z;w`;
# another; comment
/* block; comment */ SELECT 'it''s; fine', 'backslash\\'; -- trailing;
SQL;
        $expected = [
            'SELECT \'a; b\', "x; y", `z;w`',
            'SELECT \'it\'\'s; fine\', \'backslash\\\\\'',
        ];

        self::assertSame($expected, MigrationRunner::splitStatements($sql));
    }

    public function testSplitStatementsRejectsUnterminatedString(): void
    {
        $this->expectException(RuntimeException::class);
        MigrationRunner::splitStatements("SELECT 'unfinished");
    }
}
