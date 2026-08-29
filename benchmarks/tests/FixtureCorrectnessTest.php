<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Tests;

use Evolve\Benchmarks\Support\BenchmarkFixtureFactory;
use Evolve\Core\Exception\ExecutionScopeClosed;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Http\Exception\MethodNotAllowed;
use Evolve\Http\Exception\RouteNotFound;
use Evolve\Http\Routing\RouteMatch;
use PHPUnit\Framework\TestCase;

final class FixtureCorrectnessTest extends TestCase
{
    public function testRouteFixtureUsesTheRealMatcherAndReportsRouteParameters(): void
    {
        $fixture = BenchmarkFixtureFactory::routeMatchingFixture(100, 'parameterized', 'middle');
        $match = $fixture['matcher']->match($fixture['request']);

        self::assertNotNull($match);
        self::assertSame('/bench/{id}', $match->route()->path());
        self::assertSame(['id' => '50'], $match->parameters());
    }

    public function testRoutingHandlerDistinguishes404And405Paths(): void
    {
        $notFound = BenchmarkFixtureFactory::routingHandlerFixture(10, 'not-found');

        $this->expectException(RouteNotFound::class);
        $notFound['handler']->handle($notFound['request']);
    }

    public function testRoutingHandlerReports405AllowedMethods(): void
    {
        $methodMismatch = BenchmarkFixtureFactory::routingHandlerFixture(10, 'method-mismatch');

        try {
            $methodMismatch['handler']->handle($methodMismatch['request']);
            self::fail('Expected method mismatch to throw.');
        } catch (MethodNotAllowed $exception) {
            self::assertSame(['GET'], $exception->allowedMethods());
        }
    }

    public function testMiddlewareFixtureInvokesEveryPassThroughLayer(): void
    {
        $fixture = BenchmarkFixtureFactory::middlewareFixture(5, 'pass-through');

        $response = $fixture['pipeline']->handle($fixture['request']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(5, $fixture['counter']());
    }

    public function testHttpKernelFixtureExecutesKernelRoutingAndExecutionScope(): void
    {
        $fixture = BenchmarkFixtureFactory::httpKernelFixture('parameterized');
        $outcome = $fixture['kernel']->handle($fixture['request']);

        self::assertTrue($outcome->primarySucceeded());
        self::assertSame(200, $outcome->primaryResult()->getStatusCode());
        self::assertTrue($outcome->isReusable());
        self::assertNotNull($fixture['last_request']()->getAttribute(RouteMatch::class));
    }

    public function testExecutionFixtureClosesScopesAndRunsResetParticipants(): void
    {
        $fixture = BenchmarkFixtureFactory::executionOrchestratorFixture(resetParticipants: 3);

        $outcome = $fixture['orchestrator']->execute(
            ExecutionKind::HttpRequest,
            $fixture['operation'],
        );

        self::assertTrue($outcome->primarySucceeded());
        self::assertSame(3, $fixture['reset_count']());
        $this->expectException(ExecutionScopeClosed::class);
        $fixture['last_scope']()->get('bench.execution');
    }

    public function testApplicationBootFixtureRunsComponentLifecycle(): void
    {
        $fixture = BenchmarkFixtureFactory::applicationBootFixture(5);

        $fixture['kernel']->boot();

        self::assertSame(5, $fixture['register_count']());
        self::assertSame(5, $fixture['boot_count']());
        self::assertSame(5, $fixture['ready_count']());
    }

    public function testPersistentStyleHarnessRecordsMemoryAndResetsWithoutRuntimeAdapterClaims(): void
    {
        $evidence = BenchmarkFixtureFactory::persistentSequentialExecutionEvidence(25, 5);

        self::assertSame('persistent-style sequential execution evidence', $evidence['label']);
        self::assertSame(25, $evidence['execution_count']);
        self::assertSame(25, $evidence['reset_count']);
        self::assertCount(6, $evidence['checkpoints']);
    }
}
