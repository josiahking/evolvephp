<?php

declare(strict_types=1);

namespace Evolve\Contracts\Component;

/**
 * Declarative requirement for a capability provided by another component.
 *
 * @experimental
 */
final readonly class CapabilityRequirement
{
    public function __construct(
        private CapabilityIdentifier $capability,
        private CapabilityCardinality $cardinality = CapabilityCardinality::ExactlyOne,
    ) {}

    public function capability(): CapabilityIdentifier
    {
        return $this->capability;
    }

    public function cardinality(): CapabilityCardinality
    {
        return $this->cardinality;
    }
}
