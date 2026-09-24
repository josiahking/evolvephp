<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit\Logging;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionCleanupFailed;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionContextAttacher;
use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionOutcome;
use Evolve\Observe\Exception\OpenTelemetryContextDetachFailed;
use Evolve\Observe\ExecutionTraceInstrumentation;
use Evolve\Observe\Logging\ExecutionLogCorrelationInstrumentation;
use Evolve\Observe\Logging\LogCorrelation;
use Evolve\Observe\OpenTelemetryComposition;
use OpenTelemetry\API\Logs\NoopLoggerProvider;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeyInterface;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\Context\ImplicitContextKeyedInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExecutionLogCorrelationInstrumentationTest extends TestCase
{
    public function testEnabledInstrumentationWithoutActiveStateReturnsEmptySnapshot(): void
    {
        $instrumentation = $this->instrumentation();

        $this->assertTrue($instrumentation->current()->isEmpty());
    }

    public function testDisabledInstrumentationIsInertAndDoesNotDisturbExistingContext(): void
    {
        $span = Span::wrap(SpanContext::create(str_repeat('a', 32), str_repeat('b', 16), TraceFlags::SAMPLED));
        $scope = $span->activate();

        try {
            $instrumentation = new ExecutionLogCorrelationInstrumentation(OpenTelemetryComposition::disabled());
            $context = new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest);
            $attachment = $instrumentation->attach($context);

            $this->assertTrue($instrumentation->current()->isEmpty());
            $this->assertSame($span->getContext()->getTraceId(), Span::getCurrent()->getContext()->getTraceId());
            $attachment->detach();
            $this->assertSame($span->getContext()->getTraceId(), Span::getCurrent()->getContext()->getTraceId());
        } finally {
            $scope->detach();
        }
    }

    public function testExecutionOnlyCorrelationIsAvailableDuringExecutionAndClearedAfterward(): void
    {
        $instrumentation = $this->instrumentation();
        $captured = null;

        $outcome = $this->execute([$instrumentation], static function (ExecutionContext $context) use ($instrumentation, &$captured): string {
            $captured = $instrumentation->current();

            self::assertSame($context->identifier()->value(), $captured->executionId());
            self::assertSame(ExecutionKind::HttpRequest->value, $captured->executionKind());
            self::assertNull($captured->traceId());
            self::assertNull($captured->spanId());
            self::assertNull($captured->traceFlags());

            return 'ok';
        });

        $this->assertTrue($outcome->primarySucceeded());
        $this->assertInstanceOf(LogCorrelation::class, $captured);
        $this->assertTrue($instrumentation->current()->isEmpty());
    }

    public function testTraceOnlyCorrelationUsesAlreadyActiveApplicationOwnedSpan(): void
    {
        $instrumentation = $this->instrumentation();
        $span = Span::wrap(SpanContext::create(str_repeat('1', 32), str_repeat('2', 16), TraceFlags::SAMPLED));
        $scope = $span->activate();

        try {
            $correlation = $instrumentation->current();

            $this->assertSame(str_repeat('1', 32), $correlation->traceId());
            $this->assertSame(str_repeat('2', 16), $correlation->spanId());
            $this->assertSame('01', $correlation->traceFlags());
            $this->assertNull($correlation->executionId());
            $this->assertNull($correlation->executionKind());
        } finally {
            $scope->detach();
        }
    }

    public function testTraceFlagsAreFormattedFromTheLowByteAsTwoHexCharacters(): void
    {
        $instrumentation = $this->instrumentation();
        $span = Span::wrap(SpanContext::create(str_repeat('1', 32), str_repeat('2', 16), 0x101));
        $scope = $span->activate();

        try {
            $this->assertSame('01', $instrumentation->current()->traceFlags());
        } finally {
            $scope->detach();
        }
    }

    public function testRepeatedExecutionsDoNotLeakExecutionCorrelation(): void
    {
        $instrumentation = $this->instrumentation();
        $firstId = null;
        $secondId = null;

        $this->execute([$instrumentation], static function (ExecutionContext $context) use ($instrumentation, &$firstId): void {
            $firstId = $instrumentation->current()->executionId();
            self::assertSame($context->identifier()->value(), $firstId);
        });
        $this->execute([$instrumentation], static function (ExecutionContext $context) use ($instrumentation, &$secondId): void {
            $secondId = $instrumentation->current()->executionId();
            self::assertSame($context->identifier()->value(), $secondId);
        });

        $this->assertNotSame($firstId, $secondId);
        $this->assertTrue($instrumentation->current()->isEmpty());
    }

    public function testFailedApplicationExecutionHasCorrelationDuringOperationAndLeavesNoStaleState(): void
    {
        $instrumentation = $this->instrumentation();
        $captured = null;
        $throwable = new RuntimeException('application failure');

        $outcome = $this->execute([$instrumentation], static function () use ($instrumentation, &$captured, $throwable): void {
            $captured = $instrumentation->current();

            throw $throwable;
        });

        $this->assertTrue($outcome->primaryFailed());
        $this->assertSame($throwable, $outcome->primaryThrowable());
        $this->assertInstanceOf(LogCorrelation::class, $captured);
        $this->assertNotNull($captured->executionId());
        $this->assertTrue($instrumentation->current()->isEmpty());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function attacherOrders(): iterable
    {
        yield 'logging then tracing' => ['logging-first'];
        yield 'tracing then logging' => ['tracing-first'];
    }

    #[DataProvider('attacherOrders')]
    public function testTraceAndExecutionCorrelationWorksForBothAttacherOrders(string $order): void
    {
        $logging = $this->instrumentation();
        [$tracing, $exporter] = $this->traceInstrumentation();
        $attachers = $order === 'logging-first' ? [$logging, $tracing] : [$tracing, $logging];
        $sinks = [$tracing];
        $captured = null;

        $outcome = $this->execute($attachers, static function (ExecutionContext $context) use ($logging, &$captured): void {
            $captured = $logging->current();

            self::assertSame($context->identifier()->value(), $captured->executionId());
            self::assertSame(ExecutionKind::HttpRequest->value, $captured->executionKind());
            self::assertTrue(Span::getCurrent()->getContext()->isValid());
            self::assertSame(Span::getCurrent()->getContext()->getTraceId(), $captured->traceId());
            self::assertSame(Span::getCurrent()->getContext()->getSpanId(), $captured->spanId());
            self::assertSame(sprintf('%02x', Span::getCurrent()->getContext()->getTraceFlags()), $captured->traceFlags());
        }, $sinks);

        $this->assertTrue($outcome->primarySucceeded());
        $this->assertCount(1, $exporter->getSpans());
        $this->assertTrue($logging->current()->isEmpty());
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testNonZeroDetachStatusThrowsOpenTelemetryContextDetachFailedDirectly(): void
    {
        $previousStorage = Context::storage();
        Context::setStorage(new ControlledContextStorage(ScopeInterface::MISMATCH));

        try {
            $instrumentation = $this->instrumentation();
            $attachment = $instrumentation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));

            $this->expectException(OpenTelemetryContextDetachFailed::class);
            $this->expectExceptionMessage('OpenTelemetry context detach failed.');

            $attachment->detach();
        } finally {
            Context::setStorage($previousStorage);
        }
    }

    public function testDetachFailurePreservesCoreCleanupQuarantineSemantics(): void
    {
        $previousStorage = Context::storage();
        Context::setStorage(new ControlledContextStorage(ScopeInterface::MISMATCH));

        try {
            $instrumentation = $this->instrumentation();
            $outcome = $this->execute([$instrumentation], static fn(): string => 'ok');
        } finally {
            Context::setStorage($previousStorage);
        }

        $this->assertTrue($outcome->primarySucceeded());
        $this->assertTrue($outcome->cleanupFailed());
        $this->assertTrue($outcome->requiresQuarantine());
        $this->assertInstanceOf(ExecutionCleanupFailed::class, $outcome->cleanupThrowable());
        $this->assertInstanceOf(OpenTelemetryContextDetachFailed::class, $outcome->cleanupThrowable()->failures()[0]);
    }

    public function testAttachActivationFailureIsInstrumentationFailureWithoutCleanupQuarantineOrStaleCorrelation(): void
    {
        $previousStorage = Context::storage();
        Context::setStorage(new ControlledContextStorage(0, failAttach: true));
        $instrumentation = $this->instrumentation();
        $applicationRuns = 0;

        try {
            $outcome = $this->execute([$instrumentation], static function () use (&$applicationRuns): string {
                ++$applicationRuns;

                return 'ok';
            });
        } finally {
            Context::setStorage($previousStorage);
        }

        $this->assertSame(1, $applicationRuns);
        $this->assertTrue($outcome->primarySucceeded());
        $this->assertSame('ok', $outcome->primaryResult());
        $this->assertTrue($outcome->instrumentationFailed());
        $this->assertFalse($outcome->cleanupFailed());
        $this->assertFalse($outcome->requiresQuarantine());
        $this->assertTrue($instrumentation->current()->isEmpty());
    }

    public function testExecutionLookupFailureDoesNotThrowOrDiscardTraceCorrelation(): void
    {
        $previousStorage = Context::storage();
        $storage = new ControlledContextStorage(0);
        Context::setStorage($storage);
        $instrumentation = $this->instrumentation();
        $span = Span::wrap(SpanContext::create(str_repeat('1', 32), str_repeat('2', 16), TraceFlags::SAMPLED));
        $scope = $span->activate();

        try {
            $storage->failContextGetForNonSpanKeys();
            $correlation = $instrumentation->current();
        } finally {
            $scope->detach();
            Context::setStorage($previousStorage);
        }

        $this->assertSame(str_repeat('1', 32), $correlation->traceId());
        $this->assertSame(str_repeat('2', 16), $correlation->spanId());
        $this->assertSame('01', $correlation->traceFlags());
        $this->assertNull($correlation->executionId());
        $this->assertNull($correlation->executionKind());
    }

    public function testTraceLookupFailureDoesNotThrowOrDiscardExecutionCorrelation(): void
    {
        $previousStorage = Context::storage();
        $storage = new ControlledContextStorage(0);
        Context::setStorage($storage);
        $instrumentation = $this->instrumentation();
        $context = new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest);
        $attachment = $instrumentation->attach($context);

        try {
            $storage->failContextGetForSpanKey();
            $correlation = $instrumentation->current();
        } finally {
            $storage->allowContextGet();
            $attachment->detach();
            Context::setStorage($previousStorage);
        }

        $this->assertNull($correlation->traceId());
        $this->assertNull($correlation->spanId());
        $this->assertNull($correlation->traceFlags());
        $this->assertSame($context->identifier()->value(), $correlation->executionId());
        $this->assertSame(ExecutionKind::HttpRequest->value, $correlation->executionKind());
    }

    private function instrumentation(): ExecutionLogCorrelationInstrumentation
    {
        return new ExecutionLogCorrelationInstrumentation(new OpenTelemetryComposition(
            enabled: true,
            loggerProvider: NoopLoggerProvider::getInstance(),
            resource: $this->resource(),
        ));
    }

    /**
     * @param list<ExecutionContextAttacher> $attachers
     * @param list<object>|null $observationSink
     */
    private function execute(array $attachers, callable $operation, ?array $observationSink = null): ExecutionOutcome
    {
        $registry = new ServiceRegistry();
        $registry->freeze();

        return (new ExecutionOrchestrator($registry, $observationSink, $attachers))->execute(ExecutionKind::HttpRequest, $operation);
    }

    /**
     * @return array{0: ExecutionTraceInstrumentation, 1: InMemoryExporter}
     */
    private function traceInstrumentation(): array
    {
        $exporter = new InMemoryExporter();
        $provider = new TracerProvider(new SimpleSpanProcessor($exporter), resource: $this->resource());

        return [
            new ExecutionTraceInstrumentation(new OpenTelemetryComposition(
                enabled: true,
                tracerProvider: $provider,
                resource: $this->resource(),
            )),
            $exporter,
        ];
    }

    private function resource(): ResourceInfo
    {
        return ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'observe-log-test']));
    }
}

final class ControlledContextStorage implements ContextStorageInterface, ExecutionContextAwareInterface
{
    private ContextInterface $current;

    private ?string $contextGetFailure = null;

    public function __construct(private int $detachStatus, private bool $failAttach = false)
    {
        $this->current = Context::getRoot();
    }

    public function scope(): ?ContextStorageScopeInterface
    {
        return null;
    }

    public function current(): ContextInterface
    {
        if ($this->contextGetFailure !== null) {
            return new ControlledFailingContext($this->current, $this->contextGetFailure);
        }

        return $this->current;
    }

    public function attach(ContextInterface $context): ContextStorageScopeInterface
    {
        if ($this->failAttach) {
            throw new RuntimeException('context attach failed');
        }

        $previous = $this->current;
        $this->current = $context;

        return new ControlledContextScope($context, $this, $previous, $this->detachStatus);
    }

    public function restore(ContextInterface $previous): void
    {
        $this->current = $previous;
    }

    public function failContextGetForSpanKey(): void
    {
        $this->contextGetFailure = 'span';
    }

    public function failContextGetForNonSpanKeys(): void
    {
        $this->contextGetFailure = 'non-span';
    }

    public function allowContextGet(): void
    {
        $this->contextGetFailure = null;
    }

    public function fork(int|string $id): void {}

    public function switch(int|string $id): void {}

    public function destroy(int|string $id): void {}
}

final class ControlledFailingContext implements ContextInterface
{
    public function __construct(private ContextInterface $inner, private string $failureMode) {}

    /**
     * @return ContextKeyInterface<mixed>
     */
    public static function createKey(string $key): ContextKeyInterface
    {
        return Context::createKey($key);
    }

    public static function getCurrent(): ContextInterface
    {
        return Context::getCurrent();
    }

    public function activate(): ScopeInterface
    {
        return $this->inner->activate();
    }

    public function with(ContextKeyInterface $key, $value): ContextInterface
    {
        return $this->inner->with($key, $value);
    }

    public function withContextValue(ImplicitContextKeyedInterface $value): ContextInterface
    {
        return $this->inner->withContextValue($value);
    }

    public function get(ContextKeyInterface $key)
    {
        $isSpanKey = $key === \OpenTelemetry\Context\ContextKeys::span();

        if (($this->failureMode === 'span' && $isSpanKey) || ($this->failureMode === 'non-span' && !$isSpanKey)) {
            throw new RuntimeException('context lookup failed');
        }

        return $this->inner->get($key);
    }
}

final class ControlledContextScope implements ContextStorageScopeInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    public function __construct(
        private ContextInterface $context,
        private ControlledContextStorage $storage,
        private ContextInterface $previous,
        private int $detachStatus,
    ) {}

    public function context(): ContextInterface
    {
        return $this->context;
    }

    public function detach(): int
    {
        $this->storage->restore($this->previous);

        return $this->detachStatus;
    }

    public function offsetExists($offset): bool
    {
        return array_key_exists((string) $offset, $this->values);
    }

    public function offsetGet($offset): mixed
    {
        return $this->values[(string) $offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        $this->values[(string) $offset] = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->values[(string) $offset]);
    }
}
