<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit\Http;

use Evolve\Observe\Http\HttpClientInstrumentation;
use Evolve\Observe\OpenTelemetryComposition;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class HttpClientInstrumentationTest extends TestCase
{
    public function testDisabledDelegatesOriginalRequestAndPreservesResponseIdentity(): void
    {
        $request = $this->createStub(RequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $next = $this->createMock(ClientInterface::class);
        $next->expects(self::once())->method('sendRequest')->with(self::identicalTo($request))->willReturn($response);
        self::assertSame($response, (new HttpClientInstrumentation(OpenTelemetryComposition::disabled()))->process($request, $next));
    }
    public function testEnabledMiddlewareInjectsOnlyTraceContextAndPreservesResponse(): void
    {
        $exporter = new \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter();
        $meters = new \Evolve\Observe\Tests\Unit\RecordingMeterProvider();
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'http-client-test',
            ]),
        );
        $provider = new \OpenTelemetry\SDK\Trace\TracerProvider(
            new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($exporter),
            resource: $resource,
        );
        $composition = new OpenTelemetryComposition(enabled: true, tracerProvider: $provider, meterProvider: $meters, resource: $resource);
        $request = new \GuzzleHttp\Psr7\Request(
            'POST',
            'https://user:password@example.test/private?token=secret#fragment',
            ['TraceParent' => 'stale', 'TraceState' => 'stale', 'baggage' => 'tenant=private', 'Authorization' => 'Bearer secret', 'Cookie' => 'session=secret'],
            'private body',
        );
        $response = new \GuzzleHttp\Psr7\Response(503, [], 'private response');
        $next = new class ($response) implements ClientInterface {
            public int $calls = 0;
            public ?RequestInterface $received = null;
            public function __construct(private ResponseInterface $response) {}
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->calls;
                $this->received = $request;
                return $this->response;
            }
        };
        $parent = $provider->getTracer('test')->spanBuilder('parent')->startSpan();
        $scope = $parent->activate();
        $actual = (new HttpClientInstrumentation(
            $composition,
            (new \Evolve\Observe\Tests\Unit\SequenceClock(1_000_000_000, 1_250_000_000))(...),
        ))->process($request, $next);
        $scope->detach();
        $parent->end();

        self::assertSame($response, $actual);
        self::assertSame(1, $next->calls);
        self::assertNotSame($request, $next->received);
        self::assertSame('tenant=private', $next->received->getHeaderLine('baggage'));
        self::assertSame('Bearer secret', $next->received->getHeaderLine('Authorization'));
        self::assertSame('session=secret', $next->received->getHeaderLine('Cookie'));
        self::assertSame('private body', (string) $next->received->getBody());
        self::assertSame('stale', $request->getHeaderLine('TraceParent'));
        self::assertCount(1, $next->received->getHeader('traceparent'));
        self::assertSame('', $next->received->getHeaderLine('tracestate'));
        self::assertArrayHasKey('traceparent', $next->received->getHeaders());
        self::assertArrayNotHasKey('TraceParent', $next->received->getHeaders());
        $spans = array_values(array_filter($exporter->getSpans(), static fn($span): bool => $span->getName() === \Evolve\Observe\EvolveSemanticConventions::SPAN_NAME_HTTP_CLIENT));
        self::assertCount(1, $spans);
        self::assertSame($parent->getContext()->getSpanId(), $spans[0]->getParentSpanId());
        self::assertSame(\OpenTelemetry\API\Trace\SpanKind::KIND_CLIENT, $spans[0]->getKind());
        self::assertSame(503, $spans[0]->getAttributes()->get(\OpenTelemetry\SemConv\Attributes\HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        self::assertSame(['http.request.method' => 'POST'], $meters->meter->counter(\Evolve\Observe\EvolveSemanticConventions::METRIC_HTTP_CLIENT_COUNT)->records[0]['attributes']);
        self::assertSame([], $meters->meter->counter(\Evolve\Observe\EvolveSemanticConventions::METRIC_HTTP_CLIENT_FAILURES)->records);
        $serialized = json_encode($spans[0]->getAttributes()->toArray());
        foreach (['example.test', 'password', 'private', 'secret', 'Bearer', 'session'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $serialized);
        }
    }

    public function testPropagationSetupFailureDelegatesOriginalRequestAndKeepsThrowableIdentity(): void
    {
        $provider = $this->createStub(\OpenTelemetry\API\Trace\TracerProviderInterface::class);
        $provider->method('getTracer')->willThrowException(new \RuntimeException('telemetry unavailable'));
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'http-client-test',
            ]),
        );
        $composition = new OpenTelemetryComposition(enabled: true, tracerProvider: $provider, resource: $resource);
        $request = new \GuzzleHttp\Psr7\Request('GET', 'https://example.test/private', ['TraceParent' => 'stale']);
        $failure = new \RuntimeException('transport failure');
        $next = new class ($request, $failure) implements ClientInterface {
            public int $calls = 0;
            public function __construct(private RequestInterface $expected, private \RuntimeException $failure) {}
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                \PHPUnit\Framework\Assert::assertSame($this->expected, $request);
                ++$this->calls;
                throw $this->failure;
            }
        };
        try {
            (new HttpClientInstrumentation($composition))->process($request, $next);
            self::fail('Expected transport failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(1, $next->calls);
    }

    public function testMethodInspectionFailureStillDelegatesOriginalRequest(): void
    {
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'http-client-test',
            ]),
        );
        $composition = new OpenTelemetryComposition(
            enabled: true,
            meterProvider: new \OpenTelemetry\API\Metrics\Noop\NoopMeterProvider(),
            resource: $resource,
        );
        $request = $this->createStub(RequestInterface::class);
        $request->method('getMethod')->willThrowException(new \RuntimeException('request inspection unavailable'));
        $response = new \GuzzleHttp\Psr7\Response(200);
        $next = $this->createMock(ClientInterface::class);
        $next->expects(self::once())->method('sendRequest')->with(self::identicalTo($request))->willReturn($response);
        self::assertSame($response, (new HttpClientInstrumentation($composition))->process($request, $next));
    }

    public function testThrownClientErrorIncrementsOnlyBoundedMethodFailureMetric(): void
    {
        $meters = new \Evolve\Observe\Tests\Unit\RecordingMeterProvider();
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'http-client-failure-test',
            ]),
        );
        $composition = new OpenTelemetryComposition(enabled: true, meterProvider: $meters, resource: $resource);
        $failure = new \RuntimeException('secret network address');
        $next = $this->createMock(ClientInterface::class);
        $next->expects(self::once())->method('sendRequest')->willThrowException($failure);
        try {
            (new HttpClientInstrumentation($composition))->process(new \GuzzleHttp\Psr7\Request('BREW', 'https://example.test/private'), $next);
            self::fail('Expected client failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(
            ['http.request.method' => '_OTHER'],
            $meters->meter->counter(\Evolve\Observe\EvolveSemanticConventions::METRIC_HTTP_CLIENT_FAILURES)->records[0]['attributes'],
        );
    }

    public function testPropagationFailureFallsBackToOriginalRequest(): void
    {
        $exporter = new \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter();
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'http-client-failure-test',
            ]),
        );
        $composition = new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new \OpenTelemetry\SDK\Trace\TracerProvider(
                new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($exporter),
                resource: $resource,
            ),
            resource: $resource,
        );
        $request = $this->createStub(RequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('withoutHeader')->willThrowException(new \RuntimeException('immutable derivation unavailable'));
        $response = new \GuzzleHttp\Psr7\Response(200);
        $next = $this->createMock(ClientInterface::class);
        $next->expects(self::once())->method('sendRequest')->with(self::identicalTo($request))->willReturn($response);
        self::assertSame($response, (new HttpClientInstrumentation($composition))->process($request, $next));
    }

    public function testOutboundTraceStateIsInjectedOnlyWhenParentCarriesIt(): void
    {
        $exporter = new \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter();
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'http-client-tracestate-test',
            ]),
        );
        $provider = new \OpenTelemetry\SDK\Trace\TracerProvider(
            new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($exporter),
            resource: $resource,
        );
        $composition = new OpenTelemetryComposition(enabled: true, tracerProvider: $provider, resource: $resource);
        $remote = \OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::getInstance()->extract(
            [
                'traceparent' => '00-' . str_repeat('1', 32) . '-' . str_repeat('2', 16) . '-01',
                'tracestate' => 'vendor=value',
            ],
            context: \OpenTelemetry\Context\Context::getRoot(),
        );
        $parent = $provider->getTracer('test')->spanBuilder('parent')->setParent($remote)->startSpan();
        $scope = $parent->activate();
        $request = new \GuzzleHttp\Psr7\Request('GET', 'https://example.test', ['TraceState' => 'stale']);
        $response = new \GuzzleHttp\Psr7\Response(200);
        $next = new class ($response) implements ClientInterface {
            public ?RequestInterface $received = null;
            public function __construct(private ResponseInterface $response) {}
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->received = $request;

                return $this->response;
            }
        };
        self::assertSame($response, (new HttpClientInstrumentation($composition))->process($request, $next));
        $scope->detach();
        $parent->end();
        self::assertSame('vendor=value', $next->received->getHeaderLine('tracestate'));
        self::assertArrayHasKey('tracestate', $next->received->getHeaders());
        self::assertArrayNotHasKey('TraceState', $next->received->getHeaders());
    }

    public function testDisabledPreservesOriginalRequestAndExactTransportThrowable(): void
    {
        $request = $this->createStub(RequestInterface::class);
        $failure = new \RuntimeException('application transport failure');
        $next = $this->createMock(ClientInterface::class);
        $next->expects(self::once())->method('sendRequest')->with(self::identicalTo($request))->willThrowException($failure);

        try {
            (new HttpClientInstrumentation(OpenTelemetryComposition::disabled()))->process($request, $next);
            self::fail('Expected transport failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }
}
