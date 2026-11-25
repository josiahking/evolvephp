<?php

declare(strict_types=1);

namespace Evolve\ApiKeys;

use JsonSerializable;

/** The credential is deliberately available only through an explicit method. */
final readonly class IssuedApiKey implements JsonSerializable
{
    public function __construct(private string $identifier, #[\SensitiveParameter] private string $credential) {}

    public function identifier(): string
    {
        return $this->identifier;
    }
    public function credential(): string
    {
        return $this->credential;
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
