<?php

declare(strict_types=1);

namespace Matsvep\Api;

use RuntimeException;

final class ApiError extends RuntimeException
{
    /** @param array<string, int|string|bool> $params */
    public function __construct(public readonly int $status, public readonly string $errorCode, public readonly array $params = [])
    {
        parent::__construct($errorCode);
    }
}
