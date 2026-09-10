<?php

declare(strict_types=1);

namespace Evolve\Bridge\Contracts;

use Evolve\Bridge\Contracts\Internal\TransportValueValidator;
use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class BridgeRequest
{
    public function __construct(
        private string $operation,
        private BridgeContext $context,
        private mixed $payload,
    ) {
        if (trim($operation) === '') {
            throw new InvalidArgumentException('operation must be nonblank.');
        }

        TransportValueValidator::assertValid($payload);
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function context(): BridgeContext
    {
        return $this->context;
    }

    public function payload(): mixed
    {
        return $this->payload;
    }
}
