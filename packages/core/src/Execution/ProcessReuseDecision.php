<?php

declare(strict_types=1);

namespace Evolve\Core\Execution;

enum ProcessReuseDecision
{
    case Reusable;
    case QuarantineRequired;

    public function allowsReuse(): bool
    {
        return $this === self::Reusable;
    }

    public function requiresQuarantine(): bool
    {
        return $this === self::QuarantineRequired;
    }
}
