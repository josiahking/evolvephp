<?php

declare(strict_types=1);

namespace Evolve\Secret\Contracts;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Opaque logical secret identifier preserved byte for byte.
 *
 * @experimental EvolvePHP 2 is pre-beta; this secret contract may change before stable release.
 */
final readonly class SecretName
{
    public function __construct(
        #[SensitiveParameter]
        private string $value,
    ) {
        if ($value === '' || strlen($value) > 2048 || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Secret name must be non-empty, at most 2048 bytes, and contain no NUL byte.');
        }
    }

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
