<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Unit\Server;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Mcp\Server\McpAuthorizedServerFactory;
use Evolve\Mcp\Server\McpCapabilityExposure;
use Evolve\Mcp\Server\McpCapabilityKind;
use Evolve\Mcp\Server\McpCapabilityPolicy;
use Evolve\Mcp\Server\McpPrincipal;
use Evolve\Mcp\Server\McpSecurityAuditSink;
use Evolve\Mcp\Server\McpServerBuilder;
use Evolve\Mcp\Server\McpToolDefinition;
use PHPUnit\Framework\TestCase;

final class McpAuthorizedServerFactoryTest extends TestCase
{
    public function test_principal_requires_a_nonempty_identity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new McpPrincipal('');
    }

    public function test_local_only_is_the_default_and_remote_exposure_requires_policy(): void
    {
        $source = $this->source();
        self::assertSame(McpCapabilityExposure::LocalOnly, $source->tools()[0]->exposure);
        $registry = new ServiceRegistry();
        $registry->freeze();
        $calls = [];
        $factory = new McpAuthorizedServerFactory(
            $source,
            new ExecutionOrchestrator($registry),
            static function ($scope, McpPrincipal $principal, string $serviceId, array $arguments) use (&$calls): string {
                $calls[] = $principal->subject();
                return $serviceId;
            },
            new class implements McpCapabilityPolicy {
                public function allows(McpPrincipal $principal, McpCapabilityKind $kind, string $identity): bool
                {
                    return true;
                }
            },
            new class implements McpSecurityAuditSink {
                public function record(McpCapabilityKind $kind, string $identity, bool $allowed, string $correlation): void {}
            },
        );
        self::assertSame([], $factory->create(new McpPrincipal('alice'))->tools());
        self::assertSame([], $calls);
    }

    public function test_policy_or_audit_failure_aborts_construction_before_publication(): void
    {
        $source = $this->source(McpCapabilityExposure::RemoteApproved);
        $registry = new ServiceRegistry();
        $registry->freeze();
        $policy = new class implements McpCapabilityPolicy {
            public function allows(McpPrincipal $principal, McpCapabilityKind $kind, string $identity): bool
            {
                throw new \RuntimeException('private policy detail');
            }
        };
        $sink = new class implements McpSecurityAuditSink {
            public function record(McpCapabilityKind $kind, string $identity, bool $allowed, string $correlation): void {}
        };
        $factory = new McpAuthorizedServerFactory($source, new ExecutionOrchestrator($registry), static fn(): null => null, $policy, $sink);
        $this->expectException(\RuntimeException::class);
        $factory->create(new McpPrincipal('alice'));
    }


    public function test_filters_all_three_discovery_lists_and_denies_a_guessed_identity(): void
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        $calls = 0;
        $source = (new McpServerBuilder('test', '1.0', new class implements \Evolve\Mcp\Server\McpCapabilityInvoker {
            public function invoke(string $serviceId, array $arguments): mixed
            {
                return null;
            }
        }))
            ->addTool(new McpToolDefinition('visible', 'tool.visible', ['type' => 'object'], exposure: McpCapabilityExposure::RemoteApproved))
            ->addTool(new McpToolDefinition('hidden', 'tool.hidden', ['type' => 'object'], exposure: McpCapabilityExposure::RemoteApproved))
            ->addResource(new \Evolve\Mcp\Server\McpResourceDefinition('example://hidden', 'hidden', 'resource.hidden', exposure: McpCapabilityExposure::RemoteApproved))
            ->addPrompt(new \Evolve\Mcp\Server\McpPromptDefinition('hidden', 'prompt.hidden', exposure: McpCapabilityExposure::RemoteApproved))
            ->build();
        $factory = new McpAuthorizedServerFactory(
            $source,
            new ExecutionOrchestrator($registry),
            static function () use (&$calls): string {
                ++$calls;
                return 'invoked';
            },
            new class implements McpCapabilityPolicy {
                public function allows(McpPrincipal $principal, McpCapabilityKind $kind, string $identity): bool
                {
                    return $kind === McpCapabilityKind::Tool && $identity === 'visible';
                }
            },
            new class implements McpSecurityAuditSink {
                public function record(McpCapabilityKind $kind, string $identity, bool $allowed, string $correlation): void {}
            },
        );
        $definition = $factory->create(new McpPrincipal('alice'));
        self::assertSame(['visible'], array_map(static fn($tool): string => $tool->name, $definition->tools()));
        self::assertSame([], $definition->resources());
        self::assertSame([], $definition->prompts());
        self::assertSame(['visible'], array_column($this->rpc($definition, 'tools/list')['result']['tools'], 'name'));
        self::assertSame([], $this->rpc($definition, 'resources/list')['result']['resources']);
        self::assertSame([], $this->rpc($definition, 'prompts/list')['result']['prompts']);
        self::assertArrayHasKey('error', $this->rpc($definition, 'tools/call', ['name' => 'hidden', 'arguments' => []]));
        self::assertSame(0, $calls);
    }

    public function test_filtered_sdk_registry_paginates_only_visible_tools(): void
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        $builder = new McpServerBuilder('test', '1.0', new class implements \Evolve\Mcp\Server\McpCapabilityInvoker {
            public function invoke(string $serviceId, array $arguments): mixed
            {
                return null;
            }
        });
        for ($index = 0; $index < 55; ++$index) {
            $builder->addTool(new McpToolDefinition('tool-' . $index, 'service.' . $index, ['type' => 'object'], exposure: McpCapabilityExposure::RemoteApproved));
        }
        $builder->addTool(new McpToolDefinition('hidden', 'service.hidden', ['type' => 'object'], exposure: McpCapabilityExposure::RemoteApproved));
        $factory = new McpAuthorizedServerFactory(
            $builder->build(),
            new ExecutionOrchestrator($registry),
            static fn(): null => null,
            new class implements McpCapabilityPolicy {
                public function allows(McpPrincipal $principal, McpCapabilityKind $kind, string $identity): bool
                {
                    return $identity !== 'hidden';
                }
            },
            new class implements McpSecurityAuditSink {
                public function record(McpCapabilityKind $kind, string $identity, bool $allowed, string $correlation): void {}
            },
        );
        $definition = $factory->create(new McpPrincipal('alice'));
        $first = $this->rpc($definition, 'tools/list')['result'];
        self::assertCount(50, $first['tools']);
        self::assertArrayHasKey('nextCursor', $first);
        $second = $this->rpc($definition, 'tools/list', ['cursor' => $first['nextCursor']])['result'];
        self::assertCount(5, $second['tools']);
        self::assertArrayNotHasKey('nextCursor', $second);
        $names = array_column(array_merge($first['tools'], $second['tools']), 'name');
        self::assertCount(55, array_unique($names));
        self::assertNotContains('hidden', $names);
    }
    public function test_rechecks_permission_and_audit_before_invocation_without_payload_leak(): void
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        $calls = 0;
        $allowed = true;
        $policy = new class ($allowed) implements McpCapabilityPolicy {
            public function __construct(public bool &$allowed) {}
            public function allows(McpPrincipal $principal, McpCapabilityKind $kind, string $identity): bool
            {
                return $this->allowed;
            }
        };
        $sink = new class implements McpSecurityAuditSink {
            /** @var list<array{string, string, bool, string}> */
            public array $records = [];
            public function record(McpCapabilityKind $kind, string $identity, bool $allowed, string $correlation): void
            {
                $this->records[] = [$kind->value, $identity, $allowed, $correlation];
            }
        };
        $factory = new McpAuthorizedServerFactory(
            $this->source(McpCapabilityExposure::RemoteApproved),
            new ExecutionOrchestrator($registry),
            static function () use (&$calls): string {
                ++$calls;
                return 'invoked';
            },
            $policy,
            $sink,
        );
        $definition = $factory->create(new McpPrincipal('private-user'));
        $allowed = false;
        self::assertArrayHasKey('error', $this->rpc($definition, 'tools/call', ['name' => 'one', 'arguments' => ['secret' => 'private-token']]));
        self::assertSame(0, $calls);
        self::assertCount(2, $sink->records);
        $first = $sink->records[0];
        $second = $sink->records[1];
        self::assertSame(hash('sha256', 'tool:one'), $first[1]);
        self::assertFalse($second[2]);
        self::assertSame($first[3], $second[3]);
        self::assertStringNotContainsString('private-token', json_encode($sink->records, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private-user', json_encode($sink->records, JSON_THROW_ON_ERROR));
    }

    public function test_audit_failure_before_invocation_prevents_host_service_execution(): void
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        $calls = 0;
        $sink = new class implements McpSecurityAuditSink {
            public int $records = 0;
            public function record(McpCapabilityKind $kind, string $identity, bool $allowed, string $correlation): void
            {
                if (++$this->records === 2) {
                    throw new \RuntimeException('private audit failure');
                }
            }
        };
        $factory = new McpAuthorizedServerFactory(
            $this->source(McpCapabilityExposure::RemoteApproved),
            new ExecutionOrchestrator($registry),
            static function () use (&$calls): null {
                ++$calls;
                return null;
            },
            new class implements McpCapabilityPolicy {
                public function allows(McpPrincipal $principal, McpCapabilityKind $kind, string $identity): bool
                {
                    return true;
                }
            },
            $sink,
        );
        $definition = $factory->create(new McpPrincipal('alice'));
        $response = $this->rpc($definition, 'tools/call', ['name' => 'one', 'arguments' => []]);
        self::assertArrayHasKey('error', $response);
        self::assertSame(0, $calls);
        self::assertSame(2, $sink->records);
        self::assertStringNotContainsString('private audit failure', json_encode($response, JSON_THROW_ON_ERROR));
    }
    public function test_audit_failure_during_listing_fails_atomically(): void
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        $factory = new McpAuthorizedServerFactory(
            $this->source(McpCapabilityExposure::RemoteApproved),
            new ExecutionOrchestrator($registry),
            static fn(): null => null,
            new class implements McpCapabilityPolicy {
                public function allows(McpPrincipal $principal, McpCapabilityKind $kind, string $identity): bool
                {
                    return true;
                }
            },
            new class implements McpSecurityAuditSink {
                public function record(McpCapabilityKind $kind, string $identity, bool $allowed, string $correlation): void
                {
                    throw new \RuntimeException('private audit detail');
                }
            },
        );
        $this->expectException(\RuntimeException::class);
        $factory->create(new McpPrincipal('alice'));
    }

    /** @param array<string, mixed> $params
     *  @return array<string, mixed>
     */
    private function rpc(\Evolve\Mcp\Server\McpServerDefinition $definition, string $method, array $params = []): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
        $headers = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];
        if (isset($params['name'])) {
            $headers['Mcp-Name'] = $params['name'];
        }
        $result = $definition->statelessProtocol()->handle(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR),
            $headers,
        );
        return json_decode($result->toJson(), true, 512, JSON_THROW_ON_ERROR);
    }
    private function source(McpCapabilityExposure $exposure = McpCapabilityExposure::LocalOnly): \Evolve\Mcp\Server\McpServerDefinition
    {
        $invoker = new class implements \Evolve\Mcp\Server\McpCapabilityInvoker {
            public function invoke(string $serviceId, array $arguments): mixed
            {
                return null;
            }
        };
        return (new McpServerBuilder('test', '1.0', $invoker))
            ->addTool(new McpToolDefinition('one', 'tool.one', ['type' => 'object'], exposure: $exposure))
            ->build();
    }
}
