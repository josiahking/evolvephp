<?php

declare(strict_types=1);

namespace Evolve\Plugin\Tests\Unit;

use Evolve\Contracts\Component\ComponentEntryPoint;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Component\ComponentType;
use Evolve\Plugin\Exception\IncompatiblePluginDescriptor;
use Evolve\Plugin\Plugin;
use Evolve\Plugin\PluginDefinition;
use Evolve\Plugin\PluginDescriptor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class PluginDefinitionTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingPluginEntryPoint::$constructions = 0;
    }

    public function test_projects_plugin_descriptor_metadata_without_instantiating_entry_point(): void
    {
        $descriptor = new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', 2);
        $definition = new PluginDefinition($descriptor, RecordingPluginEntryPoint::class);

        self::assertTrue((new ReflectionClass($definition))->implementsInterface(\Evolve\Contracts\Component\ComponentDefinition::class));
        self::assertSame($descriptor->identifier(), $definition->identifier());
        self::assertSame(ComponentType::Plugin, $definition->type());
        self::assertSame($descriptor->graphDeclaration(), $definition->graphDeclaration());
        self::assertSame(0, RecordingPluginEntryPoint::$constructions);
    }

    public function test_validate_reuses_plugin_compatibility_and_checks_plugin_entry_point_class_without_instantiation(): void
    {
        $definition = new PluginDefinition(
            new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', 2),
            RecordingPluginEntryPoint::class,
        );

        $definition->validate();

        self::assertSame(0, RecordingPluginEntryPoint::$constructions);
    }

    public function test_validate_rejects_incompatible_plugin_descriptor(): void
    {
        $definition = new PluginDefinition(
            new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', 3),
            RecordingPluginEntryPoint::class,
        );

        self::expectException(IncompatiblePluginDescriptor::class);

        $definition->validate();
    }

    public function test_validate_rejects_nonexistent_entry_point_class(): void
    {
        $definition = new PluginDefinition(
            new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', 2),
            'Acme\\MissingPlugin',
        );

        self::expectException(InvalidArgumentException::class);

        $definition->validate();
    }

    public function test_validate_rejects_generic_entry_point_that_is_not_plugin_specific(): void
    {
        $definition = new PluginDefinition(
            new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', 2),
            GenericPluginComponentEntryPoint::class,
        );

        self::expectException(InvalidArgumentException::class);

        $definition->validate();
    }

    public function test_create_entry_point_uses_zero_argument_construction_and_returns_component_entry_point(): void
    {
        $definition = new PluginDefinition(
            new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', 2),
            RecordingPluginEntryPoint::class,
        );

        $entryPoint = $definition->createEntryPoint();

        self::assertInstanceOf(Plugin::class, $entryPoint);
        self::assertSame(1, RecordingPluginEntryPoint::$constructions);
    }

    public function test_create_entry_point_propagates_constructor_failure(): void
    {
        $definition = new PluginDefinition(
            new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', 2),
            FailingPluginEntryPoint::class,
        );

        self::expectException(RuntimeException::class);

        $definition->createEntryPoint();
    }

    public function test_class_is_final_and_experimental(): void
    {
        $definition = new ReflectionClass(PluginDefinition::class);
        $docComment = $definition->getDocComment();

        self::assertTrue($definition->isFinal());
        self::assertIsString($docComment);
        self::assertStringContainsString('@experimental', $docComment);
    }
}

final class RecordingPluginEntryPoint implements Plugin
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

final class FailingPluginEntryPoint implements Plugin
{
    public function __construct()
    {
        throw new RuntimeException('plugin construction failed');
    }

    public function register(\Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar $registrar): void {}

    public function boot(\Evolve\Contracts\Component\ComponentBootContext $context): void {}

    public function ready(): void {}

    public function shutdown(): void {}
}

final class GenericPluginComponentEntryPoint implements ComponentEntryPoint
{
    public function register(\Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar $registrar): void {}

    public function boot(\Evolve\Contracts\Component\ComponentBootContext $context): void {}

    public function ready(): void {}

    public function shutdown(): void {}
}
