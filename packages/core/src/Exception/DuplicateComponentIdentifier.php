<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Evolve\Contracts\Component\ComponentIdentifier;

/**
 * Raised when more than one active component declares the same identifier.
 *
 * @experimental
 */
final class DuplicateComponentIdentifier extends ComponentGraphResolutionFailed
{
    public function __construct(private ComponentIdentifier $identifier)
    {
        parent::__construct('Duplicate active component identifier.');
    }

    public function identifier(): ComponentIdentifier
    {
        return $this->identifier;
    }
}
