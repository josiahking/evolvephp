<?php

declare(strict_types=1);

namespace Evolve\Observe\Http\Internal;

final class HttpServerMetricState
{
    public function __construct(
        private int $started,
        private bool $activeIncremented,
        /**
         * @var array<string, string>
         */
        private array $attributes,
    ) {}

    public function started(): int
    {
        return $this->started;
    }

    public function activeIncremented(): bool
    {
        return $this->activeIncremented;
    }

    public function markActiveIncremented(): void
    {
        $this->activeIncremented = true;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }
}
