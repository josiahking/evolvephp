<?php

declare(strict_types=1);

namespace Evolve\Contracts\Tests\Unit\Component;

use Evolve\Contracts\Component\ComponentIdentifier;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

final class ComponentIdentifierTest extends TestCase
{
    public function test_accepts_simple_identifier(): void
    {
        foreach (['billing', 'identity'] as $value) {
            $identifier = new ComponentIdentifier($value);

            self::assertSame($value, $identifier->value());
        }
    }

    public function test_accepts_dotted_identifier(): void
    {
        $identifier = new ComponentIdentifier('acme.reporting');

        self::assertSame('acme.reporting', $identifier->value());
    }

    public function test_accepts_composer_style_identifier(): void
    {
        $identifier = new ComponentIdentifier('vendor/plugin');

        self::assertSame('vendor/plugin', $identifier->value());
    }

    public function test_accepts_hyphen_underscore_dot_characters(): void
    {
        foreach (['vendor/plugin-name', 'vendor/plugin_name', 'vendor/plugin.name', 'vendor/plugin--name'] as $value) {
            $identifier = new ComponentIdentifier($value);

            self::assertSame($value, $identifier->value());
        }
    }

    public function test_preserves_exact_value_through_value_method(): void
    {
        $identifier = new ComponentIdentifier('vendor/plugin_name');

        self::assertSame('vendor/plugin_name', $identifier->value());
    }

    public function test_preserves_exact_value_through_string_cast(): void
    {
        $identifier = new ComponentIdentifier('vendor/plugin.name');

        self::assertSame('vendor/plugin.name', (string) $identifier);
    }

    public function test_rejects_invalid_identifiers(): void
    {
        $invalidIdentifiers = [
            '',
            'Billing',
            ' billing',
            'billing ',
            '.billing',
            'billing.',
            '-billing',
            'billing-',
            '_billing',
            'billing_',
            '/vendor',
            'vendor/',
            'vendor/plugin/extra',
            'vendor\\plugin',
            'vendor:plugin',
            'billing module',
        ];
        $rejected = 0;

        foreach ($invalidIdentifiers as $value) {
            try {
                new ComponentIdentifier($value);

                self::fail('Expected InvalidArgumentException for identifier: ' . $value);
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        $this->addToAssertionCount($rejected);
    }

    public function test_class_is_final_and_readonly(): void
    {
        $contract = new ReflectionClass(ComponentIdentifier::class);

        self::assertTrue($contract->isFinal());
        self::assertTrue($contract->isReadOnly());
    }

    public function test_exposes_only_constructor_value_and_string_cast(): void
    {
        $contract = new ReflectionClass(ComponentIdentifier::class);

        self::assertSame(['__construct', '__toString', 'value'], $this->publicMethodNames($contract));

        $constructor = $contract->getConstructor();

        self::assertInstanceOf(ReflectionMethod::class, $constructor);
        self::assertSame(['value'], array_map(
            static fn(ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        ));

        $parameterType = $constructor->getParameters()[0]->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $parameterType);
        self::assertSame('string', $parameterType->getName());

        $valueReturnType = $contract->getMethod('value')->getReturnType();
        $toStringReturnType = $contract->getMethod('__toString')->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $valueReturnType);
        self::assertInstanceOf(ReflectionNamedType::class, $toStringReturnType);
        self::assertSame('string', $valueReturnType->getName());
        self::assertSame('string', $toStringReturnType->getName());
    }

    public function test_phpdoc_marks_api_experimental(): void
    {
        $contract = new ReflectionClass(ComponentIdentifier::class);
        $docComment = $contract->getDocComment();

        self::assertIsString($docComment);
        self::assertStringContainsString('@experimental', $docComment);
    }

    /**
     * @template T of object
     *
     * @param ReflectionClass<T> $contract
     *
     * @return list<string>
     */
    private function publicMethodNames(ReflectionClass $contract): array
    {
        $methodNames = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $contract->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methodNames);

        return $methodNames;
    }
}
