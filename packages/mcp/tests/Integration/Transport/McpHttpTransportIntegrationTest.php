<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Integration\Transport;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Http\HttpKernel;
use Evolve\Http\Routing\Route;
use Evolve\Http\Routing\RouteCollection;
use Evolve\Http\Routing\RouteMatcher;
use Evolve\Http\Routing\RoutingRequestHandler;
use Evolve\Mcp\Server\McpServerBuilder;
use Evolve\Mcp\Server\McpToolDefinition;
use Evolve\Mcp\Transport\McpHttpRequestHandler;
use Evolve\Mcp\Transport\ScopedMcpCapabilityInvoker;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Mcp\Server\Transport\CallbackStream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class McpHttpTransportIntegrationTest extends TestCase
{
    public function test_modern_json_tool_call_uses_sdk_and_remains_eager_across_requests(): void
    {
        $calls = [];
        $handler = $this->handler($calls);
        $first = $handler->handle($this->request('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'first']]));
        $second = $handler->handle($this->request('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'second']]));

        foreach ([$first, $second] as $response) {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
            self::assertNotInstanceOf(CallbackStream::class, $response->getBody());
        }
        self::assertSame('echo: first', $this->json($first)['result']['content'][0]['text']);
        self::assertSame('echo: second', $this->json($second)['result']['content'][0]['text']);
        self::assertSame(['first', 'second'], $calls);
    }

    public function test_dual_accept_and_subscription_requests_are_rejected_before_sdk_dispatch(): void
    {
        $calls = [];
        $handler = $this->handler($calls);
        $dual = $this->request('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'never']])
            ->withHeader('Accept', 'application/json, text/event-stream');
        $subscription = $this->request('subscriptions/listen');

        foreach ([$dual, $subscription] as $request) {
            $response = $handler->handle($request);
            self::assertContains($response->getStatusCode(), [400, 406, 501]);
            self::assertNotInstanceOf(CallbackStream::class, $response->getBody());
            self::assertNotSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        }
        self::assertSame([], $calls);
    }

    public function test_host_authorization_and_sdk_origin_check_reject_untrusted_requests(): void
    {
        $calls = [];
        $denied = $this->handler($calls, static fn(): bool => false)
            ->handle($this->request('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'never']]));
        self::assertSame(403, $denied->getStatusCode());

        $origin = $this->handler($calls)->handle(
            $this->request('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'never']])
                ->withHeader('Origin', 'https://untrusted.example'),
        );
        self::assertSame(403, $origin->getStatusCode());
        $host = $this->handler($calls)->handle(
            $this->request('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'never']])
                ->withHeader('Host', 'untrusted.example'),
        );
        self::assertSame(403, $host->getStatusCode());
        self::assertSame([], $calls);
    }

    public function test_invalid_payload_version_method_and_size_are_bounded_without_capability_dispatch(): void
    {
        $calls = [];
        $handler = $this->handler($calls);
        $malformed = $this->request('tools/list')->withBody((new HttpFactory())->createStream('{broken'));
        self::assertSame(400, $handler->handle($malformed)->getStatusCode());

        $oldVersion = $this->request('tools/list')
            ->withBody((new HttpFactory())->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => ['_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2025-06-18',
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ]],
            ], JSON_THROW_ON_ERROR)));
        self::assertSame(400, $handler->handle($oldVersion)->getStatusCode());
        self::assertSame(405, $handler->handle(new ServerRequest('GET', 'http://localhost/mcp', ['Host' => 'localhost']))->getStatusCode());
        self::assertSame(405, $handler->handle(new ServerRequest('DELETE', 'http://localhost/mcp', ['Host' => 'localhost']))->getStatusCode());

        $factory = new HttpFactory();
        $small = $this->handler($calls, maxBodyBytes: 64);
        self::assertSame(413, $small->handle($this->request('tools/list'))->getStatusCode());
        self::assertSame([], $calls);
    }

    public function test_notification_has_sdk_bodyless_acknowledgement(): void
    {
        $calls = [];
        $handler = $this->handler($calls);
        $request = $this->request('notifications/cancelled')
            ->withBody((new HttpFactory())->createStream(json_encode([
                'jsonrpc' => '2.0',
                'method' => 'notifications/cancelled',
            ], JSON_THROW_ON_ERROR)));
        $response = $handler->handle($request);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame([], $calls);
    }
    public function test_explicit_route_and_http_kernel_keep_authorization_request_local(): void
    {
        $calls = [];
        $authorizedUsers = [];
        $handler = $this->handler($calls, static function (\Psr\Http\Message\ServerRequestInterface $request) use (&$authorizedUsers): bool {
            $authorizedUsers[] = $request->getAttribute('user');

            return $request->getAttribute('user') === 'alice'
                && $request->getAttribute(ExecutionScope::class) instanceof ExecutionScope;
        });
        $routes = new RouteCollection([new Route(['POST'], '/mcp', $handler)]);
        $registry = new ServiceRegistry();
        $registry->freeze();
        $kernel = new HttpKernel(new RoutingRequestHandler(new RouteMatcher($routes)), new ExecutionOrchestrator($registry));

        $allowed = $kernel->handle($this->request('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'allowed']])->withAttribute('user', 'alice'));
        $denied = $kernel->handle($this->request('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'blocked']])->withAttribute('user', 'bob'));

        self::assertTrue($allowed->primarySucceeded());
        self::assertSame(200, $allowed->primaryResult()->getStatusCode());
        self::assertTrue($allowed->isReusable());
        self::assertTrue($denied->primarySucceeded());
        self::assertSame(403, $denied->primaryResult()->getStatusCode());
        self::assertTrue($denied->isReusable());
        self::assertSame(['alice', 'bob'], $authorizedUsers);
        self::assertSame(['allowed'], $calls);
    }
    public function test_http_capability_cleanup_quarantine_is_observable_and_prevents_later_dispatch(): void
    {
        $calls = new \ArrayObject();
        $registry = new ServiceRegistry();
        $registry->freeze();
        $invoker = new ScopedMcpCapabilityInvoker(
            new ExecutionOrchestrator($registry),
            static function (ExecutionScope $scope) use ($calls): string {
                $calls->append(true);
                $scope->registerResetParticipant('broken', new class implements \Evolve\Contracts\Execution\ResetParticipant {
                    public function reset(): void
                    {
                        throw new \RuntimeException('reset failed');
                    }
                });

                return 'unusable';
            },
        );
        $builder = new McpServerBuilder('example', '1.0', $invoker);
        $builder->addTool(new McpToolDefinition('echo', 'tool.echo', ['type' => 'object']));
        $factory = new HttpFactory();
        $handler = new McpHttpRequestHandler($builder->build(), $factory, $factory, static fn(): bool => true);
        $request = $this->request('tools/call', ['name' => 'echo', 'arguments' => []]);

        foreach ([$request, $request] as $attempt) {
            try {
                $handler->handle($attempt);
                self::fail('Unsafe worker must never return a normal HTTP response.');
            } catch (\RuntimeException $exception) {
                self::assertSame($handler->quarantineFailure(), $exception->getPrevious());
            }
        }
        self::assertCount(1, $calls);
        self::assertNotNull($invoker->quarantineFailure());
    }
    public function test_http_kernel_exposes_inner_quarantine_as_a_failed_primary_outcome(): void
    {
        $calls = new \ArrayObject();
        $registry = new ServiceRegistry();
        $registry->freeze();
        $invoker = new ScopedMcpCapabilityInvoker(
            new ExecutionOrchestrator($registry),
            static function (ExecutionScope $scope) use ($calls): string {
                $calls->append(true);
                $scope->registerResetParticipant('broken', new class implements \Evolve\Contracts\Execution\ResetParticipant {
                    public function reset(): void
                    {
                        throw new \RuntimeException('reset failed');
                    }
                });

                return 'unusable';
            },
        );
        $builder = new McpServerBuilder('example', '1.0', $invoker);
        $builder->addTool(new McpToolDefinition('echo', 'tool.echo', ['type' => 'object']));
        $factory = new HttpFactory();
        $handler = new McpHttpRequestHandler($builder->build(), $factory, $factory, static fn(): bool => true);
        $routes = new RouteCollection([new Route(['POST'], '/mcp', $handler)]);
        $outerRegistry = new ServiceRegistry();
        $outerRegistry->freeze();
        $kernel = new HttpKernel(new RoutingRequestHandler(new RouteMatcher($routes)), new ExecutionOrchestrator($outerRegistry));
        $request = $this->request('tools/call', ['name' => 'echo', 'arguments' => []]);

        $first = $kernel->handle($request);
        self::assertTrue($first->primaryFailed());
        self::assertSame($handler->quarantineFailure(), $first->primaryThrowableOrFail()->getPrevious());
        self::assertNotNull($handler->quarantineFailure());
        self::assertTrue($first->isReusable(), 'The outer lifecycle cannot encode inner quarantine.');

        $second = $kernel->handle($request);
        self::assertTrue($second->primaryFailed());
        self::assertSame($handler->quarantineFailure(), $second->primaryThrowableOrFail()->getPrevious());
        self::assertCount(1, $calls);
    }

    public function test_ordinary_capability_error_stays_an_sdk_json_rpc_error_without_quarantine(): void
    {
        $calls = 0;
        $registry = new ServiceRegistry();
        $registry->freeze();
        $invoker = new ScopedMcpCapabilityInvoker(
            new ExecutionOrchestrator($registry),
            static function () use (&$calls): string {
                ++$calls;
                if ($calls === 1) {
                    throw new \RuntimeException('ordinary capability failure');
                }

                return 'recovered';
            },
        );
        $builder = new McpServerBuilder('example', '1.0', $invoker);
        $builder->addTool(new McpToolDefinition('echo', 'tool.echo', ['type' => 'object']));
        $factory = new HttpFactory();
        $handler = new McpHttpRequestHandler($builder->build(), $factory, $factory, static fn(): bool => true);
        $request = $this->request('tools/call', ['name' => 'echo', 'arguments' => []]);

        $first = $handler->handle($request);
        self::assertArrayHasKey('error', $this->json($first));
        self::assertNull($handler->quarantineFailure());
        $second = $handler->handle($request);
        self::assertSame('recovered', $this->json($second)['result']['content'][0]['text']);
        self::assertSame(2, $calls);
    }

    public function test_sdk_security_middleware_covers_all_early_http_refusals(): void
    {
        $calls = [];
        $handler = $this->handler($calls);
        $factory = new HttpFactory();
        $normal = $this->request('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'never']]);
        $sse = $normal->withHeader('Accept', 'application/json, text/event-stream');
        $oversized = $normal->withBody($factory->createStream(str_repeat('x', 4194305)));
        $subscription = $this->request('subscriptions/listen');

        foreach ([$normal, $sse, $oversized, $subscription] as $request) {
            foreach ([
                $request->withHeader('Host', 'untrusted.example'),
                $request->withHeader('Origin', 'https://untrusted.example'),
            ] as $untrusted) {
                $response = $handler->handle($untrusted);
                self::assertSame(403, $response->getStatusCode());
                self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
                self::assertSame('Mcp-Session-Id', $response->getHeaderLine('Access-Control-Expose-Headers'));
            }
        }

        self::assertSame(406, $handler->handle($sse)->getStatusCode());
        self::assertSame(413, $handler->handle($oversized)->getStatusCode());
        self::assertSame(501, $handler->handle($subscription)->getStatusCode());
        self::assertSame([], $calls);
    }
    public function test_http_adapter_requires_the_scoped_invoker_bound_to_its_server_definition(): void
    {
        $builder = new McpServerBuilder('example', '1.0', new class implements \Evolve\Mcp\Server\McpCapabilityInvoker {
            public function invoke(string $serviceId, array $arguments): mixed
            {
                return 'unscoped';
            }
        });
        $factory = new HttpFactory();

        $this->expectException(\InvalidArgumentException::class);
        new McpHttpRequestHandler($builder->build(), $factory, $factory, static fn(): bool => true);
    }
    public function test_host_authorization_requires_an_explicit_true_result(): void
    {
        $calls = [];
        $unexpected = 'yes';
        $handler = $this->handler($calls, static fn(): mixed => $unexpected);
        $response = $handler->handle($this->request('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'never']]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $calls);
    }
    /** @param list<string> $calls */
    private function handler(array &$calls, ?\Closure $authorize = null, int $maxBodyBytes = 4194304): McpHttpRequestHandler
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        $invoker = new ScopedMcpCapabilityInvoker(
            new ExecutionOrchestrator($registry),
            static function (ExecutionScope $scope, string $serviceId, array $arguments) use (&$calls): string {
                $calls[] = $arguments['text'];

                return 'echo: ' . $arguments['text'];
            },
        );
        $builder = new McpServerBuilder('example', '1.0', $invoker);
        $builder->addTool(new McpToolDefinition('echo', 'tool.echo', [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
        ]));
        $factory = new HttpFactory();

        return new McpHttpRequestHandler($builder->build(), $factory, $factory, $authorize ?? static fn(): bool => true, $maxBodyBytes);
    }

    /** @param array<string, mixed> $params */
    private function request(string $method, array $params = []): ServerRequest
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
        $headers = [
            'Host' => 'localhost',
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => $method,
        ];
        if (isset($params['name'])) {
            $headers['Mcp-Name'] = $params['name'];
        }
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR);

        return new ServerRequest('POST', 'http://localhost/mcp', $headers, $body);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
