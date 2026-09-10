<?php

declare(strict_types=1);

namespace Evolve\Bridge\Contracts;

use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class BridgeError
{
    public function __construct(
        private BridgeErrorKind $kind,
        private string $code,
        private string $message,
        private bool $retryable,
    ) {
        if (trim($code) === '') {
            throw new InvalidArgumentException('code must be nonblank.');
        }

        if (trim($message) === '') {
            throw new InvalidArgumentException('message must be nonblank.');
        }
    }

    public function kind(): BridgeErrorKind
    {
        return $this->kind;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
