<?php

declare(strict_types=1);

namespace Evolve\Session\Contracts;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class SessionIdentifier
{
    public function __construct(
        #[SensitiveParameter]
        private string $value,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException('Session identifier must not be empty.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]'];
    }
}
