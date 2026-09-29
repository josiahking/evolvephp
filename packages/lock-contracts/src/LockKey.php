<?php

declare(strict_types=1);

namespace Evolve\Lock\Contracts;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Sensitive identifier for a lock resource.
 *
 * @experimental EvolvePHP 2 is pre-beta; this lock contract may change before stable release.
 */
final readonly class LockKey
{
    public function __construct(
        #[SensitiveParameter]
        private string $value,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException('Lock key must not be empty.');
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
