<?php

declare(strict_types=1);

namespace Matsvep\Admin;

final class AdminIpAllowlist
{
    public function allowsFile(string $path, string $remoteAddress): bool
    {
        $remote = @inet_pton($remoteAddress);
        if ($remote === false || !is_file($path) || !is_readable($path)) {
            return false;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return false;
        }
        $allowed = false;
        foreach ($lines as $index => $line) {
            $entry = trim(explode('#', $line, 2)[0]);
            if ($entry === '') {
                continue;
            }
            $range = $this->parseRange($entry);
            if ($range === null) {
                error_log(sprintf('Invalid admin allowlist entry at line %d.', $index + 1));
                continue;
            }
            $allowed = $allowed || $this->matches($remote, $range['address'], $range['prefix']);
        }
        return $allowed;
    }

    /** @return array{address: string, prefix: int}|null */
    private function parseRange(string $entry): ?array
    {
        $pieces = explode('/', $entry);
        if (count($pieces) > 2) {
            return null;
        }
        $addressText = $pieces[0];
        if (filter_var($addressText, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $address = inet_pton($addressText);
        if ($address === false) {
            return null;
        }
        $bits = strlen($address) * 8;
        if (count($pieces) === 1) {
            return ['address' => $address, 'prefix' => $bits];
        }
        if (preg_match('/^(0|[1-9][0-9]*)$/', $pieces[1]) !== 1) {
            return null;
        }
        $prefix = filter_var($pieces[1], FILTER_VALIDATE_INT);
        if ($prefix === false || $prefix > $bits) {
            return null;
        }
        return ['address' => $address, 'prefix' => $prefix];
    }

    private function matches(string $remote, string $network, int $prefix): bool
    {
        if (strlen($remote) !== strlen($network)) {
            return false;
        }
        $wholeBytes = intdiv($prefix, 8);
        if (substr($remote, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)) {
            return false;
        }
        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($remote[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }
}
