<?php

declare(strict_types=1);

namespace Evolve\ApiKeys;

use JsonSerializable;

/** Uniform public denial. No identifier, verifier, or reason is exposed. */
final readonly class ApiKeyVerificationResult implements JsonSerializable
{
    public function __construct(private bool $allowed) {}
    public function allowed(): bool
    {
        return $this->allowed;
    }
    /** @return array{allowed: bool} */
    public function jsonSerialize(): array
    {
        return ['allowed' => $this->allowed];
    }
}
