<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit;

use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\ComponentDefinition;
use Evolve\Contracts\Component\ComponentEntryPoint;
use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Component\ComponentType;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Contracts\Configuration\Configuration;
use Evolve\Contracts\Configuration\ConfigurationValidator;
use Evolve\Contracts\Exception\ConfigurationException;
use Evolve\Core\ApplicationKernel;
use Evolve\Core\Component\ComponentBootstrapper;
use Evolve\Core\Configuration\ArrayConfiguration;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ComponentDefinitionValidationFailed;
use Evolve\Core\Exception\ComponentShutdownFailed;
use Evolve\Core\Exception\InvalidLifecycleTransition;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class ApplicationKernelComponentBootstrapperTest extends TestCase
{
    public function test_existing_validators_run_before_bootstrapper_validation(): void
    {
        $calls = new KernelBootstrapCallLog();
        $definition = $this->definition('billing', $calls);
        $kernel = new ApplicationKernel(
            $this->enabled(['billing']),
            [$this->validator($calls, 'validator')],
            new ServiceRegistry(),
            new ComponentBootstrapper([$definition]),
        );

        $kernel->boot();

        self::assertSame(['validator', 'billing:validate', 'billing:create', 'billing:register', 'billing:boot', 'billing:ready'], $calls->all());
    }

    public function test_bootstrapper_configuration_failure_has_no_definition_or_lifecycle_side_effects(): void
    {
        $calls = new KernelBootstrapCallLog();
        $definition = $this->definition('billing', $calls);
        $kernel = new ApplicationKernel(
            new ArrayConfiguration(['evolve' => ['components' => ['enabled' => ['missing']]]]),
            [],
            new ServiceRegistry(),
            new ComponentBootstrapper([$definition]),
        );

        try {
            $kernel->boot();
            self::fail('Bootstrapper configuration should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ConfigurationException::class, $exception);
            self::assertSame([], $calls->all());
            $this->assertFailsThroughLifecycleException(static function () use ($kernel): void {
                $kernel->shutdown();
            });
        }
    }

    public function test_bootstrapper_prepare_failure_prevents_registration(): void
    {
        $calls = new KernelBootstrapCallLog();
        $kernel = new ApplicationKernel(
            $this->enabled(['billing']),
            [],
            new ServiceRegistry(),
            new ComponentBootstrapper([$this->definition('billing', $calls, validateFailure: new RuntimeException('invalid'))]),
        );

        try {
            $kernel->boot();
            self::fail('Bootstrap preparation should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ComponentDefinitionValidationFailed::class, $exception);
            self::assertSame(['billing:validate'], $calls->all());
            $this->assertFailsThroughLifecycleException(static function () use ($kernel): void {
                $kernel->shutdown();
            });
        }
    }

    public function test_successful_bootstrap_runs_register_freeze_boot_ready_and_reverse_shutdown(): void
    {
        $calls = new KernelBootstrapCallLog();
        $kernel = new ApplicationKernel(
            $this->enabled(['billing']),
            [],
            new ServiceRegistry(),
            new ComponentBootstrapper([$this->definition('billing', $calls)]),
        );

        $kernel->boot();
        $kernel->shutdown();

        self::assertSame(['billing:validate', 'billing:create', 'billing:register', 'billing:boot', 'billing:ready', 'billing:shutdown'], $calls->all());
    }

    public function test_shutdown_failures_remain_existing_coordinator_shutdown_failures(): void
    {
        $calls = new KernelBootstrapCallLog();
        $kernel = new ApplicationKernel(
            $this->enabled(['billing']),
            [],
            new ServiceRegistry(),
            new ComponentBootstrapper([$this->definition('billing', $calls, shutdownFailure: new RuntimeException('shutdown failed'))]),
        );

        $kernel->boot();

        self::expectException(ComponentShutdownFailed::class);

        $kernel->shutdown();
    }

    public function test_default_no_component_kernel_remains_valid_with_bootstrapper_absent_or_empty(): void
    {
        $default = new ApplicationKernel();
        $default->boot();
        $default->shutdown();

        $empty = new ApplicationKernel(null, [], null, new ComponentBootstrapper([]));
        $empty->boot();
        $empty->shutdown();

        $this->addToAssertionCount(1);
    }

    /**
     * @param list<string> $enabled
     */
    private function enabled(array $enabled): ArrayConfiguration
    {
        return new ArrayConfiguration(['evolve' => ['components' => ['enabled' => $enabled]]]);
    }

    /**
     */
    private function validator(KernelBootstrapCallLog $calls, string $label): ConfigurationValidator
    {
        return new class ($calls, $label) implements ConfigurationValidator {
            public function __construct(private KernelBootstrapCallLog $calls, private string $label) {}

            public function validate(Configuration $configuration): void
            {
                $this->calls->record($this->label);
            }
        };
    }

    private function definition(
        string $id,
        KernelBootstrapCallLog $calls,
        ?Throwable $validateFailure = null,
        ?Throwable $shutdownFailure = null,
    ): KernelBootstrapDefinition {
        return new KernelBootstrapDefinition($id, $calls, $validateFailure, $shutdownFailure);
    }

    /**
     * @param callable(): mixed $operation
     */
    private function assertFailsThroughLifecycleException(callable $operation): void
    {
        try {
            $operation();
            self::fail('Invalid lifecycle operation should throw.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(InvalidLifecycleTransition::class, $exception);
        }
    }
}

final class KernelBootstrapCallLog
{
    /** @var list<string> */
    private array $calls = [];

    public function record(string $call): void
    {
        $this->calls[] = $call;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->calls;
    }
}

final class KernelBootstrapDefinition implements ComponentDefinition
{
    private ComponentGraphDeclaration $declaration;

    public function __construct(
        private string $id,
        private KernelBootstrapCallLog $calls,
        private ?Throwable $validateFailure = null,
        private ?Throwable $shutdownFailure = null,
    ) {
        $this->declaration = new ComponentGraphDeclaration(new ComponentIdentifier($id));
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
        $this->calls->record($this->id . ':validate');

        if ($this->validateFailure !== null) {
            throw $this->validateFailure;
        }
    }

    public function createEntryPoint(): ComponentEntryPoint
    {
        $this->calls->record($this->id . ':create');

        return new KernelBootstrapEntryPoint($this->id, $this->calls, $this->shutdownFailure);
    }
}

final class KernelBootstrapEntryPoint implements ComponentEntryPoint
{
    public function __construct(
        private string $id,
        private KernelBootstrapCallLog $calls,
        private ?Throwable $shutdownFailure = null,
    ) {}

    public function register(ServiceDefinitionRegistrar $registrar): void
    {
        $this->calls->record($this->id . ':register');
    }

    public function boot(ComponentBootContext $context): void
    {
        $this->calls->record($this->id . ':boot');
    }

    public function ready(): void
    {
        $this->calls->record($this->id . ':ready');
    }

    public function shutdown(): void
    {
        $this->calls->record($this->id . ':shutdown');

        if ($this->shutdownFailure !== null) {
            throw $this->shutdownFailure;
        }
    }
}
