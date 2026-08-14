<?php

declare(strict_types=1);

namespace Evolve\Http\Tests\Unit;

use BadMethodCallException;
use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionResetFailed;
use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Core\Execution\ProcessReuseDecision;
use Evolve\Core\Instrumentation\InstrumentationFailure;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationSink;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Http\Exception\MethodNotAllowed;
use Evolve\Http\Exception\RouteNotFound;
use Evolve\Http\HttpKernel;
use Evolve\Http\Middleware\MiddlewarePipeline;
use Evolve\Http\Routing\Route;
use Evolve\Http\Routing\RouteCollection;
use Evolve\Http\Routing\RouteMatch;
use Evolve\Http\Routing\RouteMatcher;
use Evolve\Http\Routing\RoutingRequestHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use RuntimeException;
use Throwable;
use WeakReference;

final class HttpKernelTest extends TestCase
{
    public function test_public_api_is_final_readonly_and_returns_execution_outcome_not_psr_15(): void
    {
        $reflection = new ReflectionClass(HttpKernel::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertNotContains(RequestHandlerInterface::class, class_implements(HttpKernel::class));
        self::assertSame(
            'Evolve\Core\Execution\ExecutionOutcome',
            (string) $reflection->getMethod('handle')->getReturnType(),
        );
    }

    public function test_successful_response_is_primary_result_and_execution_attributes_are_authoritative(): void
    {
        $staleContext = new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::WorkerTask);
        $staleScope = new KernelNoopScope();
        $request = $this->request('GET', '/users')
            ->withAttribute(ExecutionContext::class, $staleContext)
            ->withAttribute(ExecutionScope::class, $staleScope)
            ->withAttribute('tenant', 'acme');
        $response = $this->response();
        $handler = new RecordingKernelHandler($response);
        $outcome = (new HttpKernel($handler, $this->orchestrator()))->handle($request);

        self::assertTrue($outcome->primarySucceeded());
        self::assertSame($response, $outcome->primaryResult());
        self::assertSame(ExecutionKind::HttpRequest, $outcome->kind());
        self::assertFalse($outcome->cleanupFailed());
        self::assertSame(ProcessReuseDecision::Reusable, $outcome->reuseDecision());

        $handledRequest = $handler->firstRequest();
        $context = $handledRequest->getAttribute(ExecutionContext::class);
        $scope = $handledRequest->getAttribute(ExecutionScope::class);

        self::assertInstanceOf(ExecutionContext::class, $context);
        self::assertInstanceOf(ExecutionScope::class, $scope);
        self::assertNotSame($staleContext, $context);
        self::assertNotSame($staleScope, $scope);
        self::assertTrue($context->identifier()->equals($outcome->identifier()));
        self::assertSame(ExecutionKind::HttpRequest, $context->kind());
        self::assertSame('acme', $handledRequest->getAttribute('tenant'));
        self::assertSame($staleContext, $request->getAttribute(ExecutionContext::class));
        self::assertSame($staleScope, $request->getAttribute(ExecutionScope::class));
    }

    public function test_clean_sequential_requests_get_unique_contexts_and_scopes_without_leaking_attributes(): void
    {
        $handler = new RecordingKernelHandler($this->response());
        $kernel = new HttpKernel($handler, $this->orchestrator());

        $first = $kernel->handle($this->request('GET', '/first')->withAttribute('request-name', 'first'));
        $second = $kernel->handle($this->request('GET', '/second')->withAttribute('request-name', 'second'));

        $firstRequest = $handler->requests[0];
        $secondRequest = $handler->requests[1];
        $firstContext = $firstRequest->getAttribute(ExecutionContext::class);
        $secondContext = $secondRequest->getAttribute(ExecutionContext::class);
        $firstScope = $firstRequest->getAttribute(ExecutionScope::class);
        $secondScope = $secondRequest->getAttribute(ExecutionScope::class);

        self::assertNotSame($first->identifier()->value(), $second->identifier()->value());
        self::assertNotSame($firstContext, $secondContext);
        self::assertNotSame($firstScope, $secondScope);
        self::assertSame('first', $firstRequest->getAttribute('request-name'));
        self::assertSame('second', $secondRequest->getAttribute('request-name'));
        self::assertSame('missing', $secondRequest->getAttribute('first-only', 'missing'));
        self::assertTrue($first->isReusable());
        self::assertTrue($second->isReusable());
    }

    public function test_arbitrary_handler_throwable_is_exact_primary_failure_and_remains_reusable(): void
    {
        $throwable = new RuntimeException('handler failed');
        $outcome = (new HttpKernel(new ThrowingKernelHandler($throwable), $this->orchestrator()))
            ->handle($this->request('GET', '/fail'));

        self::assertTrue($outcome->primaryFailed());
        self::assertSame($throwable, $outcome->primaryThrowable());
        self::assertFalse($outcome->cleanupFailed());
        self::assertTrue($outcome->isReusable());
    }

    public function test_routing_failures_remain_primary_throwables_without_http_error_response_creation(): void
    {
        $notFound = (new HttpKernel(
            new RoutingRequestHandler($this->matcher([])),
            $this->orchestrator(),
        ))->handle($this->request('GET', '/missing'));

        self::assertTrue($notFound->primaryFailed());
        self::assertInstanceOf(RouteNotFound::class, $notFound->primaryThrowable());

        $methodNotAllowed = (new HttpKernel(
            new RoutingRequestHandler($this->matcher([
                new Route(['POST'], '/users', new RecordingKernelHandler($this->response())),
            ])),
            $this->orchestrator(),
        ))->handle($this->request('GET', '/users'));

        self::assertTrue($methodNotAllowed->primaryFailed());
        self::assertInstanceOf(MethodNotAllowed::class, $methodNotAllowed->primaryThrowable());
    }

    public function test_existing_middleware_pipeline_and_routing_handler_run_inside_one_http_execution(): void
    {
        $log = new KernelEventLog();
        $response = $this->response();
        $routeHandler = new RecordingKernelHandler($response, $log, 'route handler');
        $pre = new ExecutionAttributeRecordingMiddleware('pre', $log);
        $post = new ExecutionAttributeRecordingMiddleware('post', $log);
        $routingHandler = new RoutingRequestHandler(
            $this->matcher([new Route(['GET'], '/users/{id}', $routeHandler)]),
            [$post],
        );
        $pipeline = new MiddlewarePipeline([$pre], $routingHandler);

        $outcome = (new HttpKernel($pipeline, $this->orchestrator()))->handle($this->request('GET', '/users/42'));

        self::assertSame($response, $outcome->primaryResult());
        self::assertSame(['pre before', 'post before', 'route handler', 'post after', 'pre after'], $log->all());
        self::assertCount(1, $pre->contexts);
        self::assertCount(1, $post->contexts);
        self::assertSame($pre->contexts[0], $post->contexts[0]);
        self::assertSame($pre->scopes[0], $post->scopes[0]);
        self::assertSame($pre->contexts[0], $routeHandler->firstRequest()->getAttribute(ExecutionContext::class));
        self::assertSame($pre->scopes[0], $routeHandler->firstRequest()->getAttribute(ExecutionScope::class));
        self::assertInstanceOf(RouteMatch::class, $routeHandler->firstRequest()->getAttribute(RouteMatch::class));
        self::assertSame(['id' => '42'], $routeHandler->firstRequest()->getAttribute(RouteMatch::class)->parameters());
    }

    public function test_reset_runs_after_success_and_preserves_response_when_cleanup_fails(): void
    {
        $log = new KernelEventLog();
        $resetFailure = new RuntimeException('reset failed');
        $response = $this->response();
        $handler = new ResetRegisteringHandler($response, $log, $resetFailure);

        $outcome = (new HttpKernel($handler, $this->orchestrator()))->handle($this->request('GET', '/cleanup'));

        self::assertSame(['handler', 'reset'], $log->all());
        self::assertTrue($outcome->primarySucceeded());
        self::assertSame($response, $outcome->primaryResult());
        self::assertTrue($outcome->cleanupFailed());
        self::assertInstanceOf(ExecutionResetFailed::class, $outcome->cleanupThrowable());
        self::assertSame([$resetFailure], $outcome->cleanupThrowable()->failures());
        self::assertTrue($outcome->requiresQuarantine());
        self::assertFalse($outcome->isReusable());
    }

    public function test_reset_still_runs_after_handler_failure_and_preserves_primary_throwable(): void
    {
        $log = new KernelEventLog();
        $handlerFailure = new RuntimeException('handler failed');
        $resetFailure = new RuntimeException('reset failed');
        $handler = new ResetRegisteringThrowingHandler($handlerFailure, $log, $resetFailure);

        $outcome = (new HttpKernel($handler, $this->orchestrator()))->handle($this->request('GET', '/cleanup-failure'));

        self::assertSame(['handler', 'reset'], $log->all());
        self::assertTrue($outcome->primaryFailed());
        self::assertSame($handlerFailure, $outcome->primaryThrowable());
        self::assertInstanceOf(ExecutionResetFailed::class, $outcome->cleanupThrowable());
        self::assertSame([$resetFailure], $outcome->cleanupThrowable()->failures());
        self::assertTrue($outcome->requiresQuarantine());
    }

    public function test_quarantined_orchestrator_refuses_later_request_before_handler_runs(): void
    {
        $resetFailure = new RuntimeException('reset failed');
        $handler = new ResetRegisteringHandler($this->response(), new KernelEventLog(), $resetFailure);
        $orchestrator = $this->orchestrator();
        $kernel = new HttpKernel($handler, $orchestrator);

        self::assertTrue($kernel->handle($this->request('GET', '/first'))->requiresQuarantine());

        $handler->response = $this->response();

        try {
            $kernel->handle($this->request('GET', '/second'));
            self::fail('A quarantined orchestrator must refuse the next HTTP request.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ExecutionStartFailed::class, $exception);
        }

        self::assertSame(1, $handler->calls);
    }

    public function test_core_observations_use_http_request_kind_and_outcome_identifier(): void
    {
        $sink = new CollectingKernelObservationSink();
        $outcome = (new HttpKernel(new RecordingKernelHandler($this->response()), $this->orchestrator($sink)))
            ->handle($this->request('GET', '/observed'));

        self::assertSame(
            [
                ObservationType::ExecutionStarted,
                ObservationType::HandlerCompleted,
                ObservationType::ScopeCloseStarted,
                ObservationType::ScopeCloseCompleted,
                ObservationType::ExecutionCompleted,
            ],
            $sink->types(),
        );

        foreach ($sink->observations() as $observation) {
            self::assertSame(ExecutionKind::HttpRequest, $observation->kind());
            self::assertTrue($observation->identifier()->equals($outcome->identifier()));
        }
    }

    public function test_instrumentation_failure_is_recorded_without_replacing_response_or_reuse_decision(): void
    {
        $response = $this->response();
        $sink = new ThrowingKernelObservationSink([ObservationType::ExecutionStarted]);
        $outcome = (new HttpKernel(new RecordingKernelHandler($response), $this->orchestrator($sink)))
            ->handle($this->request('GET', '/instrumentation'));

        self::assertTrue($outcome->primarySucceeded());
        self::assertSame($response, $outcome->primaryResult());
        self::assertFalse($outcome->cleanupFailed());
        self::assertTrue($outcome->isReusable());
        self::assertTrue($outcome->instrumentationFailed());
        self::assertSame([ObservationType::ExecutionStarted], array_map(
            static fn(InstrumentationFailure $failure): ObservationType => $failure->observationType(),
            $outcome->instrumentationFailures(),
        ));
    }

    public function test_kernel_does_not_retain_request_scope_response_or_outcome_after_handle_returns(): void
    {
        $weakService = null;
        $weakResponse = null;
        $registry = new ServiceRegistry();
        $registry->register('execution.object', ServiceLifetime::Execution, static function () use (&$weakService): object {
            $service = new \stdClass();
            $weakService = WeakReference::create($service);

            return $service;
        });
        $registry->freeze();
        $handler = new KernelResponseWeakReferenceHandler();
        $kernel = new HttpKernel($handler, new ExecutionOrchestrator($registry));
        $request = $this->request('GET', '/release');
        $outcome = $kernel->handle($request);
        $weakResponse = $handler->weakResponse;

        self::assertTrue($outcome->primarySucceeded());

        unset($request, $outcome);
        gc_collect_cycles();

        self::assertNull($weakService?->get());
        self::assertNull($weakResponse?->get());
    }

    private function orchestrator(?ObservationSink $sink = null): ExecutionOrchestrator
    {
        $registry = new ServiceRegistry();
        $registry->freeze();

        return new ExecutionOrchestrator($registry, $sink);
    }

    /**
     * @param iterable<Route> $routes
     */
    private function matcher(iterable $routes): RouteMatcher
    {
        return new RouteMatcher(new RouteCollection($routes));
    }

    private function request(string $method, string $path): KernelServerRequest
    {
        return new KernelServerRequest($method, new KernelUri($path));
    }

    private function response(): ResponseInterface
    {
        return $this->createStub(ResponseInterface::class);
    }
}

final class RecordingKernelHandler implements RequestHandlerInterface
{
    /**
     * @var list<ServerRequestInterface>
     */
    public array $requests = [];

    public function __construct(
        public ResponseInterface $response,
        private ?KernelEventLog $log = null,
        private readonly string $event = 'handler',
    ) {
        $this->log ??= new KernelEventLog();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->log->add($this->event);

        return $this->response;
    }

    public function firstRequest(): ServerRequestInterface
    {
        if (!isset($this->requests[0])) {
            throw new RuntimeException('No request was recorded.');
        }

        return $this->requests[0];
    }
}

final class ThrowingKernelHandler implements RequestHandlerInterface
{
    public function __construct(private readonly Throwable $throwable) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw $this->throwable;
    }
}

final class ResetRegisteringHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function __construct(
        public ResponseInterface $response,
        private readonly KernelEventLog $log,
        private readonly ?Throwable $resetFailure = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        ++$this->calls;
        $this->log->add('handler');
        $scope = $request->getAttribute(ExecutionScope::class);
        $scope->registerResetParticipant('kernel-test-reset', new KernelResetParticipant($this->log, $this->resetFailure));

        return $this->response;
    }
}

final class ResetRegisteringThrowingHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Throwable $handlerFailure,
        private readonly KernelEventLog $log,
        private readonly Throwable $resetFailure,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->log->add('handler');
        $scope = $request->getAttribute(ExecutionScope::class);
        $scope->registerResetParticipant('kernel-test-reset', new KernelResetParticipant($this->log, $this->resetFailure));

        throw $this->handlerFailure;
    }
}

final class KernelResetParticipant implements ResetParticipant
{
    public function __construct(
        private readonly KernelEventLog $log,
        private readonly ?Throwable $throwable = null,
    ) {}

    public function reset(): void
    {
        $this->log->add('reset');

        if ($this->throwable !== null) {
            throw $this->throwable;
        }
    }
}

final class KernelNoopScope implements ExecutionScope
{
    public function get(string $id): mixed
    {
        return null;
    }

    public function has(string $id): bool
    {
        return false;
    }

    public function registerResetParticipant(string $id, ResetParticipant $participant): void {}

    public function close(): void {}
}

final class KernelResponseWeakReferenceHandler implements RequestHandlerInterface
{
    /**
     * @var WeakReference<object>|null
     */
    public ?WeakReference $weakResponse = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $request->getAttribute(ExecutionScope::class)->get('execution.object');
        $response = new class implements ResponseInterface {
            use KernelResponseMethods;
        };
        $this->weakResponse = WeakReference::create($response);

        return $response;
    }
}

final class ExecutionAttributeRecordingMiddleware implements MiddlewareInterface
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
        private readonly KernelEventLog $log,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->log->add($this->name . ' before');
        $this->contexts[] = $request->getAttribute(ExecutionContext::class);
        $this->scopes[] = $request->getAttribute(ExecutionScope::class);
        $response = $handler->handle($request);
        $this->log->add($this->name . ' after');

        return $response;
    }
}

final class KernelEventLog
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

class CollectingKernelObservationSink implements ObservationSink
{
    /**
     * @var list<Observation>
     */
    protected array $observations = [];

    public function observe(Observation $observation): void
    {
        $this->observations[] = $observation;
    }

    /**
     * @return list<Observation>
     */
    public function observations(): array
    {
        return $this->observations;
    }

    /**
     * @return list<ObservationType>
     */
    public function types(): array
    {
        return array_map(static fn(Observation $observation): ObservationType => $observation->type(), $this->observations);
    }
}

final class ThrowingKernelObservationSink extends CollectingKernelObservationSink
{
    /**
     * @var array<string, true>
     */
    private array $failureTypes;

    /**
     * @param list<ObservationType> $failureTypes
     */
    public function __construct(array $failureTypes)
    {
        $this->failureTypes = array_fill_keys(
            array_map(static fn(ObservationType $type): string => $type->name, $failureTypes),
            true,
        );
    }

    public function observe(Observation $observation): void
    {
        parent::observe($observation);

        if (isset($this->failureTypes[$observation->type()->name])) {
            throw new RuntimeException('sink failed');
        }
    }
}

final readonly class KernelUri implements UriInterface
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

final readonly class KernelServerRequest implements ServerRequestInterface
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

trait KernelResponseMethods
{
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
        throw new BadMethodCallException('Test response body is not implemented.');
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        return $this;
    }

    public function getStatusCode(): int
    {
        return 200;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        return $this;
    }

    public function getReasonPhrase(): string
    {
        return 'OK';
    }
}
