<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use PHPUnit\Framework\TestCase;

final class DeploymentEnvTest extends TestCase
{
    public function testQuotedCrawlerAgentLoadsWithoutQuotes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'matsvep-env-');
        self::assertIsString($path);
        try {
            $agent = 'MatsvepCrawler/1.0 (+mailto:crawler@example.com)';
            self::assertNotFalse(file_put_contents($path, 'CRAWL_USER_AGENT="' . $agent . '"' . PHP_EOL));
            $configuration = parse_ini_file($path, false, INI_SCANNER_RAW);
            self::assertIsArray($configuration);
            self::assertSame($agent, $configuration['CRAWL_USER_AGENT']);
        } finally {
            unlink($path);
        }
    }
}
