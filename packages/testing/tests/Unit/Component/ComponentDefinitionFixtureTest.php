<?php

declare(strict_types=1);

namespace Evolve\Testing\Tests\Unit\Component;

use Evolve\Contracts\Component\CapabilityIdentifier;
use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\ComponentDependency;
use Evolve\Contracts\Component\ComponentDependencyKind;
use Evolve\Contracts\Component\ComponentEntryPoint;
use Evolve\Contracts\Component\ComponentGraphRelations;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Component\ComponentType;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Testing\Component\ComponentDefinitionFixture;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ComponentDefinitionFixtureTest extends TestCase
{
    public function test_identifier_is_returned_exactly(): void
    {
        $identifier = new ComponentIdentifier('app/testing-fixture');
        $fixture = $this->fixture(identifier: $identifier);

        self::assertSame($identifier, $fixture->identifier());
    }

    public function test_component_type_is_returned_exactly(): void
    {
        $fixture = $this->fixture(type: ComponentType::Plugin);

        self::assertSame(ComponentType::Plugin, $fixture->type());
    }

    public function test_graph_relations_are_represented_from_supplied_identifier_and_relations(): void
    {
        $identifier = new ComponentIdentifier('app/testing-fixture');
        $dependency = new ComponentDependency(
            new ComponentIdentifier('vendor/dependency'),
            ComponentDependencyKind::Required,
        );
        $capability = new CapabilityIdentifier('testing-capability');
        $relations = new ComponentGraphRelations(
            dependencies: [$dependency],
            providedCapabilities: [$capability],
        );
        $fixture = $this->fixture(identifier: $identifier, relations: $relations);
        $declaration = $fixture->graphDeclaration();

        self::assertSame($identifier, $declaration->identifier());
        self::assertSame($relations, $declaration->relations());
        self::assertSame([$dependency], $declaration->relations()->dependencies());
        self::assertSame([$capability], $declaration->relations()->providedCapabilities());
    }

    public function test_repeated_graph_declaration_calls_return_the_same_object(): void
    {
        $fixture = $this->fixture();

        self::assertSame($fixture->graphDeclaration(), $fixture->graphDeclaration());
    }

    public function test_validator_is_optional(): void
    {
        $this->fixture()->validate();

        self::addToAssertionCount(1);
    }

    public function test_supplied_validator_executes(): void
    {
        $calls = 0;
        $fixture = $this->fixture(validator: static function () use (&$calls): void {
            ++$calls;
        });

        $fixture->validate();

        self::assertSame(1, $calls);
    }

    public function test_validator_exceptions_propagate_unchanged(): void
    {
        $failure = new RuntimeException('validator failed');
        $fixture = $this->fixture(validator: static function () use ($failure): void {
            throw $failure;
        });

        try {
            $fixture->validate();
            self::fail('Validator failure should propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function test_entry_point_factory_executes_and_returns_the_produced_entry_point(): void
    {
        $calls = 0;
        $entryPoint = new DefinitionFixtureEntryPoint();
        $fixture = $this->fixture(entryPointFactory: static function () use (&$calls, $entryPoint): ComponentEntryPoint {
            ++$calls;

            return $entryPoint;
        });

        self::assertSame($entryPoint, $fixture->createEntryPoint());
        self::assertSame(1, $calls);
    }

    public function test_entry_point_factory_exceptions_propagate_unchanged(): void
    {
        $failure = new RuntimeException('factory failed');
        $fixture = $this->fixture(entryPointFactory: static function () use ($failure): ComponentEntryPoint {
            throw $failure;
        });

        try {
            $fixture->createEntryPoint();
            self::fail('Entry-point factory failure should propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function test_create_entry_point_invokes_factory_instead_of_caching_result(): void
    {
        $calls = 0;
        $fixture = $this->fixture(entryPointFactory: static function () use (&$calls): ComponentEntryPoint {
            ++$calls;

            return new DefinitionFixtureEntryPoint();
        });

        $first = $fixture->createEntryPoint();
        $second = $fixture->createEntryPoint();

        self::assertSame(2, $calls);
        self::assertNotSame($first, $second);
    }

    private function fixture(
        ?ComponentIdentifier $identifier = null,
        ComponentType $type = ComponentType::Module,
        ?ComponentGraphRelations $relations = null,
        ?\Closure $entryPointFactory = null,
        ?\Closure $validator = null,
    ): ComponentDefinitionFixture {
        return new ComponentDefinitionFixture(
            $identifier ?? new ComponentIdentifier('app/testing-fixture'),
            $type,
            $entryPointFactory ?? static fn(): ComponentEntryPoint => new DefinitionFixtureEntryPoint(),
            $relations ?? new ComponentGraphRelations(),
            $validator,
        );
    }
}

final class DefinitionFixtureEntryPoint implements ComponentEntryPoint
{
    public function register(ServiceDefinitionRegistrar $registrar): void {}

    public function boot(ComponentBootContext $context): void {}

    public function ready(): void {}

    public function shutdown(): void {}
}
