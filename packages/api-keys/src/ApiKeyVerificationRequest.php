<?php

declare(strict_types=1);

namespace Evolve\ApiKeys;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;

final readonly class ApiKeyVerificationRequest implements JsonSerializable
{
    /** @param list<string> $requiredScopes */
    public function __construct(
        #[\SensitiveParameter]
        private string $credential,
        public string $principal,
        public string $tenant,
        public array $requiredScopes,
        public DateTimeImmutable $now,
    ) {
        ApiKeyRecord::assertToken($principal);
        ApiKeyRecord::assertToken($tenant);
        if (count($requiredScopes) > 32 || array_values(array_unique($requiredScopes)) !== $requiredScopes) {
            throw new InvalidArgumentException('Invalid API key scopes.');
        }
        foreach ($requiredScopes as $scope) {
            ApiKeyRecord::assertToken($scope);
        }
    }

    public function credential(): string
    {
        return $this->credential;
    }

    /** @return array{principal: string, tenant: string, requiredScopes: list<string>} */
    public function jsonSerialize(): array
    {
        return ['principal' => $this->principal, 'tenant' => $this->tenant, 'requiredScopes' => $this->requiredScopes];
    }

    /** @return array{principal: string, tenant: string, requiredScopes: list<string>} */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('API key credentials cannot be serialized.');
    }
}
