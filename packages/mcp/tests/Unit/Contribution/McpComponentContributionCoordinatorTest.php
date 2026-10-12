<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Unit\Contribution;

use Evolve\Contracts\Component\ComponentDependency;
use Evolve\Contracts\Component\ComponentDependencyKind;
use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentGraphRelations;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Core\Component\ComponentGraphResolver;
use Evolve\Mcp\Contribution\Exception\McpContributionFailed;
use Evolve\Mcp\Contribution\McpCapabilityRegistrar;
use Evolve\Mcp\Contribution\McpComponentContribution;
use Evolve\Mcp\Contribution\McpComponentContributionCoordinator;
use Evolve\Mcp\Server\Exception\DuplicateMcpCapability;
use Evolve\Mcp\Server\McpToolDefinition;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class McpComponentContributionCoordinatorTest extends TestCase
{
    public function test_graph_order_and_input_permutations(): void
    {
        $app = self::declaration('app', ['zeta']);
        $zeta = self::declaration('zeta');
        $alpha = self::declaration('alpha');
        $graph = (new ComponentGraphResolver())->resolve([$app, $zeta, $alpha]);
        $binding = static fn(ComponentGraphDeclaration $declaration): McpComponentContribution => new McpComponentContribution(
            $declaration,
            static fn(McpCapabilityRegistrar $registrar) => $registrar->registerTool(new McpToolDefinition($declaration->identifier()->value(), 'service', ['type' => 'object'])),
        );
        $bindings = [$binding($app), $binding($zeta), $binding($alpha)];
        foreach ([$bindings, array_reverse($bindings)] as $permutation) {
            $catalog = (new McpComponentContributionCoordinator($graph, $permutation))->contribute();
            self::assertSame(['alpha', 'zeta', 'app'], array_map(static fn(McpToolDefinition $tool): string => $tool->name, $catalog->tools()));
        }
    }

    public function test_invalid_bindings_are_rejected_before_callbacks_and_missing_is_legal(): void
    {
        $active = self::declaration('active');
        $graph = (new ComponentGraphResolver())->resolve([$active]);
        self::assertSame([], (new McpComponentContributionCoordinator($graph, []))->contribute()->tools());
        $calls = 0;
        $good = new McpComponentContribution($active, static function () use (&$calls): void {
            ++$calls;
        });
        foreach ([
            [$good, new McpComponentContribution(self::declaration('disabled'), static function (): void {})],
            [$good, new McpComponentContribution(self::declaration('active'), static function (): void {})],
            [$good, $good],
            [$good, 'invalid'],
        ] as $invalid) {
            try {
                (new McpComponentContributionCoordinator($graph, $invalid))->contribute();
                self::fail('Expected structural rejection.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $calls);
            }
        }
    }

    public function test_failure_attribution_cause_stop_terminal_and_revocation(): void
    {
        $first = self::declaration('first');
        $last = self::declaration('last');
        $graph = (new ComponentGraphResolver())->resolve([$last, $first]);
        $failure = new RuntimeException('credential=private');
        $calls = 0;
        $retained = null;
        $coordinator = new McpComponentContributionCoordinator($graph, [
            new McpComponentContribution($first, static function (McpCapabilityRegistrar $registrar) use (&$retained, $failure): void {
                $retained = $registrar;
                $registrar->registerTool(new McpToolDefinition('first', 'service', ['type' => 'object']));
                throw $failure;
            }),
            new McpComponentContribution($last, static function () use (&$calls): void {
                ++$calls;
            }),
        ]);
        try {
            $coordinator->contribute();
            self::fail('Expected failure.');
        } catch (McpContributionFailed $exception) {
            self::assertSame('first', $exception->component()->value());
            self::assertSame($failure, $exception->getPrevious());
            self::assertStringNotContainsString('private', $exception->getMessage());
        }
        self::assertSame(0, $calls);
        try {
            $retained->registerTool(new McpToolDefinition('late', 'service', ['type' => 'object']));
            self::fail('Expected revocation.');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $coordinator->contribute();
    }

    public function test_reentrancy_and_contributor_collision_are_attributed(): void
    {
        $a = self::declaration('a');
        $b = self::declaration('b');
        $graph = (new ComponentGraphResolver())->resolve([$a, $b]);
        $reentrant = null;
        $reentrant = new McpComponentContributionCoordinator($graph, [new McpComponentContribution(
            $a,
            static function () use (&$reentrant): void {
                $reentrant->contribute();
            },
        )]);
        try {
            $reentrant->contribute();
            self::fail('Expected reentrant failure.');
        } catch (McpContributionFailed $exception) {
            self::assertInstanceOf(LogicException::class, $exception->getPrevious());
        }
        $binding = static fn(ComponentGraphDeclaration $declaration): McpComponentContribution => new McpComponentContribution(
            $declaration,
            static fn(McpCapabilityRegistrar $registrar) => $registrar->registerTool(new McpToolDefinition('same', 'service', ['type' => 'object'])),
        );
        try {
            (new McpComponentContributionCoordinator($graph, [$binding($b), $binding($a)]))->contribute();
            self::fail('Expected collision.');
        } catch (McpContributionFailed $exception) {
            self::assertSame('b', $exception->component()->value());
            self::assertInstanceOf(DuplicateMcpCapability::class, $exception->getPrevious());
        }
    }

    public function test_success_is_terminal_and_within_component_order_is_preserved(): void
    {
        $declaration = self::declaration('app');
        $graph = (new ComponentGraphResolver())->resolve([$declaration]);
        $retained = null;
        $coordinator = new McpComponentContributionCoordinator($graph, [new McpComponentContribution(
            $declaration,
            static function (McpCapabilityRegistrar $registrar) use (&$retained): void {
                $retained = $registrar;
                $registrar->registerTool(new McpToolDefinition('one', 'one', ['type' => 'object']));
                $registrar->registerTool(new McpToolDefinition('two', 'two', ['type' => 'object']));
            },
        )]);
        self::assertSame(['one', 'two'], array_map(
            static fn(McpToolDefinition $tool): string => $tool->name,
            $coordinator->contribute()->tools(),
        ));
        try {
            $retained->registerTool(new McpToolDefinition('late', 'late', ['type' => 'object']));
            self::fail('Expected callback registrar to be revoked.');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $coordinator->contribute();
    }

    public function test_malformed_host_definitions_are_rejected_before_callbacks(): void
    {
        $declaration = self::declaration('app');
        $graph = (new ComponentGraphResolver())->resolve([$declaration]);
        $calls = 0;
        $coordinator = new McpComponentContributionCoordinator($graph, [new McpComponentContribution(
            $declaration,
            static function () use (&$calls): void {
                ++$calls;
            },
        )]);
        try {
            $coordinator->contribute([new McpToolDefinition('valid', 'service', ['type' => 'object']), 'invalid']);
            self::fail('Expected invalid host definition.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $calls);
        }
    }
    /** @param list<string> $dependencies */
    private static function declaration(string $id, array $dependencies = []): ComponentGraphDeclaration
    {
        return new ComponentGraphDeclaration(new ComponentIdentifier($id), new ComponentGraphRelations(array_map(
            static fn(string $dependency): ComponentDependency => new ComponentDependency(new ComponentIdentifier($dependency), ComponentDependencyKind::Required),
            $dependencies,
        )));
    }
}
