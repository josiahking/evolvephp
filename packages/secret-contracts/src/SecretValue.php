<?php

declare(strict_types=1);

namespace Evolve\Secret\Contracts;

use SensitiveParameter;

/**
 * Opaque secret bytes, including empty and binary values.
 *
 * @experimental EvolvePHP 2 is pre-beta; this secret contract may change before stable release.
 */
final readonly class SecretValue
{
    public function __construct(
        #[SensitiveParameter]
        private string $value,
    ) {}

    public function value(): string
    {
        return $this->value;
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]'];
    }
}
