<?php

declare(strict_types=1);

namespace Evolve\Core\Component;

use Evolve\Contracts\Component\CapabilityCardinality;
use Evolve\Contracts\Component\CapabilityIdentifier;
use Evolve\Contracts\Component\CapabilityRequirement;
use Evolve\Contracts\Component\ComponentDependencyKind;
use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Core\Exception\ActiveComponentConflict;
use Evolve\Core\Exception\AmbiguousCapabilityProvider;
use Evolve\Core\Exception\ComponentDependencyCycle;
use Evolve\Core\Exception\DuplicateComponentIdentifier;
use Evolve\Core\Exception\InvalidCapabilityProviderSelection;
use Evolve\Core\Exception\MissingCapabilityProvider;
use Evolve\Core\Exception\MissingComponentDependency;
use InvalidArgumentException;

/**
 * Resolves active component declarations into a deterministic dependency-first graph.
 *
 * @experimental
 */
final class ComponentGraphResolver
{
    private const COMPONENT_KEY_PREFIX = 'component:';
    private const CAPABILITY_KEY_PREFIX = 'capability:';

    /**
     * @param list<ComponentGraphDeclaration> $declarations
     * @param list<CapabilityProviderSelection> $providerSelections
     */
    public function resolve(
        array $declarations,
        array $providerSelections = [],
    ): ResolvedComponentGraph {
        $declarations = $this->validatedDeclarationList($declarations);
        $providerSelections = $this->validatedSelectionList($providerSelections);

        $declarationsByIdentifier = $this->indexDeclarations($declarations);
        $orderedIdentifiers = $this->sortedIdentifierValues($declarationsByIdentifier);
        $providerIndex = $this->providerIndex($declarationsByIdentifier);
        $selectionsByConsumerCapability = $this->validateProviderSelections(
            $providerSelections,
            $declarationsByIdentifier,
        );

        $this->validateRequiredDependencies($orderedIdentifiers, $declarationsByIdentifier);
        $this->validateConflicts($orderedIdentifiers, $declarationsByIdentifier);

        $adjacency = [];
        $diagnosticEdges = [];
        $indegree = [];

        foreach ($orderedIdentifiers as $identifier) {
            $key = $this->componentKey($identifier);
            $adjacency[$key] = [];
            $diagnosticEdges[$key] = [];
            $indegree[$key] = 0;
        }

        $this->addDependencyEdges(
            $orderedIdentifiers,
            $declarationsByIdentifier,
            $adjacency,
            $diagnosticEdges,
            $indegree,
        );

        $resolvedProviders = $this->resolveCapabilities(
            $orderedIdentifiers,
            $declarationsByIdentifier,
            $providerIndex,
            $selectionsByConsumerCapability,
            $adjacency,
            $diagnosticEdges,
            $indegree,
        );

        $orderedDeclarations = $this->topologicalOrder(
            $orderedIdentifiers,
            $declarationsByIdentifier,
            $adjacency,
            $diagnosticEdges,
            $indegree,
        );

        return new ResolvedComponentGraph($orderedDeclarations, $resolvedProviders);
    }

    /**
     * @param array<mixed> $declarations
     * @return list<ComponentGraphDeclaration>
     */
    private function validatedDeclarationList(array $declarations): array
    {
        if (!array_is_list($declarations)) {
            throw new InvalidArgumentException('Component graph declarations must be a list.');
        }

        $validatedDeclarations = [];

        foreach ($declarations as $declaration) {
            if (!$declaration instanceof ComponentGraphDeclaration) {
                throw new InvalidArgumentException('Component graph declarations must contain ComponentGraphDeclaration objects.');
            }

            $validatedDeclarations[] = $declaration;
        }

        return $validatedDeclarations;
    }

    /**
     * @param array<mixed> $providerSelections
     * @return list<CapabilityProviderSelection>
     */
    private function validatedSelectionList(array $providerSelections): array
    {
        if (!array_is_list($providerSelections)) {
            throw new InvalidArgumentException('Capability provider selections must be a list.');
        }

        $validatedSelections = [];

        foreach ($providerSelections as $providerSelection) {
            if (!$providerSelection instanceof CapabilityProviderSelection) {
                throw new InvalidArgumentException('Capability provider selections must contain CapabilityProviderSelection objects.');
            }

            $validatedSelections[] = $providerSelection;
        }

        return $validatedSelections;
    }

    /**
     * @param list<ComponentGraphDeclaration> $declarations
     * @return array<string, ComponentGraphDeclaration>
     */
    private function indexDeclarations(array $declarations): array
    {
        $indexed = [];
        $duplicates = [];

        foreach ($declarations as $declaration) {
            $identifier = $declaration->identifier()->value();
            $identifierKey = $this->componentKey($identifier);

            if (isset($indexed[$identifierKey])) {
                $duplicates[$identifierKey] = $identifier;
                continue;
            }

            $indexed[$identifierKey] = $declaration;
        }

        if ($duplicates !== []) {
            $duplicateIdentifiers = array_values($duplicates);
            usort($duplicateIdentifiers, 'strcmp');

            throw new DuplicateComponentIdentifier(new ComponentIdentifier($duplicateIdentifiers[0]));
        }

        return $indexed;
    }

    /**
     * @param array<string, ComponentGraphDeclaration> $declarationsByIdentifier
     * @return list<string>
     */
    private function sortedIdentifierValues(array $declarationsByIdentifier): array
    {
        $identifiers = array_map(
            static fn(ComponentGraphDeclaration $declaration): string => $declaration->identifier()->value(),
            array_values($declarationsByIdentifier),
        );
        usort($identifiers, 'strcmp');

        return $identifiers;
    }

    /**
     * @param array<string, ComponentGraphDeclaration> $declarationsByIdentifier
     * @return array<string, list<ComponentGraphDeclaration>>
     */
    private function providerIndex(array $declarationsByIdentifier): array
    {
        $providers = [];

        foreach ($this->sortedIdentifierValues($declarationsByIdentifier) as $identifier) {
            $declaration = $declarationsByIdentifier[$this->componentKey($identifier)];

            foreach ($declaration->relations()->providedCapabilities() as $capability) {
                $providers[$this->capabilityKey($capability->value())][] = $declaration;
            }
        }

        ksort($providers);

        return $providers;
    }

    /**
     * @param list<CapabilityProviderSelection> $providerSelections
     * @param array<string, ComponentGraphDeclaration> $declarationsByIdentifier
     * @return array<string, array<string, ComponentIdentifier>>
     */
    private function validateProviderSelections(
        array $providerSelections,
        array $declarationsByIdentifier,
    ): array {
        usort(
            $providerSelections,
            static function (CapabilityProviderSelection $a, CapabilityProviderSelection $b): int {
                return strcmp($a->consumer()->value(), $b->consumer()->value())
                    ?: strcmp($a->capability()->value(), $b->capability()->value())
                    ?: strcmp($a->provider()->value(), $b->provider()->value());
            },
        );

        $selectionsByConsumerCapability = [];

        foreach ($providerSelections as $selection) {
            $consumer = $selection->consumer();
            $capability = $selection->capability();
            $provider = $selection->provider();
            $consumerValue = $consumer->value();
            $capabilityValue = $capability->value();
            $providerValue = $provider->value();
            $consumerKey = $this->componentKey($consumerValue);
            $capabilityKey = $this->capabilityKey($capabilityValue);
            $providerKey = $this->componentKey($providerValue);

            if (isset($selectionsByConsumerCapability[$consumerKey][$capabilityKey])) {
                throw new InvalidCapabilityProviderSelection(
                    $consumer,
                    $capability,
                    null,
                    InvalidCapabilityProviderSelection::REASON_DUPLICATE_SELECTION,
                );
            }

            if (!isset($declarationsByIdentifier[$consumerKey])) {
                throw new InvalidCapabilityProviderSelection(
                    $consumer,
                    $capability,
                    $provider,
                    InvalidCapabilityProviderSelection::REASON_INACTIVE_CONSUMER,
                );
            }

            $requirement = $this->requirementFor($declarationsByIdentifier[$consumerKey], $capability);

            if ($requirement === null) {
                throw new InvalidCapabilityProviderSelection(
                    $consumer,
                    $capability,
                    $provider,
                    InvalidCapabilityProviderSelection::REASON_CAPABILITY_NOT_REQUIRED,
                );
            }

            if ($requirement->cardinality() !== CapabilityCardinality::ExactlyOne) {
                throw new InvalidCapabilityProviderSelection(
                    $consumer,
                    $capability,
                    $provider,
                    InvalidCapabilityProviderSelection::REASON_UNSUPPORTED_CARDINALITY,
                );
            }

            if (!isset($declarationsByIdentifier[$providerKey])) {
                throw new InvalidCapabilityProviderSelection(
                    $consumer,
                    $capability,
                    $provider,
                    InvalidCapabilityProviderSelection::REASON_INACTIVE_PROVIDER,
                );
            }

            if (!$this->declarationProvides($declarationsByIdentifier[$providerKey], $capability)) {
                throw new InvalidCapabilityProviderSelection(
                    $consumer,
                    $capability,
                    $provider,
                    InvalidCapabilityProviderSelection::REASON_PROVIDER_DOES_NOT_PROVIDE_CAPABILITY,
                );
            }

            $selectionsByConsumerCapability[$consumerKey][$capabilityKey] = $provider;
        }

        return $selectionsByConsumerCapability;
    }

    private function requirementFor(
        ComponentGraphDeclaration $declaration,
        CapabilityIdentifier $capability,
    ): ?CapabilityRequirement {
        foreach ($declaration->relations()->requiredCapabilities() as $requirement) {
            if ($requirement->capability()->value() === $capability->value()) {
                return $requirement;
            }
        }

        return null;
    }

    private function declarationProvides(
        ComponentGraphDeclaration $declaration,
        CapabilityIdentifier $capability,
    ): bool {
        foreach ($declaration->relations()->providedCapabilities() as $providedCapability) {
            if ($providedCapability->value() === $capability->value()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $orderedIdentifiers
     * @param array<string, ComponentGraphDeclaration> $declarationsByIdentifier
     */
    private function validateRequiredDependencies(
        array $orderedIdentifiers,
        array $declarationsByIdentifier,
    ): void {
        foreach ($orderedIdentifiers as $consumerIdentifier) {
            $consumer = $declarationsByIdentifier[$this->componentKey($consumerIdentifier)];

            foreach ($consumer->relations()->dependencies() as $dependency) {
                if ($dependency->kind() !== ComponentDependencyKind::Required) {
                    continue;
                }

                if (!isset($declarationsByIdentifier[$this->componentKey($dependency->target()->value())])) {
                    throw new MissingComponentDependency($consumer->identifier(), $dependency->target());
                }
            }
        }
    }

    /**
     * @param list<string> $orderedIdentifiers
     * @param array<string, ComponentGraphDeclaration> $declarationsByIdentifier
     */
    private function validateConflicts(
        array $orderedIdentifiers,
        array $declarationsByIdentifier,
    ): void {
        foreach ($orderedIdentifiers as $sourceIdentifier) {
            $source = $declarationsByIdentifier[$this->componentKey($sourceIdentifier)];

            foreach ($source->relations()->conflicts() as $conflict) {
                $targetIdentifier = $conflict->target()->value();
                $targetKey = $this->componentKey($targetIdentifier);

                if (isset($declarationsByIdentifier[$targetKey])) {
                    throw new ActiveComponentConflict($source->identifier(), $declarationsByIdentifier[$targetKey]->identifier());
                }
            }
        }
    }

    /**
     * @param list<string> $orderedIdentifiers
     * @param array<string, ComponentGraphDeclaration> $declarationsByIdentifier
     * @param array<string, array<string, string>> $adjacency
     * @param array<string, array<string, string>> $diagnosticEdges
     * @param array<string, int> $indegree
     */
    private function addDependencyEdges(
        array $orderedIdentifiers,
        array $declarationsByIdentifier,
        array &$adjacency,
        array &$diagnosticEdges,
        array &$indegree,
    ): void {
        foreach ($orderedIdentifiers as $consumerIdentifier) {
            $consumer = $declarationsByIdentifier[$this->componentKey($consumerIdentifier)];

            foreach ($consumer->relations()->dependencies() as $dependency) {
                $targetIdentifier = $dependency->target()->value();

                if (!isset($declarationsByIdentifier[$this->componentKey($targetIdentifier)])) {
                    continue;
                }

                $this->addEdge($targetIdentifier, $consumerIdentifier, $adjacency, $diagnosticEdges, $indegree);
            }
        }
    }

    /**
     * @param list<string> $orderedIdentifiers
     * @param array<string, ComponentGraphDeclaration> $declarationsByIdentifier
     * @param array<string, list<ComponentGraphDeclaration>> $providerIndex
     * @param array<string, array<string, ComponentIdentifier>> $selectionsByConsumerCapability
     * @param array<string, array<string, string>> $adjacency
     * @param array<string, array<string, string>> $diagnosticEdges
     * @param array<string, int> $indegree
     * @return array<string, array<string, list<ComponentGraphDeclaration>>>
     */
    private function resolveCapabilities(
        array $orderedIdentifiers,
        array $declarationsByIdentifier,
        array $providerIndex,
        array $selectionsByConsumerCapability,
        array &$adjacency,
        array &$diagnosticEdges,
        array &$indegree,
    ): array {
        $resolvedProviders = [];

        foreach ($orderedIdentifiers as $consumerIdentifier) {
            $consumer = $declarationsByIdentifier[$this->componentKey($consumerIdentifier)];

            foreach ($consumer->relations()->requiredCapabilities() as $requirement) {
                $capability = $requirement->capability();
                $capabilityValue = $capability->value();
                $capabilityKey = $this->capabilityKey($capabilityValue);
                $consumerKey = $this->componentKey($consumerIdentifier);
                $providers = $providerIndex[$capabilityKey] ?? [];

                if ($providers === []) {
                    throw new MissingCapabilityProvider($consumer->identifier(), $capability);
                }

                if ($requirement->cardinality() === CapabilityCardinality::ExactlyOne) {
                    $providers = [$this->resolveExactlyOneProvider(
                        $consumer,
                        $capability,
                        $providers,
                        $selectionsByConsumerCapability[$consumerKey][$capabilityKey] ?? null,
                    )];
                }

                $resolvedProviders[$consumerKey][$capabilityKey] = $providers;

                foreach ($providers as $provider) {
                    $this->addEdge(
                        $provider->identifier()->value(),
                        $consumerIdentifier,
                        $adjacency,
                        $diagnosticEdges,
                        $indegree,
                    );
                }
            }
        }

        return $resolvedProviders;
    }

    /**
     * @param list<ComponentGraphDeclaration> $providers
     */
    private function resolveExactlyOneProvider(
        ComponentGraphDeclaration $consumer,
        CapabilityIdentifier $capability,
        array $providers,
        ?ComponentIdentifier $selection,
    ): ComponentGraphDeclaration {
        if ($selection !== null) {
            return $providers[$this->providerOffset($providers, $selection->value())];
        }

        if (count($providers) === 1) {
            return $providers[0];
        }

        throw new AmbiguousCapabilityProvider(
            $consumer->identifier(),
            $capability,
            array_map(
                static fn(ComponentGraphDeclaration $provider): ComponentIdentifier => $provider->identifier(),
                $providers,
            ),
        );
    }

    /**
     * @param list<ComponentGraphDeclaration> $providers
     */
    private function providerOffset(array $providers, string $identifier): int
    {
        foreach ($providers as $offset => $provider) {
            if ($provider->identifier()->value() === $identifier) {
                return $offset;
            }
        }

        throw new InvalidArgumentException('Selected provider was not present in the provider list.');
    }

    /**
     * @param array<string, array<string, string>> $adjacency
     * @param array<string, array<string, string>> $diagnosticEdges
     * @param array<string, int> $indegree
     */
    private function addEdge(
        string $prerequisite,
        string $dependent,
        array &$adjacency,
        array &$diagnosticEdges,
        array &$indegree,
    ): void {
        if ($prerequisite === $dependent) {
            return;
        }

        $prerequisiteKey = $this->componentKey($prerequisite);
        $dependentKey = $this->componentKey($dependent);

        if (isset($adjacency[$prerequisiteKey][$dependentKey])) {
            return;
        }

        $adjacency[$prerequisiteKey][$dependentKey] = $dependent;
        $diagnosticEdges[$dependentKey][$prerequisiteKey] = $prerequisite;
        ++$indegree[$dependentKey];
    }

    /**
     * @param list<string> $orderedIdentifiers
     * @param array<string, ComponentGraphDeclaration> $declarationsByIdentifier
     * @param array<string, array<string, string>> $adjacency
     * @param array<string, array<string, string>> $diagnosticEdges
     * @param array<string, int> $indegree
     * @return list<ComponentGraphDeclaration>
     */
    private function topologicalOrder(
        array $orderedIdentifiers,
        array $declarationsByIdentifier,
        array $adjacency,
        array $diagnosticEdges,
        array $indegree,
    ): array {
        $ready = [];

        foreach ($orderedIdentifiers as $identifier) {
            if ($indegree[$this->componentKey($identifier)] === 0) {
                $ready[] = $identifier;
            }
        }

        $orderedDeclarations = [];

        while ($ready !== []) {
            usort($ready, 'strcmp');
            $current = array_shift($ready);
            $currentKey = $this->componentKey($current);

            $orderedDeclarations[] = $declarationsByIdentifier[$currentKey];

            $dependents = array_values($adjacency[$currentKey]);
            usort($dependents, 'strcmp');

            foreach ($dependents as $dependent) {
                $dependentKey = $this->componentKey($dependent);
                --$indegree[$dependentKey];

                if ($indegree[$dependentKey] === 0) {
                    $ready[] = $dependent;
                }
            }
        }

        if (count($orderedDeclarations) !== count($orderedIdentifiers)) {
            throw new ComponentDependencyCycle($this->detectCycle($orderedIdentifiers, $declarationsByIdentifier, $diagnosticEdges));
        }

        return $orderedDeclarations;
    }

    /**
     * @param list<string> $orderedIdentifiers
     * @param array<string, ComponentGraphDeclaration> $declarationsByIdentifier
     * @param array<string, array<string, string>> $diagnosticEdges
     * @return list<ComponentIdentifier>
     */
    private function detectCycle(
        array $orderedIdentifiers,
        array $declarationsByIdentifier,
        array $diagnosticEdges,
    ): array {
        $state = [];
        $stack = [];

        foreach ($orderedIdentifiers as $identifier) {
            $state[$this->componentKey($identifier)] = 'unvisited';
        }

        foreach ($orderedIdentifiers as $identifier) {
            if ($state[$this->componentKey($identifier)] !== 'unvisited') {
                continue;
            }

            $cycle = $this->visitForCycle($identifier, $diagnosticEdges, $state, $stack);

            if ($cycle !== null) {
                return $this->canonicalCycle($cycle, $declarationsByIdentifier);
            }
        }

        throw new InvalidArgumentException('Unable to extract dependency cycle.');
    }

    /**
     * @param array<string, array<string, string>> $diagnosticEdges
     * @param array<string, string> $state
     * @param list<string> $stack
     * @return list<string>|null
     */
    private function visitForCycle(
        string $identifier,
        array $diagnosticEdges,
        array &$state,
        array &$stack,
    ): ?array {
        $identifierKey = $this->componentKey($identifier);
        $state[$identifierKey] = 'visiting';
        $stack[] = $identifier;

        $neighbors = array_values($diagnosticEdges[$identifierKey] ?? []);
        usort($neighbors, 'strcmp');

        foreach ($neighbors as $neighbor) {
            $neighborKey = $this->componentKey($neighbor);

            if ($state[$neighborKey] === 'visiting') {
                $offset = array_search($neighbor, $stack, true);

                if ($offset === false) {
                    throw new InvalidArgumentException('Cycle stack is inconsistent.');
                }

                return array_merge(array_slice($stack, $offset), [$neighbor]);
            }

            if ($state[$neighborKey] === 'unvisited') {
                $cycle = $this->visitForCycle($neighbor, $diagnosticEdges, $state, $stack);

                if ($cycle !== null) {
                    return $cycle;
                }
            }
        }

        array_pop($stack);
        $state[$identifierKey] = 'visited';

        return null;
    }

    /**
     * @param list<string> $cycle
     * @param array<string, ComponentGraphDeclaration> $declarationsByIdentifier
     * @return list<ComponentIdentifier>
     */
    private function canonicalCycle(array $cycle, array $declarationsByIdentifier): array
    {
        $members = array_slice($cycle, 0, -1);
        $minimum = $members[0];
        $minimumOffset = 0;

        foreach ($members as $offset => $member) {
            if (strcmp($member, $minimum) < 0) {
                $minimum = $member;
                $minimumOffset = $offset;
            }
        }

        $rotated = array_merge(
            array_slice($members, $minimumOffset),
            array_slice($members, 0, $minimumOffset),
        );
        $rotated[] = $rotated[0];

        return array_map(
            fn(string $identifier): ComponentIdentifier => $declarationsByIdentifier[$this->componentKey($identifier)]->identifier(),
            $rotated,
        );
    }

    private function componentKey(string $identifier): string
    {
        return self::COMPONENT_KEY_PREFIX . $identifier;
    }

    private function capabilityKey(string $capability): string
    {
        return self::CAPABILITY_KEY_PREFIX . $capability;
    }
}
