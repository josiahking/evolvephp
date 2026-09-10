<?php

declare(strict_types=1);

namespace Evolve\Bridge\Contracts;

use Evolve\Bridge\Contracts\Internal\TransportValueValidator;

/**
 * @experimental
 */
final readonly class BridgeResponse
{
    private function __construct(
        private bool $successful,
        private mixed $result,
        private ?BridgeError $error,
    ) {}

    public static function success(mixed $result): self
    {
        TransportValueValidator::assertValid($result);

        return new self(true, $result, null);
    }

    public static function failure(BridgeError $error): self
    {
        return new self(false, null, $error);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function error(): ?BridgeError
    {
        return $this->error;
    }
}
