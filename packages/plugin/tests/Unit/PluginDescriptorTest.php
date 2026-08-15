<?php

declare(strict_types=1);

namespace Evolve\Plugin\Tests\Unit;

use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Component\ComponentType;
use Evolve\Plugin\PluginDescriptor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PluginDescriptorTest extends TestCase
{
    public function test_accepts_valid_component_identifier_and_returns_identical_object(): void
    {
        $identifier = new ComponentIdentifier('vendor/plugin');
        $descriptor = new PluginDescriptor($identifier, 'Queue Plugin', 2);

        self::assertSame($identifier, $descriptor->identifier());
    }

    public function test_preserves_exact_human_readable_name(): void
    {
        $descriptor = new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', 2);

        self::assertSame('Queue Plugin', $descriptor->name());
    }

    public function test_preserves_non_whitespace_name_with_surrounding_whitespace(): void
    {
        $descriptor = new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), ' Queue Plugin ', 2);

        self::assertSame(' Queue Plugin ', $descriptor->name());
    }

    public function test_rejects_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), '', 2);
    }

    public function test_rejects_whitespace_only_names(): void
    {
        $invalidNames = [' ', '   ', "\t", "\n", "\t \n"];
        $rejected = 0;

        foreach ($invalidNames as $name) {
            try {
                new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), $name, 2);

                self::fail('Expected InvalidArgumentException for whitespace-only name.');
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        $this->addToAssertionCount($rejected);
    }

    public function test_accepts_evolve_major_two(): void
    {
        $descriptor = new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', 2);

        self::assertSame(2, $descriptor->evolveMajor());
    }

    public function test_permits_positive_non_two_major_structurally(): void
    {
        $descriptor = new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', 3);

        self::assertSame(3, $descriptor->evolveMajor());
    }

    public function test_rejects_zero_evolve_major(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', 0);
    }

    public function test_rejects_negative_evolve_major(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', -1);
    }

    public function test_type_is_plugin(): void
    {
        $descriptor = new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', 2);

        self::assertSame(ComponentType::Plugin, $descriptor->type());
    }

    public function test_schema_version_is_one(): void
    {
        $descriptor = new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', 2);

        self::assertSame(1, $descriptor->schemaVersion());
    }

    public function test_class_is_final_and_readonly(): void
    {
        $descriptor = new ReflectionClass(PluginDescriptor::class);

        self::assertTrue($descriptor->isFinal());
        self::assertTrue($descriptor->isReadOnly());
    }

    public function test_phpdoc_marks_api_experimental(): void
    {
        $descriptor = new ReflectionClass(PluginDescriptor::class);
        $docComment = $descriptor->getDocComment();

        self::assertIsString($docComment);
        self::assertStringContainsString('@experimental', $docComment);
    }

    public function test_public_method_surface_is_exact(): void
    {
        $descriptor = new ReflectionClass(PluginDescriptor::class);

        self::assertSame(
            ['__construct', 'evolveMajor', 'identifier', 'name', 'schemaVersion', 'type'],
            $this->publicMethodNames($descriptor),
        );
    }

    /**
     * @template T of object
     *
     * @param ReflectionClass<T> $class
     *
     * @return list<string>
     */
    private function publicMethodNames(ReflectionClass $class): array
    {
        $methodNames = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methodNames);

        return $methodNames;
    }
}
