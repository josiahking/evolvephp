<?php

declare(strict_types=1);

namespace Evolve\Module\Tests\Unit;

use Evolve\Contracts\Component\ComponentEntryPoint;
use Evolve\Module\Module;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ModuleContractTest extends TestCase
{
    public function test_module_is_exact_entry_point_sdk_interface(): void
    {
        self::assertTrue(interface_exists(Module::class), Module::class . ' should exist.');

        $contract = new ReflectionClass(Module::class);

        self::assertTrue($contract->isInterface());
        self::assertTrue($contract->implementsInterface(ComponentEntryPoint::class));
        self::assertStringContainsString('@experimental', (string) $contract->getDocComment());
        self::assertSame([], array_values(array_filter(
            $contract->getMethods(),
            static fn($method): bool => $method->getDeclaringClass()->getName() === Module::class,
        )));
    }
}
