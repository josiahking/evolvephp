<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Integration;

use Evolve\Http\Routing\Route;
use Evolve\Http\Routing\RouteMatch;
use Evolve\I18n\LocalizationPolicy;
use Evolve\Insight\Access\DiagnosticAccessOperation;
use Evolve\Insight\Access\DiagnosticAccessPolicy;
use Evolve\Insight\Dashboard\DashboardExposure;
use Evolve\Insight\Dashboard\DashboardRoutes;
use Evolve\Insight\Query\DiagnosticQueryCursorUnavailable;
use Evolve\Insight\Query\DiagnosticQueryService;
use Evolve\Insight\Storage\DiagnosticBatchSnapshot;
use Evolve\Insight\Storage\DiagnosticEntryAttributeSnapshot;
use Evolve\Insight\Storage\DiagnosticEntrySnapshot;
use Evolve\Insight\Storage\DiagnosticObservationSnapshot;
use Evolve\Insight\Storage\InMemoryDiagnosticBatchStore;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

final class InsightDashboardAcceptanceTest extends TestCase
{
    private static string $body = '';

    public function testExplicitRoutesAndExposure(): void
    {
        $routes = $this->routes();
        self::assertSame(['/__evolve/insight', '/__evolve/insight/{execution}'], array_map(static fn(Route $route): string => $route->path(), $routes));
        self::assertSame([['GET'], ['GET']], array_map(static fn(Route $route): array => $route->methods(), $routes));
        self::assertSame(['/tools/insight', '/tools/insight/{execution}'], array_map(static fn(Route $route): string => $route->path(), $this->routes('/tools/insight')));
        foreach (['', '/', 'tools', '/tools/', '/tools/{id}', '/tools?x=1'] as $prefix) {
            try {
                $this->routes($prefix);
                self::fail('Invalid prefix accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame('local-development', DashboardExposure::localDevelopment()->mode());
        self::assertSame('production-authorized', DashboardExposure::productionAuthorized()->mode());
        foreach (['production', 'production-authorized'] as $mode) {
            try {
                DashboardExposure::fromMode($mode);
                self::fail('Production exposure string was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testNativeListDetailPaginationAndEscaping(): void
    {
        $store = new InMemoryDiagnosticBatchStore(10);
        $store->save(new DiagnosticBatchSnapshot('<script>x</script>', 'http-request', [new DiagnosticObservationSnapshot('<img src=x>', 'ok', null, null)], 2, [new DiagnosticEntrySnapshot('db', '<b>query</b>', [new DiagnosticEntryAttributeSnapshot('sql', '<svg onload=1>')])], 3));
        $store->save(new DiagnosticBatchSnapshot('second', 'cli-command', [], 0, [new DiagnosticEntrySnapshot('db', 'query', [])]));
        $routes = $this->routes(store: $store);
        [$response, $body] = $this->dispatch($routes[0], ['page_size' => '1', 'category' => 'db']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('category=db', $body);
        self::assertStringContainsString('cursor=second', $body);
        [$response, $body] = $this->dispatch($routes[1], [], '<script>x</script>');
        self::assertSame(200, $response->getStatusCode());
        foreach (['&lt;script&gt;x&lt;/script&gt;', '&lt;img src=x&gt;', '&lt;b&gt;query&lt;/b&gt;', '&lt;svg onload=1&gt;'] as $escaped) {
            self::assertStringContainsString($escaped, $body);
        }
        self::assertStringNotContainsString('<script>x</script>', $body);
        self::assertStringContainsString('3', $body);
    }

    public function testAccessDeniedAndMissingDetail(): void
    {
        $store = new InMemoryDiagnosticBatchStore(10);
        $policy = new class implements DiagnosticAccessPolicy {
            public bool $list = false;
            public bool $detail = false;
            public function allows(DiagnosticAccessOperation $operation, ?string $executionIdentifier = null): bool
            {
                return $operation === DiagnosticAccessOperation::List ? $this->list : $this->detail;
            }
        };
        $routes = $this->routes(store: $store, policy: $policy);
        self::assertSame(403, $this->dispatch($routes[0])[0]->getStatusCode());
        self::assertSame(403, $this->dispatch($routes[1], [], 'missing')[0]->getStatusCode());
        $policy->detail = true;
        self::assertSame(404, $this->dispatch($routes[1], [], 'missing')[0]->getStatusCode());
        $policy->list = true;
        self::assertSame(200, $this->dispatch($routes[0])[0]->getStatusCode());
    }

    public function testMalformedQueryGetsBoundedClientError(): void
    {
        $route = $this->routes()[0];
        foreach ([['page_size' => '0'], ['page_size' => '101'], ['page_size' => '1.5'], ['cursor' => ''], ['cursor' => ['x']], ['execution_kind' => 'unsupported'], ['category' => ''], ['name' => new \stdClass()], ['unexpected' => 'x']] as $query) {
            [$response, $body] = $this->dispatch($route, $query);
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertStringNotContainsString('Diagnostic query', $body);
        }
    }

    public function testQueryParsingAndEnglishFallback(): void
    {
        $query = (new \Evolve\Insight\Dashboard\DashboardQueryParser())->parse([
            'page_size' => '7', 'cursor' => 'prior', 'execution_kind' => 'http-request', 'category' => 'db', 'name' => 'query',
        ]);
        self::assertSame(7, $query->pageSize());
        self::assertSame('prior', $query->cursor());
        self::assertSame('http-request', $query->executionKind());
        self::assertSame('db', $query->diagnosticCategory());
        self::assertSame('query', $query->diagnosticName());
        self::assertSame(25, (new \Evolve\Insight\Dashboard\DashboardQueryParser())->parse([])->pageSize());
        $routes = $this->routes(context: new \Evolve\I18n\LocalizationContext('fr', ['fr', 'en'], 'UTC'));
        [$response, $body] = $this->dispatch($routes[0]);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Evolve Insight', $body);
        self::assertSame(400, $this->dispatch($routes[0], ['cursor' => 'not-retained'])[0]->getStatusCode());
    }
    public function testNativeCompositionAppendsEnglishFallbackWithoutMutatingCallerContext(): void
    {
        $context = new \Evolve\I18n\LocalizationContext('fr', ['fr'], 'Africa/Lagos');
        $routes = $this->routes(context: $context);
        [$response, $body] = $this->dispatch($routes[0]);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Evolve Insight', $body);
        self::assertSame('fr', $context->locale());
        self::assertSame(['fr'], $context->fallbackChain());
        self::assertSame('Africa/Lagos', $context->timezone());
    }

    public function testRendererInvalidArgumentFailurePropagates(): void
    {
        $renderer = $this->createStub(\Evolve\View\ViewRenderer::class);
        $renderer->method('render')->willReturnCallback(static function (string $view): string {
            if ($view === 'insight::list') {
                throw new \InvalidArgumentException('renderer failed');
            }

            return '<p>error view</p>';
        });
        $translator = $this->createStub(\Evolve\I18n\Translator::class);
        $responses = $this->createStub(ResponseFactoryInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $response->method('getBody')->willReturn($this->createStub(StreamInterface::class));
        $responses->method('createResponse')->willReturn($response);
        $store = new InMemoryDiagnosticBatchStore(10);
        $policy = new class implements DiagnosticAccessPolicy {
            public function allows(DiagnosticAccessOperation $operation, ?string $executionIdentifier = null): bool
            {
                return true;
            }
        };
        $handler = new \Evolve\Insight\Dashboard\DashboardHandler(
            new DiagnosticQueryService($store, $policy),
            $responses,
            $renderer,
            $translator,
            (new LocalizationPolicy(['en'], 'en', [], 'UTC'))->context(),
            '/__evolve/insight',
            false,
        );
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('renderer failed');
        $handler->handle($request);
    }
    public function testRouteCompositionRequiresExplicitLocalOrProductionExposure(): void
    {
        $local = $this->routes(exposure: DashboardExposure::localDevelopment());
        $production = $this->routes(exposure: DashboardExposure::productionAuthorized());
        self::assertSame(200, $this->dispatch($local[0])[0]->getStatusCode());
        self::assertSame(200, $this->dispatch($production[0])[0]->getStatusCode());
        self::assertSame(4, (new \ReflectionMethod(DashboardRoutes::class, 'native'))->getNumberOfRequiredParameters());
        self::assertSame(6, (new \ReflectionMethod(DashboardRoutes::class, 'compose'))->getNumberOfRequiredParameters());
    }
    public function testExplicitExposureArgumentCannotBeOmitted(): void
    {
        $store = new InMemoryDiagnosticBatchStore(10);
        $policy = new class implements DiagnosticAccessPolicy {
            public function allows(DiagnosticAccessOperation $operation, ?string $executionIdentifier = null): bool
            {
                return true;
            }
        };
        $this->expectException(\ArgumentCountError::class);
        (new \ReflectionMethod(DashboardRoutes::class, 'native'))->invokeArgs(null, [
            new DiagnosticQueryService($store, $policy),
            $this->createStub(ResponseFactoryInterface::class),
            (new LocalizationPolicy(['en'], 'en', [], 'UTC'))->context(),
        ]);
    }

    public function testInternalReaderInvalidArgumentPropagatesWithCursor(): void
    {
        $reader = new class implements \Evolve\Insight\Query\DiagnosticBatchReader {
            public function find(string $executionIdentifier): ?DiagnosticBatchSnapshot
            {
                return null;
            }

            public function query(\Evolve\Insight\Query\DiagnosticBatchQuery $query): \Evolve\Insight\Query\DiagnosticBatchPage
            {
                throw new \InvalidArgumentException('internal reader failure');
            }
        };
        $policy = new class implements DiagnosticAccessPolicy {
            public function allows(DiagnosticAccessOperation $operation, ?string $executionIdentifier = null): bool
            {
                return true;
            }
        };
        $handler = new \Evolve\Insight\Dashboard\DashboardHandler(
            new DiagnosticQueryService($reader, $policy),
            $this->createStub(ResponseFactoryInterface::class),
            $this->createStub(\Evolve\View\ViewRenderer::class),
            $this->createStub(\Evolve\I18n\Translator::class),
            (new LocalizationPolicy(['en'], 'en', [], 'UTC'))->context(),
            '/__evolve/insight',
            false,
        );
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['cursor' => 'retained']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('internal reader failure');
        $handler->handle($request);
    }

    public function testReaderInvalidArgumentWithLegacyCursorMessagePropagates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic query cursor does not reference a retained batch.');

        $this->handleReaderFailure(new \InvalidArgumentException('Diagnostic query cursor does not reference a retained batch.'));
    }

    public function testTypedUnavailableCursorGetsBoundedClientError(): void
    {
        $response = $this->handleReaderFailure(new DiagnosticQueryCursorUnavailable());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    private function handleReaderFailure(\Throwable $failure): ResponseInterface
    {
        $reader = new class ($failure) implements \Evolve\Insight\Query\DiagnosticBatchReader {
            public function __construct(private \Throwable $failure) {}

            public function find(string $executionIdentifier): ?DiagnosticBatchSnapshot
            {
                return null;
            }

            public function query(\Evolve\Insight\Query\DiagnosticBatchQuery $query): \Evolve\Insight\Query\DiagnosticBatchPage
            {
                throw $this->failure;
            }
        };
        $policy = new class implements DiagnosticAccessPolicy {
            public function allows(DiagnosticAccessOperation $operation, ?string $executionIdentifier = null): bool
            {
                return true;
            }
        };
        $status = 0;
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturnCallback(static function () use (&$status): int {
            return $status;
        });
        $response->method('getHeaderLine')->willReturn('no-store');
        $response->method('withHeader')->willReturnSelf();
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('write')->willReturnCallback(static fn(string $body): int => strlen($body));
        $response->method('getBody')->willReturn($stream);
        $factory = $this->createStub(ResponseFactoryInterface::class);
        $factory->method('createResponse')->willReturnCallback(static function (int $code = 200) use ($response, &$status): ResponseInterface {
            $status = $code;
            return $response;
        });
        $renderer = $this->createStub(\Evolve\View\ViewRenderer::class);
        $renderer->method('render')->willReturn('<p>Invalid request</p>');
        $translator = $this->createStub(\Evolve\I18n\Translator::class);
        $translator->method('translate')->willReturn('Invalid request');
        $handler = new \Evolve\Insight\Dashboard\DashboardHandler(
            new DiagnosticQueryService($reader, $policy),
            $factory,
            $renderer,
            $translator,
            (new LocalizationPolicy(['en'], 'en', [], 'UTC'))->context(),
            '/__evolve/insight',
            false,
        );
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['cursor' => 'retained']);

        return $handler->handle($request);
    }
    /** @return list<Route> */
    private function routes(string $prefix = '/__evolve/insight', ?InMemoryDiagnosticBatchStore $store = null, ?DiagnosticAccessPolicy $policy = null, ?\Evolve\I18n\LocalizationContext $context = null, ?DashboardExposure $exposure = null): array
    {
        $store ??= new InMemoryDiagnosticBatchStore(10);
        $policy ??= new class implements DiagnosticAccessPolicy {
            public function allows(DiagnosticAccessOperation $operation, ?string $executionIdentifier = null): bool
            {
                return true;
            }
        };
        $factory = $this->createStub(ResponseFactoryInterface::class);
        $factory->method('createResponse')->willReturnCallback(function (int $code = 200): ResponseInterface {
            $headers = [];
            $stream = $this->createStub(StreamInterface::class);
            $stream->method('write')->willReturnCallback(static function (string $body): int {
                self::$body = $body;
                return strlen($body);
            });
            $response = $this->createStub(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn($code);
            $response->method('getBody')->willReturn($stream);
            $response->method('withHeader')->willReturnCallback(static function (string $name, $value) use ($response, &$headers): ResponseInterface {
                $headers[strtolower($name)] = $value;
                return $response;
            });
            $response->method('getHeaderLine')->willReturnCallback(static function (string $name) use (&$headers): string {
                return (string) ($headers[strtolower($name)] ?? '');
            });
            return $response;
        });
        return DashboardRoutes::native(new DiagnosticQueryService($store, $policy), $factory, $context ?? (new LocalizationPolicy(['en'], 'en', [], 'UTC'))->context(), $exposure ?? DashboardExposure::localDevelopment(), $prefix)->all();
    }

    /**
     * @param array<string, mixed> $query
     * @return array{ResponseInterface, string}
     */
    private function dispatch(Route $route, array $query = [], ?string $execution = null): array
    {
        self::$body = '';
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($query);
        $request->method('getAttribute')->willReturn(new RouteMatch($route, $execution === null ? [] : ['execution' => $execution]));
        $response = $route->handler()->handle($request);
        return [$response, self::$body];
    }
}
