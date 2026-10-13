<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Integration\Contribution;

use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Core\Component\ComponentGraphResolver;
use Evolve\Mcp\Contribution\Exception\McpContributionFailed;
use Evolve\Mcp\Contribution\McpCapabilityRegistrar;
use Evolve\Mcp\Contribution\McpComponentContribution;
use Evolve\Mcp\Contribution\McpContributionServerComposer;
use Evolve\Mcp\Server\McpCapabilityInvoker;
use Evolve\Mcp\Server\McpPromptDefinition;
use Evolve\Mcp\Server\McpResourceDefinition;
use Evolve\Mcp\Server\McpServerBuilder;
use Evolve\Mcp\Server\McpToolDefinition;
use PHPUnit\Framework\TestCase;

final class McpComponentContributionIntegrationTest extends TestCase
{
    public function test_host_and_component_capabilities_compose_without_eager_invocation(): void
    {
        $declaration = new ComponentGraphDeclaration(new ComponentIdentifier('plugin/one'));
        $graph = (new ComponentGraphResolver())->resolve([$declaration]);
        $invoker = new class implements McpCapabilityInvoker {
            /** @var list<string> */ public array $calls = [];
            public function invoke(string $serviceId, array $arguments): mixed
            {
                $this->calls[] = $serviceId;
                return $serviceId === 'host.tool' ? 'component response' : $arguments;
            }
        };
        $composer = new McpContributionServerComposer('example', '1.0', $invoker);
        $server = $composer->compose($graph, [new McpComponentContribution($declaration, static function (McpCapabilityRegistrar $registrar): void {
            $registrar->registerTool(new McpToolDefinition('component', 'host.tool', ['type' => 'object']));
            $registrar->registerResource(new McpResourceDefinition('example://component', 'component', 'host.resource'));
            $registrar->registerPrompt(new McpPromptDefinition('component', 'host.prompt'));
        })], [new McpToolDefinition('host', 'host.own', ['type' => 'object'])]);

        self::assertSame(['host', 'component'], array_map(static fn(McpToolDefinition $tool): string => $tool->name, $server->tools()));
        self::assertSame(\Evolve\Mcp\Server\McpCapabilityExposure::LocalOnly, $server->tools()[1]->exposure);
        self::assertCount(1, $server->resources());
        self::assertCount(1, $server->prompts());
        self::assertSame([], $invoker->calls);
        self::assertSame($invoker, $server->invoker());
        self::assertSame('component response', $server->invoker()->invoke('host.tool', ['value' => 1]));
        self::assertSame(['host.tool'], $invoker->calls);
        $response = self::callTool($server->server());
        self::assertSame('component response', $response['result']['content'][0]['text']);
        self::assertSame(['host.tool', 'host.tool'], $invoker->calls);
        self::assertNotNull($server->statelessProtocol());
    }

    public function test_empty_host_only_and_direct_builder_compatibility(): void
    {
        $graph = (new ComponentGraphResolver())->resolve([]);
        $invoker = new class implements McpCapabilityInvoker {
            public function invoke(string $serviceId, array $arguments): mixed
            {
                return null;
            }
        };
        $composer = new McpContributionServerComposer('example', '1.0', $invoker);
        self::assertSame([], $composer->compose($graph, [])->tools());
        self::assertCount(1, $composer->compose($graph, [], [], [], [new McpPromptDefinition('host', 'service')])->prompts());
        self::assertCount(1, (new McpServerBuilder('example', '1.0', $invoker))->addTool(new McpToolDefinition('direct', 'service', ['type' => 'object']))->build()->tools());
    }

    /** @return array<string, mixed> */
    private static function callTool(\Mcp\Server $server): array
    {
        $messages = [
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ]], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name' => 'component', 'arguments' => ['value' => 2],
            ]], JSON_THROW_ON_ERROR),
        ];
        $transport = new class ($messages) extends \Mcp\Server\Transport\InMemoryTransport {
            /** @param list<string> $inputs */
            public function __construct(private array $inputs)
            {
                parent::__construct();
            }
            /** @var array<string, mixed> */
            public array $response = [];
            public function listen(): mixed
            {
                foreach ($this->inputs as $message) {
                    $this->handleMessage($message, $this->sessionId);
                    if ($this->sessionId !== null) {
                        foreach ($this->getOutgoingMessages($this->sessionId) as $outgoing) {
                            $this->send($outgoing['message'], $outgoing['context']);
                        }
                    }
                }
                return null;
            }
            public function send(string $data, array $context): void
            {
                parent::send($data, $context);
                $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                if (($decoded['id'] ?? null) === 2) {
                    $this->response = $decoded;
                }
            }
        };
        $server->run($transport);
        return $transport->response;
    }
    public function test_host_collision_prevents_server_publication(): void
    {
        $declaration = new ComponentGraphDeclaration(new ComponentIdentifier('module'));
        $graph = (new ComponentGraphResolver())->resolve([$declaration]);
        $invoker = new class implements McpCapabilityInvoker {
            public function invoke(string $serviceId, array $arguments): mixed
            {
                return null;
            }
        };
        $composer = new McpContributionServerComposer('example', '1.0', $invoker);
        $this->expectException(McpContributionFailed::class);
        $composer->compose($graph, [new McpComponentContribution($declaration, static function (McpCapabilityRegistrar $registrar): void {
            $registrar->registerTool(new McpToolDefinition('same', 'component', ['type' => 'object']));
        })], [new McpToolDefinition('same', 'host', ['type' => 'object'])]);
    }
}
