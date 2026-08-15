<?php

declare(strict_types=1);

namespace Evolve\Module\Tests\Unit;

use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Module\Exception\IncompatibleModuleDescriptor;
use Evolve\Module\ModuleCompatibilityValidator;
use Evolve\Module\ModuleDescriptor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ModuleCompatibilityValidatorTest extends TestCase
{
    public function test_major_two_passes(): void
    {
        $validator = new ModuleCompatibilityValidator();

        $validator->validate(new ModuleDescriptor(new ComponentIdentifier('billing'), 'Billing', 2));

        $this->addToAssertionCount(1);
    }

    public function test_lower_incompatible_positive_major_fails(): void
    {
        $validator = new ModuleCompatibilityValidator();
        $descriptor = new ModuleDescriptor(new ComponentIdentifier('billing'), 'Billing', 1);

        try {
            $validator->validate($descriptor);

            self::fail('Expected incompatible module descriptor.');
        } catch (IncompatibleModuleDescriptor $exception) {
            self::assertStringContainsString('billing', $exception->getMessage());
            self::assertStringContainsString('1', $exception->getMessage());
            self::assertStringContainsString('2', $exception->getMessage());
        }
    }

    public function test_higher_incompatible_positive_major_fails(): void
    {
        $validator = new ModuleCompatibilityValidator();
        $descriptor = new ModuleDescriptor(new ComponentIdentifier('billing'), 'Billing', 3);

        try {
            $validator->validate($descriptor);

            self::fail('Expected incompatible module descriptor.');
        } catch (IncompatibleModuleDescriptor $exception) {
            self::assertStringContainsString('billing', $exception->getMessage());
            self::assertStringContainsString('3', $exception->getMessage());
            self::assertStringContainsString('2', $exception->getMessage());
        }
    }

    public function test_validator_and_exception_are_experimental(): void
    {
        foreach ([ModuleCompatibilityValidator::class, IncompatibleModuleDescriptor::class] as $className) {
            $reflection = new ReflectionClass($className);
            $docComment = $reflection->getDocComment();

            self::assertIsString($docComment);
            self::assertStringContainsString('@experimental', $docComment);
        }
    }
}
