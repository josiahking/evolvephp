<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Integration\Transport;

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionResetFailed;
use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Mcp\Transport\ScopedMcpCapabilityInvoker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class McpTransportIsolationTest extends TestCase
{
    public function test_each_capability_receives_a_fresh_scope_and_cleanup_precedes_the_next_call(): void
    {
        $events = [];
        $nextId = 0;
        $registry = new ServiceRegistry();
        $registry->register('capability', ServiceLifetime::Execution, static function () use (&$events, &$nextId): object {
            $service = (object) ['id' => ++$nextId];
            $events[] = ['created', $service->id];

            return $service;
        });
        $registry->freeze();

        $invoker = new ScopedMcpCapabilityInvoker(
            new ExecutionOrchestrator($registry),
            static function (ExecutionScope $scope, string $serviceId, array $arguments) use (&$events): string {
                $service = $scope->get($serviceId);
                $events[] = ['invoked', $service->id, $arguments];
                $scope->registerResetParticipant('capability-reset', new CallbackResetParticipant(static function () use (&$events): void {
                    $events[] = ['reset'];
                }));

                return $arguments['value'];
            },
        );

        self::assertSame('first', $invoker->invoke('capability', ['value' => 'first']));
        self::assertSame('second', $invoker->invoke('capability', ['value' => 'second']));
        self::assertSame(['created', 'invoked', 'reset', 'created', 'invoked', 'reset'], array_column($events, 0));
        self::assertSame(1, $events[0][1]);
        self::assertSame(2, $events[3][1]);
    }

    public function test_primary_failure_is_propagated_after_cleanup_and_does_not_poison_next_call(): void
    {
        $events = new ResetEventLog();
        $registry = new ServiceRegistry();
        $registry->freeze();
        $failure = new RuntimeException('handler failure');
        $invoker = new ScopedMcpCapabilityInvoker(
            new ExecutionOrchestrator($registry),
            static function (ExecutionScope $scope, string $serviceId, array $arguments) use (&$events, $failure): string {
                $scope->registerResetParticipant('reset', new CallbackResetParticipant(static function () use (&$events): void {
                    $events->record('reset');
                }));
                if ($arguments['fail']) {
                    throw $failure;
                }

                return 'recovered';
            },
        );

        try {
            $invoker->invoke('capability', ['fail' => true]);
            self::fail('Expected the original invocation failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertSame(['reset'], $events->all());
        self::assertSame('recovered', $invoker->invoke('capability', ['fail' => false]));
        self::assertSame(['reset', 'reset'], $events->all());
    }

    public function test_execution_start_failure_is_exposed_as_unsafe_after_external_quarantine(): void
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        $executions = new ExecutionOrchestrator($registry);
        $executions->execute(
            \Evolve\Core\Execution\ExecutionKind::WorkerTask,
            static function ($context, ExecutionScope $scope): void {
                $scope->registerResetParticipant('broken', new CallbackResetParticipant(static function (): void {
                    throw new RuntimeException('reset failed');
                }));
            },
        );
        $invoker = new ScopedMcpCapabilityInvoker($executions, static fn(): null => null);

        try {
            $invoker->invoke('capability', []);
            self::fail('A quarantined orchestrator must refuse new work.');
        } catch (ExecutionStartFailed $exception) {
            self::assertSame($exception, $invoker->quarantineFailure());
        }
    }
    public function test_cleanup_failure_is_exposed_and_quarantines_subsequent_invocations(): void
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        $calls = new \ArrayObject();
        $invoker = new ScopedMcpCapabilityInvoker(
            new ExecutionOrchestrator($registry),
            static function (ExecutionScope $scope) use (&$calls): string {
                $calls->append(true);
                $scope->registerResetParticipant('broken', new CallbackResetParticipant(static function (): void {
                    throw new RuntimeException('reset failed');
                }));

                return 'result';
            },
        );

        try {
            $invoker->invoke('capability', []);
            self::fail('Cleanup failure must not return a normal result.');
        } catch (ExecutionResetFailed) {
            self::assertCount(1, $calls);
        }

        $this->expectException(ExecutionStartFailed::class);
        try {
            $invoker->invoke('capability', []);
        } finally {
            self::assertCount(1, $calls);
        }
    }
    public function test_authorized_request_factories_do_not_share_principal_or_scope(): void
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        $source = (new \Evolve\Mcp\Server\McpServerBuilder(
            'example',
            '1.0',
            new ScopedMcpCapabilityInvoker(new ExecutionOrchestrator($registry), static fn(): null => null),
        ))
            ->addTool(new \Evolve\Mcp\Server\McpToolDefinition(
                'echo',
                'service.echo',
                ['type' => 'object'],
                exposure: \Evolve\Mcp\Server\McpCapabilityExposure::RemoteApproved,
            ))
            ->build();
        $seen = [];
        $factory = new \Evolve\Mcp\Server\McpAuthorizedServerFactory(
            $source,
            new ExecutionOrchestrator($registry),
            static function (ExecutionScope $scope, \Evolve\Mcp\Server\McpPrincipal $principal) use (&$seen): string {
                $seen[] = [$scope, $principal->subject()];
                return $principal->subject();
            },
            new class implements \Evolve\Mcp\Server\McpCapabilityPolicy {
                public function allows(\Evolve\Mcp\Server\McpPrincipal $principal, \Evolve\Mcp\Server\McpCapabilityKind $kind, string $identity): bool
                {
                    return true;
                }
            },
            new class implements \Evolve\Mcp\Server\McpSecurityAuditSink {
                public function record(\Evolve\Mcp\Server\McpCapabilityKind $kind, string $identity, bool $allowed, string $correlation): void {}
            },
        );
        $first = $factory->create(new \Evolve\Mcp\Server\McpPrincipal('alice'));
        $second = $factory->create(new \Evolve\Mcp\Server\McpPrincipal('bob'));
        self::assertNotSame($first->invoker(), $second->invoker());
        self::assertSame('alice', $first->invoker()->invoke('service.echo', []));
        self::assertSame('bob', $second->invoker()->invoke('service.echo', []));
        self::assertNotSame($seen[0][0], $seen[1][0]);
        self::assertSame(['alice', 'bob'], array_column($seen, 1));
    }

}

final readonly class CallbackResetParticipant implements ResetParticipant
{
    public function __construct(private \Closure $callback) {}

    public function reset(): void
    {
        ($this->callback)();
    }
}
final class ResetEventLog
{
    /** @var list<string> */
    private array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->events;
    }
}
