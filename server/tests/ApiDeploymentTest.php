<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use PHPUnit\Framework\TestCase;

final class ApiDeploymentTest extends TestCase
{
    public function testApiRunsInItsOwnPoolWithAuthorizationForwarded(): void
    {
        $site = file_get_contents(__DIR__ . '/../../deploy/apache/matsvep.conf.template');
        $setup = file_get_contents(__DIR__ . '/../../deploy/setup-ubuntu.sh');
        $pool = file_get_contents(__DIR__ . '/../../deploy/php-fpm/matsvep-api.conf.template');
        self::assertIsString($site);
        self::assertIsString($setup);
        self::assertIsString($pool);
        self::assertStringContainsString('CGIPassAuth On', $site);
        self::assertStringContainsString('/api/v1', $site);
        self::assertStringContainsString('php8.5-fpm-matsvep-api.sock', $site);
        self::assertStringContainsString('matsvep-api.conf', $setup);
        self::assertStringContainsString('user = matsvep', $pool);
        self::assertStringContainsString('server/public/admin/', $site);
    }
}
