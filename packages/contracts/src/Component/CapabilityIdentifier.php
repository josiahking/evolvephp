<?php

declare(strict_types=1);

namespace Evolve\Contracts\Component;

use InvalidArgumentException;

/**
 * Explicit machine identifier for a declared component capability.
 *
 * @experimental
 */
final readonly class CapabilityIdentifier
{
    public function __construct(private string $value)
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\z/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid capability identifier.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
