<?php

declare(strict_types=1);

namespace Evolve\ApiKeys;

use DateTimeImmutable;

interface ApiKeyStore
{
    public function insert(ApiKeyRecord $record): void;
    public function find(string $identifier, string $principal, string $tenant): ?ApiKeyRecord;
    public function revoke(string $identifier, string $principal, string $tenant, DateTimeImmutable $now): bool;
}
