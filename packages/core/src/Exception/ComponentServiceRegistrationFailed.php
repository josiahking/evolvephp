<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Exception\LifecycleException;
use RuntimeException;
use Throwable;

/**
 * @internal Core implementation detail for failed component service-definition contribution.
 */
final class ComponentServiceRegistrationFailed extends RuntimeException implements LifecycleException
{
    private function __construct(
        private ComponentIdentifier $component,
        Throwable $previous,
    ) {
        parent::__construct('Component service registration failed.', 0, $previous);
    }

    public static function forComponent(ComponentIdentifier $component, Throwable $previous): self
    {
        return new self($component, $previous);
    }

    public function component(): ComponentIdentifier
    {
        return $this->component;
    }
}
