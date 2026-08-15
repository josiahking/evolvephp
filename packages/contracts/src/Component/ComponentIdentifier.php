<?php

declare(strict_types=1);

namespace Evolve\Contracts\Component;

use InvalidArgumentException;

/**
 * Explicit machine identifier shared by future module and plugin metadata.
 *
 * @experimental
 */
final readonly class ComponentIdentifier
{
    public function __construct(private string $value)
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?(?:\/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?)?$/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid component identifier.');
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
