<?php

declare(strict_types=1);

namespace Evolve\Contracts\Component;

/**
 * Declarative dependency on another component.
 *
 * @experimental
 */
final readonly class ComponentDependency
{
    public function __construct(
        private ComponentIdentifier $target,
        private ComponentDependencyKind $kind,
    ) {}

    public function target(): ComponentIdentifier
    {
        return $this->target;
    }

    public function kind(): ComponentDependencyKind
    {
        return $this->kind;
    }
}
