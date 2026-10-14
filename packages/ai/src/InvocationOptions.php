<?php

declare(strict_types=1);

namespace Evolve\Ai;

use InvalidArgumentException;

final readonly class InvocationOptions
{
    public function __construct(
        public float $timeoutSeconds,
        public ?CancellationSignal $cancellationSignal = null,
    ) {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0.0) {
            throw new InvalidArgumentException('Timeout must be finite and positive.');
        }
    }
}
