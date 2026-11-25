<?php

declare(strict_types=1);

namespace Evolve\ApiKeys;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ApiKeyIssueRequest
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $actor,
        public string $principal,
        public string $tenant,
        public array $scopes,
        public DateTimeImmutable $expiresAt,
    ) {
        ApiKeyRecord::assertToken($actor);
        ApiKeyRecord::assertToken($principal);
        ApiKeyRecord::assertToken($tenant);
        ApiKeyRecord::assertScopes($scopes);
        if ($expiresAt->getTimestamp() <= time()) {
            throw new InvalidArgumentException('API key expiration must be in the future.');
        }
    }
}
