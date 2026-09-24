<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionOutcome;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Observe\EvolveSemanticConventions;
use Evolve\Observe\ExecutionMetricsInstrumentation;
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
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExecutionMetricsInstrumentationTest extends TestCase
{
    public function testDisabledAndTracerOnlyCompositionDoNotCreateMetersOrState(): void
    {
        $disabled = new ExecutionMetricsInstrumentation(OpenTelemetryComposition::disabled());
        $tracerOnly = new ExecutionMetricsInstrumentation(new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new NoopTracerProvider(),
            resource: $this->resource(),
        ));
        $observation = new Observation(ObservationType::ExecutionStarted, ExecutionIdentifier::generate(), ExecutionKind::HttpRequest);

        $disabled->observe($observation);
        $tracerOnly->observe($observation);

        $this->assertSame(0, $this->activeExecutionCount($disabled));
        $this->assertSame(0, $this->activeExecutionCount($tracerOnly));
    }

    public function testNoMeterLookupOccursForDisabledOrTracerOnlyComposition(): void
    {
        $meterProvider = new RecordingMeterProvider();
        $meterOnly = new ExecutionMetricsInstrumentation(new OpenTelemetryComposition(
            enabled: true,
            meterProvider: $meterProvider,
            resource: $this->resource(),
        ));
        $disabled = new ExecutionMetricsInstrumentation(OpenTelemetryComposition::disabled());

        $disabled->observe(new Observation(ObservationType::ExecutionStarted, ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));
        $meterOnly->observe(new Observation(ObservationType::ExecutionStarted, ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));

        $this->assertSame(1, $meterProvider->getMeterCalls);
    }

    public function testCoreExecutionObservationsRecordClosedMetricSeriesAndCacheInstruments(): void
    {
        $meterProvider = new RecordingMeterProvider();
        $clock = new SequenceClock(1_000_000_000, 1_125_000_000, 2_000_000_000, 2_250_000_000);
        $instrumentation = new ExecutionMetricsInstrumentation(
            new OpenTelemetryComposition(enabled: true, meterProvider: $meterProvider, resource: $this->resource()),
            $clock(...),
        );

        $first = $this->execute($instrumentation, ExecutionKind::HttpRequest, static fn(): string => 'ok');
        $second = $this->execute($instrumentation, ExecutionKind::CliCommand, static function (): void {
            throw new RuntimeException('primary failure');
        });

        $this->assertTrue($first->primarySucceeded());
        $this->assertTrue($second->primaryFailed());
        $this->assertSame(1, $meterProvider->getMeterCalls);
        $this->assertSame(1, $meterProvider->meter->histogram(EvolveSemanticConventions::METRIC_EXECUTION_DURATION)->createCalls);
        $this->assertSame(1, $meterProvider->meter->counter(EvolveSemanticConventions::METRIC_EXECUTION_COUNT)->createCalls);
        $this->assertSame(1, $meterProvider->meter->upDownCounter(EvolveSemanticConventions::METRIC_EXECUTION_ACTIVE)->createCalls);
        $this->assertSame(1, $meterProvider->meter->counter(EvolveSemanticConventions::METRIC_EXECUTION_FAILURES)->createCalls);
        $this->assertSame([0.125, 0.25], array_column($meterProvider->meter->histogram(EvolveSemanticConventions::METRIC_EXECUTION_DURATION)->records, 'amount'));
        $this->assertSame(0, $meterProvider->meter->upDownCounter(EvolveSemanticConventions::METRIC_EXECUTION_ACTIVE)->netAmount());
        $this->assertSame(1, array_sum(array_column($meterProvider->meter->counter(EvolveSemanticConventions::METRIC_EXECUTION_FAILURES)->records, 'amount')));
        $this->assertExecutionInstrumentUnits($meterProvider->meter);
        $this->assertSame(0, $this->activeExecutionCount($instrumentation));
        $this->assertMetricKeysAreClosed($meterProvider->meter);
    }

    public function testQuarantineMetricUsesKindOnlyAndDoesNotCreatePrimaryFailureMetric(): void
    {
        $meterProvider = new RecordingMeterProvider();
        $instrumentation = new ExecutionMetricsInstrumentation(new OpenTelemetryComposition(
            enabled: true,
            meterProvider: $meterProvider,
            resource: $this->resource(),
        ));
        $identifier = ExecutionIdentifier::generate();

        $instrumentation->observe(new Observation(ObservationType::QuarantineRequired, $identifier, ExecutionKind::WorkerTask));

        $this->assertCount(1, $meterProvider->meter->counter(EvolveSemanticConventions::METRIC_EXECUTION_QUARANTINES)->records);
        $this->assertSame(1, $meterProvider->meter->counter(EvolveSemanticConventions::METRIC_EXECUTION_QUARANTINES)->createCalls);
        $this->assertSame('{execution}', $meterProvider->meter->counter(EvolveSemanticConventions::METRIC_EXECUTION_QUARANTINES)->unit);
        $this->assertCount(0, $meterProvider->meter->counter(EvolveSemanticConventions::METRIC_EXECUTION_FAILURES)->records);
        $this->assertSame(
            [EvolveSemanticConventions::ATTRIBUTE_EXECUTION_KIND => 'worker_task'],
            $meterProvider->meter->counter(EvolveSemanticConventions::METRIC_EXECUTION_QUARANTINES)->records[0]['attributes'],
        );
    }

    public function testMetricWriteFailureLeavesLifecycleBalancedAndPropagatesAsInstrumentationFailureOnly(): void
    {
        $meterProvider = new RecordingMeterProvider();
        $meterProvider->meter->failOnHistogramRecord = true;
        $instrumentation = new ExecutionMetricsInstrumentation(
            new OpenTelemetryComposition(enabled: true, meterProvider: $meterProvider, resource: $this->resource()),
            (new SequenceClock(1_000_000_000, 1_500_000_000))(...),
        );

        $outcome = $this->execute($instrumentation, ExecutionKind::HttpRequest, static fn(): string => 'ok');

        $this->assertTrue($outcome->primarySucceeded());
        $this->assertTrue($outcome->instrumentationFailed());
        $this->assertFalse($outcome->requiresQuarantine());
        $this->assertSame(0, $meterProvider->meter->upDownCounter(EvolveSemanticConventions::METRIC_EXECUTION_ACTIVE)->netAmount());
        $this->assertSame(0, $this->activeExecutionCount($instrumentation));
    }

    public function testActiveIncrementFailureDoesNotCreateCompensatingDecrement(): void
    {
        $meterProvider = new RecordingMeterProvider();
        $meterProvider->meter->failOnPositiveUpDownCounterAdd = true;
        $instrumentation = new ExecutionMetricsInstrumentation(
            new OpenTelemetryComposition(enabled: true, meterProvider: $meterProvider, resource: $this->resource()),
            (new SequenceClock(1_000_000_000, 1_250_000_000))(...),
        );

        $outcome = $this->execute($instrumentation, ExecutionKind::HttpRequest, static fn(): string => 'ok');

        $this->assertTrue($outcome->primarySucceeded());
        $this->assertTrue($outcome->instrumentationFailed());
        $this->assertFalse($outcome->requiresQuarantine());
        $this->assertSame([1], array_column($meterProvider->meter->upDownCounter(EvolveSemanticConventions::METRIC_EXECUTION_ACTIVE)->failedRecords, 'amount'));
        $this->assertSame(0, $meterProvider->meter->upDownCounter(EvolveSemanticConventions::METRIC_EXECUTION_ACTIVE)->netAmount());
        $this->assertSame(0, $this->activeExecutionCount($instrumentation));
    }

    public function testCompletionClockFailureStillBalancesActiveWorkAndForgetsState(): void
    {
        $meterProvider = new RecordingMeterProvider();
        $instrumentation = new ExecutionMetricsInstrumentation(
            new OpenTelemetryComposition(enabled: true, meterProvider: $meterProvider, resource: $this->resource()),
            (new ThrowOnCallClock(1_000_000_000, 2))(...),
        );

        $outcome = $this->execute($instrumentation, ExecutionKind::HttpRequest, static fn(): string => 'ok');

        $this->assertTrue($outcome->primarySucceeded());
        $this->assertTrue($outcome->instrumentationFailed());
        $this->assertFalse($outcome->requiresQuarantine());
        $this->assertSame(0, $meterProvider->meter->upDownCounter(EvolveSemanticConventions::METRIC_EXECUTION_ACTIVE)->netAmount());
        $this->assertCount(1, $meterProvider->meter->counter(EvolveSemanticConventions::METRIC_EXECUTION_COUNT)->records);
        $this->assertCount(0, $meterProvider->meter->histogram(EvolveSemanticConventions::METRIC_EXECUTION_DURATION)->records);
        $this->assertSame(0, $this->activeExecutionCount($instrumentation));
    }

    private function execute(ExecutionMetricsInstrumentation $instrumentation, ExecutionKind $kind, callable $operation): ExecutionOutcome
    {
        $registry = new ServiceRegistry();
        $registry->freeze();

        return (new ExecutionOrchestrator($registry, $instrumentation))->execute($kind, $operation);
    }

    private function assertMetricKeysAreClosed(RecordingMeter $meter): void
    {
        foreach ($meter->allMeasurements() as $measurement) {
            $keys = array_keys($measurement['attributes']);
            sort($keys);
            $this->assertContains($keys, [
                [EvolveSemanticConventions::ATTRIBUTE_EXECUTION_KIND],
                [EvolveSemanticConventions::ATTRIBUTE_EXECUTION_KIND, EvolveSemanticConventions::ATTRIBUTE_EXECUTION_OUTCOME],
            ]);
            $this->assertArrayNotHasKey(EvolveSemanticConventions::ATTRIBUTE_EXECUTION_ID, $measurement['attributes']);
        }
    }

    private function resource(): ResourceInfo
    {
        return ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'observe-metrics-test']));
    }

    private function assertExecutionInstrumentUnits(RecordingMeter $meter): void
    {
        $this->assertSame('s', $meter->histogram(EvolveSemanticConventions::METRIC_EXECUTION_DURATION)->unit);
        $this->assertSame('{execution}', $meter->counter(EvolveSemanticConventions::METRIC_EXECUTION_COUNT)->unit);
        $this->assertSame('{execution}', $meter->upDownCounter(EvolveSemanticConventions::METRIC_EXECUTION_ACTIVE)->unit);
        $this->assertSame('{execution}', $meter->counter(EvolveSemanticConventions::METRIC_EXECUTION_FAILURES)->unit);
    }

    private function activeExecutionCount(ExecutionMetricsInstrumentation $instrumentation): int
    {
        $property = new \ReflectionProperty($instrumentation, 'executions');

        return count($property->getValue($instrumentation));
    }
}

final class SequenceClock
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

final class ThrowOnCallClock
{
    private int $calls = 0;

    /**
     * @param list<int> $ticks
     */
    public function __construct(private int $firstTick, private int $throwOnCall, private array $ticks = []) {}

    public function __invoke(): int
    {
        ++$this->calls;

        if ($this->calls === $this->throwOnCall) {
            throw new RuntimeException('clock failed');
        }

        return $this->calls === 1 ? $this->firstTick : array_shift($this->ticks) ?? $this->firstTick;
    }
}

final class RecordingMeterProvider implements MeterProviderInterface
{
    public int $getMeterCalls = 0;

    public RecordingMeter $meter;

    public function __construct()
    {
        $this->meter = new RecordingMeter();
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

final class RecordingMeter implements MeterInterface
{
    /**
     * @var array<string, RecordingCounter>
     */
    private array $counters = [];

    /**
     * @var array<string, RecordingHistogram>
     */
    private array $histograms = [];

    /**
     * @var array<string, RecordingUpDownCounter>
     */
    private array $upDownCounters = [];

    public bool $failOnHistogramRecord = false;

    public bool $failOnPositiveUpDownCounterAdd = false;

    public function batchObserve(callable $callback, AsynchronousInstrument $instrument, AsynchronousInstrument ...$instruments): ObservableCallbackInterface
    {
        return new NoopObservableCallback();
    }

    /**
     * @param array<mixed> $advisory
     */
    public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): CounterInterface
    {
        $counter = $this->counters[$name] ??= new RecordingCounter($name, $unit);
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
        $histogram = $this->histograms[$name] ??= new RecordingHistogram($name, $unit, $this);
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
        $counter = $this->upDownCounters[$name] ??= new RecordingUpDownCounter($name, $unit, $this);
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

    public function counter(string $name): RecordingCounter
    {
        return $this->counters[$name] ??= new RecordingCounter($name, null);
    }

    public function histogram(string $name): RecordingHistogram
    {
        return $this->histograms[$name] ??= new RecordingHistogram($name, null, $this);
    }

    public function upDownCounter(string $name): RecordingUpDownCounter
    {
        return $this->upDownCounters[$name] ??= new RecordingUpDownCounter($name, null, $this);
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

final class RecordingCounter implements CounterInterface
{
    public int $createCalls = 0;

    /**
     * @var list<array{amount: int|float, attributes: array<string, mixed>}>
     */
    public array $records = [];

    public function __construct(public string $name, public ?string $unit) {}

    /**
     * @param iterable<string, string|bool|float|int|array<mixed>|null> $attributes
     */
    public function add(float|int $amount, iterable $attributes = [], ContextInterface|false|null $context = null): void
    {
        $this->records[] = ['amount' => $amount, 'attributes' => is_array($attributes) ? $attributes : iterator_to_array($attributes)];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}

final class RecordingHistogram implements HistogramInterface
{
    public int $createCalls = 0;

    /**
     * @var list<array{amount: int|float, attributes: array<string, mixed>}>
     */
    public array $records = [];

    public function __construct(public string $name, public ?string $unit, private RecordingMeter $meter) {}

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

final class RecordingUpDownCounter implements UpDownCounterInterface
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

    public function __construct(public string $name, public ?string $unit, private RecordingMeter $meter) {}

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

final class NoopObservableCallback implements ObservableCallbackInterface
{
    public function detach(): void {}
}
