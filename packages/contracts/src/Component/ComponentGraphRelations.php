<?php

declare(strict_types=1);

namespace Evolve\Contracts\Component;

use InvalidArgumentException;

/**
 * Immutable declarative dependency, conflict and capability relations.
 *
 * @experimental
 */
final readonly class ComponentGraphRelations
{
    /** @var list<ComponentDependency> */
    private array $dependencies;

    /** @var list<ComponentConflict> */
    private array $conflicts;

    /** @var list<CapabilityRequirement> */
    private array $requiredCapabilities;

    /** @var list<CapabilityIdentifier> */
    private array $providedCapabilities;

    /**
     * @param list<ComponentDependency> $dependencies
     * @param list<ComponentConflict> $conflicts
     * @param list<CapabilityRequirement> $requiredCapabilities
     * @param list<CapabilityIdentifier> $providedCapabilities
     */
    public function __construct(
        array $dependencies = [],
        array $conflicts = [],
        array $requiredCapabilities = [],
        array $providedCapabilities = [],
    ) {
        $this->assertListOf($dependencies, ComponentDependency::class, 'dependencies');
        $this->assertListOf($conflicts, ComponentConflict::class, 'conflicts');
        $this->assertListOf($requiredCapabilities, CapabilityRequirement::class, 'requiredCapabilities');
        $this->assertListOf($providedCapabilities, CapabilityIdentifier::class, 'providedCapabilities');

        $this->assertStructuralInvariants($dependencies, $conflicts, $requiredCapabilities, $providedCapabilities);

        usort($dependencies, static fn(ComponentDependency $a, ComponentDependency $b): int => strcmp($a->target()->value(), $b->target()->value()));
        usort($conflicts, static fn(ComponentConflict $a, ComponentConflict $b): int => strcmp($a->target()->value(), $b->target()->value()));
        usort($requiredCapabilities, static fn(CapabilityRequirement $a, CapabilityRequirement $b): int => strcmp($a->capability()->value(), $b->capability()->value()));
        usort($providedCapabilities, static fn(CapabilityIdentifier $a, CapabilityIdentifier $b): int => strcmp($a->value(), $b->value()));

        $this->dependencies = $dependencies;
        $this->conflicts = $conflicts;
        $this->requiredCapabilities = $requiredCapabilities;
        $this->providedCapabilities = $providedCapabilities;
    }

    /**
     * @return list<ComponentDependency>
     */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * @return list<ComponentConflict>
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * @return list<CapabilityRequirement>
     */
    public function requiredCapabilities(): array
    {
        return $this->requiredCapabilities;
    }

    /**
     * @return list<CapabilityIdentifier>
     */
    public function providedCapabilities(): array
    {
        return $this->providedCapabilities;
    }

    /**
     * @template T of object
     *
     * @param array<mixed> $values
     * @param class-string<T> $expectedClass
     */
    private function assertListOf(array $values, string $expectedClass, string $name): void
    {
        if (!array_is_list($values)) {
            throw new InvalidArgumentException($name . ' must be a list.');
        }

        foreach ($values as $value) {
            if (!$value instanceof $expectedClass) {
                throw new InvalidArgumentException($name . ' contains an invalid element.');
            }
        }
    }

    /**
     * @param list<ComponentDependency> $dependencies
     * @param list<ComponentConflict> $conflicts
     * @param list<CapabilityRequirement> $requiredCapabilities
     * @param list<CapabilityIdentifier> $providedCapabilities
     */
    private function assertStructuralInvariants(
        array $dependencies,
        array $conflicts,
        array $requiredCapabilities,
        array $providedCapabilities,
    ): void {
        $dependencyTargets = [];

        foreach ($dependencies as $dependency) {
            $target = $dependency->target()->value();

            if (array_key_exists($target, $dependencyTargets)) {
                throw new InvalidArgumentException('Duplicate dependency target.');
            }

            $dependencyTargets[$target] = $dependency->kind();
        }

        $conflictTargets = [];

        foreach ($conflicts as $conflict) {
            $target = $conflict->target()->value();

            if (array_key_exists($target, $conflictTargets)) {
                throw new InvalidArgumentException('Duplicate conflict target.');
            }

            if (array_key_exists($target, $dependencyTargets)) {
                throw new InvalidArgumentException('Component cannot be both dependency and conflict.');
            }

            $conflictTargets[$target] = true;
        }

        $requiredCapabilityIdentifiers = [];

        foreach ($requiredCapabilities as $requirement) {
            $capability = $requirement->capability()->value();

            if (array_key_exists($capability, $requiredCapabilityIdentifiers)) {
                throw new InvalidArgumentException('Duplicate required capability.');
            }

            $requiredCapabilityIdentifiers[$capability] = true;
        }

        $providedCapabilityIdentifiers = [];

        foreach ($providedCapabilities as $capabilityIdentifier) {
            $capability = $capabilityIdentifier->value();

            if (array_key_exists($capability, $providedCapabilityIdentifiers)) {
                throw new InvalidArgumentException('Duplicate provided capability.');
            }

            $providedCapabilityIdentifiers[$capability] = true;
        }
    }
}
