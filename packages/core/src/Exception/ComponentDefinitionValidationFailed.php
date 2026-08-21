<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Evolve\Contracts\Component\ComponentIdentifier;
use RuntimeException;
use Throwable;

/**
 * Raised when an enabled component definition fails validation before entry-point creation.
 *
 * @internal Core implementation detail for component bootstrap preparation.
 */
final class ComponentDefinitionValidationFailed extends RuntimeException
{
    public function __construct(
        private ComponentIdentifier $component,
        Throwable $previous,
    ) {
        parent::__construct('Component definition validation failed.', 0, $previous);
    }

    public function component(): ComponentIdentifier
    {
        return $this->component;
    }
}
