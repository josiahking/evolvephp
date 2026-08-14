<?php

declare(strict_types=1);

namespace Evolve\Http\Tests\Integration;

use BadMethodCallException;
use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionOutcome;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Http\Health\LivenessHandler;
use Evolve\Http\Health\ReadinessCheck;
use Evolve\Http\Health\ReadinessHandler;
use Evolve\Http\HttpKernel;
use Evolve\Http\Middleware\MiddlewarePipeline;
use Evolve\Http\Response\ExecutionOutcomeResponseResolver;
use Evolve\Http\Response\ResponseEmitter;
use Evolve\Http\Routing\Route;
use Evolve\Http\Routing\RouteCollection;
use Evolve\Http\Routing\RouteMatch;
use Evolve\Http\Routing\RouteMatcher;
use Evolve\Http\Routing\RoutingRequestHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;

final class HttpFoundationIntegrationTest extends TestCase
{
    public function test_response_emitter_contract_is_runtime_neutral_and_response_only(): void
    {
        $reflection = new ReflectionClass(ResponseEmitter::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        self::assertTrue($reflection->isInterface());
        self::assertCount(1, $methods);

        $method = $methods[0];

        self::assertSame('emit', $method->getName());
        self::assertSame('void', (string) $method->getReturnType());
        self::assertSame(1, $method->getNumberOfParameters());
        self::assertSame(ResponseInterface::class, (string) $method->getParameters()[0]->getType());

        $signatureTypes = [
            (string) $method->getParameters()[0]->getType(),
            (string) $method->getReturnType(),
        ];

        self::assertNotContains(ServerRequestInterface::class, $signatureTypes);
        self::assertNotContains(ExecutionOutcome::class, $signatureTypes);
        self::assertSame(
            [],
            array_filter(
                $signatureTypes,
                static fn(string $type): bool => str_contains($type, 'Sapi')
                    || str_contains($type, 'Fpm')
                    || str_contains($type, 'FrankenPhp')
                    || str_contains($type, 'RoadRunner')
                    || str_contains($type, 'Runtime'),
            ),
        );
    }

    public function test_clean_routed_success_resolves_then_explicitly_emits_exact_response_identity(): void
    {
        $response = new IntegrationResponse(202);
        $events = new IntegrationEventLog();
        $pre = new AttributeRecordingMiddleware('pre', $events);
        $post = new AttributeRecordingMiddleware('post', $events);
        $handler = new RecordingHandler($response, $events, 'route');
        $kernel = $this->kernel(
            [
                new Route(['GET'], '/articles/{id}', $handler),
            ],
            [$post],
            [$pre],
        );
        $resolver = new ExecutionOutcomeResponseResolver(new RecordingResponseFactory());
        $emitter = new RecordingResponseEmitter();

        $outcome = $kernel->handle($this->request('GET', '/articles/42'));

        self::assertSame(ExecutionKind::HttpRequest, $outcome->kind());
        self::assertTrue($outcome->primarySucceeded());
        self::assertSame($response, $outcome->primaryResult());
        self::assertTrue($outcome->isReusable());
        self::assertSame([], $emitter->responses);

        $resolved = $resolver->resolve($outcome);

        self::assertSame($response, $resolved);
        self::assertSame([], $emitter->responses);

        $emitter->emit($resolved);

        self::assertSame([$response], $emitter->responses);
        self::assertSame(['pre before', 'post before', 'route', 'post after', 'pre after'], $events->all());
        self::assertCount(1, $pre->contexts);
        self::assertCount(1, $post->contexts);
        self::assertSame($pre->contexts[0], $post->contexts[0]);
        self::assertSame($pre->scopes[0], $post->scopes[0]);
        self::assertInstanceOf(ExecutionContext::class, $handler->firstRequest()->getAttribute(ExecutionContext::class));
        self::assertInstanceOf(ExecutionScope::class, $handler->firstRequest()->getAttribute(ExecutionScope::class));
        self::assertSame($pre->contexts[0], $handler->firstRequest()->getAttribute(ExecutionContext::class));
        self::assertSame($pre->scopes[0], $handler->firstRequest()->getAttribute(ExecutionScope::class));
        self::assertInstanceOf(RouteMatch::class, $handler->firstRequest()->getAttribute(RouteMatch::class));
        self::assertSame(['id' => '42'], $handler->firstRequest()->getAttribute(RouteMatch::class)->parameters());
        self::assertTrue($outcome->isReusable());
    }

    public function test_cleanup_reset_completes_before_outer_emission_boundary(): void
    {
        $events = new IntegrationEventLog();
        $response = new IntegrationResponse(204);
        $kernel = $this->kernel([
            new Route(['GET'], '/cleanup', new ResetRegisteringHandler($response, $events)),
        ]);
        $emitter = new RecordingResponseEmitter();

        $outcome = $kernel->handle($this->request('GET', '/cleanup'));

        self::assertSame(['handler', 'reset'], $events->all());
        self::assertTrue($outcome->primarySucceeded());
        self::assertFalse($outcome->cleanupFailed());
        self::assertSame([], $emitter->responses);

        $emitter->emit((new ExecutionOutcomeResponseResolver(new RecordingResponseFactory()))->resolve($outcome));

        self::assertSame([$response], $emitter->responses);
        self::assertSame(['handler', 'reset'], $events->all());
    }

    public function test_routing_failures_resolve_to_empty_responses_and_are_explicitly_emitted(): void
    {
        $resolver = new ExecutionOutcomeResponseResolver(new RecordingResponseFactory());
        $emitter = new RecordingResponseEmitter();

        $notFound = $this->kernel([
            new Route(['GET'], '/known', new RecordingHandler(new IntegrationResponse(200))),
        ])->handle($this->request('GET', '/missing'));
        $notFoundResponse = $resolver->resolve($notFound);

        $emitter->emit($notFoundResponse);

        self::assertTrue($notFound->primaryFailed());
        self::assertSame(404, $notFoundResponse->getStatusCode());
        self::assertSame('', (string) $notFoundResponse->getBody());
        self::assertSame($notFoundResponse, $emitter->responses[0]);

        $methodNotAllowed = $this->kernel([
            new Route(['post', 'GET'], '/known', new RecordingHandler(new IntegrationResponse(200))),
        ])->handle($this->request('PATCH', '/known'));
        $methodNotAllowedResponse = $resolver->resolve($methodNotAllowed);

        $emitter->emit($methodNotAllowedResponse);

        self::assertTrue($methodNotAllowed->primaryFailed());
        self::assertSame(405, $methodNotAllowedResponse->getStatusCode());
        self::assertSame('', (string) $methodNotAllowedResponse->getBody());
        self::assertSame('post, GET', $methodNotAllowedResponse->getHeaderLine('Allow'));
        self::assertStringNotContainsString('HEAD', $methodNotAllowedResponse->getHeaderLine('Allow'));
        self::assertStringNotContainsString('OPTIONS', $methodNotAllowedResponse->getHeaderLine('Allow'));
        self::assertSame($methodNotAllowedResponse, $emitter->responses[1]);
    }

    public function test_generic_route_failure_resolves_to_empty_500_without_detail_exposure(): void
    {
        $throwable = new RuntimeException('secret failure details');
        $outcome = $this->kernel([
            new Route(['GET'], '/fail', new ThrowingHandler($throwable)),
        ])->handle($this->request('GET', '/fail'));
        $response = (new ExecutionOutcomeResponseResolver(new RecordingResponseFactory()))->resolve($outcome);
        $emitter = new RecordingResponseEmitter();

        $emitter->emit($response);

        self::assertTrue($outcome->primaryFailed());
        self::assertSame($throwable, $outcome->primaryThrowable());
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertStringNotContainsString('secret', (string) $response->getBody());
        self::assertSame([$response], $emitter->responses);
    }

    public function test_explicit_health_routes_flow_through_phase_4_stack_without_magic_routes(): void
    {
        $factory = new RecordingResponseFactory();
        $kernel = $this->kernel([
            new Route(['GET'], '/live', new LivenessHandler($factory)),
            new Route(['GET'], '/ready', new ReadinessHandler($factory, [new FixedReadinessCheck(false)])),
        ]);
        $resolver = new ExecutionOutcomeResponseResolver($factory);
        $emitter = new RecordingResponseEmitter();

        $live = $resolver->resolve($kernel->handle($this->request('GET', '/live')));
        $ready = $resolver->resolve($kernel->handle($this->request('GET', '/ready')));
        $unregistered = $resolver->resolve($kernel->handle($this->request('GET', '/health')));

        $emitter->emit($live);
        $emitter->emit($ready);
        $emitter->emit($unregistered);

        self::assertSame(200, $live->getStatusCode());
        self::assertSame(503, $ready->getStatusCode());
        self::assertSame(404, $unregistered->getStatusCode());
        self::assertSame([$live, $ready, $unregistered], $emitter->responses);
    }

    public function test_quarantine_policy_remains_separate_from_response_resolution_and_emission(): void
    {
        $events = new IntegrationEventLog();
        $response = new IntegrationResponse(200);
        $outcome = $this->kernel([
            new Route(['GET'], '/quarantine', new ResetRegisteringHandler(
                $response,
                $events,
                new RuntimeException('reset failed'),
            )),
        ])->handle($this->request('GET', '/quarantine'));
        $emitter = new RecordingResponseEmitter();

        self::assertSame(['handler', 'reset'], $events->all());
        self::assertTrue($outcome->primarySucceeded());
        self::assertSame($response, $outcome->primaryResult());
        self::assertTrue($outcome->cleanupFailed());
        self::assertTrue($outcome->requiresQuarantine());
        self::assertFalse($outcome->isReusable());

        $resolved = (new ExecutionOutcomeResponseResolver(new RecordingResponseFactory()))->resolve($outcome);

        self::assertSame($response, $resolved);
        self::assertSame([], $emitter->responses);
        self::assertTrue($outcome->requiresQuarantine());
    }

    public function test_execution_start_failed_stays_outside_response_resolution_and_emission(): void
    {
        $orchestrator = $this->orchestrator();
        $kernel = $this->kernel(
            [
                new Route(['GET'], '/one', new ResetRegisteringHandler(
                    new IntegrationResponse(200),
                    new IntegrationEventLog(),
                    new RuntimeException('reset failed'),
                )),
                new Route(['GET'], '/two', new RecordingHandler(new IntegrationResponse(200))),
            ],
            [],
            [],
            $orchestrator,
        );
        $emitter = new RecordingResponseEmitter();

        self::assertTrue($kernel->handle($this->request('GET', '/one'))->requiresQuarantine());

        $this->expectException(ExecutionStartFailed::class);

        try {
            $kernel->handle($this->request('GET', '/two'));
        } finally {
            self::assertSame([], $emitter->responses);
        }
    }

    public function test_sequential_clean_executions_keep_execution_identity_and_state_isolated(): void
    {
        $handler = new SequentialHandler([
            new IntegrationResponse(201),
            new IntegrationResponse(202),
        ]);
        $kernel = $this->kernel([
            new Route(['GET'], '/same', $handler),
        ]);

        $first = $kernel->handle($this->request('GET', '/same')->withAttribute('name', 'first'));
        $second = $kernel->handle($this->request('GET', '/same')->withAttribute('name', 'second'));

        $firstRequest = $handler->requests[0];
        $secondRequest = $handler->requests[1];

        self::assertNotSame($first->identifier()->value(), $second->identifier()->value());
        self::assertNotSame(
            $firstRequest->getAttribute(ExecutionContext::class),
            $secondRequest->getAttribute(ExecutionContext::class),
        );
        self::assertNotSame(
            $firstRequest->getAttribute(ExecutionScope::class),
            $secondRequest->getAttribute(ExecutionScope::class),
        );
        self::assertSame('first', $firstRequest->getAttribute('name'));
        self::assertSame('second', $secondRequest->getAttribute('name'));
        self::assertSame('missing', $secondRequest->getAttribute('first-only', 'missing'));
        self::assertTrue($first->isReusable());
        self::assertTrue($second->isReusable());
    }

    /**
     * @param iterable<Route> $routes
     * @param iterable<MiddlewareInterface> $postMatchMiddleware
     * @param iterable<MiddlewareInterface> $preRoutingMiddleware
     */
    private function kernel(
        iterable $routes,
        iterable $postMatchMiddleware = [],
        iterable $preRoutingMiddleware = [],
        ?ExecutionOrchestrator $orchestrator = null,
    ): HttpKernel {
        $routing = new RoutingRequestHandler(
            new RouteMatcher(new RouteCollection($routes)),
            $postMatchMiddleware,
        );
        $pipeline = new MiddlewarePipeline($preRoutingMiddleware, $routing);

        return new HttpKernel($pipeline, $orchestrator ?? $this->orchestrator());
    }

    private function orchestrator(): ExecutionOrchestrator
    {
        $registry = new ServiceRegistry();
        $registry->freeze();

        return new ExecutionOrchestrator($registry);
    }

    private function request(string $method, string $path): IntegrationServerRequest
    {
        return new IntegrationServerRequest($method, new IntegrationUri($path));
    }
}

final class RecordingResponseEmitter implements ResponseEmitter
{
    /**
     * @var list<ResponseInterface>
     */
    public array $responses = [];

    public function emit(ResponseInterface $response): void
    {
        $this->responses[] = $response;
    }
}

final class RecordingHandler implements RequestHandlerInterface
{
    /**
     * @var list<ServerRequestInterface>
     */
    public array $requests = [];

    public function __construct(
        public readonly ResponseInterface $response,
        private ?IntegrationEventLog $events = null,
        private readonly string $event = 'handler',
    ) {
        $this->events ??= new IntegrationEventLog();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->events->add($this->event);

        return $this->response;
    }

    public function firstRequest(): ServerRequestInterface
    {
        if (! isset($this->requests[0])) {
            throw new RuntimeException('No request was recorded.');
        }

        return $this->requests[0];
    }
}

final class SequentialHandler implements RequestHandlerInterface
{
    /**
     * @var list<ServerRequestInterface>
     */
    public array $requests = [];

    /**
     * @param list<ResponseInterface> $responses
     */
    public function __construct(private array $responses) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);

        if (! $response instanceof ResponseInterface) {
            throw new RuntimeException('No response is available for this request.');
        }

        return $response;
    }
}

final readonly class ThrowingHandler implements RequestHandlerInterface
{
    public function __construct(private Throwable $throwable) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw $this->throwable;
    }
}

final class ResetRegisteringHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ResponseInterface $response,
        private readonly IntegrationEventLog $events,
        private readonly ?Throwable $resetFailure = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->events->add('handler');
        $scope = $request->getAttribute(ExecutionScope::class);

        if (! $scope instanceof ExecutionScope) {
            throw new RuntimeException('Execution scope was not attached to the request.');
        }

        $scope->registerResetParticipant(
            'phase-4-integration-reset',
            new RecordingResetParticipant($this->events, $this->resetFailure),
        );

        return $this->response;
    }
}

final readonly class RecordingResetParticipant implements ResetParticipant
{
    public function __construct(
        private IntegrationEventLog $events,
        private ?Throwable $throwable = null,
    ) {}

    public function reset(): void
    {
        $this->events->add('reset');

        if ($this->throwable !== null) {
            throw $this->throwable;
        }
    }
}

final class AttributeRecordingMiddleware implements MiddlewareInterface
{
    /**
     * @var list<ExecutionContext>
     */
    public array $contexts = [];

    /**
     * @var list<ExecutionScope>
     */
    public array $scopes = [];

    public function __construct(
        private readonly string $name,
        private readonly IntegrationEventLog $events,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->events->add($this->name . ' before');
        $context = $request->getAttribute(ExecutionContext::class);
        $scope = $request->getAttribute(ExecutionScope::class);

        if (! $context instanceof ExecutionContext || ! $scope instanceof ExecutionScope) {
            throw new RuntimeException('Execution attributes were not attached before middleware.');
        }

        $this->contexts[] = $context;
        $this->scopes[] = $scope;
        $response = $handler->handle($request);
        $this->events->add($this->name . ' after');

        return $response;
    }
}

final class IntegrationEventLog
{
    /**
     * @var list<string>
     */
    private array $events = [];

    public function add(string $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->events;
    }
}

final readonly class FixedReadinessCheck implements ReadinessCheck
{
    public function __construct(private bool $ready) {}

    public function isReady(): bool
    {
        return $this->ready;
    }
}

final class RecordingResponseFactory implements ResponseFactoryInterface
{
    /**
     * @var list<int>
     */
    public array $createdStatuses = [];

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        $this->createdStatuses[] = $code;

        return new IntegrationResponse($code, $reasonPhrase);
    }
}

final readonly class IntegrationResponse implements ResponseInterface
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        private int $statusCode,
        private string $reasonPhrase = '',
        private array $headers = [],
    ) {}

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->normalizedHeaders());
    }

    public function getHeader(string $name): array
    {
        return $this->normalizedHeaders()[strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        $headers = $this->headers;
        $headers[$name] = is_array($value) ? array_values($value) : [$value];

        return new self($this->statusCode, $this->reasonPhrase, $headers);
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $headers = $this->headers;
        $headers[$name] = array_merge($headers[$name] ?? [], is_array($value) ? array_values($value) : [$value]);

        return new self($this->statusCode, $this->reasonPhrase, $headers);
    }

    public function withoutHeader(string $name): MessageInterface
    {
        $headers = $this->headers;
        $normalizedName = strtolower($name);

        foreach (array_keys($headers) as $headerName) {
            if (strtolower($headerName) === $normalizedName) {
                unset($headers[$headerName]);
            }
        }

        return new self($this->statusCode, $this->reasonPhrase, $headers);
    }

    public function getBody(): StreamInterface
    {
        return new EmptyIntegrationStream();
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        return new self($code, $reasonPhrase, $this->headers);
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /**
     * @return array<string, list<string>>
     */
    private function normalizedHeaders(): array
    {
        $headers = [];

        foreach ($this->headers as $name => $values) {
            $headers[strtolower($name)] = array_map('strval', $values);
        }

        return $headers;
    }
}

final readonly class IntegrationUri implements UriInterface
{
    public function __construct(private string $path) {}

    public function getScheme(): string
    {
        return '';
    }

    public function getAuthority(): string
    {
        return '';
    }

    public function getUserInfo(): string
    {
        return '';
    }

    public function getHost(): string
    {
        return '';
    }

    public function getPort(): ?int
    {
        return null;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return '';
    }

    public function getFragment(): string
    {
        return '';
    }

    public function withScheme(string $scheme): UriInterface
    {
        return $this;
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        return $this;
    }

    public function withHost(string $host): UriInterface
    {
        return $this;
    }

    public function withPort(?int $port): UriInterface
    {
        return $this;
    }

    public function withPath(string $path): UriInterface
    {
        return new self($path);
    }

    public function withQuery(string $query): UriInterface
    {
        return $this;
    }

    public function withFragment(string $fragment): UriInterface
    {
        return $this;
    }

    public function __toString(): string
    {
        return $this->path;
    }
}

final readonly class IntegrationServerRequest implements ServerRequestInterface
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private string $method,
        private UriInterface $uri,
        private array $attributes = [],
    ) {}

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        return $this;
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function hasHeader(string $name): bool
    {
        return false;
    }

    public function getHeader(string $name): array
    {
        return [];
    }

    public function getHeaderLine(string $name): string
    {
        return '';
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        return $this;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        return $this;
    }

    public function withoutHeader(string $name): MessageInterface
    {
        return $this;
    }

    public function getBody(): StreamInterface
    {
        throw new BadMethodCallException('Test request body is not implemented.');
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        return $this;
    }

    public function getRequestTarget(): string
    {
        return $this->uri->getPath();
    }

    public function withRequestTarget(string $requestTarget): ServerRequestInterface
    {
        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): ServerRequestInterface
    {
        return new self($method, $this->uri, $this->attributes);
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): ServerRequestInterface
    {
        return new self($this->method, $uri, $this->attributes);
    }

    /**
     * @return array<string, mixed>
     */
    public function getServerParams(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function getCookieParams(): array
    {
        return [];
    }

    /**
     * @param array<string, string> $cookies
     */
    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $query
     */
    public function withQueryParams(array $query): ServerRequestInterface
    {
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUploadedFiles(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $uploadedFiles
     */
    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        return $this;
    }

    /**
     * @return array<mixed, mixed>|object|null
     */
    public function getParsedBody()
    {
        return null;
    }

    /**
     * @param array<mixed, mixed>|object|null $data
     */
    public function withParsedBody($data): ServerRequestInterface
    {
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        $attributes = $this->attributes;
        $attributes[$name] = $value;

        return new self($this->method, $this->uri, $attributes);
    }

    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $attributes = $this->attributes;
        unset($attributes[$name]);

        return new self($this->method, $this->uri, $attributes);
    }
}

final class EmptyIntegrationStream implements StreamInterface
{
    public function __toString(): string
    {
        return '';
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): int
    {
        return 0;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void {}

    public function rewind(): void {}

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new BadMethodCallException('Test stream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        return '';
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata(?string $key = null): mixed
    {
        return null;
    }
}
