<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Evolve\Contracts\Component\ComponentIdentifier;

/**
 * Raised when an active component declares a conflict with another active component.
 *
 * @experimental
 */
final class ActiveComponentConflict extends ComponentGraphResolutionFailed
{
    public function __construct(
        private ComponentIdentifier $source,
        private ComponentIdentifier $target,
    ) {
        parent::__construct('Active component conflict.');
    }

    public function source(): ComponentIdentifier
    {
        return $this->source;
    }

    public function target(): ComponentIdentifier
    {
        return $this->target;
    }
}
