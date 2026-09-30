<?php

declare(strict_types=1);

namespace Evolve\Queue\Contracts;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Immutable opaque transport payload with flat string metadata.
 *
 * @experimental EvolvePHP 2 is pre-beta; this queue contract may change before stable release.
 */
final readonly class MessageEnvelope
{
    /** @var array<string, string> */
    private array $metadata;

    /**
     * @param array<array-key, mixed> $metadata
     */
    public function __construct(
        #[SensitiveParameter]
        private string $payload,
        #[SensitiveParameter]
        array $metadata = [],
    ) {
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new InvalidArgumentException('Message metadata keys and values must be strings.');
            }
        }

        $this->metadata = $metadata;
    }

    public function payload(): string
    {
        return $this->payload;
    }

    /** @return array<string, string> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /** @return array{payload: string, metadata: string} */
    public function __debugInfo(): array
    {
        return [
            'payload' => '[REDACTED]',
            'metadata' => '[REDACTED]',
        ];
    }
}
