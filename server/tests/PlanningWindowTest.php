<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use DateTimeImmutable;
use Matsvep\Api\ApiError;
use Matsvep\Engine\PlanningWindow;
use PHPUnit\Framework\TestCase;

final class PlanningWindowTest extends TestCase
{
    public function testSplitHalvesOpenAtLocalMidnight(): void
    {
        $sunday = new PlanningWindow(new DateTimeImmutable('2026-11-21 23:00:00+00:00'));
        self::assertSame(
            ['2026-11-22', '2026-11-23', '2026-11-24'],
            $sunday->dates('Europe/Stockholm', '2026-11-22', 1, 'split_week', 7)
        );
        try {
            $sunday->dates('Europe/Stockholm', '2026-11-22', 2, 'split_week', 7);
            self::fail('Wednesday planning opened on Sunday.');
        } catch (ApiError $error) {
            self::assertSame('slot_not_open', $error->errorCode);
        }
        $wednesday = new PlanningWindow(new DateTimeImmutable('2026-11-24 23:00:00+00:00'));
        self::assertSame(
            ['2026-11-25', '2026-11-26', '2026-11-27', '2026-11-28'],
            $wednesday->dates('Europe/Stockholm', '2026-11-22', 2, 'split_week', 7)
        );
    }

    public function testDaylightSavingTransitionsUseHouseholdLocalDates(): void
    {
        $spring = new PlanningWindow(new DateTimeImmutable('2026-03-28 23:00:00+00:00'));
        self::assertSame('2026-03-29', $spring->dates('Europe/Stockholm', '2026-03-29', 1, 'split_week', 7)[0]);
        $fall = new PlanningWindow(new DateTimeImmutable('2026-10-24 22:00:00+00:00'));
        self::assertSame('2026-10-25', $fall->dates('Europe/Stockholm', '2026-10-25', 1, 'split_week', 7)[0]);
        $before = new PlanningWindow(new DateTimeImmutable('2026-10-24 21:59:59+00:00'));
        $this->expectException(ApiError::class);
        $before->dates('Europe/Stockholm', '2026-10-25', 1, 'split_week', 7);
    }
}
