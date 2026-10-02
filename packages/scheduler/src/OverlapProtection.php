<?php

declare(strict_types=1);

namespace Evolve\Scheduler;

use Evolve\Lock\Contracts\LeaseDuration;

/** @experimental This API may change before stable release. */
final readonly class OverlapProtection
{
    private function __construct(private ?LeaseDuration $duration) {}

    public static function allow(): self
    {
        return new self(null);
    }

    public static function prevent(LeaseDuration $duration): self
    {
        return new self($duration);
    }

    public function preventsOverlap(): bool
    {
        return $this->duration !== null;
    }

    public function duration(): ?LeaseDuration
    {
        return $this->duration;
    }
}
