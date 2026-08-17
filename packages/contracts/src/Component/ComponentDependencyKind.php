<?php

declare(strict_types=1);

namespace Evolve\Contracts\Component;

/**
 * Declares whether a component dependency is required or optional.
 *
 * @experimental
 */
enum ComponentDependencyKind
{
    case Required;
    case Optional;
}
