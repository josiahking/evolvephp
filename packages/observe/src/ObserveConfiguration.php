<?php

declare(strict_types=1);

namespace Evolve\Observe;

final readonly class ObserveConfiguration
{
    public function __construct(
        private bool $enabled = false
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
