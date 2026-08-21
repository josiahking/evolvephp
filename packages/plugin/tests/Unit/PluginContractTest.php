<?php

declare(strict_types=1);

namespace Evolve\Plugin\Tests\Unit;

use Evolve\Contracts\Component\ComponentEntryPoint;
use Evolve\Plugin\Plugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PluginContractTest extends TestCase
{
    public function test_plugin_is_exact_entry_point_sdk_interface(): void
    {
        self::assertTrue(interface_exists(Plugin::class), Plugin::class . ' should exist.');

        $contract = new ReflectionClass(Plugin::class);

        self::assertTrue($contract->isInterface());
        self::assertTrue($contract->implementsInterface(ComponentEntryPoint::class));
        self::assertStringContainsString('@experimental', (string) $contract->getDocComment());
        self::assertSame([], array_values(array_filter(
            $contract->getMethods(),
            static fn($method): bool => $method->getDeclaringClass()->getName() === Plugin::class,
        )));
    }
}
