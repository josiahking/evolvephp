<?php

declare(strict_types=1);

namespace Evolve\Core\Component;

use Evolve\Contracts\Component\ComponentDefinition;
use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Configuration\Configuration;
use Evolve\Core\Component\Lifecycle\ComponentLifecycleBinding;
use Evolve\Core\Component\Lifecycle\ComponentLifecycleCoordinator;
use Evolve\Core\Exception\ComponentDefinitionValidationFailed;
use Evolve\Core\Exception\ComponentEntryPointCreationFailed;
use Evolve\Core\Exception\DuplicateComponentIdentifier;
use Evolve\Core\Exception\InvalidConfiguration;
use InvalidArgumentException;
use Throwable;

/**
 * Prepares explicitly enabled component definitions for the existing lifecycle coordinator.
 *
 * @experimental
 */
final class ComponentBootstrapper
{
    private const ENABLED_COMPONENTS_PATH = 'evolve.components.enabled';
    private const COMPONENT_KEY_PREFIX = 'component:';

    /**
     * @var array<string, ComponentDefinition>
     */
    private array $definitionsByIdentifier;

    /**
     * @var list<CapabilityProviderSelection>
     */
    private array $providerSelections;

    /**
     * @param iterable<mixed> $definitions
     * @param list<CapabilityProviderSelection> $providerSelections
     */
    public function __construct(
        iterable $definitions,
        array $providerSelections = [],
    ) {
        $this->definitionsByIdentifier = $this->indexDefinitions($definitions);
        $this->providerSelections = $this->validateProviderSelections($providerSelections);
    }

    public function validateConfiguration(Configuration $configuration): void
    {
        $this->enabledIdentifiers($configuration);
    }

    public function prepare(Configuration $configuration): ComponentLifecycleCoordinator
    {
        $enabledDefinitions = array_map(
            fn(ComponentIdentifier $identifier): ComponentDefinition => $this->definitionsByIdentifier[$this->componentKey($identifier->value())],
            $this->enabledIdentifiers($configuration),
        );

        $firstValidationFailure = null;

        foreach ($enabledDefinitions as $definition) {
            try {
                $definition->validate();
            } catch (Throwable $exception) {
                $firstValidationFailure ??= new ComponentDefinitionValidationFailed($definition->identifier(), $exception);
            }
        }

        if ($firstValidationFailure !== null) {
            throw $firstValidationFailure;
        }

        $graph = (new ComponentGraphResolver())->resolve(
            array_map(
                static fn(ComponentDefinition $definition): ComponentGraphDeclaration => $definition->graphDeclaration(),
                $enabledDefinitions,
            ),
            $this->providerSelections,
        );

        $definitionsByDeclaration = [];

        foreach ($enabledDefinitions as $definition) {
            $definitionsByDeclaration[spl_object_id($definition->graphDeclaration())] = $definition;
        }

        $bindings = [];

        foreach ($graph->orderedDeclarations() as $declaration) {
            $definition = $definitionsByDeclaration[spl_object_id($declaration)];

            try {
                $entryPoint = $definition->createEntryPoint();
            } catch (Throwable $exception) {
                throw new ComponentEntryPointCreationFailed($definition->identifier(), $exception);
            }

            $bindings[] = new ComponentLifecycleBinding($declaration, $entryPoint);
        }

        return new ComponentLifecycleCoordinator($graph, $bindings);
    }

    /**
     * @param iterable<mixed> $definitions
     * @return array<string, ComponentDefinition>
     */
    private function indexDefinitions(iterable $definitions): array
    {
        $indexed = [];
        $duplicates = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof ComponentDefinition) {
                throw new InvalidArgumentException('Component bootstrap definitions must contain ComponentDefinition objects.');
            }

            $identifier = $definition->identifier()->value();
            $key = $this->componentKey($identifier);

            if (isset($indexed[$key])) {
                $duplicates[$key] = $identifier;
                continue;
            }

            $indexed[$key] = $definition;
        }

        if ($duplicates !== []) {
            $duplicateIdentifiers = array_values($duplicates);
            usort($duplicateIdentifiers, 'strcmp');

            throw new DuplicateComponentIdentifier(new ComponentIdentifier($duplicateIdentifiers[0]));
        }

        ksort($indexed);

        return $indexed;
    }

    /**
     * @param array<mixed> $providerSelections
     * @return list<CapabilityProviderSelection>
     */
    private function validateProviderSelections(array $providerSelections): array
    {
        if (! array_is_list($providerSelections)) {
            throw new InvalidArgumentException('Capability provider selections must be a list.');
        }

        foreach ($providerSelections as $providerSelection) {
            if (! $providerSelection instanceof CapabilityProviderSelection) {
                throw new InvalidArgumentException('Capability provider selections must contain CapabilityProviderSelection objects.');
            }
        }

        return $providerSelections;
    }

    /**
     * @return list<ComponentIdentifier>
     */
    private function enabledIdentifiers(Configuration $configuration): array
    {
        if (! $configuration->has(self::ENABLED_COMPONENTS_PATH)) {
            return [];
        }

        $configured = $configuration->get(self::ENABLED_COMPONENTS_PATH);

        if (! is_array($configured) || ! array_is_list($configured)) {
            throw new InvalidConfiguration('Enabled component configuration must be a list.');
        }

        $identifiers = [];
        $seen = [];

        foreach ($configured as $value) {
            if (! is_string($value)) {
                throw new InvalidConfiguration('Enabled component identifiers must be strings.');
            }

            try {
                $identifier = new ComponentIdentifier($value);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidConfiguration('Enabled component identifier is malformed.', 0, $exception);
            }

            $key = $this->componentKey($identifier->value());

            if (isset($seen[$key])) {
                throw new InvalidConfiguration('Enabled component identifier is duplicated.');
            }

            if (! isset($this->definitionsByIdentifier[$key])) {
                throw new InvalidConfiguration('Enabled component identifier is unknown.');
            }

            $seen[$key] = true;
            $identifiers[] = $identifier;
        }

        return $identifiers;
    }

    private function componentKey(string $identifier): string
    {
        return self::COMPONENT_KEY_PREFIX . $identifier;
    }
}
