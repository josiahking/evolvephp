<?php

declare(strict_types=1);

namespace Evolve\Http\Tests\Unit\Middleware;

use Evolve\Http\Middleware\MiddlewarePipeline;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

final class MiddlewarePipelineTest extends TestCase
{
    public function test_pipeline_is_a_psr_15_request_handler(): void
    {
        $pipeline = new MiddlewarePipeline([], $this->terminalHandler($this->response()));

        self::assertContains(RequestHandlerInterface::class, class_implements($pipeline));
    }

    public function test_empty_pipeline_invokes_terminal_handler_once_and_returns_its_response(): void
    {
        $request = $this->request();
        $response = $this->response();
        $terminal = $this->terminalHandler($response);

        $result = (new MiddlewarePipeline([], $terminal))->handle($request);

        self::assertSame($response, $result);
        self::assertSame([$request], $terminal->requests);
    }

    public function test_constructor_rejects_invalid_middleware_immediately(): void
    {
        $terminal = $this->terminalHandler($this->response());

        $this->expectException(InvalidArgumentException::class);

        new MiddlewarePipeline([new PassThroughMiddleware('valid'), new \stdClass()], $terminal);
    }

    public function test_constructor_copies_iterable_once_and_preserves_supplied_order(): void
    {
        $constructionLog = new EventLog();
        $executionLog = new EventLog();
        $response = $this->response();

        $middleware = function () use ($constructionLog, $executionLog): iterable {
            $constructionLog->add('yield A');
            yield new PassThroughMiddleware('A', $executionLog);

            $constructionLog->add('yield B');
            yield new PassThroughMiddleware('B', $executionLog);
        };

        $pipeline = new MiddlewarePipeline($middleware(), $this->terminalHandler($response, $executionLog));

        self::assertSame(['yield A', 'yield B'], $constructionLog->all());

        $constructionLog->add('after construction ' . spl_object_id($pipeline));
        $result = $pipeline->handle($this->request());

        self::assertSame($response, $result);
        self::assertCount(3, $constructionLog->all());
        self::assertSame(['A before', 'B before', 'terminal', 'B after', 'A after'], $executionLog->all());
    }

    public function test_middleware_execute_in_onion_order(): void
    {
        $log = new EventLog();
        $response = $this->response();
        $pipeline = new MiddlewarePipeline(
            [
                new PassThroughMiddleware('A', $log),
                new PassThroughMiddleware('B', $log),
            ],
            $this->terminalHandler($response, $log),
        );

        self::assertSame($response, $pipeline->handle($this->request()));
        self::assertSame(['A before', 'B before', 'terminal', 'B after', 'A after'], $log->all());
    }

    public function test_short_circuit_response_stops_remaining_middleware_and_terminal(): void
    {
        $log = new EventLog();
        $shortCircuitResponse = $this->response();
        $terminal = $this->terminalHandler($this->response(), $log);
        $pipeline = new MiddlewarePipeline(
            [
                new ShortCircuitMiddleware('A', $shortCircuitResponse, $log),
                new PassThroughMiddleware('B', $log),
            ],
            $terminal,
        );

        self::assertSame($shortCircuitResponse, $pipeline->handle($this->request()));
        self::assertSame(['A before'], $log->all());
        self::assertSame([], $terminal->requests);
    }

    public function test_replacement_request_reaches_downstream_middleware_and_terminal_handler(): void
    {
        $originalRequest = $this->request();
        $replacementRequest = $this->request();
        $response = $this->response();
        $terminal = $this->terminalHandler($response);
        $downstream = new RequestRecordingMiddleware();
        $pipeline = new MiddlewarePipeline(
            [
                new ReplacementRequestMiddleware($replacementRequest),
                $downstream,
            ],
            $terminal,
        );

        self::assertSame($response, $pipeline->handle($originalRequest));
        self::assertSame([$replacementRequest], $downstream->requests);
        self::assertSame([$replacementRequest], $terminal->requests);
    }

    public function test_middleware_throwable_propagates_unchanged(): void
    {
        $throwable = new RuntimeException('middleware failed');
        $terminal = $this->terminalHandler($this->response());
        $pipeline = new MiddlewarePipeline(
            [new ThrowingMiddleware($throwable)],
            $terminal,
        );

        try {
            $pipeline->handle($this->request());
            self::fail('Expected middleware throwable to propagate.');
        } catch (Throwable $caught) {
            self::assertSame($throwable, $caught);
            self::assertSame([], $terminal->requests);
        }
    }

    public function test_terminal_handler_throwable_propagates_unchanged(): void
    {
        $throwable = new RuntimeException('terminal failed');
        $pipeline = new MiddlewarePipeline(
            [new PassThroughMiddleware('A')],
            $this->throwingTerminalHandler($throwable),
        );

        try {
            $pipeline->handle($this->request());
            self::fail('Expected terminal throwable to propagate.');
        } catch (Throwable $caught) {
            self::assertSame($throwable, $caught);
        }
    }

    public function test_sequential_reuse_starts_from_first_middleware_for_every_invocation(): void
    {
        $log = new EventLog();
        $requestA = $this->request();
        $requestB = $this->request();
        $terminal = $this->terminalHandler($this->response(), $log);
        $pipeline = new MiddlewarePipeline(
            [new PassThroughMiddleware('A', $log), new PassThroughMiddleware('B', $log)],
            $terminal,
        );

        $pipeline->handle($requestA);
        $pipeline->handle($requestB);

        self::assertSame(
            [
                'A before',
                'B before',
                'terminal',
                'B after',
                'A after',
                'A before',
                'B before',
                'terminal',
                'B after',
                'A after',
            ],
            $log->all(),
        );
        self::assertSame([$requestA, $requestB], $terminal->requests);
    }

    public function test_request_state_does_not_alter_dispatch_position_for_next_request(): void
    {
        $log = new EventLog();
        $shortCircuitResponse = $this->response();
        $normalResponse = $this->response();
        $terminal = $this->terminalHandler($normalResponse, $log);
        $middleware = new FirstRequestOnlyShortCircuitMiddleware($shortCircuitResponse, $log);
        $pipeline = new MiddlewarePipeline([$middleware, new PassThroughMiddleware('B', $log)], $terminal);

        self::assertSame($shortCircuitResponse, $pipeline->handle($this->request()));
        self::assertSame($normalResponse, $pipeline->handle($this->request()));
        self::assertSame(['A before', 'A before', 'B before', 'terminal', 'B after', 'A after'], $log->all());
    }

    public function test_reentrant_invocation_of_same_pipeline_starts_from_first_middleware(): void
    {
        $log = new EventLog();
        $outerRequest = $this->request();
        $nestedRequest = $this->request();
        $response = $this->response();
        $terminal = $this->terminalHandlerWithRequestLabels(
            $response,
            [
                spl_object_id($outerRequest) => 'outer terminal',
                spl_object_id($nestedRequest) => 'nested terminal',
            ],
            $log,
        );
        $reentrant = new ReentrantMiddleware($nestedRequest, $log);
        $pipeline = new MiddlewarePipeline([$reentrant, new PassThroughMiddleware('B', $log)], $terminal);
        $reentrant->usePipeline($pipeline);

        self::assertSame($response, $pipeline->handle($outerRequest));
        self::assertSame(
            [
                'A before',
                'A before',
                'B before',
                'nested terminal',
                'B after',
                'A after',
                'B before',
                'outer terminal',
                'B after',
                'A after',
            ],
            $log->all(),
        );
    }

    public function test_normal_pipeline_invokes_terminal_once_per_downstream_call(): void
    {
        $terminal = $this->terminalHandler($this->response());
        $pipeline = new MiddlewarePipeline(
            [new PassThroughMiddleware('A'), new PassThroughMiddleware('B')],
            $terminal,
        );

        $pipeline->handle($this->request());

        self::assertCount(1, $terminal->requests);
    }

    private function request(): ServerRequestInterface
    {
        return $this->createStub(ServerRequestInterface::class);
    }

    private function response(): ResponseInterface
    {
        return $this->createStub(ResponseInterface::class);
    }

    private function terminalHandler(ResponseInterface $response, ?EventLog $log = null): RecordingTerminalHandler
    {
        return new RecordingTerminalHandler($response, $log);
    }

    /**
     * @param array<int, string> $labels
     */
    private function terminalHandlerWithRequestLabels(ResponseInterface $response, array $labels, EventLog $log): RecordingTerminalHandler
    {
        return new RecordingTerminalHandler($response, $log, $labels);
    }

    private function throwingTerminalHandler(Throwable $throwable): ThrowingTerminalHandler
    {
        return new ThrowingTerminalHandler($throwable);
    }
}

final class RecordingTerminalHandler implements RequestHandlerInterface
{
    /**
     * @var list<ServerRequestInterface>
     */
    public array $requests = [];

    /**
     * @param array<int, string> $labels
     */
    public function __construct(
        private readonly ResponseInterface $response,
        ?EventLog $log = null,
        private readonly array $labels = [],
    ) {
        $this->log = $log ?? new EventLog();
    }

    /**
     * @var EventLog
     */
    private EventLog $log;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->log->add($this->labels[spl_object_id($request)] ?? 'terminal');

        return $this->response;
    }
}

final class ThrowingTerminalHandler implements RequestHandlerInterface
{
    public function __construct(private readonly Throwable $throwable) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw $this->throwable;
    }
}

final class PassThroughMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $name,
        ?EventLog $log = null,
    ) {
        $this->log = $log ?? new EventLog();
    }

    /**
     * @var EventLog
     */
    private EventLog $log;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->log->add($this->name . ' before');
        $response = $handler->handle($request);
        $this->log->add($this->name . ' after');

        return $response;
    }
}

final class ShortCircuitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $name,
        private readonly ResponseInterface $response,
        private readonly EventLog $log,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->log->add($this->name . ' before');

        return $this->response;
    }
}

final class ReplacementRequestMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ServerRequestInterface $replacementRequest) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($this->replacementRequest);
    }
}

final class RequestRecordingMiddleware implements MiddlewareInterface
{
    /**
     * @var list<ServerRequestInterface>
     */
    public array $requests = [];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->requests[] = $request;

        return $handler->handle($request);
    }
}

final class ThrowingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Throwable $throwable) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        throw $this->throwable;
    }
}

final class FirstRequestOnlyShortCircuitMiddleware implements MiddlewareInterface
{
    private bool $used = false;

    public function __construct(
        private readonly ResponseInterface $response,
        private readonly EventLog $log,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->log->add('A before');

        if (!$this->used) {
            $this->used = true;

            return $this->response;
        }

        $response = $handler->handle($request);
        $this->log->add('A after');

        return $response;
    }
}

final class ReentrantMiddleware implements MiddlewareInterface
{
    private ?RequestHandlerInterface $pipeline = null;

    private bool $nestedInvocationStarted = false;

    public function __construct(
        private readonly ServerRequestInterface $nestedRequest,
        private readonly EventLog $log,
    ) {}

    public function usePipeline(RequestHandlerInterface $pipeline): void
    {
        $this->pipeline = $pipeline;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->log->add('A before');

        if (!$this->nestedInvocationStarted) {
            $this->nestedInvocationStarted = true;
            if ($this->pipeline === null) {
                throw new RuntimeException('Re-entrant test pipeline was not configured.');
            }

            $this->pipeline->handle($this->nestedRequest);
        }

        $response = $handler->handle($request);
        $this->log->add('A after');

        return $response;
    }
}

final class EventLog
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
