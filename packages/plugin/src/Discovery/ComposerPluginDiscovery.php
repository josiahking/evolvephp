<?php

declare(strict_types=1);

namespace Evolve\Plugin\Discovery;

use Evolve\Contracts\Component\CapabilityCardinality;
use Evolve\Contracts\Component\CapabilityIdentifier;
use Evolve\Contracts\Component\CapabilityRequirement;
use Evolve\Contracts\Component\ComponentConflict;
use Evolve\Contracts\Component\ComponentDependency;
use Evolve\Contracts\Component\ComponentDependencyKind;
use Evolve\Contracts\Component\ComponentGraphRelations;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Plugin\Exception\ComposerPluginMetadataUnavailable;
use Evolve\Plugin\Exception\DuplicateComposerPluginIdentifier;
use Evolve\Plugin\Exception\MalformedComposerPluginMetadata;
use Evolve\Plugin\PluginDefinition;
use Evolve\Plugin\PluginDescriptor;
use InvalidArgumentException;
use JsonException;
use stdClass;
use Throwable;

/**
 * Discovers packaged EvolvePHP plugins from Composer 2 installed.json metadata.
 *
 * @experimental
 */
final readonly class ComposerPluginDiscovery
{
    public function __construct(private string $installedJsonPath) {}

    /**
     * @return list<PluginDefinition>
     */
    public function discover(): array
    {
        if (str_contains($this->installedJsonPath, '://')) {
            throw new ComposerPluginMetadataUnavailable('Composer plugin metadata path must be a local filesystem path.');
        }

        $contents = @file_get_contents($this->installedJsonPath);

        if (!is_string($contents)) {
            throw new ComposerPluginMetadataUnavailable('Composer plugin metadata is unavailable at ' . $this->installedJsonPath . '.');
        }

        try {
            $installed = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MalformedComposerPluginMetadata('Composer plugin metadata is not valid JSON at ' . $this->installedJsonPath . '.', 0, $exception);
        }

        if (!$installed instanceof stdClass || !property_exists($installed, 'packages') || !is_array($installed->packages) || !array_is_list($installed->packages)) {
            throw new MalformedComposerPluginMetadata('Composer plugin metadata must use Composer 2 installed.json packages list at ' . $this->installedJsonPath . '.');
        }

        $definitions = [];
        $seenIdentifiers = [];

        foreach ($installed->packages as $offset => $package) {
            if (!$package instanceof stdClass) {
                throw new MalformedComposerPluginMetadata('Composer package entry at offset ' . $offset . ' must be an object.');
            }

            $packageName = property_exists($package, 'name') ? $package->name : null;

            if (!is_string($packageName) || trim($packageName) === '') {
                throw new MalformedComposerPluginMetadata('Composer package entry at offset ' . $offset . ' must have a non-empty string name.');
            }

            $metadata = $this->pluginMetadata($package, $packageName);

            if ($metadata === null) {
                continue;
            }

            if (array_key_exists($packageName, $seenIdentifiers)) {
                throw new DuplicateComposerPluginIdentifier('Duplicate Composer plugin identifier "' . $packageName . '" in ' . $this->installedJsonPath . '.');
            }

            $seenIdentifiers[$packageName] = true;
            $definitions[] = $this->definitionFromMetadata($packageName, $metadata);
        }

        usort(
            $definitions,
            static fn(PluginDefinition $left, PluginDefinition $right): int => strcmp(
                $left->identifier()->value(),
                $right->identifier()->value(),
            ),
        );

        return $definitions;
    }

    /**
     * @return stdClass|null
     */
    private function pluginMetadata(stdClass $package, string $packageName): ?stdClass
    {
        if (!property_exists($package, 'extra')) {
            return null;
        }

        if (!$package->extra instanceof stdClass) {
            return null;
        }

        if (!property_exists($package->extra, 'evolvephp')) {
            return null;
        }

        if (!$package->extra->evolvephp instanceof stdClass) {
            throw $this->malformed($packageName, 'extra.evolvephp must be an object.');
        }

        if (!property_exists($package->extra->evolvephp, 'plugin')) {
            return null;
        }

        if (!$package->extra->evolvephp->plugin instanceof stdClass) {
            throw $this->malformed($packageName, 'extra.evolvephp.plugin must be an object.');
        }

        return $package->extra->evolvephp->plugin;
    }

    private function definitionFromMetadata(string $packageName, stdClass $metadata): PluginDefinition
    {
        $allowedKeys = ['schema', 'type', 'name', 'evolve_major', 'entry_point', 'graph'];
        $metadataProperties = get_object_vars($metadata);

        foreach (array_keys($metadataProperties) as $key) {
            if ($key === 'identifier') {
                throw $this->malformed($packageName, 'Composer package name is the authoritative component/plugin identifier; extra.evolvephp.plugin must not contain identifier.');
            }

            if (!in_array($key, $allowedKeys, true)) {
                throw $this->malformed($packageName, 'extra.evolvephp.plugin contains unsupported key.');
            }
        }

        foreach (['schema', 'type', 'name', 'evolve_major', 'entry_point'] as $requiredKey) {
            if (!property_exists($metadata, $requiredKey)) {
                throw $this->malformed($packageName, 'extra.evolvephp.plugin is missing required key "' . $requiredKey . '".');
            }
        }

        if ($metadata->schema !== 1) {
            throw $this->malformed($packageName, 'extra.evolvephp.plugin schema must be integer 1.');
        }

        if ($metadata->type !== 'plugin') {
            throw $this->malformed($packageName, 'extra.evolvephp.plugin type must be "plugin".');
        }

        if (!is_string($metadata->name) || trim($metadata->name) === '') {
            throw $this->malformed($packageName, 'extra.evolvephp.plugin name must be a non-empty string.');
        }

        if (!is_int($metadata->evolve_major) || $metadata->evolve_major < 1) {
            throw $this->malformed($packageName, 'extra.evolvephp.plugin evolve_major must be an integer greater than or equal to 1.');
        }

        if (!is_string($metadata->entry_point) || trim($metadata->entry_point) === '') {
            throw $this->malformed($packageName, 'extra.evolvephp.plugin entry_point must be a non-empty string.');
        }

        try {
            $identifier = new ComponentIdentifier($packageName);
            $relations = $this->graphRelations($packageName, $metadata);

            return new PluginDefinition(
                new PluginDescriptor($identifier, $metadata->name, $metadata->evolve_major, $relations),
                $metadata->entry_point,
            );
        } catch (InvalidArgumentException $exception) {
            throw $this->malformed($packageName, $exception->getMessage(), $exception);
        }
    }

    private function graphRelations(string $packageName, stdClass $metadata): ComponentGraphRelations
    {
        if (!property_exists($metadata, 'graph')) {
            return new ComponentGraphRelations();
        }

        $graph = $metadata->graph;

        if (!$graph instanceof stdClass) {
            throw $this->malformed($packageName, 'extra.evolvephp.plugin.graph must be an object.');
        }

        $allowedKeys = ['dependencies', 'conflicts', 'requires', 'provides'];
        $graphProperties = get_object_vars($graph);

        foreach (array_keys($graphProperties) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                throw $this->malformed($packageName, 'extra.evolvephp.plugin.graph contains unsupported key.');
            }
        }

        return new ComponentGraphRelations(
            $this->dependencies($packageName, property_exists($graph, 'dependencies') ? $graph->dependencies : []),
            $this->conflicts($packageName, property_exists($graph, 'conflicts') ? $graph->conflicts : []),
            $this->requirements($packageName, property_exists($graph, 'requires') ? $graph->requires : []),
            $this->providers($packageName, property_exists($graph, 'provides') ? $graph->provides : []),
        );
    }

    /**
     * @param mixed $dependencies
     * @return list<ComponentDependency>
     */
    private function dependencies(string $packageName, mixed $dependencies): array
    {
        if (!is_array($dependencies) || !array_is_list($dependencies)) {
            throw $this->malformed($packageName, 'graph.dependencies must be a list.');
        }

        return array_map(function (mixed $dependency) use ($packageName): ComponentDependency {
            if (!$dependency instanceof stdClass || !$this->containsExactlyProperties($dependency, ['component', 'kind'])) {
                throw $this->malformed($packageName, 'graph.dependencies entries must contain only component and kind.');
            }

            if (!is_string($dependency->component)) {
                throw $this->malformed($packageName, 'graph.dependencies component must be a string.');
            }

            $kind = match ($dependency->kind) {
                'required' => ComponentDependencyKind::Required,
                'optional' => ComponentDependencyKind::Optional,
                default => throw $this->malformed($packageName, 'graph.dependencies kind must be required or optional.'),
            };

            try {
                return new ComponentDependency(new ComponentIdentifier($dependency->component), $kind);
            } catch (InvalidArgumentException $exception) {
                throw $this->malformed($packageName, $exception->getMessage(), $exception);
            }
        }, $dependencies);
    }

    /**
     * @param mixed $conflicts
     * @return list<ComponentConflict>
     */
    private function conflicts(string $packageName, mixed $conflicts): array
    {
        if (!is_array($conflicts) || !array_is_list($conflicts)) {
            throw $this->malformed($packageName, 'graph.conflicts must be a list.');
        }

        return array_map(function (mixed $conflict) use ($packageName): ComponentConflict {
            if (!is_string($conflict)) {
                throw $this->malformed($packageName, 'graph.conflicts entries must be strings.');
            }

            try {
                return new ComponentConflict(new ComponentIdentifier($conflict));
            } catch (InvalidArgumentException $exception) {
                throw $this->malformed($packageName, $exception->getMessage(), $exception);
            }
        }, $conflicts);
    }

    /**
     * @param mixed $requirements
     * @return list<CapabilityRequirement>
     */
    private function requirements(string $packageName, mixed $requirements): array
    {
        if (!is_array($requirements) || !array_is_list($requirements)) {
            throw $this->malformed($packageName, 'graph.requires must be a list.');
        }

        return array_map(function (mixed $requirement) use ($packageName): CapabilityRequirement {
            if (!$requirement instanceof stdClass || !$this->containsExactlyProperties($requirement, ['capability', 'cardinality'])) {
                throw $this->malformed($packageName, 'graph.requires entries must contain only capability and cardinality.');
            }

            if (!is_string($requirement->capability)) {
                throw $this->malformed($packageName, 'graph.requires capability must be a string.');
            }

            $cardinality = match ($requirement->cardinality) {
                'exactly_one' => CapabilityCardinality::ExactlyOne,
                'one_or_more' => CapabilityCardinality::OneOrMore,
                default => throw $this->malformed($packageName, 'graph.requires cardinality must be exactly_one or one_or_more.'),
            };

            try {
                return new CapabilityRequirement(new CapabilityIdentifier($requirement->capability), $cardinality);
            } catch (InvalidArgumentException $exception) {
                throw $this->malformed($packageName, $exception->getMessage(), $exception);
            }
        }, $requirements);
    }

    /**
     * @param mixed $providers
     * @return list<CapabilityIdentifier>
     */
    private function providers(string $packageName, mixed $providers): array
    {
        if (!is_array($providers) || !array_is_list($providers)) {
            throw $this->malformed($packageName, 'graph.provides must be a list.');
        }

        return array_map(function (mixed $provider) use ($packageName): CapabilityIdentifier {
            if (!is_string($provider)) {
                throw $this->malformed($packageName, 'graph.provides entries must be strings.');
            }

            try {
                return new CapabilityIdentifier($provider);
            } catch (InvalidArgumentException $exception) {
                throw $this->malformed($packageName, $exception->getMessage(), $exception);
            }
        }, $providers);
    }

    /**
     * @param list<string> $expectedKeys
     */
    private function containsExactlyProperties(stdClass $value, array $expectedKeys): bool
    {
        $keys = array_keys(get_object_vars($value));
        sort($keys);
        sort($expectedKeys);

        return $keys === $expectedKeys;
    }

    private function malformed(string $packageName, string $reason, ?Throwable $previous = null): MalformedComposerPluginMetadata
    {
        return new MalformedComposerPluginMetadata(
            'Malformed Composer plugin metadata for package "' . $packageName . '" in ' . $this->installedJsonPath . ': ' . $reason,
            0,
            $previous,
        );
    }
}
