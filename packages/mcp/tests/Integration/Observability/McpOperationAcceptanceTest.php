<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Integration\Observability;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Mcp\Observability\McpOperationDispatcher;
use Evolve\Mcp\Observability\McpOperationSink;
use Evolve\Mcp\Server\McpAuthorizedServerFactory;
use Evolve\Mcp\Server\McpCapabilityExposure;
use Evolve\Mcp\Server\McpCapabilityInvoker;
use Evolve\Mcp\Server\McpCapabilityKind;
use Evolve\Mcp\Server\McpCapabilityPolicy;
use Evolve\Mcp\Server\McpPrincipal;
use Evolve\Mcp\Server\McpSecurityAuditSink;
use Evolve\Mcp\Server\McpServerBuilder;
use Evolve\Mcp\Server\McpToolDefinition;
use PHPUnit\Framework\TestCase;

final class McpOperationAcceptanceTest extends TestCase
{
    public function test_denied_discovery_stays_denied_when_diagnostic_sink_throws(): void
    {
        $source = (new McpServerBuilder('test', '1', new class implements McpCapabilityInvoker {
            public function invoke(string $serviceId, array $arguments): mixed
            {
                return null;
            }
        }))->addTool(new McpToolDefinition('private', 'tool.private', ['type' => 'object'], exposure: McpCapabilityExposure::RemoteApproved))->build();
        $registry = new ServiceRegistry();
        $registry->freeze();
        $sink = new class implements McpOperationSink {
            /** @var list<array<string, string|int>> */
            public array $events = [];
            public function record(array $fields): void
            {
                $this->events[] = $fields;
                throw new \RuntimeException('sink failed');
            }
        };
        $factory = new McpAuthorizedServerFactory(
            $source,
            new ExecutionOrchestrator($registry),
            static fn(): null => null,
            new class implements McpCapabilityPolicy {
                public function allows(McpPrincipal $principal, McpCapabilityKind $kind, string $identity): bool
                {
                    return false;
                }
            },
            new class implements McpSecurityAuditSink {
                public function record(McpCapabilityKind $kind, string $identity, bool $allowed, string $correlation): void {}
            },
            new McpOperationDispatcher($sink),
        );
        self::assertSame([], $factory->create(new McpPrincipal('alice'))->tools());
        self::assertCount(1, $sink->events);
        self::assertSame('denied', $sink->events[0]['outcome']);
        self::assertSame(hash('sha256', 'private'), $sink->events[0]['capability_hash']);
    }
    public function test_scoped_invocation_emits_bounded_success_and_contains_sink_failure(): void
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        $sink = new class implements McpOperationSink {
            /** @var list<array<string, string|int>> */
            public array $events = [];
            public function record(array $fields): void
            {
                $this->events[] = $fields;
                throw new \RuntimeException('sink failed');
            }
        };
        $invoker = new \Evolve\Mcp\Transport\ScopedMcpCapabilityInvoker(
            new ExecutionOrchestrator($registry),
            static fn(): string => 'done',
            new McpOperationDispatcher($sink),
        );
        self::assertSame('done', $invoker->invoke('service.private', ['secret' => 'payload']));
        self::assertCount(1, $sink->events);
        self::assertSame('invoke', $sink->events[0]['kind']);
        self::assertSame('success', $sink->events[0]['outcome']);
        self::assertSame(hash('sha256', 'service.private'), $sink->events[0]['capability_hash']);
        self::assertStringNotContainsString('payload', json_encode($sink->events, JSON_THROW_ON_ERROR));
    }
    public function test_invalid_transport_configuration_is_rejected_before_execution(): void
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        $calls = 0;
        try {
            new \Evolve\Mcp\Transport\ScopedMcpCapabilityInvoker(
                new ExecutionOrchestrator($registry),
                static function () use (&$calls): string {
                    ++$calls;
                    return 'done';
                },
                transport: 'unsupported',
            );
            self::fail('Expected invalid transport rejection.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $calls);
        }
    }

    public function test_telemetry_event_failure_never_masks_success_operation_or_cleanup(): void
    {
        foreach (['success', 'operation', 'cleanup'] as $mode) {
            $registry = new ServiceRegistry();
            $registry->freeze();
            $sink = new class implements McpOperationSink {
                /** @var list<array<string, string|int>> */
                public array $events = [];
                public function record(array $fields): void
                {
                    $this->events[] = $fields;
                }
            };
            $invoker = new \Evolve\Mcp\Transport\ScopedMcpCapabilityInvoker(
                new ExecutionOrchestrator($registry),
                static function (\Evolve\Core\Execution\ExecutionScope $scope) use ($mode): string {
                    if ($mode === 'operation') {
                        throw new \RuntimeException('primary failure');
                    }
                    if ($mode === 'cleanup') {
                        $scope->registerResetParticipant('broken', new class implements \Evolve\Contracts\Execution\ResetParticipant {
                            public function reset(): void
                            {
                                throw new \RuntimeException('cleanup failure');
                            }
                        });
                    }
                    return 'done';
                },
                new McpOperationDispatcher($sink),
            );
            (new \ReflectionProperty($invoker, 'transport'))->setValue($invoker, 'unsupported');
            try {
                $result = $invoker->invoke('tool.private', []);
                self::assertSame('success', $mode);
                self::assertSame('done', $result);
            } catch (\RuntimeException $exception) {
                self::assertSame($mode === 'operation' ? 'primary failure' : 'Execution scope reset failed.', $exception->getMessage());
                if ($mode === 'cleanup') {
                    self::assertSame($exception, $invoker->quarantineFailure());
                }
            }
            self::assertSame([], $sink->events);
        }
    }

    public function test_native_windows_stdio_refusal_emits_safe_category_without_child_launch(): void
    {
        if (PHP_OS_FAMILY === 'Linux') {
            self::markTestSkipped('Native Windows refusal is a Windows-only check.');
        }
        $sink = new class implements McpOperationSink {
            /** @var list<array<string, string|int>> */
            public array $events = [];
            public function record(array $fields): void
            {
                $this->events[] = $fields;
            }
        };
        $configuration = \Evolve\Mcp\Client\McpClientConfiguration::stdio(
            PHP_BINARY,
            [],
            dirname(PHP_BINARY),
            [],
            static fn(): bool => true,
        );
        try {
            (new \Evolve\Mcp\Client\McpClientFactory(new McpOperationDispatcher($sink)))->connect($configuration);
            self::fail('Expected native Windows STDIO refusal.');
        } catch (\Evolve\Mcp\Client\McpClientException $exception) {
            self::assertSame(\Evolve\Mcp\Client\McpClientFailureCategory::Configuration, $exception->category);
        }
        self::assertCount(1, $sink->events);
        self::assertSame('connect', $sink->events[0]['kind']);
        self::assertSame('configuration', $sink->events[0]['failure_category']);
    }
    public function test_failed_client_discovery_is_categorical_and_poisons_connection(): void
    {
        $sink = new class implements McpOperationSink {
            /** @var list<array<string, string|int>> */
            public array $events = [];
            public function record(array $fields): void
            {
                $this->events[] = $fields;
            }
        };
        $configuration = \Evolve\Mcp\Client\McpClientConfiguration::stdio(
            PHP_BINARY,
            [],
            dirname(PHP_BINARY),
            [],
            static fn(): bool => true,
        );
        $connection = new \Evolve\Mcp\Client\McpClientConnection(
            \Mcp\Client::builder()->build(),
            $configuration,
            new McpOperationDispatcher($sink),
        );
        try {
            $connection->discover();
            self::fail('Expected unconnected SDK client failure.');
        } catch (\Evolve\Mcp\Client\McpClientException $exception) {
            self::assertSame(\Evolve\Mcp\Client\McpClientFailureCategory::Discovery, $exception->category);
        }
        self::assertFalse($connection->isReusable());
        self::assertCount(1, $sink->events);
        self::assertSame('discover', $sink->events[0]['kind']);
        self::assertSame('discovery', $sink->events[0]['failure_category']);
    }
    public function test_registration_emits_only_hashed_capability_identity(): void
    {
        $sink = new class implements McpOperationSink {
            /** @var list<array<string, string|int>> */
            public array $events = [];
            public function record(array $fields): void
            {
                $this->events[] = $fields;
            }
        };
        $builder = new McpServerBuilder('test', '1', new class implements McpCapabilityInvoker {
            public function invoke(string $serviceId, array $arguments): mixed
            {
                return null;
            }
        }, operations: new McpOperationDispatcher($sink));
        $builder->addTool(new McpToolDefinition('private-name', 'tool.private', ['type' => 'object']));
        self::assertCount(1, $sink->events);
        self::assertSame('register', $sink->events[0]['kind']);
        self::assertSame(hash('sha256', 'private-name'), $sink->events[0]['capability_hash']);
        self::assertStringNotContainsString('private-name', json_encode($sink->events, JSON_THROW_ON_ERROR));
    }
    public function test_http_policy_transport_failure_is_safe_and_does_not_change_denial(): void
    {
        $sink = new class implements McpOperationSink {
            /** @var list<array<string, string|int>> */
            public array $events = [];
            public function record(array $fields): void
            {
                $this->events[] = $fields;
            }
        };
        $factory = new \GuzzleHttp\Psr7\HttpFactory();
        $policy = new \Evolve\Mcp\Client\McpHttpPolicyClient(
            new class implements \Psr\Http\Client\ClientInterface {
                public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'text/event-stream'], 'secret');
                }
            },
            $factory,
            'https://example.test/mcp',
            static fn(): bool => true,
            1024,
            operations: new McpOperationDispatcher($sink),
        );
        try {
            $policy->sendRequest($factory->createRequest('POST', 'https://example.test/mcp'));
            self::fail('Expected JSON-only transport denial.');
        } catch (\Evolve\Mcp\Client\McpClientException $exception) {
            self::assertSame(\Evolve\Mcp\Client\McpClientFailureCategory::Security, $exception->category);
        }
        self::assertCount(1, $sink->events);
        self::assertSame('transport', $sink->events[0]['kind']);
        self::assertSame('security', $sink->events[0]['failure_category']);
        self::assertStringNotContainsString('secret', json_encode($sink->events, JSON_THROW_ON_ERROR));
    }
    public function test_throwing_diagnostic_sink_cannot_suppress_scope_cleanup_quarantine(): void
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        $sink = new class implements McpOperationSink {
            /** @var list<array<string, string|int>> */
            public array $events = [];
            public function record(array $fields): void
            {
                $this->events[] = $fields;
                throw new \RuntimeException('sink failed');
            }
        };
        $invoker = new \Evolve\Mcp\Transport\ScopedMcpCapabilityInvoker(
            new ExecutionOrchestrator($registry),
            static function (\Evolve\Core\Execution\ExecutionScope $scope): string {
                $scope->registerResetParticipant('broken', new class implements \Evolve\Contracts\Execution\ResetParticipant {
                    public function reset(): void
                    {
                        throw new \RuntimeException('cleanup failed');
                    }
                });
                return 'not-returned';
            },
            new McpOperationDispatcher($sink),
        );
        try {
            $invoker->invoke('tool.private', []);
            self::fail('Expected cleanup failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame($exception, $invoker->quarantineFailure());
        }
        self::assertCount(1, $sink->events);
        self::assertSame('cleanup', $sink->events[0]['failure_category']);
    }
}
