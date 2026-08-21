<?php

declare(strict_types=1);

namespace Evolve\Core\Component\Lifecycle;

use Evolve\Contracts\Component\ComponentIdentifier;
use Throwable;

/**
 * @internal Attributed component lifecycle cleanup or shutdown failure.
 */
final readonly class ComponentLifecycleFailure
{
    public function __construct(
        private ComponentIdentifier $component,
        private Throwable $throwable,
    ) {}

    public function component(): ComponentIdentifier
    {
        return $this->component;
    }

    public function throwable(): Throwable
    {
        return $this->throwable;
    }
}
