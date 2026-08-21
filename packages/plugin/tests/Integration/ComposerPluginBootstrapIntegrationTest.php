<?php

declare(strict_types=1);

namespace Evolve\Plugin\Tests\Integration;

use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Core\Component\ComponentBootstrapper;
use Evolve\Core\Configuration\ArrayConfiguration;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ComponentDefinitionValidationFailed;
use Evolve\Core\Exception\ComponentEntryPointCreationFailed;
use Evolve\Core\Exception\DuplicateComponentIdentifier;
use Evolve\Module\Module;
use Evolve\Module\ModuleDefinition;
use Evolve\Module\ModuleDescriptor;
use Evolve\Plugin\Discovery\ComposerPluginDiscovery;
use Evolve\Plugin\Plugin;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class ComposerPluginBootstrapIntegrationTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        IntegrationValidPlugin::$calls = [];
        IntegrationValidModule::$calls = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    public function test_composer_discovered_plugins_are_not_automatically_enabled(): void
    {
        $definitions = $this->discoverFromPackages([
            $this->package('vendor/plugin', entryPoint: IntegrationValidPlugin::class),
        ]);

        $coordinator = (new ComponentBootstrapper($definitions))->prepare(new ArrayConfiguration());
        $registry = new ServiceRegistry();
        $coordinator->register($registry);
        $coordinator->boot($registry->freeze());
        $coordinator->ready();
        $coordinator->shutdown();

        self::assertSame([], IntegrationValidPlugin::$calls);
    }

    public function test_disabled_incompatible_and_bad_entry_point_metadata_remain_inert(): void
    {
        $definitions = $this->discoverFromPackages([
            $this->package('vendor/incompatible', evolveMajor: 3, entryPoint: IntegrationValidPlugin::class),
            $this->package('vendor/missing-entry', entryPoint: 'Vendor\\Missing\\Plugin'),
        ]);

        (new ComponentBootstrapper($definitions))->prepare(new ArrayConfiguration());

        self::assertSame([], IntegrationValidPlugin::$calls);
    }

    public function test_enabled_incompatible_discovered_plugin_fails_validation(): void
    {
        $definitions = $this->discoverFromPackages([
            $this->package('vendor/incompatible', evolveMajor: 3, entryPoint: IntegrationValidPlugin::class),
        ]);

        $this->expectException(ComponentDefinitionValidationFailed::class);

        (new ComponentBootstrapper($definitions))->prepare($this->enabled(['vendor/incompatible']));
    }

    public function test_enabled_nonexistent_discovered_plugin_entry_point_fails_validation(): void
    {
        $definitions = $this->discoverFromPackages([
            $this->package('vendor/missing-entry', entryPoint: 'Vendor\\Missing\\Plugin'),
        ]);

        $this->expectException(ComponentDefinitionValidationFailed::class);

        (new ComponentBootstrapper($definitions))->prepare($this->enabled(['vendor/missing-entry']));
    }

    public function test_enabled_wrong_interface_discovered_plugin_entry_point_fails_validation(): void
    {
        $definitions = $this->discoverFromPackages([
            $this->package('vendor/wrong-entry', entryPoint: IntegrationWrongEntryPoint::class),
        ]);

        $this->expectException(ComponentDefinitionValidationFailed::class);

        (new ComponentBootstrapper($definitions))->prepare($this->enabled(['vendor/wrong-entry']));
    }

    public function test_enabled_discovered_plugin_constructor_failure_is_wrapped_by_bootstrapper(): void
    {
        $definitions = $this->discoverFromPackages([
            $this->package('vendor/failing-entry', entryPoint: IntegrationFailingPlugin::class),
        ]);

        $this->expectException(ComponentEntryPointCreationFailed::class);

        (new ComponentBootstrapper($definitions))->prepare($this->enabled(['vendor/failing-entry']));
    }

    public function test_explicit_module_and_composer_discovered_plugin_can_bootstrap_together(): void
    {
        $definitions = [
            new ModuleDefinition(
                new ModuleDescriptor(new ComponentIdentifier('app/module'), 'Application Module', 2),
                IntegrationValidModule::class,
            ),
            ...$this->discoverFromPackages([
                $this->package('vendor/plugin', entryPoint: IntegrationValidPlugin::class),
            ]),
        ];

        $coordinator = (new ComponentBootstrapper($definitions))->prepare($this->enabled(['app/module', 'vendor/plugin']));
        $registry = new ServiceRegistry();
        $coordinator->register($registry);
        $coordinator->boot($registry->freeze());
        $coordinator->ready();
        $coordinator->shutdown();

        self::assertSame(['module:register', 'module:boot', 'module:ready', 'module:shutdown'], IntegrationValidModule::$calls);
        self::assertSame(['plugin:register', 'plugin:boot', 'plugin:ready', 'plugin:shutdown'], IntegrationValidPlugin::$calls);
    }

    public function test_explicit_definition_and_composer_definition_duplicate_identifier_remains_bootstrapper_fatal(): void
    {
        $definitions = [
            new ModuleDefinition(
                new ModuleDescriptor(new ComponentIdentifier('vendor/plugin'), 'Duplicate Module', 2),
                IntegrationValidModule::class,
            ),
            ...$this->discoverFromPackages([
                $this->package('vendor/plugin', entryPoint: IntegrationValidPlugin::class),
            ]),
        ];

        try {
            new ComponentBootstrapper($definitions);
            self::fail('Duplicate explicit and Composer definitions should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(DuplicateComponentIdentifier::class, $exception);
        }
    }

    /**
     * @param list<array<string, mixed>> $packages
     * @return list<\Evolve\Plugin\PluginDefinition>
     */
    private function discoverFromPackages(array $packages): array
    {
        $path = $this->writeInstalledJsonPayload(json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));

        return (new ComposerPluginDiscovery($path))->discover();
    }

    /**
     * @return array<string, mixed>
     */
    private function package(string $name, int $evolveMajor = 2, string $entryPoint = IntegrationValidPlugin::class): array
    {
        return [
            'name' => $name,
            'extra' => [
                'evolvephp' => [
                    'plugin' => [
                        'schema' => 1,
                        'type' => 'plugin',
                        'name' => 'Integration Plugin',
                        'evolve_major' => $evolveMajor,
                        'entry_point' => $entryPoint,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param list<string> $identifiers
     */
    private function enabled(array $identifiers): ArrayConfiguration
    {
        return new ArrayConfiguration(['evolve' => ['components' => ['enabled' => $identifiers]]]);
    }

    private function writeInstalledJsonPayload(string $payload): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evolvephp-installed-' . count($this->temporaryFiles) . '.json';
        file_put_contents($path, $payload);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}

final class IntegrationValidPlugin implements Plugin
{
    /** @var list<string> */
    public static array $calls = [];

    public function register(ServiceDefinitionRegistrar $registrar): void
    {
        self::$calls[] = 'plugin:register';
    }

    public function boot(ComponentBootContext $context): void
    {
        self::$calls[] = 'plugin:boot';
    }

    public function ready(): void
    {
        self::$calls[] = 'plugin:ready';
    }

    public function shutdown(): void
    {
        self::$calls[] = 'plugin:shutdown';
    }
}

final class IntegrationValidModule implements Module
{
    /** @var list<string> */
    public static array $calls = [];

    public function register(ServiceDefinitionRegistrar $registrar): void
    {
        self::$calls[] = 'module:register';
    }

    public function boot(ComponentBootContext $context): void
    {
        self::$calls[] = 'module:boot';
    }

    public function ready(): void
    {
        self::$calls[] = 'module:ready';
    }

    public function shutdown(): void
    {
        self::$calls[] = 'module:shutdown';
    }
}

final class IntegrationWrongEntryPoint implements \Evolve\Contracts\Component\ComponentEntryPoint
{
    public function register(ServiceDefinitionRegistrar $registrar): void {}

    public function boot(ComponentBootContext $context): void {}

    public function ready(): void {}

    public function shutdown(): void {}
}

final class IntegrationFailingPlugin implements Plugin
{
    public function __construct()
    {
        throw new RuntimeException('integration plugin construction failed');
    }

    public function register(ServiceDefinitionRegistrar $registrar): void {}

    public function boot(ComponentBootContext $context): void {}

    public function ready(): void {}

    public function shutdown(): void {}
}
