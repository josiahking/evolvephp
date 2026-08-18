<?php

declare(strict_types=1);

namespace Evolve\Core\Component;

use Evolve\Contracts\Component\CapabilityIdentifier;
use Evolve\Contracts\Component\ComponentIdentifier;

/**
 * Consumer-scoped explicit provider selection for component capability resolution.
 *
 * @experimental
 */
final readonly class CapabilityProviderSelection
{
    public function __construct(
        private ComponentIdentifier $consumer,
        private CapabilityIdentifier $capability,
        private ComponentIdentifier $provider,
    ) {}

    public function consumer(): ComponentIdentifier
    {
        return $this->consumer;
    }

    public function capability(): CapabilityIdentifier
    {
        return $this->capability;
    }

    public function provider(): ComponentIdentifier
    {
        return $this->provider;
    }
}
