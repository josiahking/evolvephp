<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Component;

use Evolve\Contracts\Component\CapabilityCardinality;
use Evolve\Contracts\Component\CapabilityIdentifier;
use Evolve\Contracts\Component\CapabilityRequirement;
use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\ComponentDefinition;
use Evolve\Contracts\Component\ComponentDependency;
use Evolve\Contracts\Component\ComponentDependencyKind;
use Evolve\Contracts\Component\ComponentEntryPoint;
use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentGraphRelations;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Component\ComponentType;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Core\Component\CapabilityProviderSelection;
use Evolve\Core\Component\ComponentBootstrapper;
use Evolve\Core\Configuration\ArrayConfiguration;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ComponentDefinitionValidationFailed;
use Evolve\Core\Exception\ComponentEntryPointCreationFailed;
use Evolve\Core\Exception\DuplicateComponentIdentifier;
use Evolve\Core\Exception\InvalidCapabilityProviderSelection;
use Evolve\Core\Exception\InvalidConfiguration;
use Evolve\Core\Exception\MissingCapabilityProvider;
use Evolve\Core\Exception\MissingComponentDependency;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class ComponentBootstrapperTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingEntryPoint::$calls = [];
    }

    public function test_bootstrap_failure_exceptions_are_internal_not_experimental(): void
    {
        foreach ([
            ComponentDefinitionValidationFailed::class,
            ComponentEntryPointCreationFailed::class,
        ] as $exceptionClass) {
            $reflection = new ReflectionClass($exceptionClass);
            $docComment = (string) $reflection->getDocComment();

            self::assertStringContainsString(
                '@internal',
                $docComment,
                $exceptionClass . ' must remain an internal Core implementation detail.',
            );

            self::assertStringNotContainsString(
                '@experimental',
                $docComment,
                $exceptionClass . ' must not be exposed as experimental public API.',
            );
        }
    }

    public function test_duplicate_discovered_identifiers_fail_before_validation_or_creation(): void
    {
        $first = $this->definition('billing');
        $second = $this->definition('billing');

        try {
            new ComponentBootstrapper([$first, $second]);
            self::fail('Duplicate discovered identifiers should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(DuplicateComponentIdentifier::class, $exception);
            self::assertSame('billing', $exception->identifier()->value());
            self::assertSame(0, $first->validations + $second->validations);
            self::assertSame(0, $first->creations + $second->creations);
        }
    }

    public function test_missing_enablement_configuration_means_zero_active_components(): void
    {
        $definition = $this->definition('billing', validateFailure: new RuntimeException('disabled invalid'));
        $coordinator = (new ComponentBootstrapper([$definition]))->prepare(new ArrayConfiguration());

        $registry = new ServiceRegistry();
        $coordinator->register($registry);
        $coordinator->boot($registry->freeze());
        $coordinator->ready();
        $coordinator->shutdown();

        self::assertSame(0, $definition->validations);
        self::assertSame(0, $definition->creations);
    }

    public function test_enablement_configuration_is_a_strict_identifier_allowlist(): void
    {
        $bootstrapper = new ComponentBootstrapper([$this->definition('billing')]);

        foreach ([
            new ArrayConfiguration(['evolve' => ['components' => ['enabled' => 'billing']]]),
            new ArrayConfiguration(['evolve' => ['components' => ['enabled' => ['billing', 123]]]]),
            new ArrayConfiguration(['evolve' => ['components' => ['enabled' => ['billing', 'billing']]]]),
            new ArrayConfiguration(['evolve' => ['components' => ['enabled' => ['Invalid']]]]),
            new ArrayConfiguration(['evolve' => ['components' => ['enabled' => ['missing']]]]),
        ] as $configuration) {
            try {
                $bootstrapper->validateConfiguration($configuration);
                self::fail('Malformed enablement configuration should fail.');
            } catch (Throwable $exception) {
                self::assertInstanceOf(InvalidConfiguration::class, $exception);
            }
        }
    }

    public function test_all_enabled_definitions_validate_before_any_entry_point_creation(): void
    {
        $billing = $this->definition('billing');
        $identity = $this->definition('identity', validateFailure: new RuntimeException('invalid identity'));

        try {
            (new ComponentBootstrapper([$billing, $identity]))->prepare($this->enabled(['billing', 'identity']));
            self::fail('Definition validation should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ComponentDefinitionValidationFailed::class, $exception);
            self::assertSame('identity', $exception->component()->value());
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            self::assertSame(1, $billing->validations);
            self::assertSame(1, $identity->validations);
            self::assertSame(0, $billing->creations + $identity->creations);
        }
    }

    public function test_graph_failure_happens_before_any_entry_point_creation(): void
    {
        $billing = $this->definition('billing', dependencies: ['identity']);

        try {
            (new ComponentBootstrapper([$billing, $this->definition('identity')]))->prepare($this->enabled(['billing']));
            self::fail('Missing enabled dependency should fail through graph resolver.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(MissingComponentDependency::class, $exception);
            self::assertSame('billing', $exception->consumer()->value());
            self::assertSame('identity', $exception->dependency()->value());
            self::assertSame(1, $billing->validations);
            self::assertSame(0, $billing->creations);
        }
    }

    public function test_disabled_required_capability_provider_fails_through_graph_resolver(): void
    {
        $consumer = $this->definition('worker', requiredCapabilities: ['queue']);
        $provider = $this->definition('queue', providedCapabilities: ['queue']);

        $this->expectException(MissingCapabilityProvider::class);

        (new ComponentBootstrapper([$consumer, $provider]))->prepare($this->enabled(['worker']));
    }

    public function test_provider_selections_are_preserved_for_graph_resolution(): void
    {
        $consumer = $this->definition('worker', requiredCapabilities: ['cache']);
        $array = $this->definition('array-cache', providedCapabilities: ['cache']);
        $redis = $this->definition('redis-cache', providedCapabilities: ['cache']);
        $bootstrapper = new ComponentBootstrapper([
            $consumer,
            $array,
            $redis,
        ], [
            new CapabilityProviderSelection(new ComponentIdentifier('worker'), new CapabilityIdentifier('cache'), new ComponentIdentifier('redis-cache')),
        ]);

        $coordinator = $bootstrapper->prepare($this->enabled(['worker', 'array-cache', 'redis-cache']));
        RecordingEntryPoint::$calls = [];
        $registry = new ServiceRegistry();
        $coordinator->register($registry);
        $coordinator->boot($registry->freeze());
        $coordinator->ready();

        self::assertSame(
            ['array-cache:register', 'redis-cache:register', 'worker:register', 'array-cache:boot', 'redis-cache:boot', 'worker:boot', 'array-cache:ready', 'redis-cache:ready', 'worker:ready'],
            RecordingEntryPoint::$calls,
        );
    }

    public function test_invalid_provider_selection_fails_before_creation(): void
    {
        $worker = $this->definition('worker', requiredCapabilities: ['cache']);
        $provider = $this->definition('array-cache', providedCapabilities: ['cache']);
        $bootstrapper = new ComponentBootstrapper([$worker, $provider], [
            new CapabilityProviderSelection(new ComponentIdentifier('worker'), new CapabilityIdentifier('cache'), new ComponentIdentifier('missing-cache')),
        ]);

        try {
            $bootstrapper->prepare($this->enabled(['worker', 'array-cache']));
            self::fail('Invalid provider selection should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(InvalidCapabilityProviderSelection::class, $exception);
            self::assertSame(0, $worker->creations + $provider->creations);
        }
    }

    public function test_entry_points_are_created_in_dependency_first_order_and_creation_failures_are_attributed(): void
    {
        $identity = $this->definition('identity');
        $billing = $this->definition('billing', dependencies: ['identity'], createFailure: new RuntimeException('billing failed'));

        try {
            (new ComponentBootstrapper([$billing, $identity]))->prepare($this->enabled(['billing', 'identity']));
            self::fail('Entry point creation should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ComponentEntryPointCreationFailed::class, $exception);
            self::assertSame('billing', $exception->component()->value());
            self::assertSame('billing failed', $exception->getPrevious()?->getMessage());
            self::assertSame(['identity:create'], RecordingEntryPoint::$calls);
        }
    }

    /**
     * @param list<string> $identifiers
     */
    private function enabled(array $identifiers): ArrayConfiguration
    {
        return new ArrayConfiguration(['evolve' => ['components' => ['enabled' => $identifiers]]]);
    }

    /**
     * @param list<string> $dependencies
     * @param list<string> $requiredCapabilities
     * @param list<string> $providedCapabilities
     */
    private function definition(
        string $identifier,
        array $dependencies = [],
        array $requiredCapabilities = [],
        array $providedCapabilities = [],
        ?Throwable $validateFailure = null,
        ?Throwable $createFailure = null,
    ): RecordingDefinition {
        return new RecordingDefinition($identifier, $dependencies, $requiredCapabilities, $providedCapabilities, $validateFailure, $createFailure);
    }
}

final class RecordingDefinition implements ComponentDefinition
{
    public int $validations = 0;

    public int $creations = 0;

    private ComponentGraphDeclaration $declaration;

    /**
     * @param list<string> $dependencies
     * @param list<string> $requiredCapabilities
     * @param list<string> $providedCapabilities
     */
    public function __construct(
        private string $id,
        array $dependencies = [],
        array $requiredCapabilities = [],
        array $providedCapabilities = [],
        private ?Throwable $validateFailure = null,
        private ?Throwable $createFailure = null,
    ) {
        $this->declaration = new ComponentGraphDeclaration(
            new ComponentIdentifier($id),
            new ComponentGraphRelations(
                array_map(
                    static fn(string $dependency): ComponentDependency => new ComponentDependency(new ComponentIdentifier($dependency), ComponentDependencyKind::Required),
                    $dependencies,
                ),
                [],
                array_map(
                    static fn(string $capability): CapabilityRequirement => new CapabilityRequirement(new CapabilityIdentifier($capability), CapabilityCardinality::ExactlyOne),
                    $requiredCapabilities,
                ),
                array_map(
                    static fn(string $capability): CapabilityIdentifier => new CapabilityIdentifier($capability),
                    $providedCapabilities,
                ),
            ),
        );
    }

    public function identifier(): ComponentIdentifier
    {
        return $this->declaration->identifier();
    }

    public function type(): ComponentType
    {
        return ComponentType::Module;
    }

    public function graphDeclaration(): ComponentGraphDeclaration
    {
        return $this->declaration;
    }

    public function validate(): void
    {
        ++$this->validations;

        if ($this->validateFailure !== null) {
            throw $this->validateFailure;
        }
    }

    public function createEntryPoint(): ComponentEntryPoint
    {
        ++$this->creations;

        if ($this->createFailure !== null) {
            throw $this->createFailure;
        }

        RecordingEntryPoint::$calls[] = $this->id . ':create';

        return new RecordingEntryPoint($this->id);
    }
}

final class RecordingEntryPoint implements ComponentEntryPoint
{
    /** @var list<string> */
    public static array $calls = [];

    public function __construct(private string $id) {}

    public function register(ServiceDefinitionRegistrar $registrar): void
    {
        self::$calls[] = $this->id . ':register';
    }

    public function boot(ComponentBootContext $context): void
    {
        self::$calls[] = $this->id . ':boot';
    }

    public function ready(): void
    {
        self::$calls[] = $this->id . ':ready';
    }

    public function shutdown(): void
    {
        self::$calls[] = $this->id . ':shutdown';
    }
}
