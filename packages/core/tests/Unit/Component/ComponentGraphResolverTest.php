<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Component;

use Evolve\Contracts\Component\CapabilityCardinality;
use Evolve\Contracts\Component\CapabilityIdentifier;
use Evolve\Contracts\Component\CapabilityRequirement;
use Evolve\Contracts\Component\ComponentConflict;
use Evolve\Contracts\Component\ComponentDependency;
use Evolve\Contracts\Component\ComponentDependencyKind;
use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentGraphRelations;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Core\Component\CapabilityProviderSelection;
use Evolve\Core\Component\ComponentGraphResolver;
use Evolve\Core\Component\ResolvedComponentGraph;
use Evolve\Core\Exception\ActiveComponentConflict;
use Evolve\Core\Exception\AmbiguousCapabilityProvider;
use Evolve\Core\Exception\ComponentDependencyCycle;
use Evolve\Core\Exception\ComponentGraphResolutionFailed;
use Evolve\Core\Exception\DuplicateComponentIdentifier;
use Evolve\Core\Exception\InvalidCapabilityProviderSelection;
use Evolve\Core\Exception\MissingCapabilityProvider;
use Evolve\Core\Exception\MissingComponentDependency;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ComponentGraphResolverTest extends TestCase
{
    public function test_declarations_must_be_a_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // @phpstan-ignore argument.type (intentional invalid runtime boundary input)
        (new ComponentGraphResolver())->resolve(['app' => $this->declaration('app')]);
    }

    public function test_declaration_elements_must_be_graph_declarations(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // @phpstan-ignore argument.type (intentional invalid runtime boundary input)
        (new ComponentGraphResolver())->resolve([$this->id('app')]);
    }

    public function test_provider_selections_must_be_a_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // @phpstan-ignore argument.type (intentional invalid runtime boundary input)
        (new ComponentGraphResolver())->resolve([$this->declaration('app')], ['cache' => new CapabilityProviderSelection($this->id('app'), $this->capability('cache'), $this->id('redis'))]);
    }

    public function test_provider_selection_elements_must_be_selection_objects(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // @phpstan-ignore argument.type (intentional invalid runtime boundary input)
        (new ComponentGraphResolver())->resolve([$this->declaration('app')], [$this->id('app')]);
    }

    public function test_public_api_surfaces_and_classification_are_exact(): void
    {
        $resolver = new ReflectionClass(ComponentGraphResolver::class);
        self::assertTrue($resolver->isFinal());
        self::assertFalse($resolver->isReadOnly());
        self::assertSame(['resolve'], $this->publicMethodNames($resolver));
        self::assertStringContainsString('@experimental', (string) $resolver->getDocComment());
        self::assertStringContainsString(
            '@param list<ComponentGraphDeclaration> $declarations',
            (string) $resolver->getMethod('resolve')->getDocComment(),
        );
        self::assertStringContainsString(
            '@param list<CapabilityProviderSelection> $providerSelections',
            (string) $resolver->getMethod('resolve')->getDocComment(),
        );

        $selection = new ReflectionClass(CapabilityProviderSelection::class);
        self::assertTrue($selection->isFinal());
        self::assertTrue($selection->isReadOnly());
        self::assertSame(['__construct', 'capability', 'consumer', 'provider'], $this->publicMethodNames($selection));
        self::assertStringContainsString('@experimental', (string) $selection->getDocComment());

        $result = new ReflectionClass(ResolvedComponentGraph::class);
        self::assertTrue($result->isFinal());
        self::assertTrue($result->isReadOnly());
        self::assertSame(['__construct', 'orderedDeclarations', 'resolvedProvidersFor'], $this->publicMethodNames($result));
        self::assertStringContainsString('@experimental', (string) $result->getDocComment());
        self::assertStringContainsString('@internal', (string) $result->getConstructor()?->getDocComment());
        self::assertFalse($result->hasMethod('declarationFor'));
        self::assertFalse($result->hasMethod('providersFor'));

        $base = new ReflectionClass(ComponentGraphResolutionFailed::class);
        self::assertTrue($base->isAbstract());
        self::assertTrue($base->isSubclassOf(\RuntimeException::class));
        self::assertStringContainsString('@experimental', (string) $base->getDocComment());

        foreach ($this->graphExceptionClasses() as $className => $methods) {
            $reflection = new ReflectionClass($className);

            self::assertTrue($reflection->isFinal(), $className);
            self::assertTrue($reflection->isSubclassOf(ComponentGraphResolutionFailed::class), $className);
            self::assertSame($methods, $this->declaredPublicMethodNames($reflection), $className);
            self::assertStringContainsString('@experimental', (string) $reflection->getDocComment(), $className);
        }
    }

    public function test_empty_graph_resolves_to_empty_result(): void
    {
        $result = (new ComponentGraphResolver())->resolve([]);

        self::assertSame([], $result->orderedDeclarations());
        self::assertSame([], $result->resolvedProvidersFor($this->id('app'), $this->capability('cache')));
    }

    public function test_single_declaration_resolves_and_preserves_identity(): void
    {
        $app = $this->declaration('app');

        $result = (new ComponentGraphResolver())->resolve([$app]);

        self::assertSame([$app], $result->orderedDeclarations());
    }

    public function test_unrelated_declarations_are_ordered_lexically_independent_of_input_order(): void
    {
        $gamma = $this->declaration('gamma');
        $alpha = $this->declaration('alpha');
        $beta = $this->declaration('beta');

        $result = (new ComponentGraphResolver())->resolve([$gamma, $alpha, $beta]);
        $reordered = (new ComponentGraphResolver())->resolve([$beta, $gamma, $alpha]);

        self::assertSame([$alpha, $beta, $gamma], $result->orderedDeclarations());
        self::assertSame(['alpha', 'beta', 'gamma'], $this->identifierValues($reordered->orderedDeclarations()));
    }

    public function test_numeric_component_identifiers_remain_string_ordered_semantically(): void
    {
        $two = $this->declaration('2');
        $ten = $this->declaration('10');

        $result = (new ComponentGraphResolver())->resolve([$two, $ten]);

        self::assertSame(['10', '2'], $this->identifierValues($result->orderedDeclarations()));
        self::assertSame([$ten, $two], $result->orderedDeclarations());
    }

    public function test_duplicate_active_identifier_fails_deterministically(): void
    {
        $first = $this->declaration('zeta');
        $duplicate = new ComponentGraphDeclaration(new ComponentIdentifier('alpha'));
        $otherDuplicate = new ComponentGraphDeclaration(new ComponentIdentifier('alpha'));

        try {
            (new ComponentGraphResolver())->resolve([$first, $duplicate, $otherDuplicate]);

            self::fail('Expected duplicate identifier failure.');
        } catch (DuplicateComponentIdentifier $exception) {
            self::assertSame('alpha', $exception->identifier()->value());
        }
    }

    public function test_missing_required_dependency_fails_with_payload(): void
    {
        $app = $this->declaration('app', dependencies: [['cache', ComponentDependencyKind::Required]]);

        try {
            (new ComponentGraphResolver())->resolve([$app]);

            self::fail('Expected missing dependency failure.');
        } catch (MissingComponentDependency $exception) {
            self::assertSame($app->identifier(), $exception->consumer());
            self::assertSame('cache', $exception->dependency()->value());
        }
    }

    public function test_present_required_dependency_orders_dependency_before_consumer(): void
    {
        $app = $this->declaration('app', dependencies: [['cache', ComponentDependencyKind::Required]]);
        $cache = $this->declaration('cache');

        $result = (new ComponentGraphResolver())->resolve([$app, $cache]);

        self::assertSame([$cache, $app], $result->orderedDeclarations());
    }

    public function test_numeric_component_dependency_orders_dependency_before_consumer_and_preserves_identity(): void
    {
        $consumer = $this->declaration('20', dependencies: [['10', ComponentDependencyKind::Required]]);
        $dependency = $this->declaration('10');

        $result = (new ComponentGraphResolver())->resolve([$consumer, $dependency]);

        self::assertSame(['10', '20'], $this->identifierValues($result->orderedDeclarations()));
        self::assertSame([$dependency, $consumer], $result->orderedDeclarations());
    }

    public function test_absent_optional_dependency_succeeds(): void
    {
        $app = $this->declaration('app', dependencies: [['metrics', ComponentDependencyKind::Optional]]);

        $result = (new ComponentGraphResolver())->resolve([$app]);

        self::assertSame([$app], $result->orderedDeclarations());
    }

    public function test_present_optional_dependency_orders_dependency_before_consumer(): void
    {
        $app = $this->declaration('app', dependencies: [['metrics', ComponentDependencyKind::Optional]]);
        $metrics = $this->declaration('metrics');

        $result = (new ComponentGraphResolver())->resolve([$app, $metrics]);

        self::assertSame([$metrics, $app], $result->orderedDeclarations());
    }

    public function test_absent_conflict_target_succeeds(): void
    {
        $app = $this->declaration('app', conflicts: ['legacy']);

        $result = (new ComponentGraphResolver())->resolve([$app]);

        self::assertSame([$app], $result->orderedDeclarations());
    }

    public function test_active_one_sided_conflict_fails_with_deterministic_payload(): void
    {
        $zeta = $this->declaration('zeta', conflicts: ['omega']);
        $alpha = $this->declaration('alpha', conflicts: ['beta']);
        $beta = $this->declaration('beta');
        $omega = $this->declaration('omega');

        try {
            (new ComponentGraphResolver())->resolve([$zeta, $omega, $beta, $alpha]);

            self::fail('Expected active conflict failure.');
        } catch (ActiveComponentConflict $exception) {
            self::assertSame($alpha->identifier(), $exception->source());
            self::assertSame($beta->identifier()->value(), $exception->target()->value());
        }
    }

    public function test_exactly_one_zero_providers_fails(): void
    {
        $app = $this->declaration('app', requiredCapabilities: [['cache', CapabilityCardinality::ExactlyOne]]);

        try {
            (new ComponentGraphResolver())->resolve([$app]);

            self::fail('Expected missing capability provider.');
        } catch (MissingCapabilityProvider $exception) {
            self::assertSame($app->identifier(), $exception->consumer());
            self::assertSame('cache', $exception->capability()->value());
        }
    }

    public function test_exactly_one_single_provider_resolves_and_orders_provider_before_consumer(): void
    {
        $app = $this->declaration('app', requiredCapabilities: [['cache', CapabilityCardinality::ExactlyOne]]);
        $cache = $this->declaration('cache', providedCapabilities: ['cache']);

        $result = (new ComponentGraphResolver())->resolve([$app, $cache]);

        self::assertSame([$cache, $app], $result->orderedDeclarations());
        self::assertSame([$cache], $result->resolvedProvidersFor($app->identifier(), $this->capability('cache')));
    }

    public function test_numeric_capability_identifier_resolves_exactly_one_provider(): void
    {
        $app = $this->declaration('app', requiredCapabilities: [['10', CapabilityCardinality::ExactlyOne]]);
        $provider = $this->declaration('provider', providedCapabilities: ['10']);

        $result = (new ComponentGraphResolver())->resolve([$app, $provider]);

        self::assertSame([$provider], $result->resolvedProvidersFor($app->identifier(), $this->capability('10')));
        self::assertSame([$provider, $app], $result->orderedDeclarations());
    }

    public function test_exactly_one_multiple_providers_without_selection_is_ambiguous_with_lexical_provider_identifiers(): void
    {
        $app = $this->declaration('app', requiredCapabilities: [['cache', CapabilityCardinality::ExactlyOne]]);
        $redis = $this->declaration('redis', providedCapabilities: ['cache']);
        $array = $this->declaration('array-cache', providedCapabilities: ['cache']);

        try {
            (new ComponentGraphResolver())->resolve([$redis, $app, $array]);

            self::fail('Expected ambiguous provider failure.');
        } catch (AmbiguousCapabilityProvider $exception) {
            self::assertSame($app->identifier(), $exception->consumer());
            self::assertSame('cache', $exception->capability()->value());
            self::assertSame(['array-cache', 'redis'], array_map(
                static fn(ComponentIdentifier $identifier): string => $identifier->value(),
                $exception->providers(),
            ));
        }
    }

    public function test_one_or_more_zero_providers_fails(): void
    {
        $app = $this->declaration('app', requiredCapabilities: [['cache', CapabilityCardinality::OneOrMore]]);

        $this->expectException(MissingCapabilityProvider::class);

        (new ComponentGraphResolver())->resolve([$app]);
    }

    public function test_one_or_more_one_provider_resolves(): void
    {
        $app = $this->declaration('app', requiredCapabilities: [['cache', CapabilityCardinality::OneOrMore]]);
        $cache = $this->declaration('cache', providedCapabilities: ['cache']);

        $result = (new ComponentGraphResolver())->resolve([$app, $cache]);

        self::assertSame([$cache], $result->resolvedProvidersFor($app->identifier(), $this->capability('cache')));
        self::assertSame([$cache, $app], $result->orderedDeclarations());
    }

    public function test_one_or_more_multiple_providers_resolve_in_lexical_order_and_preserve_identity(): void
    {
        $app = $this->declaration('app', requiredCapabilities: [['cache', CapabilityCardinality::OneOrMore]]);
        $redis = $this->declaration('redis', providedCapabilities: ['cache']);
        $array = $this->declaration('array-cache', providedCapabilities: ['cache']);

        $result = (new ComponentGraphResolver())->resolve([$redis, $app, $array]);

        self::assertSame([$array, $redis], $result->resolvedProvidersFor($app->identifier(), $this->capability('cache')));
        self::assertSame(['array-cache', 'redis', 'app'], $this->identifierValues($result->orderedDeclarations()));
    }

    public function test_consumer_scoped_selections_allow_different_providers_for_same_capability(): void
    {
        $app = $this->declaration('app', requiredCapabilities: [['cache', CapabilityCardinality::ExactlyOne]]);
        $worker = $this->declaration('worker', requiredCapabilities: [['cache', CapabilityCardinality::ExactlyOne]]);
        $redis = $this->declaration('redis', providedCapabilities: ['cache']);
        $array = $this->declaration('array-cache', providedCapabilities: ['cache']);

        $result = (new ComponentGraphResolver())->resolve(
            [$app, $worker, $redis, $array],
            [
                new CapabilityProviderSelection($app->identifier(), $this->capability('cache'), $redis->identifier()),
                new CapabilityProviderSelection($worker->identifier(), $this->capability('cache'), $array->identifier()),
            ],
        );

        self::assertSame([$redis], $result->resolvedProvidersFor($app->identifier(), $this->capability('cache')));
        self::assertSame([$array], $result->resolvedProvidersFor($worker->identifier(), $this->capability('cache')));
    }

    public function test_invalid_selection_reasons_and_constants_are_exact(): void
    {
        $reflection = new ReflectionClass(InvalidCapabilityProviderSelection::class);

        self::assertSame('inactive_consumer', $reflection->getConstant('REASON_INACTIVE_CONSUMER'));
        self::assertSame('capability_not_required', $reflection->getConstant('REASON_CAPABILITY_NOT_REQUIRED'));
        self::assertSame('unsupported_cardinality', $reflection->getConstant('REASON_UNSUPPORTED_CARDINALITY'));
        self::assertSame('inactive_provider', $reflection->getConstant('REASON_INACTIVE_PROVIDER'));
        self::assertSame('provider_does_not_provide_capability', $reflection->getConstant('REASON_PROVIDER_DOES_NOT_PROVIDE_CAPABILITY'));
        self::assertSame('duplicate_selection', $reflection->getConstant('REASON_DUPLICATE_SELECTION'));

        $this->expectException(InvalidArgumentException::class);

        new InvalidCapabilityProviderSelection(
            $this->id('app'),
            $this->capability('cache'),
            null,
            'anything_goes',
        );
    }

    public function test_inactive_consumer_selection_fails(): void
    {
        $provider = $this->declaration('redis', providedCapabilities: ['cache']);

        $this->assertInvalidSelectionReason(
            [$provider],
            [new CapabilityProviderSelection($this->id('app'), $this->capability('cache'), $provider->identifier())],
            InvalidCapabilityProviderSelection::REASON_INACTIVE_CONSUMER,
            'app',
            'cache',
            'redis',
        );
    }

    public function test_consumer_selection_for_unrequired_capability_fails(): void
    {
        $app = $this->declaration('app');
        $provider = $this->declaration('redis', providedCapabilities: ['cache']);

        $this->assertInvalidSelectionReason(
            [$app, $provider],
            [new CapabilityProviderSelection($app->identifier(), $this->capability('cache'), $provider->identifier())],
            InvalidCapabilityProviderSelection::REASON_CAPABILITY_NOT_REQUIRED,
            'app',
            'cache',
            'redis',
        );
    }

    public function test_selection_for_one_or_more_requirement_fails(): void
    {
        $app = $this->declaration('app', requiredCapabilities: [['cache', CapabilityCardinality::OneOrMore]]);
        $provider = $this->declaration('redis', providedCapabilities: ['cache']);

        $this->assertInvalidSelectionReason(
            [$app, $provider],
            [new CapabilityProviderSelection($app->identifier(), $this->capability('cache'), $provider->identifier())],
            InvalidCapabilityProviderSelection::REASON_UNSUPPORTED_CARDINALITY,
            'app',
            'cache',
            'redis',
        );
    }

    public function test_inactive_provider_selection_fails(): void
    {
        $app = $this->declaration('app', requiredCapabilities: [['cache', CapabilityCardinality::ExactlyOne]]);

        $this->assertInvalidSelectionReason(
            [$app],
            [new CapabilityProviderSelection($app->identifier(), $this->capability('cache'), $this->id('redis'))],
            InvalidCapabilityProviderSelection::REASON_INACTIVE_PROVIDER,
            'app',
            'cache',
            'redis',
        );
    }

    public function test_selection_for_provider_that_does_not_provide_capability_fails(): void
    {
        $app = $this->declaration('app', requiredCapabilities: [['cache', CapabilityCardinality::ExactlyOne]]);
        $provider = $this->declaration('redis', providedCapabilities: ['queue']);

        $this->assertInvalidSelectionReason(
            [$app, $provider],
            [new CapabilityProviderSelection($app->identifier(), $this->capability('cache'), $provider->identifier())],
            InvalidCapabilityProviderSelection::REASON_PROVIDER_DOES_NOT_PROVIDE_CAPABILITY,
            'app',
            'cache',
            'redis',
        );
    }

    public function test_duplicate_consumer_capability_selection_fails_without_authoritative_provider(): void
    {
        $app = $this->declaration('app', requiredCapabilities: [['cache', CapabilityCardinality::ExactlyOne]]);
        $redis = $this->declaration('redis', providedCapabilities: ['cache']);
        $array = $this->declaration('array-cache', providedCapabilities: ['cache']);

        $this->assertInvalidSelectionReason(
            [$app, $redis, $array],
            [
                new CapabilityProviderSelection($app->identifier(), $this->capability('cache'), $redis->identifier()),
                new CapabilityProviderSelection($app->identifier(), $this->capability('cache'), $array->identifier()),
            ],
            InvalidCapabilityProviderSelection::REASON_DUPLICATE_SELECTION,
            'app',
            'cache',
            null,
        );
    }

    public function test_valid_selection_with_one_provider_is_allowed(): void
    {
        $app = $this->declaration('app', requiredCapabilities: [['cache', CapabilityCardinality::ExactlyOne]]);
        $redis = $this->declaration('redis', providedCapabilities: ['cache']);

        $result = (new ComponentGraphResolver())->resolve(
            [$app, $redis],
            [new CapabilityProviderSelection($app->identifier(), $this->capability('cache'), $redis->identifier())],
        );

        self::assertSame([$redis], $result->resolvedProvidersFor($app->identifier(), $this->capability('cache')));
    }

    public function test_valid_selection_with_multiple_providers_resolves_ambiguity(): void
    {
        $app = $this->declaration('app', requiredCapabilities: [['cache', CapabilityCardinality::ExactlyOne]]);
        $redis = $this->declaration('redis', providedCapabilities: ['cache']);
        $array = $this->declaration('array-cache', providedCapabilities: ['cache']);

        $result = (new ComponentGraphResolver())->resolve(
            [$app, $redis, $array],
            [new CapabilityProviderSelection($app->identifier(), $this->capability('cache'), $array->identifier())],
        );

        self::assertSame([$array], $result->resolvedProvidersFor($app->identifier(), $this->capability('cache')));
    }

    public function test_numeric_consumer_scoped_provider_selection_resolves_selected_provider(): void
    {
        $consumer = $this->declaration('20', requiredCapabilities: [['30', CapabilityCardinality::ExactlyOne]]);
        $selectedProvider = $this->declaration('10', providedCapabilities: ['30']);
        $otherProvider = $this->declaration('2', providedCapabilities: ['30']);

        $result = (new ComponentGraphResolver())->resolve(
            [$consumer, $otherProvider, $selectedProvider],
            [new CapabilityProviderSelection($consumer->identifier(), $this->capability('30'), $selectedProvider->identifier())],
        );

        self::assertSame([$selectedProvider], $result->resolvedProvidersFor($consumer->identifier(), $this->capability('30')));
        self::assertSame(['10', '2', '20'], $this->identifierValues($result->orderedDeclarations()));
    }

    public function test_self_only_exactly_one_succeeds_without_self_edge(): void
    {
        $app = $this->declaration(
            'app',
            requiredCapabilities: [['cache', CapabilityCardinality::ExactlyOne]],
            providedCapabilities: ['cache'],
        );

        $result = (new ComponentGraphResolver())->resolve([$app]);

        self::assertSame([$app], $result->orderedDeclarations());
        self::assertSame([$app], $result->resolvedProvidersFor($app->identifier(), $this->capability('cache')));
    }

    public function test_self_plus_external_exactly_one_without_selection_is_ambiguous(): void
    {
        $app = $this->declaration(
            'app',
            requiredCapabilities: [['cache', CapabilityCardinality::ExactlyOne]],
            providedCapabilities: ['cache'],
        );
        $redis = $this->declaration('redis', providedCapabilities: ['cache']);

        $this->expectException(AmbiguousCapabilityProvider::class);

        (new ComponentGraphResolver())->resolve([$app, $redis]);
    }

    public function test_selected_self_provider_succeeds_without_self_edge(): void
    {
        $app = $this->declaration(
            'app',
            requiredCapabilities: [['cache', CapabilityCardinality::ExactlyOne]],
            providedCapabilities: ['cache'],
        );
        $redis = $this->declaration('redis', providedCapabilities: ['cache']);

        $result = (new ComponentGraphResolver())->resolve(
            [$redis, $app],
            [new CapabilityProviderSelection($app->identifier(), $this->capability('cache'), $app->identifier())],
        );

        self::assertSame(['app', 'redis'], $this->identifierValues($result->orderedDeclarations()));
        self::assertSame([$app], $result->resolvedProvidersFor($app->identifier(), $this->capability('cache')));
    }

    public function test_one_or_more_self_and_external_provider_includes_self_but_edges_only_external(): void
    {
        $app = $this->declaration(
            'app',
            requiredCapabilities: [['cache', CapabilityCardinality::OneOrMore]],
            providedCapabilities: ['cache'],
        );
        $redis = $this->declaration('redis', providedCapabilities: ['cache']);

        $result = (new ComponentGraphResolver())->resolve([$app, $redis]);

        self::assertSame([$app, $redis], $result->resolvedProvidersFor($app->identifier(), $this->capability('cache')));
        self::assertSame([$redis, $app], $result->orderedDeclarations());
    }

    public function test_duplicate_effective_edge_is_counted_once(): void
    {
        $app = $this->declaration(
            'app',
            dependencies: [['cache', ComponentDependencyKind::Required]],
            requiredCapabilities: [['cache', CapabilityCardinality::ExactlyOne]],
        );
        $cache = $this->declaration('cache', providedCapabilities: ['cache']);

        $result = (new ComponentGraphResolver())->resolve([$app, $cache]);

        self::assertSame([$cache, $app], $result->orderedDeclarations());
        self::assertSame([$cache], $result->resolvedProvidersFor($app->identifier(), $this->capability('cache')));
    }

    public function test_mixed_graph_orders_dependencies_optional_dependencies_and_capability_providers(): void
    {
        $app = $this->declaration(
            'app',
            dependencies: [
                ['config', ComponentDependencyKind::Required],
                ['metrics', ComponentDependencyKind::Optional],
            ],
            requiredCapabilities: [['cache', CapabilityCardinality::ExactlyOne]],
        );
        $config = $this->declaration('config');
        $metrics = $this->declaration('metrics');
        $cache = $this->declaration('cache', providedCapabilities: ['cache']);

        $result = (new ComponentGraphResolver())->resolve([$app, $metrics, $cache, $config]);

        self::assertSame(['cache', 'config', 'metrics', 'app'], $this->identifierValues($result->orderedDeclarations()));
    }

    public function test_required_dependency_cycle_reports_canonical_cycle(): void
    {
        $alpha = $this->declaration('alpha', dependencies: [['beta', ComponentDependencyKind::Required]]);
        $beta = $this->declaration('beta', dependencies: [['gamma', ComponentDependencyKind::Required]]);
        $gamma = $this->declaration('gamma', dependencies: [['alpha', ComponentDependencyKind::Required]]);

        $this->assertCycle([$gamma, $alpha, $beta], ['alpha', 'beta', 'gamma', 'alpha']);
    }

    public function test_active_optional_dependency_cycle_reports_canonical_cycle(): void
    {
        $alpha = $this->declaration('alpha', dependencies: [['beta', ComponentDependencyKind::Optional]]);
        $beta = $this->declaration('beta', dependencies: [['alpha', ComponentDependencyKind::Optional]]);

        $this->assertCycle([$beta, $alpha], ['alpha', 'beta', 'alpha']);
    }

    public function test_capability_cycle_reports_canonical_cycle(): void
    {
        $alpha = $this->declaration(
            'alpha',
            requiredCapabilities: [['beta-capability', CapabilityCardinality::ExactlyOne]],
            providedCapabilities: ['alpha-capability'],
        );
        $beta = $this->declaration(
            'beta',
            requiredCapabilities: [['alpha-capability', CapabilityCardinality::ExactlyOne]],
            providedCapabilities: ['beta-capability'],
        );

        $this->assertCycle([$beta, $alpha], ['alpha', 'beta', 'alpha']);
    }

    public function test_mixed_dependency_capability_cycle_reports_actual_cycle_not_all_unresolved_nodes(): void
    {
        $alpha = $this->declaration(
            'alpha',
            dependencies: [['beta', ComponentDependencyKind::Required]],
            providedCapabilities: ['alpha-capability'],
        );
        $beta = $this->declaration('beta', requiredCapabilities: [['alpha-capability', CapabilityCardinality::ExactlyOne]]);
        $unrelated = $this->declaration('unrelated', dependencies: [['alpha', ComponentDependencyKind::Required]]);

        $this->assertCycle([$unrelated, $beta, $alpha], ['alpha', 'beta', 'alpha']);
    }

    public function test_cycle_result_is_input_order_independent(): void
    {
        $alpha = $this->declaration('alpha', dependencies: [['beta', ComponentDependencyKind::Required]]);
        $beta = $this->declaration('beta', dependencies: [['gamma', ComponentDependencyKind::Required]]);
        $gamma = $this->declaration('gamma', dependencies: [['alpha', ComponentDependencyKind::Required]]);

        $first = $this->captureCycle([$alpha, $beta, $gamma]);
        $second = $this->captureCycle([$gamma, $alpha, $beta]);

        self::assertSame($first, $second);
        self::assertSame(['alpha', 'beta', 'gamma', 'alpha'], $first);
    }

    public function test_semantic_failure_precedence_is_deterministic(): void
    {
        $duplicateA = $this->declaration('alpha');
        $duplicateB = $this->declaration('alpha', dependencies: [['missing', ComponentDependencyKind::Required]]);
        $conflicting = $this->declaration('conflicting', conflicts: ['target']);
        $target = $this->declaration('target');

        $this->expectException(DuplicateComponentIdentifier::class);

        (new ComponentGraphResolver())->resolve([$conflicting, $target, $duplicateB, $duplicateA]);
    }

    public function test_missing_provider_precedes_ambiguity_by_lexical_consumer_and_capability(): void
    {
        $alpha = $this->declaration('alpha', requiredCapabilities: [['cache', CapabilityCardinality::ExactlyOne]]);
        $beta = $this->declaration('beta', requiredCapabilities: [['queue', CapabilityCardinality::ExactlyOne]]);
        $queueA = $this->declaration('queue-a', providedCapabilities: ['queue']);
        $queueB = $this->declaration('queue-b', providedCapabilities: ['queue']);

        try {
            (new ComponentGraphResolver())->resolve([$beta, $queueB, $queueA, $alpha]);

            self::fail('Expected missing provider before ambiguity.');
        } catch (MissingCapabilityProvider $exception) {
            self::assertSame('alpha', $exception->consumer()->value());
            self::assertSame('cache', $exception->capability()->value());
        }
    }

    public function test_result_unknown_consumer_or_capability_returns_empty_list(): void
    {
        $app = $this->declaration('app');

        $result = (new ComponentGraphResolver())->resolve([$app]);

        self::assertSame([], $result->resolvedProvidersFor($app->identifier(), $this->capability('cache')));
        self::assertSame([], $result->resolvedProvidersFor($this->id('missing'), $this->capability('cache')));
    }

    /**
     * @return array<class-string, list<string>>
     */
    private function graphExceptionClasses(): array
    {
        return [
            DuplicateComponentIdentifier::class => ['__construct', 'identifier'],
            MissingComponentDependency::class => ['__construct', 'consumer', 'dependency'],
            ActiveComponentConflict::class => ['__construct', 'source', 'target'],
            MissingCapabilityProvider::class => ['__construct', 'capability', 'consumer'],
            AmbiguousCapabilityProvider::class => ['__construct', 'capability', 'consumer', 'providers'],
            InvalidCapabilityProviderSelection::class => ['__construct', 'capability', 'consumer', 'provider', 'reason'],
            ComponentDependencyCycle::class => ['__construct', 'cycle'],
        ];
    }

    /**
     * @param list<ComponentGraphDeclaration> $declarations
     * @param list<CapabilityProviderSelection> $selections
     */
    private function assertInvalidSelectionReason(
        array $declarations,
        array $selections,
        string $expectedReason,
        string $expectedConsumer,
        string $expectedCapability,
        ?string $expectedProvider,
    ): void {
        try {
            (new ComponentGraphResolver())->resolve($declarations, $selections);

            self::fail('Expected invalid provider selection.');
        } catch (InvalidCapabilityProviderSelection $exception) {
            self::assertSame($expectedReason, $exception->reason());
            self::assertSame($expectedConsumer, $exception->consumer()->value());
            self::assertSame($expectedCapability, $exception->capability()->value());
            self::assertSame($expectedProvider, $exception->provider()?->value());
        }
    }

    /**
     * @param list<ComponentGraphDeclaration> $declarations
     * @param list<string> $expectedCycle
     */
    private function assertCycle(array $declarations, array $expectedCycle): void
    {
        self::assertSame($expectedCycle, $this->captureCycle($declarations));
    }

    /**
     * @param list<ComponentGraphDeclaration> $declarations
     * @return list<string>
     */
    private function captureCycle(array $declarations): array
    {
        try {
            (new ComponentGraphResolver())->resolve($declarations);

            self::fail('Expected dependency cycle.');
        } catch (ComponentDependencyCycle $exception) {
            $cycle = $exception->cycle();

            self::assertGreaterThanOrEqual(3, count($cycle));
            self::assertSame($cycle[0]->value(), $cycle[count($cycle) - 1]->value());

            return array_map(
                static fn(ComponentIdentifier $identifier): string => $identifier->value(),
                $cycle,
            );
        }
    }

    /**
     * @param list<array{0: string, 1: ComponentDependencyKind}> $dependencies
     * @param list<string> $conflicts
     * @param list<array{0: string, 1: CapabilityCardinality}> $requiredCapabilities
     * @param list<string> $providedCapabilities
     */
    private function declaration(
        string $identifier,
        array $dependencies = [],
        array $conflicts = [],
        array $requiredCapabilities = [],
        array $providedCapabilities = [],
    ): ComponentGraphDeclaration {
        return new ComponentGraphDeclaration(
            $this->id($identifier),
            new ComponentGraphRelations(
                array_map(
                    fn(array $dependency): ComponentDependency => new ComponentDependency($this->id($dependency[0]), $dependency[1]),
                    $dependencies,
                ),
                array_map(
                    fn(string $target): ComponentConflict => new ComponentConflict($this->id($target)),
                    $conflicts,
                ),
                array_map(
                    fn(array $requirement): CapabilityRequirement => new CapabilityRequirement($this->capability($requirement[0]), $requirement[1]),
                    $requiredCapabilities,
                ),
                array_map(
                    fn(string $capability): CapabilityIdentifier => $this->capability($capability),
                    $providedCapabilities,
                ),
            ),
        );
    }

    private function id(string $identifier): ComponentIdentifier
    {
        return new ComponentIdentifier($identifier);
    }

    private function capability(string $capability): CapabilityIdentifier
    {
        return new CapabilityIdentifier($capability);
    }

    /**
     * @param list<ComponentGraphDeclaration> $declarations
     * @return list<string>
     */
    private function identifierValues(array $declarations): array
    {
        return array_map(
            static fn(ComponentGraphDeclaration $declaration): string => $declaration->identifier()->value(),
            $declarations,
        );
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
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

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return list<string>
     */
    private function declaredPublicMethodNames(ReflectionClass $class): array
    {
        $methodNames = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === $class->getName()) {
                $methodNames[] = $method->getName();
            }
        }

        sort($methodNames);

        return $methodNames;
    }
}
