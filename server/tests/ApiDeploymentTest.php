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

    public function testExpiredApiStateHasAnInstalledNightlyTimer(): void
    {
        $setup = file_get_contents(__DIR__ . '/../../deploy/setup-ubuntu.sh');
        $service = file_get_contents(__DIR__ . '/../../deploy/systemd/matsvep-api-purge.service');
        $timer = file_get_contents(__DIR__ . '/../../deploy/systemd/matsvep-api-purge.timer');
        self::assertIsString($setup);
        self::assertIsString($service);
        self::assertIsString($timer);
        self::assertStringContainsString('matsvep-api-purge.service', $setup);
        self::assertStringContainsString('matsvep-api-purge.timer', $setup);
        self::assertStringContainsString('systemctl enable --now matsvep-api-purge.timer', $setup);
        self::assertStringContainsString('User=matsvep', $service);
        self::assertStringContainsString('bin/purge-api-state.php', $service);
        self::assertStringContainsString('OnCalendar=*-*-* 00:15:00', $timer);
    }
}
