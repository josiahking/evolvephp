<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit;

use Closure;
use Evolve\Contracts\Configuration\Configuration;
use Evolve\Contracts\Configuration\ConfigurationValidator;
use Evolve\Contracts\Exception\ConfigurationException;
use Evolve\Contracts\Exception\LifecycleException;
use Evolve\Core\ApplicationKernel;
use Evolve\Core\Configuration\ArrayConfiguration;
use Evolve\Core\Exception\ConfigurationValidationFailed;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class ApplicationKernelConfigurationTest extends TestCase
{
    public function test_default_kernel_still_boots_and_shuts_down(): void
    {
        $kernel = new ApplicationKernel();

        $kernel->boot();
        $kernel->shutdown();

        $this->addToAssertionCount(1);
    }

    public function test_valid_supplied_configuration_allows_boot(): void
    {
        $kernel = new ApplicationKernel($this->newConfiguration(['app' => 'evolve']));

        $kernel->boot();
        $kernel->shutdown();
    }

    public function test_validator_receives_supplied_configuration(): void
    {
        $configuration = $this->newConfiguration(['app' => 'evolve']);
        $received = null;

        $kernel = new ApplicationKernel($configuration, [
            $this->validator(static function (Configuration $configuration) use (&$received): void {
                $received = $configuration;
            }),
        ]);

        $kernel->boot();

        self::assertSame($configuration, $received);
    }

    public function test_validators_execute_in_supplied_order(): void
    {
        $calls = [];
        $kernel = new ApplicationKernel(null, [
            $this->validator(static function () use (&$calls): void {
                $calls[] = 'first';
            }),
            $this->validator(static function () use (&$calls): void {
                $calls[] = 'second';
            }),
        ]);

        $kernel->boot();

        self::assertSame(['first', 'second'], $calls);
    }

    public function test_successful_validators_allow_shutdown(): void
    {
        $kernel = new ApplicationKernel(null, [
            $this->validator(static function (): void {}),
        ]);

        $kernel->boot();
        $kernel->shutdown();
    }

    public function test_configuration_exception_from_validator_prevents_readiness_without_wrapping(): void
    {
        $failure = $this->configurationFailure('Invalid supplied configuration.');
        $kernel = new ApplicationKernel(null, [
            $this->validator(static function () use ($failure): void {
                throw $failure;
            }),
        ]);

        try {
            $kernel->boot();
            self::fail('Configuration validation should fail.');
        } catch (Throwable $exception) {
            self::assertSame($failure, $exception);
            self::assertNotInstanceOf(ConfigurationValidationFailed::class, $exception);
        }
    }

    public function test_unexpected_validator_throwable_is_wrapped_and_preserved(): void
    {
        self::assertTrue(class_exists(ConfigurationValidationFailed::class), ConfigurationValidationFailed::class . ' should exist.');

        $failure = new RuntimeException('Unexpected validator failure.');
        $kernel = new ApplicationKernel(null, [
            $this->validator(static function () use ($failure): void {
                throw $failure;
            }),
        ]);

        try {
            $kernel->boot();
            self::fail('Unexpected validation throwable should be wrapped.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ConfigurationValidationFailed::class, $exception);
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function test_validation_is_fail_fast(): void
    {
        $calls = [];
        $kernel = new ApplicationKernel(null, [
            $this->validator(static function () use (&$calls): void {
                $calls[] = 'first';

                throw new RuntimeException('Stop here.');
            }),
            $this->validator(static function () use (&$calls): void {
                $calls[] = 'second';
            }),
        ]);

        try {
            $kernel->boot();
            self::fail('Validation should fail fast.');
        } catch (Throwable) {
            self::assertSame(['first'], $calls);
        }
    }

    public function test_failed_validation_makes_kernel_terminal_for_shutdown_and_boot(): void
    {
        $kernel = new ApplicationKernel(null, [
            $this->validator(static function (): void {
                throw new RuntimeException('Invalid.');
            }),
        ]);

        $this->ignoreExpectedConfigurationFailure($kernel);

        $this->assertFailsThroughLifecycleException(static function () use ($kernel): void {
            $kernel->shutdown();
        });

        $this->assertFailsThroughLifecycleException(static function () use ($kernel): void {
            $kernel->boot();
        });
    }

    public function test_failed_kernel_does_not_rerun_validation(): void
    {
        $calls = 0;
        $kernel = new ApplicationKernel(null, [
            $this->validator(static function () use (&$calls): void {
                ++$calls;

                throw new RuntimeException('Invalid.');
            }),
        ]);

        $this->ignoreExpectedConfigurationFailure($kernel);
        $this->assertFailsThroughLifecycleException(static function () use ($kernel): void {
            $kernel->boot();
        });

        self::assertSame(1, $calls);
    }

    public function test_new_kernel_with_corrected_configuration_can_boot_after_prior_failure(): void
    {
        $validator = $this->validator(static function (Configuration $configuration): void {
            if ($configuration->get('feature.enabled') !== true) {
                throw new RuntimeException('Feature must be enabled.');
            }
        });

        $failedKernel = new ApplicationKernel($this->newConfiguration(['feature' => ['enabled' => false]]), [$validator]);

        $this->ignoreExpectedConfigurationFailure($failedKernel);

        $correctedKernel = new ApplicationKernel($this->newConfiguration(['feature' => ['enabled' => true]]), [$validator]);

        $correctedKernel->boot();
        $correctedKernel->shutdown();
    }

    public function test_reentrant_boot_during_validation_is_rejected_without_recursing(): void
    {
        self::assertTrue(class_exists(ConfigurationValidationFailed::class), ConfigurationValidationFailed::class . ' should exist.');

        $validator = new class implements ConfigurationValidator {
            public ?ApplicationKernel $kernel = null;

            public int $calls = 0;

            public function validate(Configuration $configuration): void
            {
                ++$this->calls;

                if ($this->kernel === null) {
                    throw new RuntimeException('Kernel reference is missing.');
                }

                $this->kernel->boot();
            }
        };
        $kernel = new ApplicationKernel(null, [$validator]);
        $validator->kernel = $kernel;

        try {
            $kernel->boot();
            self::fail('Reentrant boot should fail validation.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ConfigurationValidationFailed::class, $exception);
            self::assertInstanceOf(LifecycleException::class, $exception->getPrevious());
            self::assertSame(1, $validator->calls);
        }

        $this->assertFailsThroughLifecycleException(static function () use ($kernel): void {
            $kernel->boot();
        });
    }

    public function test_non_configuration_validator_entries_are_rejected_immediately(): void
    {
        self::expectException(InvalidArgumentException::class);

        new ApplicationKernel(null, ['invalid']);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function newConfiguration(array $values): Configuration
    {
        self::assertTrue(class_exists(ArrayConfiguration::class), ArrayConfiguration::class . ' should exist.');

        return new ArrayConfiguration($values);
    }

    /**
     * @param callable(Configuration): void $callback
     */
    private function validator(callable $callback): ConfigurationValidator
    {
        self::assertTrue(interface_exists(ConfigurationValidator::class), ConfigurationValidator::class . ' should exist.');

        return new class (Closure::fromCallable($callback)) implements ConfigurationValidator {
            public function __construct(private Closure $callback) {}

            public function validate(Configuration $configuration): void
            {
                ($this->callback)($configuration);
            }
        };
    }

    private function configurationFailure(string $message): ConfigurationException
    {
        self::assertTrue(interface_exists(ConfigurationException::class), ConfigurationException::class . ' should exist.');

        return new class ($message) extends RuntimeException implements ConfigurationException {};
    }

    private function ignoreExpectedConfigurationFailure(ApplicationKernel $kernel): void
    {
        try {
            $kernel->boot();
            self::fail('Configuration validation should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ConfigurationException::class, $exception);
        }
    }

    /**
     * @param callable(): mixed $operation
     */
    private function assertFailsThroughLifecycleException(callable $operation): void
    {
        self::assertTrue(interface_exists(LifecycleException::class), LifecycleException::class . ' should exist.');

        try {
            $operation();
            self::fail('Invalid lifecycle operation should throw a lifecycle exception.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(LifecycleException::class, $exception);
        }
    }
}
