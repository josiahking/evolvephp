<?php

declare(strict_types=1);

namespace Evolve\Storage\Contracts;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Opaque storage identifier preserved exactly as supplied.
 *
 * @experimental EvolvePHP 2 is pre-beta; this storage contract may change before stable release.
 */
final readonly class StorageKey
{
    public function __construct(
        #[SensitiveParameter]
        private string $value,
    ) {
        if ($value === '' || strlen($value) > 1024 || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Storage key must be non-empty, at most 1024 bytes, and contain no NUL byte.');
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
