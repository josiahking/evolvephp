<?php

declare(strict_types=1);

namespace Evolve\Contracts\Tests\Unit\Component;

use Evolve\Contracts\Component\ComponentType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumUnitCase;

final class ComponentTypeTest extends TestCase
{
    public function test_exposes_exactly_module_and_plugin_cases(): void
    {
        $contract = new ReflectionEnum(ComponentType::class);

        self::assertSame(['Module', 'Plugin'], array_map(
            static fn(ReflectionEnumUnitCase $case): string => $case->getName(),
            $contract->getCases(),
        ));
    }

    public function test_module_backed_value_is_module(): void
    {
        $case = (new ReflectionEnum(ComponentType::class))->getCase('Module');

        self::assertSame('module', $case->getBackingValue());
    }

    public function test_plugin_backed_value_is_plugin(): void
    {
        $case = (new ReflectionEnum(ComponentType::class))->getCase('Plugin');

        self::assertSame('plugin', $case->getBackingValue());
    }

    public function test_phpdoc_marks_api_experimental(): void
    {
        $contract = new ReflectionClass(ComponentType::class);
        $docComment = $contract->getDocComment();

        self::assertIsString($docComment);
        self::assertStringContainsString('@experimental', $docComment);
    }
}
