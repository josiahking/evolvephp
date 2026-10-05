<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Http;

use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\Capture\DiagnosticEntrySink;
use Evolve\Insight\Http\HttpServerDiagnosticMiddleware;
use Evolve\Insight\Http\HttpServerDiagnosticState;
use Evolve\Insight\Http\MatchedRouteDiagnosticMiddleware;
use Evolve\Insight\Infrastructure\ExecutionCorrelation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class HttpServerDiagnosticMiddlewareTest extends TestCase
{
    public function test_records_bounded_success_and_preserves_response_identity(): void
    {
        $entries = [];
        $sink = $this->sink($entries);
        $correlation = new ExecutionCorrelation();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));
        $request = $this->request('M-SEARCH');
        $response = $this->response(500);
        $calls = 0;
        $handler = $this->handler(static function () use (&$calls, $response): ResponseInterface {
            ++$calls;
            return $response;
        });
        $clock = static function (): int {
            static $now = 10;
            return $now += 5;
        };

        try {
            self::assertSame($response, (new HttpServerDiagnosticMiddleware($sink, $correlation, $clock))->process($request, $handler));
        } finally {
            $attachment->detach();
        }

        self::assertSame(1, $calls);
        self::assertCount(1, $entries);
        self::assertSame('evolve.http.server', $entries[0]->category());
        self::assertSame('request', $entries[0]->name());
        self::assertSame(['method' => 'M-SEARCH', 'status_code' => 500, 'outcome' => 'success', 'duration_ns' => 5], $this->values($entries[0]));
    }

    public function test_failure_preserves_throwable_and_does_not_capture_message(): void
    {
        $entries = [];
        $correlation = new ExecutionCorrelation();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));
        $failure = new RuntimeException('private throwable message');
        $calls = 0;
        $handler = $this->handler(static function () use (&$calls, $failure): never {
            ++$calls;
            throw $failure;
        });

        try {
            (new HttpServerDiagnosticMiddleware($this->sink($entries), $correlation))->process($this->request('GET'), $handler);
            self::fail('Expected primary throwable.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        } finally {
            $attachment->detach();
        }

        self::assertSame(1, $calls);
        self::assertCount(1, $entries);
        self::assertSame('failure', $this->values($entries[0])['outcome']);
        self::assertSame(RuntimeException::class, $this->values($entries[0])['error_type']);
        self::assertStringNotContainsString('private throwable message', json_encode($this->values($entries[0]), JSON_THROW_ON_ERROR));
    }

    public function test_without_execution_delegates_once_and_captures_nothing(): void
    {
        $entries = [];
        $response = $this->response(200);
        $calls = 0;
        $handler = $this->handler(static function () use (&$calls, $response): ResponseInterface {
            ++$calls;
            return $response;
        });
        self::assertSame($response, (new HttpServerDiagnosticMiddleware($this->sink($entries), new ExecutionCorrelation()))->process($this->request('GET'), $handler));
        self::assertSame(1, $calls);
        self::assertSame([], $entries);
    }

    public function test_duplicate_outer_installation_captures_once(): void
    {
        $entries = [];
        $correlation = new ExecutionCorrelation();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));
        $outer = new HttpServerDiagnosticMiddleware($this->sink($entries), $correlation);
        $inner = new HttpServerDiagnosticMiddleware($this->sink($entries), $correlation);
        $response = $this->response(200);
        $calls = 0;
        $terminal = $this->handler(static function () use (&$calls, $response): ResponseInterface {
            ++$calls;
            return $response;
        });
        $nested = $this->handler(static fn(ServerRequestInterface $request): ResponseInterface => $inner->process($request, $terminal));
        try {
            self::assertSame($response, $outer->process($this->request('GET'), $nested));
        } finally {
            $attachment->detach();
        }
        self::assertSame(1, $calls);
        self::assertCount(1, $entries);
    }

    public function test_clock_and_sink_failures_do_not_change_primary_result(): void
    {
        $correlation = new ExecutionCorrelation();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));
        $sink = $this->createStub(DiagnosticEntrySink::class);
        $sink->method('capture')->willThrowException(new RuntimeException('sink failed'));
        $response = $this->response(200);
        $calls = 0;
        $handler = $this->handler(static function () use (&$calls, $response): ResponseInterface {
            ++$calls;
            return $response;
        });
        try {
            self::assertSame($response, (new HttpServerDiagnosticMiddleware($sink, $correlation, static fn(): never => throw new RuntimeException('clock failed')))->process($this->request('GET'), $handler));
        } finally {
            $attachment->detach();
        }
        self::assertSame(1, $calls);
    }

    public function test_state_attachment_failure_delegates_original_request_once(): void
    {
        $entries = [];
        $correlation = new ExecutionCorrelation();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));
        $request = $this->request('GET');
        $request->method('withAttribute')->willThrowException(new RuntimeException('attribute failure'));
        $response = $this->response(200);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::identicalTo($request))->willReturn($response);
        try {
            self::assertSame($response, (new HttpServerDiagnosticMiddleware($this->sink($entries), $correlation))->process($request, $handler));
        } finally {
            $attachment->detach();
        }
        self::assertSame([], $entries);
    }

    public function test_matched_route_enrichment_ignores_unrelated_attributes_and_propagates_handler_failure(): void
    {
        $request = $this->request('GET');
        $request->expects(self::exactly(2))->method('getAttribute')->willReturnMap([
            [HttpServerDiagnosticState::class, null, null],
            [\Evolve\Http\Routing\RouteMatch::class, null, null],
        ]);
        $failure = new RuntimeException('primary');
        $handler = $this->handler(static fn(): never => throw $failure);
        try {
            (new MatchedRouteDiagnosticMiddleware())->process($request, $handler);
            self::fail('Expected primary throwable.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function test_stale_state_is_replaced_for_a_new_execution(): void
    {
        $entries = [];
        $correlation = new ExecutionCorrelation();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));
        $request = $this->request('GET')->withAttribute(HttpServerDiagnosticState::class, new HttpServerDiagnosticState('old-execution'));
        $response = $this->response(204);
        $handler = $this->handler(static fn(): ResponseInterface => $response);
        try {
            self::assertSame($response, (new HttpServerDiagnosticMiddleware($this->sink($entries), $correlation))->process($request, $handler));
            self::assertCount(1, $entries);
            self::assertSame($correlation->identifier(), $entries[0]->executionIdentifier());
        } finally {
            $attachment->detach();
        }
    }

    public function test_invalid_method_and_status_are_omitted_without_changing_success(): void
    {
        $entries = [];
        $correlation = new ExecutionCorrelation();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));
        $response = $this->response(700);
        try {
            self::assertSame($response, (new HttpServerDiagnosticMiddleware($this->sink($entries), $correlation))->process(
                $this->request(str_repeat('X', 33)),
                $this->handler(static fn(): ResponseInterface => $response),
            ));
        } finally {
            $attachment->detach();
        }
        $values = $this->values($entries[0]);
        self::assertArrayNotHasKey('method', $values);
        self::assertArrayNotHasKey('status_code', $values);
        self::assertSame('success', $values['outcome']);
    }

    public function test_route_enrichment_keeps_only_bounded_declared_template(): void
    {
        $state = new HttpServerDiagnosticState('execution');
        $response = $this->response(200);
        $handler = $this->handler(static fn(): ResponseInterface => $response);
        $route = new \Evolve\Http\Routing\Route(['GET'], '/users/{id}', $handler);
        $request = $this->request('GET')
            ->withAttribute(HttpServerDiagnosticState::class, $state)
            ->withAttribute(\Evolve\Http\Routing\RouteMatch::class, new \Evolve\Http\Routing\RouteMatch($route, ['id' => 'private-83927']));
        self::assertSame($response, (new MatchedRouteDiagnosticMiddleware())->process($request, $handler));
        self::assertSame('/users/{id}', $state->routeTemplate());
        self::assertStringNotContainsString('83927', (string) $state->routeTemplate());
    }

    public function test_oversized_route_template_is_omitted_without_changing_response_or_handler_count(): void
    {
        $state = new HttpServerDiagnosticState('execution');
        $response = $this->response(200);
        $calls = 0;
        $handler = $this->handler(static function () use (&$calls, $response): ResponseInterface {
            ++$calls;
            return $response;
        });
        $template = '/' . str_repeat('a', 2048);
        $route = new \Evolve\Http\Routing\Route(['GET'], $template, $handler);
        $request = $this->request('GET')
            ->withAttribute(HttpServerDiagnosticState::class, $state)
            ->withAttribute(\Evolve\Http\Routing\RouteMatch::class, new \Evolve\Http\Routing\RouteMatch($route, []));

        self::assertSame($response, (new MatchedRouteDiagnosticMiddleware())->process($request, $handler));
        self::assertSame(1, $calls);
        self::assertNull($state->routeTemplate());
        self::assertStringNotContainsString($template, json_encode($state, JSON_THROW_ON_ERROR));
    }
    public function test_route_metadata_failure_still_delegates_once(): void
    {
        $request = $this->request('GET');
        $request->method('getAttribute')->willThrowException(new RuntimeException('diagnostic metadata failure'));
        $response = $this->response(200);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::identicalTo($request))->willReturn($response);
        self::assertSame($response, (new MatchedRouteDiagnosticMiddleware())->process($request, $handler));
    }
    /** @param list<DiagnosticEntry> $entries */
    private function sink(array &$entries): DiagnosticEntrySink
    {
        $sink = $this->createStub(DiagnosticEntrySink::class);
        $sink->method('capture')->willReturnCallback(static function (DiagnosticEntry $entry) use (&$entries): void {
            $entries[] = $entry;
        });
        return $sink;
    }

    private function request(string $method): ServerRequestInterface&MockObject
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $attributes = new \ArrayObject();
        $request->method('getAttribute')->willReturnCallback(static function (string $name, mixed $default = null) use ($attributes): mixed {
            return $attributes[$name] ?? $default;
        });
        $request->method('withAttribute')->willReturnCallback(static function (string $name, mixed $value) use ($request, $attributes): ServerRequestInterface {
            $attributes[$name] = $value;
            return clone $request;
        });
        $request->method('getMethod')->willReturn($method);
        $request->expects(self::never())->method('getBody');
        $request->expects(self::never())->method('getHeaders');
        return $request;
    }

    private function response(int $status): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->expects(self::never())->method('getBody');
        $response->expects(self::never())->method('getHeaders');
        return $response;
    }

    /** @param callable(ServerRequestInterface): ResponseInterface $callback */
    private function handler(callable $callback): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback($callback);
        return $handler;
    }

    /** @return array<string, string|int|float|bool|null> */
    private function values(DiagnosticEntry $entry): array
    {
        $values = [];
        foreach ($entry->attributes() as $attribute) {
            $values[$attribute->name()] = $attribute->value();
        }
        return $values;
    }
}
