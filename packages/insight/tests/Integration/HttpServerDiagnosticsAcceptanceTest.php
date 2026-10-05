<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Integration;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Http\Exception\MethodNotAllowed;
use Evolve\Http\Exception\RouteNotFound;
use Evolve\Http\HttpKernel;
use Evolve\Http\Middleware\MiddlewarePipeline;
use Evolve\Http\Routing\Route;
use Evolve\Http\Routing\RouteCollection;
use Evolve\Http\Routing\RouteMatcher;
use Evolve\Http\Routing\RoutingRequestHandler;
use Evolve\Insight\DiagnosticPipeline;
use Evolve\Insight\Http\HttpServerDiagnosticMiddleware;
use Evolve\Insight\Http\MatchedRouteDiagnosticMiddleware;
use Evolve\Insight\Infrastructure\ExecutionCorrelation;
use Evolve\Insight\Storage\InMemoryDiagnosticBatchStore;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HttpServerDiagnosticsAcceptanceTest extends TestCase
{
    public function test_matched_request_joins_the_core_execution_batch_without_exposing_route_values(): void
    {
        [$kernel, $store, $response] = $this->kernel();
        $outcome = $kernel->handle($this->request('GET', '/users/83927'));
        self::assertTrue($outcome->primarySucceeded());
        self::assertSame($response, $outcome->primaryResult());
        $snapshot = $store->find($outcome->identifier()->value());
        self::assertNotNull($snapshot);
        self::assertCount(1, $snapshot->diagnosticEntries());
        self::assertNotEmpty($snapshot->observations());
        $entry = $snapshot->diagnosticEntries()[0];
        self::assertSame('evolve.http.server', $entry->category());
        self::assertSame('request', $entry->name());
        $values = $this->values($entry);
        self::assertSame('GET', $values['method']);
        self::assertSame('/users/{id}', $values['route_template']);
        self::assertSame(201, $values['status_code']);
        self::assertSame('success', $values['outcome']);
        self::assertGreaterThanOrEqual(0, $values['duration_ns']);
        self::assertStringNotContainsString('83927', json_encode($values, JSON_THROW_ON_ERROR));
    }

    public function test_unmatched_and_wrong_method_failures_are_captured_before_routing_middleware(): void
    {
        [$kernel, $store] = $this->kernel();
        foreach ([
            ['GET', '/missing/private?token=secret', RouteNotFound::class],
            ['POST', '/users/83927', MethodNotAllowed::class],
        ] as [$method, $path, $errorType]) {
            $outcome = $kernel->handle($this->request($method, $path));
            self::assertFalse($outcome->primarySucceeded());
            self::assertInstanceOf($errorType, $outcome->primaryThrowable());
            $snapshot = $store->find($outcome->identifier()->value());
            self::assertNotNull($snapshot);
            self::assertCount(1, $snapshot->diagnosticEntries());
            $values = $this->values($snapshot->diagnosticEntries()[0]);
            self::assertSame('failure', $values['outcome']);
            self::assertSame($errorType, $values['error_type']);
            self::assertArrayNotHasKey('route_template', $values);
            self::assertArrayNotHasKey('status_code', $values);
            self::assertStringNotContainsString('83927', json_encode($values, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('token=secret', json_encode($values, JSON_THROW_ON_ERROR));
        }
    }

    /** @return array{HttpKernel, InMemoryDiagnosticBatchStore, ResponseInterface} */
    private function kernel(): array
    {
        $store = new InMemoryDiagnosticBatchStore(4);
        $pipeline = DiagnosticPipeline::storing($store, 20, 10);
        $correlation = new ExecutionCorrelation();
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(201);
        $response->expects(self::never())->method('getBody');
        $response->expects(self::never())->method('getHeaders');
        $routeHandler = $this->createStub(RequestHandlerInterface::class);
        $routeHandler->method('handle')->willReturn($response);
        $routing = new RoutingRequestHandler(
            new RouteMatcher(new RouteCollection([new Route(['GET'], '/users/{id}', $routeHandler)])),
            [new MatchedRouteDiagnosticMiddleware()],
        );
        $handler = new MiddlewarePipeline([new HttpServerDiagnosticMiddleware($pipeline, $correlation)], $routing);
        $services = new ServiceRegistry();
        $services->freeze();
        return [new HttpKernel($handler, new ExecutionOrchestrator($services, $pipeline, [$correlation])), $store, $response];
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn($method);
        $attributes = new \ArrayObject();
        $request->method('getAttribute')->willReturnCallback(static function (string $name, mixed $default = null) use ($attributes): mixed {
            return $attributes[$name] ?? $default;
        });
        $request->method('withAttribute')->willReturnCallback(static function (string $name, mixed $value) use ($request, $attributes): ServerRequestInterface {
            $attributes[$name] = $value;
            return clone $request;
        });
        $request->expects(self::never())->method('getBody');
        $request->expects(self::never())->method('getHeaders');
        return $request;
    }

    /** @return array<string, string|int|float|bool|null> */
    private function values(\Evolve\Insight\Storage\DiagnosticEntrySnapshot $entry): array
    {
        $values = [];
        foreach ($entry->attributes() as $attribute) {
            $values[$attribute->name()] = $attribute->value();
        }
        return $values;
    }
}
