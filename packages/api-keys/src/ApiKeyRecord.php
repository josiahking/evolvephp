<?php

declare(strict_types=1);

namespace Evolve\ApiKeys;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;

/** Persisted verifier and binding metadata. Never serialize the verifier. */
final readonly class ApiKeyRecord implements JsonSerializable
{
    /** @param list<string> $scopes */
    public function __construct(
        private string $identifier,
        #[\SensitiveParameter]
        private string $digest,
        private string $principal,
        private string $tenant,
        private array $scopes,
        private DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $revokedAt = null,
    ) {
        self::assertToken($identifier);
        self::assertToken($principal);
        self::assertToken($tenant);
        self::assertScopes($scopes);
        if (preg_match('/\A[0-9a-f]{64}\z/D', $digest) !== 1) {
            throw new InvalidArgumentException('Invalid API key verifier.');
        }
    }

    public static function assertToken(string $value): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid API key identifier.');
        }
    }

    /** @param list<string> $scopes */
    public static function assertScopes(array $scopes): void
    {
        if ($scopes === [] || count($scopes) > 32 || array_values(array_unique($scopes)) !== $scopes) {
            throw new InvalidArgumentException('Invalid API key scopes.');
        }
        foreach ($scopes as $scope) {
            self::assertToken($scope);
        }
    }

    public function identifier(): string
    {
        return $this->identifier;
    }
    public function digest(): string
    {
        return $this->digest;
    }
    public function principal(): string
    {
        return $this->principal;
    }
    public function tenant(): string
    {
        return $this->tenant;
    }
    /** @return list<string> */
    public function scopes(): array
    {
        return $this->scopes;
    }
    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }
    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }
    /** @return array{identifier: string} */
    public function jsonSerialize(): array
    {
        return ['identifier' => $this->identifier];
    }
    /** @return array{identifier: string} */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('API key secrets cannot be serialized.');
    }
}
