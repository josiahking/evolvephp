<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Integration\Client;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Mcp\Client\McpClientConfiguration;
use Evolve\Mcp\Client\McpClientFactory;
use Evolve\Mcp\Observability\McpOperationDispatcher;
use Evolve\Mcp\Observability\McpOperationSink;
use Evolve\Mcp\Server\McpAuthorizedServerFactory;
use Evolve\Mcp\Server\McpCapabilityExposure;
use Evolve\Mcp\Server\McpCapabilityKind;
use Evolve\Mcp\Server\McpCapabilityPolicy;
use Evolve\Mcp\Server\McpPrincipal;
use Evolve\Mcp\Server\McpPromptDefinition;
use Evolve\Mcp\Server\McpResourceDefinition;
use Evolve\Mcp\Server\McpSecurityAuditSink;
use Evolve\Mcp\Server\McpServerBuilder;
use Evolve\Mcp\Server\McpToolDefinition;
use Evolve\Mcp\Transport\McpHttpRequestHandler;
use Evolve\Mcp\Transport\ScopedMcpCapabilityInvoker;
use Evolve\Secret\Contracts\SecretName;
use Evolve\Secret\Contracts\SecretResolver;
use Evolve\Secret\Contracts\SecretValue;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Mcp\Schema\PromptArgument;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class McpHttpClientIntegrationTest extends TestCase
{
    public function test_modern_json_client_discovers_without_invocation_and_explicitly_invokes_each_kind(): void
    {
        $calls = new \ArrayObject();
        $factory = new HttpFactory();
        $registry = new ServiceRegistry();
        $registry->freeze();
        $invoker = new ScopedMcpCapabilityInvoker(
            new ExecutionOrchestrator($registry),
            static function (ExecutionScope $scope, string $serviceId, array $arguments) use (&$calls): mixed {
                $calls->append([$serviceId, $arguments]);
                return match ($serviceId) {
                    'tool.echo' => 'echo: ' . $arguments['text'],
                    'resource.read' => 'resource body',
                    'prompt.greet' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Hello ' . $arguments['name']]]],
                    default => throw new \LogicException('Unexpected service ID.'),
                };
            },
        );
        $builder = new McpServerBuilder('example', '1.0', $invoker);
        $builder->addTool(new McpToolDefinition('echo', 'tool.echo', ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']], exposure: McpCapabilityExposure::RemoteApproved));
        $builder->addResource(new McpResourceDefinition('example://document', 'document', 'resource.read', exposure: McpCapabilityExposure::RemoteApproved));
        $builder->addPrompt(new McpPromptDefinition('greet', 'prompt.greet', [new PromptArgument('name', required: true)], exposure: McpCapabilityExposure::RemoteApproved));
        $servers = new McpAuthorizedServerFactory(
            $builder->build(),
            new ExecutionOrchestrator($registry),
            static fn(ExecutionScope $scope, McpPrincipal $principal, string $serviceId, array $arguments): mixed => $invoker->invoke($serviceId, $arguments),
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
        $handler = new McpHttpRequestHandler($servers, $factory, $factory, static fn(): McpPrincipal => new McpPrincipal('alice'));

        $http = $this->createStub(ClientInterface::class);
        $http->method('sendRequest')->willReturnCallback(static function (RequestInterface $request) use ($handler): ResponseInterface {
            self::assertSame('application/json', $request->getHeaderLine('Accept'));
            return $handler->handle(new ServerRequest(
                $request->getMethod(),
                $request->getUri(),
                $request->getHeaders(),
                $request->getBody(),
            ));
        });
        $configuration = McpClientConfiguration::http('https://localhost/mcp', $http, $factory, $factory, static fn(): bool => true);
        $sink = new class implements McpOperationSink {
            /** @var list<array<string, string|int>> */
            public array $events = [];
            public function record(array $fields): void
            {
                $this->events[] = $fields;
            }
        };
        $connection = (new McpClientFactory(new McpOperationDispatcher($sink)))->connect($configuration);

        $catalog = $connection->discover();
        self::assertSame('echo', $catalog->tools()[0]->name);
        self::assertSame('example://document', $catalog->resources()[0]->uri);
        self::assertSame('greet', $catalog->prompts()[0]->name);
        self::assertCount(0, $calls);
        $toolResult = $connection->callTool('echo', ['text' => 'safe']);
        self::assertInstanceOf(\Mcp\Schema\Content\TextContent::class, $toolResult->content[0]);
        self::assertSame('echo: safe', $toolResult->content[0]->text);
        $resourceResult = $connection->readResource('example://document');
        self::assertInstanceOf(\Mcp\Schema\Content\TextResourceContents::class, $resourceResult->contents[0]);
        self::assertSame('resource body', $resourceResult->contents[0]->text);
        self::assertSame('Hello Ada', $connection->getPrompt('greet', ['name' => 'Ada'])->messages[0]->content->text);
        self::assertCount(3, $calls);
        $connection->close();
        self::assertFalse($connection->isReusable());
        self::assertNotEmpty(array_filter($sink->events, static fn(array $event): bool => $event['kind'] === 'transport' && $event['outcome'] === 'success'));
    }

    public function test_factory_http_policy_denial_reaches_sink_and_remains_denied(): void
    {
        $factory = new HttpFactory();
        $sink = new class implements McpOperationSink {
            /** @var list<array<string, string|int>> */
            public array $events = [];
            public function record(array $fields): void
            {
                $this->events[] = $fields;
            }
        };
        $http = $this->createStub(ClientInterface::class);
        $http->method('sendRequest')->willReturn(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'text/event-stream'], 'private'));
        $configuration = McpClientConfiguration::http('https://localhost/mcp', $http, $factory, $factory, static fn(): bool => true);
        try {
            (new McpClientFactory(new McpOperationDispatcher($sink)))->connect($configuration);
            self::fail('Expected JSON-only policy refusal.');
        } catch (\Evolve\Mcp\Client\McpClientException $exception) {
            self::assertSame(\Evolve\Mcp\Client\McpClientFailureCategory::Security, $exception->category);
        }
        self::assertNotEmpty(array_filter($sink->events, static fn(array $event): bool => $event['kind'] === 'transport' && $event['failure_category'] === 'security'));
        self::assertStringNotContainsString('private', json_encode($sink->events, JSON_THROW_ON_ERROR));
    }

    public function test_named_bearer_secret_is_resolved_before_any_request_and_never_appears_in_errors(): void
    {
        $factory = new HttpFactory();
        $secret = new SecretName('mcp.remote.token');
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('sendRequest');
        $resolver = $this->createMock(SecretResolver::class);
        $resolver->expects(self::once())->method('resolve')->with($secret)->willReturn(null);
        $configuration = McpClientConfiguration::http('https://localhost/mcp', $http, $factory, $factory, static fn(): bool => true, $secret);

        $this->expectException(\Evolve\Mcp\Client\McpClientException::class);
        (new McpClientFactory())->connect($configuration, $resolver);
    }

    public function test_invalid_and_failing_secret_resolution_stop_before_http_io(): void
    {
        $factory = new HttpFactory();
        $secret = new SecretName('mcp.remote.token');
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('sendRequest');
        $configuration = McpClientConfiguration::http('https://localhost/mcp', $http, $factory, $factory, static fn(): bool => true, $secret);
        foreach ([
            static fn(): SecretValue => new SecretValue("bad\nheader"),
            static function (): never {
                throw new \RuntimeException('private secret backend detail');
            },
        ] as $resolve) {
            $resolver = $this->createStub(SecretResolver::class);
            $resolver->method('resolve')->willReturnCallback($resolve);
            try {
                (new McpClientFactory())->connect($configuration, $resolver);
                self::fail('Expected safe secret failure.');
            } catch (\Evolve\Mcp\Client\McpClientException $exception) {
                self::assertSame(\Evolve\Mcp\Client\McpClientFailureCategory::Secret, $exception->category);
                self::assertStringNotContainsString('private', $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
        }
    }
    public function test_resolved_bearer_header_is_sent_without_appearing_in_public_failure(): void
    {
        $factory = new HttpFactory();
        $secret = new SecretName('mcp.remote.token');
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('sendRequest')->willReturnCallback(static function (RequestInterface $request): ResponseInterface {
            self::assertSame('Bearer private-value', $request->getHeaderLine('Authorization'));
            throw new \RuntimeException('private-value transport details');
        });
        $resolver = $this->createStub(SecretResolver::class);
        $resolver->method('resolve')->willReturn(new SecretValue('private-value'));
        $configuration = McpClientConfiguration::http('https://localhost/mcp', $http, $factory, $factory, static fn(): bool => true, $secret);

        try {
            (new McpClientFactory())->connect($configuration, $resolver);
            self::fail('Expected safe connection failure.');
        } catch (\Evolve\Mcp\Client\McpClientException $exception) {
            self::assertStringNotContainsString('private-value', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }
}
