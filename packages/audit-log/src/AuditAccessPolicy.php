<?php

declare(strict_types=1);

namespace Evolve\AuditLog;

/** Host-owned authorization; the package has no implicit grants. */
interface AuditAccessPolicy
{
    public function canRead(string $principal, ?string $tenant): bool;

    public function canPurge(string $principal, ?string $tenant): bool;
}