<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionCleanupFailed;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionOutcome;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationSink;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Observe\EvolveSemanticConventions;
use Evolve\Observe\Exception\OpenTelemetryContextDetachFailed;
use Evolve\Observe\ExecutionTraceInstrumentation;
use Evolve\Observe\OpenTelemetryComposition;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExecutionTraceInstrumentationTest extends TestCase
{
    public function testDisabledInstrumentationCreatesNoSpanOrContextState(): void
    {
        $instrumentation = new ExecutionTraceInstrumentation(OpenTelemetryComposition::disabled());

        $outcome = $this->execute($instrumentation, static function (): string {
            self::assertFalse(Span::getCurrent()->getContext()->isValid());

            return 'ok';
        });

        $this->assertTrue($outcome->primarySucceeded());
        $this->assertFalse($outcome->instrumentationFailed());
        $this->assertFalse($outcome->cleanupFailed());
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testSuccessfulCoreExecutionCreatesOneInternalExecutionSpan(): void
    {
        [$instrumentation, $exporter] = $this->instrumentationHarness();
        $capturedContext = null;

        $outcome = $this->execute($instrumentation, static function (ExecutionContext $context) use (&$capturedContext): string {
            $capturedContext = $context;
            self::assertTrue(Span::getCurrent()->getContext()->isValid());

            return 'ok';
        });

        $this->assertTrue($outcome->primarySucceeded());
        $span = $this->spanNamed($exporter, EvolveSemanticConventions::SPAN_NAME_EXECUTION);
        $attributes = $span->getAttributes();

        $this->assertSame(EvolveSemanticConventions::SPAN_NAME_EXECUTION, $span->getName());
        $this->assertSame(SpanKind::KIND_INTERNAL, $span->getKind());
        $this->assertSame($outcome->identifier()->value(), $attributes->get(EvolveSemanticConventions::ATTRIBUTE_EXECUTION_ID));
        $this->assertSame($outcome->identifier()->value(), $capturedContext->identifier()->value());
        $this->assertSame(ExecutionKind::HttpRequest->value, $attributes->get(EvolveSemanticConventions::ATTRIBUTE_EXECUTION_KIND));
        $this->assertSame(EvolveSemanticConventions::OUTCOME_SUCCEEDED, $attributes->get(EvolveSemanticConventions::ATTRIBUTE_EXECUTION_OUTCOME));
        $this->assertNotSame($outcome->identifier()->value(), $span->getTraceId());
        $this->assertNotSame($outcome->identifier()->value(), $span->getSpanId());
        $this->assertSame(
            [EvolveSemanticConventions::EVENT_HANDLER_COMPLETED, EvolveSemanticConventions::EVENT_SCOPE_CLOSE_STARTED],
            array_map(static fn($event): string => $event->getName(), $span->getEvents()),
        );
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testActiveOpenTelemetryContextIsInheritedAsParent(): void
    {
        [$instrumentation, $exporter, $provider] = $this->instrumentationHarness();
        $parent = $provider->getTracer('test-parent')->spanBuilder('parent')->startSpan();
        $scope = $parent->activate();

        try {
            $this->execute($instrumentation, static fn(): string => 'ok');
        } finally {
            $scope->detach();
            $parent->end();
        }

        $span = $this->spanNamed($exporter, EvolveSemanticConventions::SPAN_NAME_EXECUTION);

        $this->assertSame($parent->getContext()->getTraceId(), $span->getTraceId());
        $this->assertSame($parent->getContext()->getSpanId(), $span->getParentSpanId());
    }

    public function testHandlerFailureRecordsBoundedFailureTelemetryWithoutSensitiveExceptionData(): void
    {
        [$instrumentation, $exporter] = $this->instrumentationHarness();

        $outcome = $this->execute($instrumentation, static function (): void {
            throw new RuntimeException('secret-token-value');
        });

        $this->assertTrue($outcome->primaryFailed());
        $span = $this->singleSpan($exporter);
        $attributes = $span->getAttributes()->toArray();
        $events = $span->getEvents();

        $this->assertSame(EvolveSemanticConventions::OUTCOME_FAILED, $attributes[EvolveSemanticConventions::ATTRIBUTE_EXECUTION_OUTCOME]);
        $this->assertSame(RuntimeException::class, $attributes[ErrorAttributes::ERROR_TYPE]);
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertStringNotContainsString('secret-token-value', json_encode($attributes, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret-token-value', json_encode($events, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('exception.message', $attributes);
        $this->assertArrayNotHasKey('exception.stacktrace', $attributes);
    }

    public function testSpanEndsOnScopeCloseStartedBeforeAttachmentDetach(): void
    {
        [$instrumentation, $exporter] = $this->instrumentationHarness();
        $observer = new ScopeCloseStartedProbe($exporter);

        $this->execute($instrumentation, static fn(): string => 'ok', [$instrumentation, $observer]);

        $this->assertTrue($observer->sawEndedSpanBeforeDetach);
        $this->assertTrue($observer->sawActiveContextBeforeDetach);
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testNonZeroDetachStatusThrowsTypedCleanupFailureAndQuarantinesCore(): void
    {
        $provider = new FakeTracerProvider(new FakeSpan(new FakeScope(ScopeInterface::MISMATCH)));
        $instrumentation = new ExecutionTraceInstrumentation(new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: $provider,
            resource: $this->resource(),
        ));

        $outcome = $this->execute($instrumentation, static fn(): string => 'ok');

        $this->assertTrue($outcome->cleanupFailed());
        $this->assertTrue($outcome->requiresQuarantine());
        $this->assertInstanceOf(ExecutionCleanupFailed::class, $outcome->cleanupThrowable());
        $failure = $outcome->cleanupThrowable()->failures()[0];
        $this->assertInstanceOf(OpenTelemetryContextDetachFailed::class, $failure);
        $this->assertSame(ScopeInterface::MISMATCH, $failure->detachStatus());
        $this->assertSame('OpenTelemetry context detach failed.', $failure->getMessage());
        $this->assertSame([], $provider->span->recordedExceptions);
    }

    public function testAttachFailureIsNonFatalAndDoesNotCorruptLaterCleanExecution(): void
    {
        $exporter = new InMemoryExporter();
        $provider = new FirstActivationFailsThenSdkTracerProvider($this->sdkProvider($exporter));
        $instrumentation = new ExecutionTraceInstrumentation(new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: $provider,
            resource: $this->resource(),
        ));

        $first = $this->execute($instrumentation, static fn(): string => 'first');
        $instrumentation->observe(new Observation(ObservationType::HandlerCompleted, $first->identifier(), $first->kind()));
        $instrumentation->observe(new Observation(ObservationType::ScopeCloseStarted, $first->identifier(), $first->kind()));

        $second = $this->execute($instrumentation, static fn(): string => 'second');

        $this->assertTrue($first->primarySucceeded());
        $this->assertTrue($first->instrumentationFailed());
        $this->assertFalse($first->cleanupFailed());
        $this->assertTrue($second->primarySucceeded());
        $this->assertFalse($second->instrumentationFailed());
        $this->assertCount(1, $exporter->getSpans());
    }

    public function testRepeatedExecutionsStayIsolatedAndCoexistWithAnotherObservationConsumer(): void
    {
        [$instrumentation, $exporter] = $this->instrumentationHarness();
        $observer = new RecordingObservationSink();

        $first = $this->execute($instrumentation, static fn(): string => 'first', [$observer, $instrumentation]);
        $second = $this->execute($instrumentation, static function (): void {
            throw new RuntimeException('ordinary failure');
        }, [$observer, $instrumentation]);

        $spans = $exporter->getSpans();
        $this->assertCount(2, $spans);
        $this->assertNotSame($spans[0]->getSpanId(), $spans[1]->getSpanId());
        $this->assertNotSame($first->identifier()->value(), $second->identifier()->value());
        $this->assertContains(ObservationType::HandlerCompleted, $observer->types);
        $this->assertContains(ObservationType::ScopeCloseStarted, $observer->types);

        $instrumentation->observe(new Observation(ObservationType::ScopeCloseCompleted, $first->identifier(), $first->kind()));
        $instrumentation->observe(new Observation(ObservationType::QuarantineRequired, $first->identifier(), $first->kind()));
        $instrumentation->observe(new Observation(ObservationType::ExecutionCompleted, $first->identifier(), $first->kind()));

        $this->assertCount(2, $exporter->getSpans());
    }

    public function testExceptionApiIsFinalAndPreservesOnlyNumericDetachStatus(): void
    {
        $exception = new OpenTelemetryContextDetachFailed(ScopeInterface::DETACHED);

        $this->assertSame(ScopeInterface::DETACHED, $exception->detachStatus());
        $this->assertSame('OpenTelemetry context detach failed.', $exception->getMessage());
    }

    /**
     * @param ObservationSink|list<ObservationSink>|null $observationSink
     */
    private function execute(
        ExecutionTraceInstrumentation $instrumentation,
        callable $operation,
        ObservationSink|array|null $observationSink = null,
    ): ExecutionOutcome {
        $registry = new ServiceRegistry();
        $registry->freeze();

        return (new ExecutionOrchestrator(
            $registry,
            $observationSink ?? $instrumentation,
            [$instrumentation],
        ))->execute(ExecutionKind::HttpRequest, $operation);
    }

    /**
     * @return array{0: ExecutionTraceInstrumentation, 1: InMemoryExporter, 2: TracerProvider}
     */
    private function instrumentationHarness(): array
    {
        $exporter = new InMemoryExporter();
        $provider = $this->sdkProvider($exporter);

        return [
            new ExecutionTraceInstrumentation(new OpenTelemetryComposition(
                enabled: true,
                tracerProvider: $provider,
                resource: $this->resource(),
            )),
            $exporter,
            $provider,
        ];
    }

    private function sdkProvider(InMemoryExporter $exporter): TracerProvider
    {
        return new TracerProvider(new SimpleSpanProcessor($exporter), resource: $this->resource());
    }

    private function resource(): ResourceInfo
    {
        return ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'observe-test']));
    }

    private function singleSpan(InMemoryExporter $exporter): SpanDataInterface
    {
        $spans = $exporter->getSpans();

        $this->assertCount(1, $spans);

        return $spans[0];
    }

    private function spanNamed(InMemoryExporter $exporter, string $name): SpanDataInterface
    {
        $matches = array_values(array_filter(
            $exporter->getSpans(),
            static fn(SpanDataInterface $span): bool => $span->getName() === $name,
        ));

        $this->assertCount(1, $matches);

        return $matches[0];
    }
}

final class RecordingObservationSink implements ObservationSink
{
    /**
     * @var list<ObservationType>
     */
    public array $types = [];

    public function observe(Observation $observation): void
    {
        $this->types[] = $observation->type();
    }
}

final class ScopeCloseStartedProbe implements ObservationSink
{
    public bool $sawEndedSpanBeforeDetach = false;

    public bool $sawActiveContextBeforeDetach = false;

    public function __construct(private InMemoryExporter $exporter) {}

    public function observe(Observation $observation): void
    {
        if ($observation->type() !== ObservationType::ScopeCloseStarted) {
            return;
        }

        $this->sawEndedSpanBeforeDetach = count($this->exporter->getSpans()) === 1;
        $this->sawActiveContextBeforeDetach = Span::getCurrent()->getContext()->isValid();
    }
}

final class FakeTracerProvider implements TracerProviderInterface
{
    public function __construct(public FakeSpan $span) {}

    /**
     * @param iterable<mixed> $attributes
     */
    public function getTracer(string $name, ?string $version = null, ?string $schemaUrl = null, iterable $attributes = []): TracerInterface
    {
        return new FakeTracer($this->span);
    }
}

final class FakeTracer implements TracerInterface
{
    public function __construct(private FakeSpan $span) {}

    public function spanBuilder(string $spanName): SpanBuilderInterface
    {
        return new FakeSpanBuilder($this->span);
    }

    public function isEnabled(): bool
    {
        return true;
    }
}

final class FakeSpanBuilder implements SpanBuilderInterface
{
    public function __construct(private FakeSpan $span) {}

    public function setParent(ContextInterface|false|null $context): SpanBuilderInterface
    {
        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanBuilderInterface
    {
        return $this;
    }

    public function setAttribute(string $key, mixed $value): SpanBuilderInterface
    {
        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function setAttributes(iterable $attributes): SpanBuilderInterface
    {
        return $this;
    }

    public function setStartTimestamp(int $timestampNanos): SpanBuilderInterface
    {
        return $this;
    }

    public function setSpanKind(int $spanKind): SpanBuilderInterface
    {
        return $this;
    }

    public function startSpan(): SpanInterface
    {
        return $this->span;
    }
}

final class FakeSpan implements SpanInterface
{
    /**
     * @var list<object>
     */
    public array $recordedExceptions = [];

    public bool $ended = false;

    public function __construct(private ScopeInterface $scope, private bool $activationFails = false) {}

    public static function fromContext(ContextInterface $context): SpanInterface
    {
        return Span::fromContext($context);
    }

    public static function getCurrent(): SpanInterface
    {
        return Span::getCurrent();
    }

    public static function getInvalid(): SpanInterface
    {
        return Span::getInvalid();
    }

    public static function wrap(SpanContextInterface $spanContext): SpanInterface
    {
        return Span::wrap($spanContext);
    }

    public function activate(): ScopeInterface
    {
        if ($this->activationFails) {
            throw new RuntimeException('activation failed');
        }

        return $this->scope;
    }

    public function storeInContext(ContextInterface $context): ContextInterface
    {
        return $context;
    }

    public function getContext(): SpanContextInterface
    {
        return SpanContext::create(str_repeat('1', 32), str_repeat('2', 16), TraceFlags::SAMPLED);
    }

    public function isRecording(): bool
    {
        return true;
    }

    /**
     * @param bool|int|float|string|array<array-key, mixed>|null $value
     */
    public function setAttribute(string $key, bool|int|float|string|array|null $value): SpanInterface
    {
        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function setAttributes(iterable $attributes): SpanInterface
    {
        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanInterface
    {
        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function addEvent(string $name, iterable $attributes = [], ?int $timestamp = null): SpanInterface
    {
        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function recordException(\Throwable $exception, iterable $attributes = []): SpanInterface
    {
        $this->recordedExceptions[] = $exception;

        return $this;
    }

    public function updateName(string $name): SpanInterface
    {
        return $this;
    }

    public function setStatus(string $code, ?string $description = null): SpanInterface
    {
        return $this;
    }

    public function end(?int $endEpochNanos = null): void
    {
        $this->ended = true;
    }
}

final class FakeScope implements ScopeInterface
{
    public function __construct(private int $detachStatus = 0) {}

    public function detach(): int
    {
        return $this->detachStatus;
    }
}

final class FirstActivationFailsThenSdkTracerProvider implements TracerProviderInterface
{
    private bool $failed = false;

    public function __construct(private TracerProvider $delegate) {}

    /**
     * @param iterable<mixed> $attributes
     */
    public function getTracer(string $name, ?string $version = null, ?string $schemaUrl = null, iterable $attributes = []): TracerInterface
    {
        if (!$this->failed) {
            $this->failed = true;

            return new FakeTracer(new FakeSpan(new FakeScope(), activationFails: true));
        }

        return $this->delegate->getTracer($name, $version, $schemaUrl, $attributes);
    }
}
