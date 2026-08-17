<?php

declare(strict_types=1);

namespace Evolve\Contracts\Tests\Unit\Component;

use Evolve\Contracts\Component\CapabilityCardinality;
use Evolve\Contracts\Component\CapabilityIdentifier;
use Evolve\Contracts\Component\CapabilityRequirement;
use Evolve\Contracts\Component\ComponentConflict;
use Evolve\Contracts\Component\ComponentDependency;
use Evolve\Contracts\Component\ComponentDependencyKind;
use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentGraphRelations;
use Evolve\Contracts\Component\ComponentIdentifier;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumUnitCase;
use ReflectionMethod;

final class ComponentGraphDeclarationVocabularyTest extends TestCase
{
    public function test_capability_identifier_accepts_lowercase_ascii_grammar_and_preserves_input(): void
    {
        foreach (['search', 'search.provider', 'search_provider', 'search-provider', 'search2'] as $value) {
            $identifier = new CapabilityIdentifier($value);

            self::assertSame($value, $identifier->value());
            self::assertSame($value, (string) $identifier);
        }
    }

    public function test_capability_identifier_rejects_values_outside_grammar(): void
    {
        foreach (['', ' ', "search\n", 'Search', 'search/provider', 'search\\provider', '.search', 'search.'] as $value) {
            try {
                new CapabilityIdentifier($value);

                self::fail('Expected InvalidArgumentException for invalid capability identifier.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_capability_identifier_is_immutable_experimental_and_has_exact_public_surface(): void
    {
        $reflection = new ReflectionClass(CapabilityIdentifier::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertSame(['__construct', '__toString', 'value'], $this->publicMethodNames($reflection));
        self::assertStringContainsString('@experimental', (string) $reflection->getDocComment());
    }

    public function test_dependency_kind_cases_are_exact(): void
    {
        self::assertSame(
            ['Required', 'Optional'],
            array_map(
                static fn(ReflectionEnumUnitCase $case): string => $case->getName(),
                (new ReflectionEnum(ComponentDependencyKind::class))->getCases(),
            ),
        );
    }

    public function test_component_dependency_preserves_target_and_kind(): void
    {
        $target = new ComponentIdentifier('billing');
        $dependency = new ComponentDependency($target, ComponentDependencyKind::Required);

        self::assertSame($target, $dependency->target());
        self::assertSame(ComponentDependencyKind::Required, $dependency->kind());
    }

    public function test_component_conflict_preserves_target(): void
    {
        $target = new ComponentIdentifier('payments');
        $conflict = new ComponentConflict($target);

        self::assertSame($target, $conflict->target());
    }

    public function test_capability_cardinality_cases_are_exact(): void
    {
        $cases = [];

        foreach ((new ReflectionEnum(CapabilityCardinality::class))->getCases() as $case) {
            $cases[$case->getName()] = $case->getBackingValue();
        }

        self::assertSame(
            [
                'ExactlyOne' => 'exactly_one',
                'OneOrMore' => 'one_or_more',
            ],
            $cases,
        );
    }

    public function test_capability_requirement_defaults_to_exactly_one_and_preserves_capability(): void
    {
        $capability = new CapabilityIdentifier('search.provider');
        $requirement = new CapabilityRequirement($capability);

        self::assertSame($capability, $requirement->capability());
        self::assertSame(CapabilityCardinality::ExactlyOne, $requirement->cardinality());
    }

    public function test_capability_requirement_accepts_explicit_cardinality(): void
    {
        $capability = new CapabilityIdentifier('search.provider');
        $requirement = new CapabilityRequirement($capability, CapabilityCardinality::OneOrMore);

        self::assertSame($capability, $requirement->capability());
        self::assertSame(CapabilityCardinality::OneOrMore, $requirement->cardinality());
    }

    public function test_graph_relations_default_to_empty_lists(): void
    {
        $relations = new ComponentGraphRelations();

        self::assertSame([], $relations->dependencies());
        self::assertSame([], $relations->conflicts());
        self::assertSame([], $relations->requiredCapabilities());
        self::assertSame([], $relations->providedCapabilities());
    }

    public function test_graph_relations_reject_non_list_arrays_for_each_argument(): void
    {
        $dependency = new ComponentDependency(new ComponentIdentifier('billing'), ComponentDependencyKind::Required);
        $conflict = new ComponentConflict(new ComponentIdentifier('legacy'));
        $requiredCapability = new CapabilityRequirement(new CapabilityIdentifier('search'));
        $providedCapability = new CapabilityIdentifier('queue');

        $this->assertInvalidRelations(['first' => $dependency], [], [], []);
        $this->assertInvalidRelations([], ['first' => $conflict], [], []);
        $this->assertInvalidRelations([], [], ['first' => $requiredCapability], []);
        $this->assertInvalidRelations([], [], [], ['first' => $providedCapability]);
    }

    public function test_graph_relations_reject_invalid_element_types_for_each_argument(): void
    {
        $this->assertInvalidRelations([new ComponentIdentifier('billing')], [], [], []);
        $this->assertInvalidRelations([], [new ComponentIdentifier('legacy')], [], []);
        $this->assertInvalidRelations([], [], [new CapabilityIdentifier('search')], []);
        $this->assertInvalidRelations([], [], [], [new CapabilityRequirement(new CapabilityIdentifier('queue'))]);
    }

    public function test_graph_relations_reject_structural_invariant_violations(): void
    {
        $billing = new ComponentIdentifier('billing');
        $legacy = new ComponentIdentifier('legacy');
        $search = new CapabilityIdentifier('search');

        $this->assertInvalidRelations(
            [
                new ComponentDependency($billing, ComponentDependencyKind::Required),
                new ComponentDependency($billing, ComponentDependencyKind::Required),
            ],
            [],
            [],
            [],
        );
        $this->assertInvalidRelations(
            [
                new ComponentDependency($billing, ComponentDependencyKind::Required),
                new ComponentDependency($billing, ComponentDependencyKind::Optional),
            ],
            [],
            [],
            [],
        );
        $this->assertInvalidRelations(
            [],
            [new ComponentConflict($legacy), new ComponentConflict($legacy)],
            [],
            [],
        );
        $this->assertInvalidRelations(
            [],
            [],
            [new CapabilityRequirement($search), new CapabilityRequirement($search)],
            [],
        );
        $this->assertInvalidRelations(
            [],
            [],
            [],
            [$search, $search],
        );
        $this->assertInvalidRelations(
            [new ComponentDependency($billing, ComponentDependencyKind::Required)],
            [new ComponentConflict($billing)],
            [],
            [],
        );
    }

    public function test_graph_relations_expose_canonical_order_without_recreating_objects(): void
    {
        $zetaDependency = new ComponentDependency(new ComponentIdentifier('zeta'), ComponentDependencyKind::Optional);
        $alphaDependency = new ComponentDependency(new ComponentIdentifier('alpha'), ComponentDependencyKind::Required);
        $zetaConflict = new ComponentConflict(new ComponentIdentifier('zeta-conflict'));
        $alphaConflict = new ComponentConflict(new ComponentIdentifier('alpha-conflict'));
        $zetaRequirement = new CapabilityRequirement(new CapabilityIdentifier('zeta.capability'));
        $alphaRequirement = new CapabilityRequirement(new CapabilityIdentifier('alpha.capability'));
        $zetaProvided = new CapabilityIdentifier('zeta.provided');
        $alphaProvided = new CapabilityIdentifier('alpha.provided');

        $relations = new ComponentGraphRelations(
            [$zetaDependency, $alphaDependency],
            [$zetaConflict, $alphaConflict],
            [$zetaRequirement, $alphaRequirement],
            [$zetaProvided, $alphaProvided],
        );

        self::assertSame([$alphaDependency, $zetaDependency], $relations->dependencies());
        self::assertSame([$alphaConflict, $zetaConflict], $relations->conflicts());
        self::assertSame([$alphaRequirement, $zetaRequirement], $relations->requiredCapabilities());
        self::assertSame([$alphaProvided, $zetaProvided], $relations->providedCapabilities());
    }

    public function test_graph_declaration_preserves_identifier_and_relations_objects(): void
    {
        $identifier = new ComponentIdentifier('billing');
        $relations = new ComponentGraphRelations();
        $declaration = new ComponentGraphDeclaration($identifier, $relations);

        self::assertSame($identifier, $declaration->identifier());
        self::assertSame($relations, $declaration->relations());
    }

    public function test_graph_declaration_rejects_self_dependency_and_self_conflict(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ComponentGraphDeclaration(
            new ComponentIdentifier('billing'),
            new ComponentGraphRelations(
                [new ComponentDependency(new ComponentIdentifier('billing'), ComponentDependencyKind::Required)],
            ),
        );
    }

    public function test_graph_declaration_rejects_self_conflict(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ComponentGraphDeclaration(
            new ComponentIdentifier('billing'),
            new ComponentGraphRelations(
                [],
                [new ComponentConflict(new ComponentIdentifier('billing'))],
            ),
        );
    }

    public function test_graph_declaration_is_immutable_experimental_and_has_exact_public_surface(): void
    {
        $reflection = new ReflectionClass(ComponentGraphDeclaration::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertSame(['__construct', 'identifier', 'relations'], $this->publicMethodNames($reflection));
        self::assertStringContainsString('@experimental', (string) $reflection->getDocComment());
    }

    public function test_all_graph_value_objects_are_public_experimental_with_exact_surfaces(): void
    {
        $expectedSurfaces = [
            ComponentDependency::class => ['__construct', 'kind', 'target'],
            ComponentConflict::class => ['__construct', 'target'],
            CapabilityRequirement::class => ['__construct', 'capability', 'cardinality'],
            ComponentGraphRelations::class => ['__construct', 'conflicts', 'dependencies', 'providedCapabilities', 'requiredCapabilities'],
        ];

        foreach ($expectedSurfaces as $className => $expectedMethods) {
            $reflection = new ReflectionClass($className);

            self::assertTrue($reflection->isFinal(), $className);
            self::assertTrue($reflection->isReadOnly(), $className);
            self::assertSame($expectedMethods, $this->publicMethodNames($reflection), $className);
            self::assertStringContainsString('@experimental', (string) $reflection->getDocComment(), $className);
        }
    }

    public function test_graph_enums_are_public_experimental_with_exact_surfaces(): void
    {
        $expectedSurfaces = [
            ComponentDependencyKind::class => ['cases'],
            CapabilityCardinality::class => ['cases', 'from', 'tryFrom'],
        ];

        foreach ($expectedSurfaces as $className => $expectedMethods) {
            $reflection = new ReflectionEnum($className);

            self::assertStringContainsString('@experimental', (string) $reflection->getDocComment(), $className);
            self::assertSame($expectedMethods, $this->publicMethodNames($reflection), $className);
        }
    }

    public function test_no_provided_capability_wrapper_type_exists(): void
    {
        self::assertFalse(class_exists('Evolve\\Contracts\\Component\\ProvidedCapability'));
    }

    /**
     * @param array<mixed> $dependencies
     * @param array<mixed> $conflicts
     * @param array<mixed> $requiredCapabilities
     * @param array<mixed> $providedCapabilities
     */
    private function assertInvalidRelations(
        array $dependencies,
        array $conflicts,
        array $requiredCapabilities,
        array $providedCapabilities,
    ): void {
        try {
            new ComponentGraphRelations($dependencies, $conflicts, $requiredCapabilities, $providedCapabilities);

            self::fail('Expected InvalidArgumentException for invalid graph relations.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
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
