<?php

declare(strict_types=1);

namespace Evolve\Plugin\Tests\Unit\Discovery;

use Evolve\Contracts\Component\CapabilityCardinality;
use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\ComponentDependencyKind;
use Evolve\Contracts\Component\ComponentType;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Plugin\Discovery\ComposerPluginDiscovery;
use Evolve\Plugin\Exception\ComposerPluginDiscoveryFailed;
use Evolve\Plugin\Exception\ComposerPluginMetadataUnavailable;
use Evolve\Plugin\Exception\DuplicateComposerPluginIdentifier;
use Evolve\Plugin\Exception\MalformedComposerPluginMetadata;
use Evolve\Plugin\Plugin;
use Evolve\Plugin\PluginDefinition;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class ComposerPluginDiscoveryTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        DiscoveryConstructingPlugin::$constructions = 0;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    public function test_constructor_does_not_touch_filesystem(): void
    {
        $path = $this->temporaryPath('missing-installed-json');
        $discovery = new ComposerPluginDiscovery($path);

        unset($discovery);
        self::assertFileDoesNotExist($path);
    }

    public function test_missing_installed_json_fails_as_metadata_unavailable(): void
    {
        $discovery = new ComposerPluginDiscovery($this->temporaryPath('missing-installed-json'));

        $this->expectException(ComposerPluginMetadataUnavailable::class);

        $discovery->discover();
    }

    public function test_url_paths_are_rejected_without_opening_wrappers(): void
    {
        $discovery = new ComposerPluginDiscovery('https://example.com/vendor/composer/installed.json');

        $this->expectException(ComposerPluginMetadataUnavailable::class);

        $discovery->discover();
    }

    public function test_malformed_json_and_installed_json_shape_fail(): void
    {
        $malformedCount = 0;

        foreach ([
            'not json',
            '[]',
            '{"packages":{}}',
            '{"packages":[null]}',
            '{"packages":[{"extra":{"evolvephp":{"plugin":{}}}}]}',
            '{"packages":[{"name":""}]}',
        ] as $payload) {
            try {
                (new ComposerPluginDiscovery($this->writeInstalledJsonPayload($payload)))->discover();
                self::fail('Malformed installed.json should fail.');
            } catch (MalformedComposerPluginMetadata) {
                ++$malformedCount;
            }
        }

        self::addToAssertionCount($malformedCount);
    }

    public function test_packages_without_plugin_metadata_are_ignored(): void
    {
        $definitions = $this->discoverFromPackages([
            ['name' => 'vendor/plain'],
            ['name' => 'vendor/extra', 'extra' => ['branch-alias' => ['dev-main' => '1.x-dev']]],
            ['name' => 'vendor/evolve', 'extra' => ['evolvephp' => ['module' => ['schema' => 1]]]],
        ]);

        self::assertSame([], $definitions);
    }

    public function test_evolvephp_extra_must_be_an_object_when_present(): void
    {
        $this->expectException(MalformedComposerPluginMetadata::class);

        $this->discoverFromPackages([
            ['name' => 'vendor/broken', 'extra' => ['evolvephp' => 'plugin']],
        ]);
    }

    public function test_evolvephp_extra_json_list_is_malformed_when_present(): void
    {
        $this->expectException(MalformedComposerPluginMetadata::class);

        $this->discoverFromPayload('{"packages":[{"name":"vendor/broken","extra":{"evolvephp":[]}}]}');
    }

    public function test_evolvephp_object_with_unrelated_sibling_metadata_and_no_plugin_is_ignored(): void
    {
        $definitions = $this->discoverFromPayload('{"packages":[{"name":"vendor/plain","extra":{"evolvephp":{"module":{"schema":1}}}}]}');

        self::assertSame([], $definitions);
    }

    public function test_plugin_metadata_must_be_a_json_object_when_present(): void
    {
        foreach ([
            '{"packages":[{"name":"vendor/list-plugin","extra":{"evolvephp":{"plugin":[]}}}]}',
            '{"packages":[{"name":"vendor/null-plugin","extra":{"evolvephp":{"plugin":null}}}]}',
        ] as $payload) {
            try {
                $this->discoverFromPayload($payload);
                self::fail('Malformed plugin metadata should fail.');
            } catch (MalformedComposerPluginMetadata $exception) {
                self::assertStringContainsString('extra.evolvephp.plugin', $exception->getMessage());
            }
        }
    }

    public function test_valid_plugin_metadata_discovers_plugin_definition(): void
    {
        $definitions = $this->discoverFromPackages([
            ['name' => 'vendor/queue', 'extra' => ['evolvephp' => ['plugin' => $this->pluginMetadata()]]],
        ]);

        self::assertCount(1, $definitions);
        self::assertSame('vendor/queue', $definitions[0]->identifier()->value());
        self::assertSame(ComponentType::Plugin, $definitions[0]->type());
    }

    public function test_discovered_definitions_are_sorted_by_package_identifier(): void
    {
        $definitions = $this->discoverFromPackages([
            ['name' => 'vendor/zeta', 'extra' => ['evolvephp' => ['plugin' => $this->pluginMetadata(name: 'Zeta')]]],
            ['name' => 'vendor/alpha', 'extra' => ['evolvephp' => ['plugin' => $this->pluginMetadata(name: 'Alpha')]]],
        ]);

        self::assertSame(['vendor/alpha', 'vendor/zeta'], array_map(
            static fn(PluginDefinition $definition): string => $definition->identifier()->value(),
            $definitions,
        ));
    }

    public function test_composer_package_name_is_the_authoritative_identifier(): void
    {
        $definition = $this->discoverFromPackages([
            ['name' => 'vendor/authoritative', 'extra' => ['evolvephp' => ['plugin' => $this->pluginMetadata()]]],
        ])[0];

        self::assertSame('vendor/authoritative', $definition->identifier()->value());
    }

    public function test_identifier_metadata_is_rejected_even_when_it_matches_package_name(): void
    {
        $this->expectException(MalformedComposerPluginMetadata::class);

        $this->discoverFromPackages([
            ['name' => 'vendor/plugin', 'extra' => ['evolvephp' => ['plugin' => $this->pluginMetadata(['identifier' => 'vendor/plugin'])]]],
        ]);
    }

    public function test_identifier_metadata_rejection_explains_composer_package_name_authority(): void
    {
        try {
            $this->discoverFromPackages([
                ['name' => 'vendor/plugin', 'extra' => ['evolvephp' => ['plugin' => $this->pluginMetadata(['identifier' => 'vendor/plugin'])]]],
            ]);
            self::fail('Identifier metadata should fail.');
        } catch (MalformedComposerPluginMetadata $exception) {
            self::assertStringContainsString('Composer package name', $exception->getMessage());
            self::assertStringContainsString('authoritative', $exception->getMessage());
        }
    }

    public function test_invalid_composer_package_identifier_is_rejected(): void
    {
        $this->expectException(MalformedComposerPluginMetadata::class);

        $this->discoverFromPackages([
            ['name' => 'InvalidVendor/plugin', 'extra' => ['evolvephp' => ['plugin' => $this->pluginMetadata()]]],
        ]);
    }

    public function test_plugin_schema_type_name_major_and_entry_point_are_structurally_validated(): void
    {
        $malformedCount = 0;

        foreach ([
            $this->pluginMetadata(['schema' => 2]),
            $this->pluginMetadata(['schema' => '1']),
            $this->pluginMetadata(['type' => 'module']),
            $this->pluginMetadata(['name' => ' ']),
            $this->pluginMetadata(['evolve_major' => 0]),
            $this->pluginMetadata(['evolve_major' => '2']),
            $this->pluginMetadata(['entry_point' => '']),
            $this->pluginMetadata(['entry_point' => 123]),
            $this->pluginMetadata(['unknown' => true]),
        ] as $metadata) {
            try {
                $this->discoverFromPackages([
                    ['name' => 'vendor/broken', 'extra' => ['evolvephp' => ['plugin' => $metadata]]],
                ]);
                self::fail('Malformed plugin metadata should fail.');
            } catch (MalformedComposerPluginMetadata) {
                ++$malformedCount;
            }
        }

        self::addToAssertionCount($malformedCount);
    }

    public function test_graph_absence_defaults_to_empty_relations(): void
    {
        $graph = $this->discoverFromPackages([
            ['name' => 'vendor/plugin', 'extra' => ['evolvephp' => ['plugin' => $this->pluginMetadata()]]],
        ])[0]->graphDeclaration()->relations();

        self::assertSame([], $graph->dependencies());
        self::assertSame([], $graph->conflicts());
        self::assertSame([], $graph->requiredCapabilities());
        self::assertSame([], $graph->providedCapabilities());
    }

    public function test_empty_graph_json_object_defaults_to_empty_relations(): void
    {
        $graph = $this->discoverFromPayload('{"packages":[{"name":"vendor/plugin","extra":{"evolvephp":{"plugin":{"schema":1,"type":"plugin","name":"Queue Plugin","evolve_major":2,"entry_point":"Vendor\\\\Plugin\\\\QueuePlugin","graph":{}}}}}]}')[0]->graphDeclaration()->relations();

        self::assertSame([], $graph->dependencies());
        self::assertSame([], $graph->conflicts());
        self::assertSame([], $graph->requiredCapabilities());
        self::assertSame([], $graph->providedCapabilities());
    }

    public function test_plugin_plus_unrelated_evolvephp_sibling_metadata_is_accepted(): void
    {
        $definitions = $this->discoverFromPayload('{"packages":[{"name":"vendor/plugin","extra":{"evolvephp":{"module":{"schema":1},"plugin":{"schema":1,"type":"plugin","name":"Queue Plugin","evolve_major":2,"entry_point":"Vendor\\\\Plugin\\\\QueuePlugin"}}}}]}');

        self::assertCount(1, $definitions);
        self::assertSame('vendor/plugin', $definitions[0]->identifier()->value());
    }

    public function test_graph_must_be_a_json_object_when_present(): void
    {
        foreach ([
            '{"packages":[{"name":"vendor/null-graph","extra":{"evolvephp":{"plugin":{"schema":1,"type":"plugin","name":"Queue Plugin","evolve_major":2,"entry_point":"Vendor\\\\Plugin\\\\QueuePlugin","graph":null}}}}]}',
            '{"packages":[{"name":"vendor/list-graph","extra":{"evolvephp":{"plugin":{"schema":1,"type":"plugin","name":"Queue Plugin","evolve_major":2,"entry_point":"Vendor\\\\Plugin\\\\QueuePlugin","graph":[]}}}}]}',
            '{"packages":[{"name":"vendor/scalar-graph","extra":{"evolvephp":{"plugin":{"schema":1,"type":"plugin","name":"Queue Plugin","evolve_major":2,"entry_point":"Vendor\\\\Plugin\\\\QueuePlugin","graph":"relations"}}}}]}',
        ] as $payload) {
            try {
                $this->discoverFromPayload($payload);
                self::fail('Malformed graph metadata should fail.');
            } catch (MalformedComposerPluginMetadata $exception) {
                self::assertStringContainsString('graph', $exception->getMessage());
            }
        }
    }

    public function test_explicit_null_relation_fields_are_malformed(): void
    {
        foreach (['dependencies', 'conflicts', 'requires', 'provides'] as $field) {
            try {
                $this->discoverFromPayload('{"packages":[{"name":"vendor/null-relation","extra":{"evolvephp":{"plugin":{"schema":1,"type":"plugin","name":"Queue Plugin","evolve_major":2,"entry_point":"Vendor\\\\Plugin\\\\QueuePlugin","graph":{"' . $field . '":null}}}}}]}');
                self::fail('Null graph relation field should fail.');
            } catch (MalformedComposerPluginMetadata $exception) {
                self::assertStringContainsString($field, $exception->getMessage());
            }
        }
    }

    public function test_graph_dependencies_conflicts_requirements_and_providers_are_mapped(): void
    {
        $graph = $this->discoverFromPackages([
            [
                'name' => 'vendor/plugin',
                'extra' => [
                    'evolvephp' => [
                        'plugin' => $this->pluginMetadata([
                            'graph' => [
                                'dependencies' => [
                                    ['component' => 'vendor/cache', 'kind' => 'optional'],
                                    ['component' => 'vendor/logger', 'kind' => 'required'],
                                ],
                                'conflicts' => ['vendor/conflict'],
                                'requires' => [
                                    ['capability' => 'queue', 'cardinality' => 'exactly_one'],
                                    ['capability' => 'logger', 'cardinality' => 'one_or_more'],
                                ],
                                'provides' => ['worker'],
                            ],
                        ]),
                    ],
                ],
            ],
        ])[0]->graphDeclaration()->relations();

        self::assertSame(['vendor/cache', 'vendor/logger'], array_map(
            static fn($dependency): string => $dependency->target()->value(),
            $graph->dependencies(),
        ));
        self::assertSame(ComponentDependencyKind::Optional, $graph->dependencies()[0]->kind());
        self::assertSame(ComponentDependencyKind::Required, $graph->dependencies()[1]->kind());
        self::assertSame('vendor/conflict', $graph->conflicts()[0]->target()->value());
        $requiredCapabilities = [];
        foreach ($graph->requiredCapabilities() as $requirement) {
            $requiredCapabilities[$requirement->capability()->value()] = $requirement->cardinality();
        }

        self::assertSame(CapabilityCardinality::ExactlyOne, $requiredCapabilities['queue']);
        self::assertSame(CapabilityCardinality::OneOrMore, $requiredCapabilities['logger']);
        self::assertSame('worker', $graph->providedCapabilities()[0]->value());
    }

    public function test_malformed_graph_metadata_is_rejected(): void
    {
        $malformedCount = 0;

        foreach ([
            ['graph' => ['unknown' => []]],
            ['graph' => ['dependencies' => ['vendor/cache']]],
            ['graph' => ['dependencies' => [['vendor/cache', 'required']]]],
            ['graph' => ['dependencies' => [['component' => 'vendor/cache', 'kind' => 'required', 'extra' => true]]]],
            ['graph' => ['dependencies' => [['component' => 'vendor/cache', 'kind' => 'hard']]]],
            ['graph' => ['conflicts' => [['component' => 'vendor/conflict']]]],
            ['graph' => ['requires' => [['queue', 'exactly_one']]]],
            ['graph' => ['requires' => [['capability' => 'queue', 'cardinality' => 'many']]]],
            ['graph' => ['provides' => [['capability' => 'worker']]]],
        ] as $override) {
            try {
                $this->discoverFromPackages([
                    ['name' => 'vendor/broken', 'extra' => ['evolvephp' => ['plugin' => $this->pluginMetadata($override)]]],
                ]);
                self::fail('Malformed graph metadata should fail.');
            } catch (MalformedComposerPluginMetadata) {
                ++$malformedCount;
            }
        }

        self::addToAssertionCount($malformedCount);
    }

    public function test_duplicate_composer_plugin_package_names_are_rejected(): void
    {
        $this->expectException(DuplicateComposerPluginIdentifier::class);

        $this->discoverFromPackages([
            ['name' => 'vendor/plugin', 'extra' => ['evolvephp' => ['plugin' => $this->pluginMetadata()]]],
            ['name' => 'vendor/plugin', 'extra' => ['evolvephp' => ['plugin' => $this->pluginMetadata(name: 'Duplicate')]]],
        ]);
    }

    public function test_discovery_does_not_instantiate_entry_points(): void
    {
        $this->discoverFromPackages([
            [
                'name' => 'vendor/plugin',
                'extra' => [
                    'evolvephp' => [
                        'plugin' => $this->pluginMetadata(['entry_point' => DiscoveryConstructingPlugin::class]),
                    ],
                ],
            ],
        ]);

        self::assertSame(0, DiscoveryConstructingPlugin::$constructions);
    }

    public function test_discovery_public_api_is_final_readonly_experimental_and_minimal(): void
    {
        $reflection = new ReflectionClass(ComposerPluginDiscovery::class);
        $publicMethods = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($publicMethods);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertIsString($reflection->getDocComment());
        self::assertStringContainsString('@experimental', $reflection->getDocComment());
        self::assertSame(['__construct', 'discover'], $publicMethods);
    }

    public function test_discovery_exceptions_keep_locked_hierarchy_and_experimental_classification(): void
    {
        $base = new ReflectionClass(ComposerPluginDiscoveryFailed::class);

        self::assertTrue($base->isAbstract());
        self::assertTrue($base->isSubclassOf(RuntimeException::class));
        self::assertIsString($base->getDocComment());
        self::assertStringContainsString('@experimental', $base->getDocComment());

        foreach ([
            ComposerPluginMetadataUnavailable::class,
            MalformedComposerPluginMetadata::class,
            DuplicateComposerPluginIdentifier::class,
        ] as $exceptionClass) {
            $reflection = new ReflectionClass($exceptionClass);

            self::assertTrue($reflection->isFinal());
            self::assertTrue($reflection->isSubclassOf(ComposerPluginDiscoveryFailed::class));
            self::assertIsString($reflection->getDocComment());
            self::assertStringContainsString('@experimental', $reflection->getDocComment());
        }
    }

    /**
     * @param list<array<string, mixed>> $packages
     * @return list<PluginDefinition>
     */
    private function discoverFromPackages(array $packages): array
    {
        return (new ComposerPluginDiscovery($this->writeInstalledJsonPayload(json_encode(['packages' => $packages], JSON_THROW_ON_ERROR))))->discover();
    }

    /**
     * @return list<PluginDefinition>
     */
    private function discoverFromPayload(string $payload): array
    {
        return (new ComposerPluginDiscovery($this->writeInstalledJsonPayload($payload)))->discover();
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function pluginMetadata(array $override = [], string $name = 'Queue Plugin'): array
    {
        return array_replace([
            'schema' => 1,
            'type' => 'plugin',
            'name' => $name,
            'evolve_major' => 2,
            'entry_point' => 'Vendor\\Plugin\\QueuePlugin',
        ], $override);
    }

    private function writeInstalledJsonPayload(string $payload): string
    {
        $path = $this->temporaryPath('installed-' . count($this->temporaryFiles) . '.json');
        file_put_contents($path, $payload);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function temporaryPath(string $basename): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $basename;
    }
}

final class DiscoveryConstructingPlugin implements Plugin
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }

    public function register(ServiceDefinitionRegistrar $registrar): void {}

    public function boot(ComponentBootContext $context): void {}

    public function ready(): void {}

    public function shutdown(): void {}
}
