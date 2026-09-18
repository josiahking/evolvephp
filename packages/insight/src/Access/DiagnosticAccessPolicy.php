<?php

declare(strict_types=1);

namespace Evolve\Insight\Access;

interface DiagnosticAccessPolicy
{
    public function allows(DiagnosticAccessOperation $operation, ?string $executionIdentifier = null): bool;
}
