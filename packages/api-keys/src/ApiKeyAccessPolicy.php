<?php

declare(strict_types=1);

namespace Evolve\ApiKeys;

/** Host implements every grant; the package supplies no default allow policy. */
interface ApiKeyAccessPolicy
{
    /** @param list<string> $scopes */
    public function canIssue(string $actor, string $principal, string $tenant, array $scopes): bool;
    public function canRevoke(string $actor, string $principal, string $tenant): bool;
}
