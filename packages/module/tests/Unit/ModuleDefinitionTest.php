<?php

declare(strict_types=1);

namespace Evolve\Module\Tests\Unit;

use Evolve\Contracts\Component\ComponentEntryPoint;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Component\ComponentType;
use Evolve\Module\Exception\IncompatibleModuleDescriptor;
use Evolve\Module\Module;
use Evolve\Module\ModuleDefinition;
use Evolve\Module\ModuleDescriptor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class ModuleDefinitionTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingModuleEntryPoint::$constructions = 0;
    }

    public function test_projects_module_descriptor_metadata_without_instantiating_entry_point(): void
    {
        $descriptor = new ModuleDescriptor(new ComponentIdentifier('billing'), 'Billing', 2);
        $definition = new ModuleDefinition($descriptor, RecordingModuleEntryPoint::class);

        self::assertTrue((new ReflectionClass($definition))->implementsInterface(\Evolve\Contracts\Component\ComponentDefinition::class));
        self::assertSame($descriptor->identifier(), $definition->identifier());
        self::assertSame(ComponentType::Module, $definition->type());
        self::assertSame($descriptor->graphDeclaration(), $definition->graphDeclaration());
        self::assertSame(0, RecordingModuleEntryPoint::$constructions);
    }

    public function test_validate_reuses_module_compatibility_and_checks_module_entry_point_class_without_instantiation(): void
    {
        $definition = new ModuleDefinition(
            new ModuleDescriptor(new ComponentIdentifier('billing'), 'Billing', 2),
            RecordingModuleEntryPoint::class,
        );

        $definition->validate();

        self::assertSame(0, RecordingModuleEntryPoint::$constructions);
    }

    public function test_validate_rejects_incompatible_module_descriptor(): void
    {
        $definition = new ModuleDefinition(
            new ModuleDescriptor(new ComponentIdentifier('billing'), 'Billing', 3),
            RecordingModuleEntryPoint::class,
        );

        self::expectException(IncompatibleModuleDescriptor::class);

        $definition->validate();
    }

    public function test_validate_rejects_nonexistent_entry_point_class(): void
    {
        $definition = new ModuleDefinition(
            new ModuleDescriptor(new ComponentIdentifier('billing'), 'Billing', 2),
            'Acme\\MissingModule',
        );

        self::expectException(InvalidArgumentException::class);

        $definition->validate();
    }

    public function test_validate_rejects_plugin_or_generic_entry_point_that_is_not_module_specific(): void
    {
        $definition = new ModuleDefinition(
            new ModuleDescriptor(new ComponentIdentifier('billing'), 'Billing', 2),
            GenericComponentEntryPoint::class,
        );

        self::expectException(InvalidArgumentException::class);

        $definition->validate();
    }

    public function test_create_entry_point_uses_zero_argument_construction_and_returns_component_entry_point(): void
    {
        $definition = new ModuleDefinition(
            new ModuleDescriptor(new ComponentIdentifier('billing'), 'Billing', 2),
            RecordingModuleEntryPoint::class,
        );

        $entryPoint = $definition->createEntryPoint();

        self::assertInstanceOf(Module::class, $entryPoint);
        self::assertSame(1, RecordingModuleEntryPoint::$constructions);
    }

    public function test_create_entry_point_propagates_constructor_failure(): void
    {
        $definition = new ModuleDefinition(
            new ModuleDescriptor(new ComponentIdentifier('billing'), 'Billing', 2),
            FailingModuleEntryPoint::class,
        );

        self::expectException(RuntimeException::class);

        $definition->createEntryPoint();
    }

    public function test_class_is_final_and_experimental(): void
    {
        $definition = new ReflectionClass(ModuleDefinition::class);
        $docComment = $definition->getDocComment();

        self::assertTrue($definition->isFinal());
        self::assertIsString($docComment);
        self::assertStringContainsString('@experimental', $docComment);
    }
}

final class RecordingModuleEntryPoint implements Module
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }

    public function register(\Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar $registrar): void {}

    public function boot(\Evolve\Contracts\Component\ComponentBootContext $context): void {}

    public function ready(): void {}

    public function shutdown(): void {}
}

final class FailingModuleEntryPoint implements Module
{
    public function __construct()
    {
        throw new RuntimeException('module construction failed');
    }

    public function register(\Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar $registrar): void {}

    public function boot(\Evolve\Contracts\Component\ComponentBootContext $context): void {}

    public function ready(): void {}

    public function shutdown(): void {}
}

final class GenericComponentEntryPoint implements ComponentEntryPoint
{
    public function register(\Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar $registrar): void {}

    public function boot(\Evolve\Contracts\Component\ComponentBootContext $context): void {}

    public function ready(): void {}

    public function shutdown(): void {}
}
