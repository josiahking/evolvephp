<?php

declare(strict_types=1);

namespace Evolve\Lock\Contracts;

use InvalidArgumentException;

/**
 * Positive requested lease duration in milliseconds.
 *
 * @experimental EvolvePHP 2 is pre-beta; this lock contract may change before stable release.
 */
final readonly class LeaseDuration
{
    public function __construct(private int $milliseconds)
    {
        if ($milliseconds <= 0) {
            throw new InvalidArgumentException('Lease duration must be a positive number of milliseconds.');
        }
    }

    public function milliseconds(): int
    {
        return $this->milliseconds;
    }
}
