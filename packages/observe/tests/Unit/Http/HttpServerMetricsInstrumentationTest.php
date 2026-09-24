<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit\Http;

use Evolve\Observe\EvolveSemanticConventions;
use Evolve\Observe\Http\HttpServerMetricsInstrumentation;
use Evolve\Observe\Http\Internal\HttpServerMetricState;
use Evolve\Observe\OpenTelemetryComposition;
use OpenTelemetry\API\Metrics\AsynchronousInstrument;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

require_once __DIR__ . '/HttpServerTraceInstrumentationTest.php';

final class HttpServerMetricsInstrumentationTest extends TestCase
{
    public function testDisabledAndTracerOnlyCompositionAreInert(): void
    {
        $request = TraceServerRequest::get('/metrics');
        $response = new TraceResponse(200);

        foreach ([OpenTelemetryComposition::disabled(), new OpenTelemetryComposition(enabled: true, tracerProvider: new NoopTracerProvider(), resource: $this->resource())] as $composition) {
            $instrumentation = new HttpServerMetricsInstrumentation($composition);
            $handledRequest = null;

            $actual = $instrumentation->measure($request, static function (ServerRequestInterface $operationRequest) use (&$handledRequest, $response): ResponseInterface {
                $handledRequest = $operationRequest;

                return $response;
            });

            $this->assertSame($response, $actual);
            $this->assertSame($request, $handledRequest);
            $this->assertNull($request->getAttribute(HttpServerMetricState::class));
        }
    }

    public function testSuccessfulAndFailingHttpRequestsRecordClosedMetricsAndCacheInstruments(): void
    {
        $meterProvider = new HttpRecordingMeterProvider();
        $instrumentation = new HttpServerMetricsInstrumentation(
            new OpenTelemetryComposition(enabled: true, meterProvider: $meterProvider, resource: $this->resource()),
            (new HttpSequenceClock(1_000_000_000, 1_050_000_000, 2_000_000_000, 2_125_000_000, 3_000_000_000, 3_250_000_000))(...),
        );

        $instrumentation->measure(TraceServerRequest::method('get', '/ok?secret=1'), static fn(): ResponseInterface => new TraceResponse(200));
        $instrumentation->measure(TraceServerRequest::method('CUSTOM', '/server-error'), static fn(): ResponseInterface => new TraceResponse(503));

        try {
            $instrumentation->measure(TraceServerRequest::method('POST', '/throw'), static function (): ResponseInterface {
                throw new RuntimeException('application failure');
            });
            self::fail('Application throwable should be rethrown.');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $meterProvider->getMeterCalls);
        $this->assertSame(1, $meterProvider->meter->histogram(HttpMetrics::HTTP_SERVER_REQUEST_DURATION)->createCalls);
        $this->assertSame(1, $meterProvider->meter->counter(EvolveSemanticConventions::METRIC_HTTP_SERVER_REQUEST_COUNT)->createCalls);
        $this->assertSame(1, $meterProvider->meter->upDownCounter(EvolveSemanticConventions::METRIC_HTTP_SERVER_ACTIVE_REQUESTS)->createCalls);
        $this->assertSame(1, $meterProvider->meter->counter(EvolveSemanticConventions::METRIC_HTTP_SERVER_REQUEST_FAILURES)->createCalls);
        $this->assertSame([0.05, 0.125, 0.25], array_column($meterProvider->meter->histogram(HttpMetrics::HTTP_SERVER_REQUEST_DURATION)->records, 'amount'));
        $this->assertSame(0, $meterProvider->meter->upDownCounter(EvolveSemanticConventions::METRIC_HTTP_SERVER_ACTIVE_REQUESTS)->netAmount());
        $this->assertCount(2, $meterProvider->meter->counter(EvolveSemanticConventions::METRIC_HTTP_SERVER_REQUEST_FAILURES)->records);
        $this->assertHttpInstrumentUnits($meterProvider->meter);

        foreach ($meterProvider->meter->allMeasurements() as $measurement) {
            $this->assertSame([HttpAttributes::HTTP_REQUEST_METHOD], array_keys($measurement['attributes']));
            $this->assertContains($measurement['attributes'][HttpAttributes::HTTP_REQUEST_METHOD], ['GET', 'POST', HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER]);
            $encoded = json_encode($measurement['attributes'], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('CUSTOM', $encoded);
            $this->assertStringNotContainsString('/ok', $encoded);
            $this->assertStringNotContainsString('503', $encoded);
        }
    }

    public function testNestedMetricWrapperUsesExistingOwnershipWithoutDuplicateMeasurements(): void
    {
        $meterProvider = new HttpRecordingMeterProvider();
        $instrumentation = new HttpServerMetricsInstrumentation(
            new OpenTelemetryComposition(enabled: true, meterProvider: $meterProvider, resource: $this->resource()),
            (new HttpSequenceClock(1_000_000_000, 1_100_000_000))(...),
        );

        $instrumentation->measure(
            TraceServerRequest::get('/nested'),
            fn(ServerRequestInterface $request): ResponseInterface => $instrumentation->measure(
                $request,
                static fn(): ResponseInterface => new TraceResponse(200),
            ),
        );

        $this->assertCount(1, $meterProvider->meter->counter(EvolveSemanticConventions::METRIC_HTTP_SERVER_REQUEST_COUNT)->records);
        $this->assertCount(1, $meterProvider->meter->histogram(HttpMetrics::HTTP_SERVER_REQUEST_DURATION)->records);
        $this->assertSame(0, $meterProvider->meter->upDownCounter(EvolveSemanticConventions::METRIC_HTTP_SERVER_ACTIVE_REQUESTS)->netAmount());
    }

    public function testTelemetryFailuresDoNotReplaceApplicationBehaviorAndCleanupStaysBalanced(): void
    {
        $meterProvider = new HttpRecordingMeterProvider();
        $meterProvider->meter->failOnHistogramRecord = true;
        $instrumentation = new HttpServerMetricsInstrumentation(
            new OpenTelemetryComposition(enabled: true, meterProvider: $meterProvider, resource: $this->resource()),
            (new HttpSequenceClock(1_000_000_000, 1_100_000_000))(...),
        );
        $response = new TraceResponse(201);

        $actual = $instrumentation->measure(TraceServerRequest::get('/telemetry-failure'), static fn(): ResponseInterface => $response);

        $this->assertSame($response, $actual);
        $this->assertSame(0, $meterProvider->meter->upDownCounter(EvolveSemanticConventions::METRIC_HTTP_SERVER_ACTIVE_REQUESTS)->netAmount());
    }

    public function testSetupClockFailureFailsOpenToOriginalApplicationOperation(): void
    {
        $meterProvider = new HttpRecordingMeterProvider();
        $instrumentation = new HttpServerMetricsInstrumentation(
            new OpenTelemetryComposition(enabled: true, meterProvider: $meterProvider, resource: $this->resource()),
            (new HttpThrowingClock())(...),
        );
        $request = TraceServerRequest::get('/setup-clock-failure');
        $response = new TraceResponse(204);
        $handledRequest = null;

        $actual = $instrumentation->measure($request, static function (ServerRequestInterface $operationRequest) use (&$handledRequest, $response): ResponseInterface {
            $handledRequest = $operationRequest;

            return $response;
        });

        $this->assertSame($response, $actual);
        $this->assertSame($request, $handledRequest);
        $this->assertNull($request->getAttribute(HttpServerMetricState::class));
        $this->assertSame(0, $meterProvider->getMeterCalls);
    }

    public function testActiveIncrementFailureDoesNotCreateCompensatingDecrement(): void
    {
        $meterProvider = new HttpRecordingMeterProvider();
        $meterProvider->meter->failOnPositiveUpDownCounterAdd = true;
        $instrumentation = new HttpServerMetricsInstrumentation(
            new OpenTelemetryComposition(enabled: true, meterProvider: $meterProvider, resource: $this->resource()),
            (new HttpSequenceClock(1_000_000_000, 1_100_000_000))(...),
        );

        $response = $instrumentation->measure(TraceServerRequest::get('/active-failure'), static fn(): ResponseInterface => new TraceResponse(200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([1], array_column($meterProvider->meter->upDownCounter(EvolveSemanticConventions::METRIC_HTTP_SERVER_ACTIVE_REQUESTS)->failedRecords, 'amount'));
        $this->assertSame(0, $meterProvider->meter->upDownCounter(EvolveSemanticConventions::METRIC_HTTP_SERVER_ACTIVE_REQUESTS)->netAmount());
    }

    public function testSuccessfulActiveIncrementIsBalancedDespiteLaterTelemetryFailure(): void
    {
        $meterProvider = new HttpRecordingMeterProvider();
        $meterProvider->meter->failOnHistogramRecord = true;
        $meterProvider->meter->failOnRequestCountAdd = true;
        $instrumentation = new HttpServerMetricsInstrumentation(
            new OpenTelemetryComposition(enabled: true, meterProvider: $meterProvider, resource: $this->resource()),
            (new HttpSequenceClock(1_000_000_000, 1_100_000_000))(...),
        );

        $response = $instrumentation->measure(TraceServerRequest::get('/late-telemetry-failure'), static fn(): ResponseInterface => new TraceResponse(200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $meterProvider->meter->upDownCounter(EvolveSemanticConventions::METRIC_HTTP_SERVER_ACTIVE_REQUESTS)->netAmount());
    }

    public function testApplicationThrowableIdentityIsPreservedExactly(): void
    {
        $meterProvider = new HttpRecordingMeterProvider();
        $instrumentation = new HttpServerMetricsInstrumentation(
            new OpenTelemetryComposition(enabled: true, meterProvider: $meterProvider, resource: $this->resource()),
            (new HttpSequenceClock(1_000_000_000, 1_100_000_000))(...),
        );
        $throwable = new RuntimeException('application failure');

        try {
            $instrumentation->measure(TraceServerRequest::get('/throwable-identity'), static function () use ($throwable): ResponseInterface {
                throw $throwable;
            });
            self::fail('Application throwable should be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($throwable, $caught);
        }

        $this->assertCount(1, $meterProvider->meter->counter(EvolveSemanticConventions::METRIC_HTTP_SERVER_REQUEST_FAILURES)->records);
        $this->assertSame(0, $meterProvider->meter->upDownCounter(EvolveSemanticConventions::METRIC_HTTP_SERVER_ACTIVE_REQUESTS)->netAmount());
    }

    public function testResponseFailureClassificationUsesExactServerErrorRange(): void
    {
        $meterProvider = new HttpRecordingMeterProvider();
        $instrumentation = new HttpServerMetricsInstrumentation(
            new OpenTelemetryComposition(enabled: true, meterProvider: $meterProvider, resource: $this->resource()),
            (new HttpSequenceClock(
                1_000_000_000,
                1_010_000_000,
                2_000_000_000,
                2_010_000_000,
                3_000_000_000,
                3_010_000_000,
                4_000_000_000,
                4_010_000_000,
            ))(...),
        );

        foreach ([404, 500, 599, 600] as $statusCode) {
            $instrumentation->measure(TraceServerRequest::get('/status-' . $statusCode), static fn(): ResponseInterface => new TraceResponse($statusCode));
        }

        $this->assertCount(2, $meterProvider->meter->counter(EvolveSemanticConventions::METRIC_HTTP_SERVER_REQUEST_FAILURES)->records);
    }

    private function resource(): ResourceInfo
    {
        return ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'observe-http-metrics-test']));
    }

    private function assertHttpInstrumentUnits(HttpRecordingMeter $meter): void
    {
        $this->assertSame('s', $meter->histogram(HttpMetrics::HTTP_SERVER_REQUEST_DURATION)->unit);
        $this->assertSame('{request}', $meter->counter(EvolveSemanticConventions::METRIC_HTTP_SERVER_REQUEST_COUNT)->unit);
        $this->assertSame('{request}', $meter->upDownCounter(EvolveSemanticConventions::METRIC_HTTP_SERVER_ACTIVE_REQUESTS)->unit);
        $this->assertSame('{request}', $meter->counter(EvolveSemanticConventions::METRIC_HTTP_SERVER_REQUEST_FAILURES)->unit);
    }
}

final class HttpSequenceClock
{
    /**
     * @var list<int>
     */
    private array $ticks;

    public function __construct(int ...$ticks)
    {
        $this->ticks = $ticks;
    }

    public function __invoke(): int
    {
        return array_shift($this->ticks) ?? 0;
    }
}

final class HttpThrowingClock
{
    public function __invoke(): int
    {
        throw new RuntimeException('clock failed');
    }
}

final class HttpRecordingMeterProvider implements MeterProviderInterface
{
    public int $getMeterCalls = 0;

    public HttpRecordingMeter $meter;

    public function __construct()
    {
        $this->meter = new HttpRecordingMeter();
    }

    /**
     * @param iterable<string, string|bool|float|int|array<mixed>|null> $attributes
     */
    public function getMeter(string $name, ?string $version = null, ?string $schemaUrl = null, iterable $attributes = []): MeterInterface
    {
        ++$this->getMeterCalls;

        return $this->meter;
    }
}

final class HttpRecordingMeter implements MeterInterface
{
    /**
     * @var array<string, HttpRecordingCounter>
     */
    private array $counters = [];

    /**
     * @var array<string, HttpRecordingHistogram>
     */
    private array $histograms = [];

    /**
     * @var array<string, HttpRecordingUpDownCounter>
     */
    private array $upDownCounters = [];

    public bool $failOnHistogramRecord = false;

    public bool $failOnPositiveUpDownCounterAdd = false;

    public bool $failOnRequestCountAdd = false;

    public function batchObserve(callable $callback, AsynchronousInstrument $instrument, AsynchronousInstrument ...$instruments): ObservableCallbackInterface
    {
        return new HttpNoopObservableCallback();
    }

    /**
     * @param array<mixed> $advisory
     */
    public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): CounterInterface
    {
        $counter = $this->counters[$name] ??= new HttpRecordingCounter($name, $unit, $this);
        ++$counter->createCalls;

        return $counter;
    }

    /**
     * @param array<mixed>|callable $advisory
     */
    public function createObservableCounter(string $name, ?string $unit = null, ?string $description = null, array|callable $advisory = [], callable ...$callbacks): ObservableCounterInterface
    {
        throw new RuntimeException('Observable counters are out of scope.');
    }

    /**
     * @param array<mixed> $advisory
     */
    public function createHistogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): HistogramInterface
    {
        $histogram = $this->histograms[$name] ??= new HttpRecordingHistogram($name, $unit, $this);
        ++$histogram->createCalls;

        return $histogram;
    }

    /**
     * @param array<mixed> $advisory
     */
    public function createGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): GaugeInterface
    {
        throw new RuntimeException('Gauges are out of scope.');
    }

    /**
     * @param array<mixed>|callable $advisory
     */
    public function createObservableGauge(string $name, ?string $unit = null, ?string $description = null, array|callable $advisory = [], callable ...$callbacks): ObservableGaugeInterface
    {
        throw new RuntimeException('Observable gauges are out of scope.');
    }

    /**
     * @param array<mixed> $advisory
     */
    public function createUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): UpDownCounterInterface
    {
        $counter = $this->upDownCounters[$name] ??= new HttpRecordingUpDownCounter($name, $unit, $this);
        ++$counter->createCalls;

        return $counter;
    }

    /**
     * @param array<mixed>|callable $advisory
     */
    public function createObservableUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array|callable $advisory = [], callable ...$callbacks): ObservableUpDownCounterInterface
    {
        throw new RuntimeException('Observable up-down counters are out of scope.');
    }

    public function counter(string $name): HttpRecordingCounter
    {
        return $this->counters[$name] ??= new HttpRecordingCounter($name, null, $this);
    }

    public function histogram(string $name): HttpRecordingHistogram
    {
        return $this->histograms[$name] ??= new HttpRecordingHistogram($name, null, $this);
    }

    public function upDownCounter(string $name): HttpRecordingUpDownCounter
    {
        return $this->upDownCounters[$name] ??= new HttpRecordingUpDownCounter($name, null, $this);
    }

    /**
     * @return list<array{instrument: string, amount: int|float, attributes: array<string, mixed>}>
     */
    public function allMeasurements(): array
    {
        $measurements = [];

        foreach ([$this->counters, $this->histograms, $this->upDownCounters] as $instruments) {
            foreach ($instruments as $instrument) {
                foreach ($instrument->records as $record) {
                    $measurements[] = $record + ['instrument' => $instrument->name];
                }
            }
        }

        return $measurements;
    }
}

final class HttpRecordingCounter implements CounterInterface
{
    public int $createCalls = 0;

    /**
     * @var list<array{amount: int|float, attributes: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @var list<array{amount: int|float, attributes: array<string, mixed>}>
     */
    public array $failedRecords = [];

    public function __construct(public string $name, public ?string $unit, private HttpRecordingMeter $meter) {}

    /**
     * @param iterable<string, string|bool|float|int|array<mixed>|null> $attributes
     */
    public function add(float|int $amount, iterable $attributes = [], ContextInterface|false|null $context = null): void
    {
        $attributes = is_array($attributes) ? $attributes : iterator_to_array($attributes);

        if ($this->name === EvolveSemanticConventions::METRIC_HTTP_SERVER_REQUEST_COUNT && $this->meter->failOnRequestCountAdd) {
            $this->failedRecords[] = ['amount' => $amount, 'attributes' => $attributes];

            throw new RuntimeException('request count failed');
        }

        $this->records[] = ['amount' => $amount, 'attributes' => $attributes];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}

final class HttpRecordingHistogram implements HistogramInterface
{
    public int $createCalls = 0;

    /**
     * @var list<array{amount: int|float, attributes: array<string, mixed>}>
     */
    public array $records = [];

    public function __construct(public string $name, public ?string $unit, private HttpRecordingMeter $meter) {}

    /**
     * @param iterable<string, string|bool|float|int|array<mixed>|null> $attributes
     */
    public function record(float|int $amount, iterable $attributes = [], ContextInterface|false|null $context = null): void
    {
        if ($this->meter->failOnHistogramRecord) {
            throw new RuntimeException('histogram failed');
        }

        $this->records[] = ['amount' => $amount, 'attributes' => is_array($attributes) ? $attributes : iterator_to_array($attributes)];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}

final class HttpRecordingUpDownCounter implements UpDownCounterInterface
{
    public int $createCalls = 0;

    /**
     * @var list<array{amount: int|float, attributes: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @var list<array{amount: int|float, attributes: array<string, mixed>}>
     */
    public array $failedRecords = [];

    public function __construct(public string $name, public ?string $unit, private HttpRecordingMeter $meter) {}

    /**
     * @param iterable<string, string|bool|float|int|array<mixed>|null> $attributes
     */
    public function add($amount, iterable $attributes = [], $context = null): void
    {
        $attributes = is_array($attributes) ? $attributes : iterator_to_array($attributes);

        if ($amount > 0 && $this->meter->failOnPositiveUpDownCounterAdd) {
            $this->failedRecords[] = ['amount' => $amount, 'attributes' => $attributes];

            throw new RuntimeException('active increment failed');
        }

        $this->records[] = ['amount' => $amount, 'attributes' => $attributes];
    }

    public function netAmount(): int|float
    {
        return array_sum(array_column($this->records, 'amount'));
    }

    public function isEnabled(): bool
    {
        return true;
    }
}

final class HttpNoopObservableCallback implements ObservableCallbackInterface
{
    public function detach(): void {}
}
