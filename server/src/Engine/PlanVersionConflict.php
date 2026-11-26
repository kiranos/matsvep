<?php

declare(strict_types=1);

namespace Matsvep\Engine;

use RuntimeException;

final class PlanVersionConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('plan_version_conflict');
    }
}
