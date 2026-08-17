<?php

declare(strict_types=1);

namespace Evolve\Contracts\Component;

/**
 * Declarative conflict with another component.
 *
 * @experimental
 */
final readonly class ComponentConflict
{
    public function __construct(private ComponentIdentifier $target) {}

    public function target(): ComponentIdentifier
    {
        return $this->target;
    }
}
