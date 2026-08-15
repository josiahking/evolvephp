<?php

declare(strict_types=1);

namespace Evolve\Plugin\Tests\Unit;

use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Plugin\Exception\IncompatiblePluginDescriptor;
use Evolve\Plugin\PluginCompatibilityValidator;
use Evolve\Plugin\PluginDescriptor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PluginCompatibilityValidatorTest extends TestCase
{
    public function test_major_two_passes(): void
    {
        $validator = new PluginCompatibilityValidator();

        $validator->validate(new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', 2));

        $this->addToAssertionCount(1);
    }

    public function test_lower_incompatible_positive_major_fails(): void
    {
        $validator = new PluginCompatibilityValidator();
        $descriptor = new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', 1);

        try {
            $validator->validate($descriptor);

            self::fail('Expected incompatible plugin descriptor.');
        } catch (IncompatiblePluginDescriptor $exception) {
            self::assertStringContainsString('vendor/plugin', $exception->getMessage());
            self::assertStringContainsString('1', $exception->getMessage());
            self::assertStringContainsString('2', $exception->getMessage());
        }
    }

    public function test_higher_incompatible_positive_major_fails(): void
    {
        $validator = new PluginCompatibilityValidator();
        $descriptor = new PluginDescriptor(new ComponentIdentifier('vendor/plugin'), 'Queue Plugin', 3);

        try {
            $validator->validate($descriptor);

            self::fail('Expected incompatible plugin descriptor.');
        } catch (IncompatiblePluginDescriptor $exception) {
            self::assertStringContainsString('vendor/plugin', $exception->getMessage());
            self::assertStringContainsString('3', $exception->getMessage());
            self::assertStringContainsString('2', $exception->getMessage());
        }
    }

    public function test_validator_and_exception_are_experimental(): void
    {
        foreach ([PluginCompatibilityValidator::class, IncompatiblePluginDescriptor::class] as $className) {
            $reflection = new ReflectionClass($className);
            $docComment = $reflection->getDocComment();

            self::assertIsString($docComment);
            self::assertStringContainsString('@experimental', $docComment);
        }
    }
}
