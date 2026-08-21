<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Evolve\Contracts\Component\ComponentIdentifier;
use RuntimeException;
use Throwable;

/**
 * Raised when an enabled component entry point cannot be constructed before lifecycle callbacks run.
 *
 * @internal Core implementation detail for component bootstrap preparation.
 */
final class ComponentEntryPointCreationFailed extends RuntimeException
{
    public function __construct(
        private ComponentIdentifier $component,
        Throwable $previous,
    ) {
        parent::__construct('Component entry-point creation failed.', 0, $previous);
    }

    public function component(): ComponentIdentifier
    {
        return $this->component;
    }
}
