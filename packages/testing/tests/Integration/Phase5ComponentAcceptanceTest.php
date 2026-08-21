<?php

declare(strict_types=1);

namespace Evolve\Testing\Tests\Integration;

use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\ComponentDependencyKind;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Core\ApplicationKernel;
use Evolve\Core\Component\ComponentBootstrapper;
use Evolve\Core\Configuration\ArrayConfiguration;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Module\Module;
use Evolve\Module\ModuleDefinition;
use Evolve\Module\ModuleDescriptor;
use Evolve\Plugin\Discovery\ComposerPluginDiscovery;
use Evolve\Plugin\Plugin;
use PHPUnit\Framework\TestCase;

final class Phase5ComponentAcceptanceTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        Phase5AcceptanceState::reset();
    }

    protected function tearDown(): void
    {
        Phase5AcceptanceState::reset();

        foreach ($this->temporaryFiles as $temporaryFile) {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    public function test_phase5_component_path_uses_real_discovery_bootstrap_graph_registration_boot_ready_and_shutdown(): void
    {
        $installedJson = $this->writeInstalledJson([
            [
                'name' => 'vendor/acceptance-plugin',
                'extra' => [
                    'evolvephp' => [
                        'plugin' => [
                            'schema' => 1,
                            'type' => 'plugin',
                            'name' => 'Acceptance Plugin',
                            'evolve_major' => 2,
                            'entry_point' => Phase5AcceptancePlugin::class,
                            'graph' => [
                                'dependencies' => [
                                    [
                                        'component' => 'app/acceptance-module',
                                        'kind' => 'required',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $moduleDefinition = new ModuleDefinition(
            new ModuleDescriptor(
                new \Evolve\Contracts\Component\ComponentIdentifier('app/acceptance-module'),
                'Acceptance Module',
                2,
            ),
            Phase5AcceptanceModule::class,
        );
        $pluginDefinitions = (new ComposerPluginDiscovery($installedJson))->discover();
        $bootstrapper = new ComponentBootstrapper([$moduleDefinition, ...$pluginDefinitions]);
        $kernel = new ApplicationKernel(
            new ArrayConfiguration([
                'evolve' => [
                    'components' => [
                        'enabled' => [
                            'vendor/acceptance-plugin',
                            'app/acceptance-module',
                        ],
                    ],
                ],
            ]),
            services: new ServiceRegistry(),
            components: $bootstrapper,
        );

        $kernel->boot();

        self::assertSame(['registered-value'], Phase5AcceptanceState::$pluginBootValues);
        self::assertSame(
            [
                'module:register',
                'plugin:register',
                'module:boot',
                'plugin:boot',
                'module:ready',
                'plugin:ready',
            ],
            Phase5AcceptanceState::$events,
        );

        $kernel->shutdown();

        self::assertSame(
            [
                'module:register',
                'plugin:register',
                'module:boot',
                'plugin:boot',
                'module:ready',
                'plugin:ready',
                'plugin:shutdown',
                'module:shutdown',
            ],
            Phase5AcceptanceState::$events,
        );
        self::assertSame(ComponentDependencyKind::Required, $pluginDefinitions[0]->graphDeclaration()->relations()->dependencies()[0]->kind());
        self::assertSame('app/acceptance-module', $pluginDefinitions[0]->graphDeclaration()->relations()->dependencies()[0]->target()->value());
    }

    /**
     * @param list<array<string, mixed>> $packages
     */
    private function writeInstalledJson(array $packages): string
    {
        $path = tempnam(sys_get_temp_dir(), 'evolve-phase5-acceptance-');

        self::assertIsString($path);
        file_put_contents($path, json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
        $this->temporaryFiles[] = $path;

        return $path;
    }
}

final class Phase5AcceptanceState
{
    /** @var list<string> */
    public static array $events = [];

    /** @var list<mixed> */
    public static array $pluginBootValues = [];

    public static function reset(): void
    {
        self::$events = [];
        self::$pluginBootValues = [];
    }
}

final class Phase5AcceptanceModule implements Module
{
    public function register(ServiceDefinitionRegistrar $registrar): void
    {
        Phase5AcceptanceState::$events[] = 'module:register';
        $registrar->registerApplication('acceptance.value', static fn(): string => 'registered-value');
    }

    public function boot(ComponentBootContext $context): void
    {
        Phase5AcceptanceState::$events[] = 'module:boot';
    }

    public function ready(): void
    {
        Phase5AcceptanceState::$events[] = 'module:ready';
    }

    public function shutdown(): void
    {
        Phase5AcceptanceState::$events[] = 'module:shutdown';
    }
}

final class Phase5AcceptancePlugin implements Plugin
{
    public function register(ServiceDefinitionRegistrar $registrar): void
    {
        Phase5AcceptanceState::$events[] = 'plugin:register';
    }

    public function boot(ComponentBootContext $context): void
    {
        Phase5AcceptanceState::$events[] = 'plugin:boot';
        Phase5AcceptanceState::$pluginBootValues[] = $context->services()->get('acceptance.value');
    }

    public function ready(): void
    {
        Phase5AcceptanceState::$events[] = 'plugin:ready';
    }

    public function shutdown(): void
    {
        Phase5AcceptanceState::$events[] = 'plugin:shutdown';
    }
}
