<?php

declare(strict_types=1);

use Matsvep\Database\MigrationRunner;

require_once __DIR__ . '/../src/Database/MigrationRunner.php';

$sql = <<<'SQL'
-- first; comment
SELECT 'a; b', "x; y", `z;w`;
# another; comment
/* block; comment */ SELECT 'it''s; fine', 'backslash\\'; -- trailing;
SQL;
$expected = [
    <<<'SQL'
SELECT 'a; b', "x; y", `z;w`
SQL,
    <<<'SQL'
SELECT 'it''s; fine', 'backslash\\'
SQL,
];
if (MigrationRunner::splitStatements($sql) !== $expected) {
    fwrite(STDERR, 'SQL statement splitting failed.' . PHP_EOL);
    exit(1);
}
echo 'PASS SQL statement splitting', PHP_EOL;
