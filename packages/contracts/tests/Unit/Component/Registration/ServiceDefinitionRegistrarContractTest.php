<?php

declare(strict_types=1);

namespace Evolve\Contracts\Tests\Unit\Component\Registration;

use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ServiceDefinitionRegistrarContractTest extends TestCase
{
    public function test_public_experimental_registrar_exposes_only_restricted_contribution_methods(): void
    {
        self::assertTrue(interface_exists(ServiceDefinitionRegistrar::class));

        $reflection = new ReflectionClass(ServiceDefinitionRegistrar::class);

        self::assertTrue($reflection->isInterface());
        self::assertStringContainsString('@experimental', (string) $reflection->getDocComment());
        self::assertSame(
            ['registerApplication', 'registerExecution', 'registerTransient'],
            array_map(static fn($method): string => $method->getName(), $reflection->getMethods()),
        );

        foreach (['register', 'get', 'has', 'freeze', 'createExecutionScope', 'remove', 'replace'] as $forbiddenMethod) {
            self::assertFalse($reflection->hasMethod($forbiddenMethod), ServiceDefinitionRegistrar::class . ' must not expose ' . $forbiddenMethod . '().');
        }
    }

    public function test_factory_phpdoc_accepts_zero_or_one_psr11_resolver_argument(): void
    {
        $reflection = new ReflectionClass(ServiceDefinitionRegistrar::class);
        $expectedParam = '@param callable(\Psr\Container\ContainerInterface=): mixed $factory';

        foreach (['registerApplication', 'registerExecution', 'registerTransient'] as $methodName) {
            $method = $reflection->getMethod($methodName);

            self::assertSame(['id', 'factory'], array_map(static fn($parameter): string => $parameter->getName(), $method->getParameters()));
            self::assertSame('void', (string) $method->getReturnType());
            self::assertStringContainsString($expectedParam, (string) $method->getDocComment());
        }
    }

    public function test_contracts_manifest_owns_psr11_factory_contract_dependency_without_first_party_dependencies(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/composer.json'),
            true,
        );

        self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
        self::assertSame('^1.1 || ^2.0', $manifest['require']['psr/container'] ?? null);

        foreach (array_keys($manifest['require']) as $packageName) {
            self::assertStringStartsNotWith('evolvephp/', $packageName, 'Contracts must keep no first-party EvolvePHP dependency.');
        }
    }
}
