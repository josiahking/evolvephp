<?php

declare(strict_types=1);

namespace Evolve\Bridge\Psr\Tests\Unit;

use BadMethodCallException;
use Evolve\Bridge\Contracts\BridgeContext;
use Evolve\Bridge\Contracts\BridgeError;
use Evolve\Bridge\Contracts\BridgeErrorKind;
use Evolve\Bridge\Psr\EmbeddedBridgeAdapter;
use Evolve\Bridge\Psr\EmbeddedBridgeResult;
use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationSink;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Http\Health\ReadinessCheck;
use Evolve\Http\HttpKernel;
use Evolve\Http\Response\ExecutionOutcomeResponseResolver;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use RuntimeException;
use Throwable;
use WeakReference;

final class EmbeddedBridgeAdapterTest extends TestCase
{
    public function test_not_ready_prevents_kernel_execution_and_returns_safe_boot_error(): void
    {
        $handler = new BridgeRecordingHandler(new BridgeResponse(200));
        $adapter = $this->adapter($handler, ready: false);

        $result = $adapter->invoke($this->request('GET', '/delegated'), $this->context());

        self::assertNull($result->response());
        self::assertFalse($result->requiresQuarantine());
        self::assertTrue($result->isReusable());
        self::assertInstanceOf(BridgeError::class, $result->error());
        self::assertSame(BridgeErrorKind::BootOrReadiness, $result->error()->kind());
        self::assertSame('embedded_not_ready', $result->error()->code());
        self::assertSame('Embedded Evolve application is not ready for delegated HTTP execution.', $result->error()->message());
        self::assertFalse($result->error()->isRetryable());
        self::assertSame([], $handler->requests);
    }

    public function test_successful_delegation_attaches_exact_context_without_mutating_caller_request(): void
    {
        $context = $this->context();
        $response = new BridgeResponse(202);
        $handler = new BridgeRecordingHandler($response);
        $sink = new BridgeObservationSink();
        $request = $this->request('POST', '/delegated')
            ->withAttribute(BridgeContext::class, new BridgeContext('stale', 'stale'));
        $adapter = $this->adapter($handler, observationSink: $sink);

        $result = $adapter->invoke($request, $context);

        self::assertSame($response, $result->response());
        self::assertNull($result->error());
        self::assertTrue($result->isReusable());
        self::assertFalse($result->requiresQuarantine());
        self::assertCount(1, $handler->requests);
        self::assertCount(1, $sink->ofType(ObservationType::ExecutionStarted));
        self::assertSame(ExecutionKind::HttpRequest, $sink->ofType(ObservationType::ExecutionStarted)[0]->kind());

        $handledRequest = $handler->requests[0];

        self::assertNotSame($request, $handledRequest);
        self::assertSame($context, $handledRequest->getAttribute(BridgeContext::class));
        self::assertInstanceOf(ExecutionContext::class, $handledRequest->getAttribute(ExecutionContext::class));
        self::assertInstanceOf(ExecutionScope::class, $handledRequest->getAttribute(ExecutionScope::class));
        self::assertSame('stale', $request->getAttribute(BridgeContext::class)->requestIdentifier());
        self::assertSame('missing', $request->getAttribute(ExecutionContext::class, 'missing'));
        self::assertSame('missing', $request->getAttribute(ExecutionScope::class, 'missing'));
    }

    public function test_scope_reset_runs_after_success_and_after_handler_failure(): void
    {
        $successLog = new BridgeEventLog();
        $success = $this->adapter(new BridgeResettingHandler(new BridgeResponse(204), $successLog))
            ->invoke($this->request('GET', '/success'), $this->context());

        self::assertSame(204, $success->response()?->getStatusCode());
        self::assertSame(['handler', 'reset'], $successLog->all());
        self::assertTrue($success->isReusable());

        $failureLog = new BridgeEventLog();
        $failure = $this->adapter(new BridgeResettingThrowingHandler(new RuntimeException('handler failed'), $failureLog))
            ->invoke($this->request('GET', '/failure'), $this->context());

        self::assertSame(500, $failure->response()?->getStatusCode());
        self::assertSame(['handler', 'reset'], $failureLog->all());
        self::assertTrue($failure->isReusable());
        self::assertNull($failure->error());
    }

    public function test_cleanup_failure_preserves_primary_response_and_reports_safe_quarantine_error(): void
    {
        $log = new BridgeEventLog();
        $resetFailure = new RuntimeException('reset failure with details');
        $response = new BridgeResponse(209);
        $handler = new BridgeResettingHandler($response, $log, $resetFailure);
        $adapter = $this->adapter($handler);

        $result = $adapter->invoke($this->request('GET', '/cleanup-failure'), $this->context());

        self::assertSame($response, $result->response());
        self::assertSame(['handler', 'reset'], $log->all());
        self::assertFalse($result->isReusable());
        self::assertTrue($result->requiresQuarantine());
        self::assertInstanceOf(BridgeError::class, $result->error());
        self::assertSame(BridgeErrorKind::ResetOrQuarantine, $result->error()->kind());
        self::assertSame('embedded_process_quarantined', $result->error()->code());
        self::assertSame('Embedded Evolve execution completed, but cleanup did not prove process reuse safe.', $result->error()->message());
        self::assertFalse($result->error()->isRetryable());

        foreach ((new ReflectionClass($result))->getProperties() as $property) {
            self::assertNotSame($resetFailure, $property->getValue($result));
        }
    }

    public function test_result_constructor_accepts_only_the_three_valid_state_families(): void
    {
        $bootError = new BridgeError(
            BridgeErrorKind::BootOrReadiness,
            'embedded_not_ready',
            'Embedded Evolve application is not ready for delegated HTTP execution.',
            false,
        );
        $quarantineError = new BridgeError(
            BridgeErrorKind::ResetOrQuarantine,
            'embedded_process_quarantined',
            'Embedded Evolve execution completed, but cleanup did not prove process reuse safe.',
            false,
        );
        $response = new BridgeResponse(200);

        $preExecutionFailure = new EmbeddedBridgeResult(null, $bootError, false);
        self::assertNull($preExecutionFailure->response());
        self::assertSame($bootError, $preExecutionFailure->error());
        self::assertTrue($preExecutionFailure->isReusable());
        self::assertFalse($preExecutionFailure->requiresQuarantine());

        $completedReusable = new EmbeddedBridgeResult($response, null, false);
        self::assertSame($response, $completedReusable->response());
        self::assertNull($completedReusable->error());
        self::assertTrue($completedReusable->isReusable());
        self::assertFalse($completedReusable->requiresQuarantine());

        $completedQuarantined = new EmbeddedBridgeResult($response, $quarantineError, true);
        self::assertSame($response, $completedQuarantined->response());
        self::assertSame($quarantineError, $completedQuarantined->error());
        self::assertFalse($completedQuarantined->isReusable());
        self::assertTrue($completedQuarantined->requiresQuarantine());
    }

    public function test_result_constructor_rejects_impossible_public_states(): void
    {
        $response = new BridgeResponse(200);
        $bootError = new BridgeError(BridgeErrorKind::BootOrReadiness, 'embedded_not_ready', 'Not ready.', false);
        $quarantineError = new BridgeError(BridgeErrorKind::ResetOrQuarantine, 'embedded_process_quarantined', 'Quarantined.', false);

        $this->assertInvalidResultState(
            null,
            null,
            false,
            'Embedded bridge results require either a response or an error.',
        );
        $this->assertInvalidResultState(
            null,
            $bootError,
            true,
            'Embedded bridge quarantine requires a response.',
        );
        $this->assertInvalidResultState(
            $response,
            null,
            true,
            'Embedded bridge quarantine requires an error.',
        );
        $this->assertInvalidResultState(
            $response,
            $bootError,
            true,
            'Embedded bridge quarantine requires a reset or quarantine error.',
        );
        $this->assertInvalidResultState(
            null,
            $quarantineError,
            false,
            'Embedded bridge reset or quarantine errors require quarantine.',
        );
        $this->assertInvalidResultState(
            $response,
            $bootError,
            false,
            'Embedded bridge reusable responses must not include an error.',
        );
        $this->assertInvalidResultState(
            null,
            $quarantineError,
            true,
            'Embedded bridge quarantine requires a response.',
        );
    }

    public function test_quarantined_underlying_orchestrator_refuses_subsequent_work(): void
    {
        $handler = new BridgeResettingHandler(new BridgeResponse(200), new BridgeEventLog(), new RuntimeException('reset failed'));
        $adapter = $this->adapter($handler);

        self::assertTrue($adapter->invoke($this->request('GET', '/first'), $this->context())->requiresQuarantine());

        try {
            $adapter->invoke($this->request('GET', '/second'), $this->context());
            self::fail('The quarantined underlying orchestrator must refuse subsequent work.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ExecutionStartFailed::class, $exception);
        }

        self::assertSame(1, $handler->calls);
    }

    public function test_adapter_does_not_retain_completed_request(): void
    {
        $adapter = $this->adapter(new BridgeEphemeralResponseHandler());
        $request = $this->request('GET', '/release');
        $weakRequest = WeakReference::create($request);

        $result = $adapter->invoke($request, $this->context());

        self::assertInstanceOf(ResponseInterface::class, $result->response());

        unset($request, $result);
        gc_collect_cycles();

        self::assertNull($weakRequest->get());
    }

    public function test_public_api_and_package_source_do_not_own_response_emission_or_process_exit(): void
    {
        $adapterApi = new ReflectionClass(EmbeddedBridgeAdapter::class);
        $resultApi = new ReflectionClass(EmbeddedBridgeResult::class);

        self::assertTrue($adapterApi->isFinal());
        self::assertTrue($adapterApi->isReadOnly());
        self::assertTrue($resultApi->isFinal());
        self::assertTrue($resultApi->isReadOnly());
        self::assertStringContainsString('@experimental', (string) $adapterApi->getDocComment());
        self::assertStringContainsString('@experimental', (string) $resultApi->getDocComment());
        self::assertSame(
            [
                ServerRequestInterface::class,
                BridgeContext::class,
            ],
            array_map(
                static fn(\ReflectionParameter $parameter): string => (string) $parameter->getType(),
                $adapterApi->getMethod('invoke')->getParameters(),
            ),
        );
        self::assertSame(EmbeddedBridgeResult::class, (string) $adapterApi->getMethod('invoke')->getReturnType());

        foreach ($adapterApi->getConstructor()?->getParameters() ?? [] as $parameter) {
            self::assertNotSame('Evolve\Http\Response\ResponseEmitter', (string) $parameter->getType());
        }

        $source = '';
        foreach (glob(dirname(__DIR__, 2) . '/src/*.php') ?: [] as $path) {
            $source .= "\n" . file_get_contents($path);
        }

        foreach (['exit', 'die', 'header', 'echo', 'print'] as $forbiddenToken) {
            self::assertDoesNotMatchRegularExpression('/\b' . preg_quote($forbiddenToken, '/') . '\b\s*(?:\(|;)/i', $source);
        }
    }

    private function adapter(
        RequestHandlerInterface $handler,
        bool $ready = true,
        ?ObservationSink $observationSink = null,
    ): EmbeddedBridgeAdapter {
        $registry = new ServiceRegistry();
        $registry->freeze();

        return new EmbeddedBridgeAdapter(
            new HttpKernel($handler, new ExecutionOrchestrator($registry, $observationSink)),
            new ExecutionOutcomeResponseResolver(new BridgeResponseFactory()),
            new BridgeReadinessCheck($ready),
        );
    }

    private function request(string $method, string $path): BridgeServerRequest
    {
        return new BridgeServerRequest($method, new BridgeUri($path));
    }

    private function context(): BridgeContext
    {
        return new BridgeContext('request-1', 'correlation-1', 'principal-1', 'tenant-1');
    }

    private function assertInvalidResultState(
        ?ResponseInterface $response,
        ?BridgeError $error,
        bool $requiresQuarantine,
        string $message,
    ): void {
        try {
            new EmbeddedBridgeResult($response, $error, $requiresQuarantine);
            self::fail('Expected LogicException for invalid embedded bridge result state.');
        } catch (LogicException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}

final readonly class BridgeReadinessCheck implements ReadinessCheck
{
    public function __construct(private bool $ready) {}

    public function isReady(): bool
    {
        return $this->ready;
    }
}

final class BridgeObservationSink implements ObservationSink
{
    /**
     * @var list<Observation>
     */
    public array $observations = [];

    public function observe(Observation $observation): void
    {
        $this->observations[] = $observation;
    }

    /**
     * @return list<Observation>
     */
    public function ofType(ObservationType $type): array
    {
        return array_values(array_filter(
            $this->observations,
            static fn(Observation $observation): bool => $observation->type() === $type,
        ));
    }
}

final class BridgeRecordingHandler implements RequestHandlerInterface
{
    /**
     * @var list<ServerRequestInterface>
     */
    public array $requests = [];

    public function __construct(private readonly ResponseInterface $response) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return $this->response;
    }
}

final class BridgeResettingHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly ResponseInterface $response,
        private readonly BridgeEventLog $log,
        private readonly ?Throwable $resetFailure = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        ++$this->calls;
        $this->log->add('handler');
        $scope = $request->getAttribute(ExecutionScope::class);
        if (! $scope instanceof ExecutionScope) {
            throw new RuntimeException('Execution scope was not attached.');
        }

        $scope->registerResetParticipant('bridge-psr-test-reset', new BridgeResetParticipant($this->log, $this->resetFailure));

        return $this->response;
    }
}

final class BridgeResettingThrowingHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Throwable $throwable,
        private readonly BridgeEventLog $log,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->log->add('handler');
        $scope = $request->getAttribute(ExecutionScope::class);
        if (! $scope instanceof ExecutionScope) {
            throw new RuntimeException('Execution scope was not attached.');
        }

        $scope->registerResetParticipant('bridge-psr-test-reset', new BridgeResetParticipant($this->log));

        throw $this->throwable;
    }
}

final class BridgeEphemeralResponseHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new BridgeResponse(200);
    }
}

final class BridgeResetParticipant implements ResetParticipant
{
    public function __construct(
        private readonly BridgeEventLog $log,
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

final class BridgeEventLog
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

final class BridgeResponseFactory implements ResponseFactoryInterface
{
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new BridgeResponse($code, $reasonPhrase);
    }
}

final readonly class BridgeUri implements UriInterface
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

final readonly class BridgeServerRequest implements ServerRequestInterface
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

final readonly class BridgeResponse implements ResponseInterface
{
    public function __construct(
        private int $statusCode,
        private string $reasonPhrase = '',
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
        throw new BadMethodCallException('Test response body is not implemented.');
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
        return new self($code, $reasonPhrase);
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }
}
