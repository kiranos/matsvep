<?php

declare(strict_types=1);

namespace Matsvep\Engine;

use DateTimeImmutable;
use DateTimeZone;
use Matsvep\Api\ApiError;

final class PlanningWindow
{
    public function __construct(private readonly ?DateTimeImmutable $now = null)
    {
    }

    /** @return list<string> */
    public function dates(string $timezone, string $weekStart, int $part, string $mode, int $dayCount): array
    {
        $zone = new DateTimeZone($timezone);
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $weekStart, $zone);
        if ($start === false || $start->format('Y-m-d') !== $weekStart || $start->format('w') !== '0'
            || !in_array($mode, ['whole_week', 'split_week'], true)
            || !in_array($dayCount, [5, 7], true)
            || ($mode === 'whole_week' && $part !== 1)
            || ($mode === 'split_week' && !in_array($part, [1, 2], true))) {
            throw new ApiError(422, 'validation_failed');
        }
        $localNow = ($this->now ?? new DateTimeImmutable('now'))->setTimezone($zone);
        $opens = $start->modify($part === 2 ? '+3 days' : '+0 days');
        $closes = $start->modify('+7 days');
        if ($localNow < $opens || $localNow >= $closes) {
            throw new ApiError(409, 'slot_not_open');
        }
        $dates = [];
        for ($offset = $part === 2 ? 3 : 0; $offset < ($part === 1 && $mode === 'split_week' ? 3 : $dayCount); $offset++) {
            $dates[] = $start->modify('+' . $offset . ' days')->format('Y-m-d');
        }
        return $dates;
    }
}
