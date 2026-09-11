<?php

declare(strict_types=1);

namespace Evolve\Bridge\Remote;

use Evolve\Bridge\Contracts\BridgeError;
use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class RemoteBridgeClientResult
{
    private function __construct(
        private ?RemoteBridgeResult $result,
        private ?BridgeError $error,
    ) {
        if (($result === null) === ($error === null)) {
            throw new InvalidArgumentException('Remote Bridge client result requires exactly one result or error.');
        }
    }

    public static function received(RemoteBridgeResult $result): self
    {
        return new self($result, null);
    }

    public static function failure(BridgeError $error): self
    {
        return new self(null, $error);
    }

    public function result(): ?RemoteBridgeResult
    {
        return $this->result;
    }

    public function error(): ?BridgeError
    {
        return $this->error;
    }
}
