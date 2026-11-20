<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use PHPUnit\Framework\TestCase;

final class AdminDeploymentTest extends TestCase
{
    public function testPublicAdminUsesHttpsAndAppLocalAllowlist(): void
    {
        $setup = file_get_contents(__DIR__ . '/../../deploy/setup-ubuntu.sh');
        $site = file_get_contents(__DIR__ . '/../../deploy/apache/matsvep.conf.template');
        $pool = file_get_contents(__DIR__ . '/../../deploy/php-fpm/matsvep-admin.conf.template');
        $front = file_get_contents(__DIR__ . '/../public/admin/index.php');
        $ignore = file_get_contents(__DIR__ . '/../../.gitignore');
        self::assertIsString($setup);
        self::assertIsString($site);
        self::assertIsString($pool);
        self::assertIsString($front);
        self::assertIsString($ignore);

        self::assertStringContainsString('server/config/admin-allowlist.conf.example', $setup);
        self::assertStringContainsString('install -o root -g matsvep -m 0640', $setup);
        self::assertStringContainsString('server/config/admin-allowlist.conf', $ignore);
        self::assertStringContainsString('allowsFile(', $front);
        self::assertLessThan(strpos($front, 'startSession()'), strpos($front, 'allowsFile('));
        self::assertStringContainsString('https://@DOMAIN@', $site);
        self::assertStringContainsString('<VirtualHost *:443>', $site);
        self::assertStringContainsString('server/public/admin/', $site);
        self::assertStringContainsString('server/var/sessions', $pool);
        self::assertStringNotContainsString('8081', $setup);
        self::assertStringNotContainsString('/var/lib/matsvep/admin-sessions', $pool);
    }

    public function testSystemRegistrationsAreInstalledAsRootOwnedCopies(): void
    {
        $setup = file_get_contents(__DIR__ . '/../../deploy/setup-ubuntu.sh');
        self::assertIsString($setup);
        self::assertStringContainsString('install -o root -g root -m 0644 "$rendered" "$destination"', $setup);
        self::assertStringContainsString('/etc/apache2/sites-available/matsvep.conf', $setup);
        self::assertStringContainsString('/etc/php/8.5/fpm/pool.d/matsvep-admin.conf', $setup);
        self::assertStringContainsString('install -o root -g root -m 0644 "$project_root/deploy/systemd/', $setup);
        self::assertStringContainsString('a2ensite matsvep.conf', $setup);
    }
}
