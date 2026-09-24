<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Integration;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Http\HttpKernel;
use Evolve\Http\Middleware\MiddlewarePipeline;
use Evolve\Http\Routing\Route;
use Evolve\Http\Routing\RouteCollection;
use Evolve\Http\Routing\RouteMatcher;
use Evolve\Http\Routing\RoutingRequestHandler;
use Evolve\Observe\EvolveSemanticConventions;
use Evolve\Observe\ExecutionTraceInstrumentation;
use Evolve\Observe\Http\HttpRouteSpanMiddleware;
use Evolve\Observe\Http\HttpServerTraceInstrumentation;
use Evolve\Observe\OpenTelemetryComposition;
use Evolve\Observe\Tests\Unit\Http\TraceResponse;
use Evolve\Observe\Tests\Unit\Http\TraceServerRequest;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HttpServerTraceIntegrationTest extends TestCase
{
    public function testServerSpanWrapsHttpKernelAndExecutionSpanBecomesChild(): void
    {
        [$composition, $exporter] = $this->compositionHarness();
        $executionInstrumentation = new ExecutionTraceInstrumentation($composition);
        $kernelProbe = new KernelActiveSpanProbe();
        $kernel = new HttpKernel(
            new MiddlewarePipeline([], new RoutingRequestHandler(
                new RouteMatcher(new RouteCollection([
                    new Route(['GET'], '/users/{id}', $kernelProbe),
                ])),
                [new HttpRouteSpanMiddleware()],
            )),
            $this->orchestrator($executionInstrumentation),
        );
        $serverInstrumentation = new HttpServerTraceInstrumentation($composition);
        $traceId = str_repeat('a', 32);
        $parentSpanId = str_repeat('b', 16);

        $response = $serverInstrumentation->trace(
            TraceServerRequest::get('/users/123')->withHeader('traceparent', '00-' . $traceId . '-' . $parentSpanId . '-01'),
            static function (ServerRequestInterface $request) use ($kernel): ResponseInterface {
                $outcome = $kernel->handle($request);

                return $outcome->primaryResult();
            },
        );

        $serverSpan = $this->spanNamed($exporter, 'GET /users/{id}');
        $executionSpan = $this->spanNamed($exporter, EvolveSemanticConventions::SPAN_NAME_EXECUTION);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($kernelProbe->sawActiveServerSpanBeforeKernelHandler);
        $this->assertInstanceOf(ExecutionContext::class, $kernelProbe->request?->getAttribute(ExecutionContext::class));
        $this->assertSame($traceId, $serverSpan->getTraceId());
        $this->assertSame($parentSpanId, $serverSpan->getParentSpanId());
        $this->assertSame('/users/{id}', $serverSpan->getAttributes()->get(HttpAttributes::HTTP_ROUTE));
        $this->assertSame($serverSpan->getTraceId(), $executionSpan->getTraceId());
        $this->assertSame($serverSpan->getSpanId(), $executionSpan->getParentSpanId());
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testSequentialRequestsDoNotShareActiveContext(): void
    {
        [$composition, $exporter] = $this->compositionHarness();
        $instrumentation = new HttpServerTraceInstrumentation($composition);

        $instrumentation->trace(TraceServerRequest::get('/first'), static fn(): ResponseInterface => new TraceResponse(200));
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());

        $instrumentation->trace(TraceServerRequest::get('/second'), static function (): ResponseInterface {
            self::assertTrue(Span::getCurrent()->getContext()->isValid());

            return new TraceResponse(200);
        });

        $spans = $exporter->getSpans();

        $this->assertCount(2, $spans);
        $this->assertNotSame($spans[0]->getTraceId(), $spans[1]->getTraceId());
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    /**
     * @return array{0: OpenTelemetryComposition, 1: InMemoryExporter}
     */
    private function compositionHarness(): array
    {
        $exporter = new InMemoryExporter();
        $resource = ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'observe-http-integration']));
        $provider = new TracerProvider(new SimpleSpanProcessor($exporter), resource: $resource);

        return [
            new OpenTelemetryComposition(
                enabled: true,
                tracerProvider: $provider,
                resource: $resource,
            ),
            $exporter,
        ];
    }

    private function orchestrator(ExecutionTraceInstrumentation $instrumentation): ExecutionOrchestrator
    {
        $registry = new ServiceRegistry();
        $registry->freeze();

        return new ExecutionOrchestrator($registry, $instrumentation, [$instrumentation]);
    }

    private function spanNamed(InMemoryExporter $exporter, string $name): SpanDataInterface
    {
        $matches = array_values(array_filter(
            $exporter->getSpans(),
            static fn(SpanDataInterface $span): bool => $span->getName() === $name,
        ));

        $this->assertCount(1, $matches, 'Expected one span named ' . $name . '.');

        return $matches[0];
    }
}

final class KernelActiveSpanProbe implements RequestHandlerInterface
{
    public ?ServerRequestInterface $request = null;

    public bool $sawActiveServerSpanBeforeKernelHandler = false;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;
        $this->sawActiveServerSpanBeforeKernelHandler = Span::getCurrent()->getContext()->isValid();

        return new TraceResponse(200);
    }
}
