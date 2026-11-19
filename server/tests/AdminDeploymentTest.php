<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use PHPUnit\Framework\TestCase;

final class AdminDeploymentTest extends TestCase
{
    public function testAdminSiteIsConfiguredForLoopbackOnly(): void
    {
        $setup = file_get_contents(__DIR__ . '/../../deploy/setup-ubuntu.sh');
        $deployment = file_get_contents(__DIR__ . '/../../docs/deployment.md');
        self::assertIsString($setup);
        self::assertIsString($deployment);
        self::assertStringContainsString('Listen 127.0.0.1:8081', $setup);
        self::assertStringContainsString('<VirtualHost 127.0.0.1:8081>', $setup);
        self::assertStringContainsString('Require local', $setup);
        self::assertStringContainsString('DocumentRoot /var/www/matsvep', $setup);
        self::assertStringContainsString('php8.5-fpm-matsvep-admin.sock', $setup);
        self::assertStringContainsString('ssh -L 8080:localhost:8081 user@vps', $deployment);
    }
}
