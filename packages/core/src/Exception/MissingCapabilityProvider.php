<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Evolve\Contracts\Component\CapabilityIdentifier;
use Evolve\Contracts\Component\ComponentIdentifier;

/**
 * Raised when an active component requires a capability with no active provider.
 *
 * @experimental
 */
final class MissingCapabilityProvider extends ComponentGraphResolutionFailed
{
    public function __construct(
        private ComponentIdentifier $consumer,
        private CapabilityIdentifier $capability,
    ) {
        parent::__construct('Required capability provider is missing.');
    }

    public function consumer(): ComponentIdentifier
    {
        return $this->consumer;
    }

    public function capability(): CapabilityIdentifier
    {
        return $this->capability;
    }
}
