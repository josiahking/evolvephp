<?php

declare(strict_types=1);

namespace Evolve\Queue\Contracts;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Opaque queue identifier, preserved exactly as supplied.
 *
 * @experimental EvolvePHP 2 is pre-beta; this queue contract may change before stable release.
 */
final readonly class QueueName
{
    public function __construct(
        #[SensitiveParameter]
        private string $value,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException('Queue name must not be empty.');
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
