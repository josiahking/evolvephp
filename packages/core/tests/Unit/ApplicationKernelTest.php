<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit;

use Evolve\Contracts\Exception\LifecycleException;
use Evolve\Contracts\Lifecycle\ApplicationLifecycle;
use Evolve\Core\ApplicationKernel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

final class ApplicationKernelTest extends TestCase
{
    public function test_application_kernel_exists_and_implements_lifecycle_contract(): void
    {
        self::assertTrue(class_exists(ApplicationKernel::class), ApplicationKernel::class . ' should exist.');
        self::assertTrue(interface_exists(ApplicationLifecycle::class), ApplicationLifecycle::class . ' should exist.');

        $kernel = new ReflectionClass(ApplicationKernel::class);

        self::assertTrue($kernel->isFinal());
        self::assertTrue($kernel->implementsInterface(ApplicationLifecycle::class));
        self::assertNull($kernel->getConstructor());
    }

    public function test_new_kernel_can_boot_once(): void
    {
        $kernel = $this->newKernel();

        $kernel->boot();
    }

    public function test_booted_kernel_can_shutdown_once(): void
    {
        $kernel = $this->newKernel();

        $kernel->boot();
        $kernel->shutdown();
    }

    public function test_boot_twice_fails_through_lifecycle_exception(): void
    {
        $kernel = $this->newKernel();
        $kernel->boot();

        $this->assertFailsThroughLifecycleException(static function () use ($kernel): void {
            $kernel->boot();
        });
    }

    public function test_shutdown_before_boot_fails_through_lifecycle_exception(): void
    {
        $kernel = $this->newKernel();

        $this->assertFailsThroughLifecycleException(static function () use ($kernel): void {
            $kernel->shutdown();
        });
    }

    public function test_boot_after_shutdown_fails_through_lifecycle_exception(): void
    {
        $kernel = $this->newKernel();
        $kernel->boot();
        $kernel->shutdown();

        $this->assertFailsThroughLifecycleException(static function () use ($kernel): void {
            $kernel->boot();
        });
    }

    public function test_shutdown_twice_fails_through_lifecycle_exception(): void
    {
        $kernel = $this->newKernel();
        $kernel->boot();
        $kernel->shutdown();

        $this->assertFailsThroughLifecycleException(static function () use ($kernel): void {
            $kernel->shutdown();
        });
    }

    public function test_concrete_failure_satisfies_public_lifecycle_catch_boundary(): void
    {
        $kernel = $this->newKernel();

        $this->assertFailsThroughLifecycleException(static function () use ($kernel): void {
            $kernel->shutdown();
        });
    }

    private function newKernel(): ApplicationKernel
    {
        self::assertTrue(class_exists(ApplicationKernel::class), ApplicationKernel::class . ' should exist.');

        return new ApplicationKernel();
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
