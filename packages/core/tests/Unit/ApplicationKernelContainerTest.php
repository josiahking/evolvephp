<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit;

use Closure;
use Evolve\Contracts\Configuration\Configuration;
use Evolve\Contracts\Configuration\ConfigurationValidator;
use Evolve\Contracts\Exception\ConfigurationException;
use Evolve\Contracts\Exception\LifecycleException;
use Evolve\Core\ApplicationKernel;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class ApplicationKernelContainerTest extends TestCase
{
    public function test_default_kernel_still_boots_and_shuts_down(): void
    {
        $kernel = new ApplicationKernel();

        $kernel->boot();
        $kernel->shutdown();

        $this->addToAssertionCount(1);
    }

    public function test_kernel_accepts_optional_service_registry_as_third_argument(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('service', ServiceLifetime::Application, static fn(): object => new \stdClass());

        $kernel = new ApplicationKernel(null, [], $registry);

        $kernel->boot();

        self::assertTrue($registry->freeze()->has('service'));
    }

    public function test_configuration_validation_runs_before_service_freeze(): void
    {
        $calls = [];
        $registry = new ServiceRegistry();
        $registry->register('service', ServiceLifetime::Application, static function () use (&$calls): object {
            $calls[] = 'factory';

            return new \stdClass();
        });

        $validator = new class (static function () use (&$calls): void {
            $calls[] = 'validator';
        }) implements ConfigurationValidator {
            public function __construct(private Closure $callback) {}

            public function validate(Configuration $configuration): void
            {
                ($this->callback)();
            }
        };

        $kernel = new ApplicationKernel(null, [$validator], $registry);

        $kernel->boot();

        self::assertSame(['validator'], $calls);
        self::assertTrue($registry->freeze()->has('service'));
    }

    public function test_failed_configuration_validation_prevents_service_freeze(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('service', ServiceLifetime::Application, static fn(): object => new \stdClass());
        $kernel = new ApplicationKernel(null, [
            new class implements ConfigurationValidator {
                public function validate(Configuration $configuration): void
                {
                    throw new RuntimeException('Invalid configuration.');
                }
            },
        ], $registry);

        try {
            $kernel->boot();
            self::fail('Kernel boot should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ConfigurationException::class, $exception);
        }

        $registry->register('later', ServiceLifetime::Application, static fn(): object => new \stdClass());

        self::assertTrue($registry->freeze()->has('later'));
    }

    public function test_kernel_accepts_execution_definitions_without_constructing_or_scoping_them(): void
    {
        $calls = [];
        $registry = new ServiceRegistry();
        $registry->register('execution.service', ServiceLifetime::Execution, static function () use (&$calls): object {
            $calls[] = 'factory';

            return new \stdClass();
        });
        $validator = new class (static function () use (&$calls): void {
            $calls[] = 'validator';
        }) implements ConfigurationValidator {
            public function __construct(private Closure $callback) {}

            public function validate(Configuration $configuration): void
            {
                ($this->callback)();
            }
        };
        $kernel = new ApplicationKernel(null, [$validator], $registry);

        $kernel->boot();

        self::assertSame(['validator'], $calls);
        self::assertTrue($registry->freeze()->has('execution.service'));
        self::assertTrue($registry->createExecutionScope()->has('execution.service'));
        $kernelApi = new ReflectionClass($kernel);

        self::assertFalse($kernelApi->hasMethod('createExecutionScope'));
        self::assertFalse($kernelApi->hasMethod('beginExecution'));
        self::assertFalse($kernelApi->hasMethod('currentScope'));

        $kernel->shutdown();

        $this->assertFailsThroughLifecycleException(static function () use ($kernel): void {
            $kernel->boot();
        });
    }

    /**
     * @param callable(): mixed $operation
     */
    private function assertFailsThroughLifecycleException(callable $operation): void
    {
        try {
            $operation();
            self::fail('Invalid lifecycle operation should throw a lifecycle exception.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(LifecycleException::class, $exception);
        }
    }
}
