<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Evolve\Contracts\Component\ComponentIdentifier;

/**
 * Raised when a required component dependency is not active.
 *
 * @experimental
 */
final class MissingComponentDependency extends ComponentGraphResolutionFailed
{
    public function __construct(
        private ComponentIdentifier $consumer,
        private ComponentIdentifier $dependency,
    ) {
        parent::__construct('Required component dependency is missing.');
    }

    public function consumer(): ComponentIdentifier
    {
        return $this->consumer;
    }

    public function dependency(): ComponentIdentifier
    {
        return $this->dependency;
    }
}
