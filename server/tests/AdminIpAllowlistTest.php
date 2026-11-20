<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Admin\AdminIpAllowlist;
use PHPUnit\Framework\TestCase;

final class AdminIpAllowlistTest extends TestCase
{
    public function testExactAddressesAndComments(): void
    {
        $this->withFile("# Office access\n\n192.0.2.17 # workstation\n2001:db8::17\n", static function (string $path): void {
            $allowlist = new AdminIpAllowlist();
            self::assertTrue($allowlist->allowsFile($path, '192.0.2.17'));
            self::assertFalse($allowlist->allowsFile($path, '192.0.2.18'));
            self::assertTrue($allowlist->allowsFile($path, '2001:db8::17'));
            self::assertFalse($allowlist->allowsFile($path, '2001:db8::18'));
        });
    }

    public function testIpv4AndIpv6CidrsRespectBoundaries(): void
    {
        $this->withFile("198.51.100.64/27\n2001:db8:abcd::/48\n", static function (string $path): void {
            $allowlist = new AdminIpAllowlist();
            self::assertTrue($allowlist->allowsFile($path, '198.51.100.64'));
            self::assertTrue($allowlist->allowsFile($path, '198.51.100.95'));
            self::assertFalse($allowlist->allowsFile($path, '198.51.100.96'));
            self::assertTrue($allowlist->allowsFile($path, '2001:db8:abcd:ffff::1'));
            self::assertFalse($allowlist->allowsFile($path, '2001:db8:abce::1'));
        });
    }

    public function testInvalidEntriesNeverGrantAccess(): void
    {
        $this->withFile("192.0.2.0/33\n2001:db8::/129\n192.0.2.999\nexample.com\n", static function (string $path): void {
            $allowlist = new AdminIpAllowlist();
            self::assertFalse($allowlist->allowsFile($path, '192.0.2.1'));
            self::assertFalse($allowlist->allowsFile($path, '2001:db8::1'));
        });
        $this->withFile("192.0.2.0/33\n192.0.2.17\n", static function (string $path): void {
            $allowlist = new AdminIpAllowlist();
            self::assertTrue($allowlist->allowsFile($path, '192.0.2.17'));
            self::assertFalse($allowlist->allowsFile($path, '192.0.2.18'));
        });
    }

    public function testMissingEmptyAndUnreadableInputsDenyAccess(): void
    {
        $allowlist = new AdminIpAllowlist();
        $missing = sys_get_temp_dir() . '/matsvep-missing-' . bin2hex(random_bytes(8));
        self::assertFalse($allowlist->allowsFile($missing, '127.0.0.1'));
        $this->withFile('', static function (string $path) use ($allowlist): void {
            self::assertFalse($allowlist->allowsFile($path, '127.0.0.1'));
            self::assertFalse($allowlist->allowsFile($path, 'not-an-ip'));
        });
    }

    public function testFileEditsTakeEffectOnTheNextCheck(): void
    {
        $this->withFile("192.0.2.17\n", static function (string $path): void {
            $allowlist = new AdminIpAllowlist();
            self::assertTrue($allowlist->allowsFile($path, '192.0.2.17'));
            self::assertFalse($allowlist->allowsFile($path, '192.0.2.18'));
            self::assertNotFalse(file_put_contents($path, "192.0.2.18\n"));
            self::assertFalse($allowlist->allowsFile($path, '192.0.2.17'));
            self::assertTrue($allowlist->allowsFile($path, '192.0.2.18'));
        });
    }

    /** @param callable(string): void $assertions */
    private function withFile(string $contents, callable $assertions): void
    {
        $path = tempnam(sys_get_temp_dir(), 'matsvep-admin-');
        self::assertIsString($path);
        try {
            self::assertNotFalse(file_put_contents($path, $contents));
            $assertions($path);
        } finally {
            unlink($path);
        }
    }
}
