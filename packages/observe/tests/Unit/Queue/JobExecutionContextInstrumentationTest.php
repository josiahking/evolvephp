<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit\Queue;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionOutcome;
use Evolve\Job\JobRunner;
use Evolve\Observe\EvolveSemanticConventions as Names;
use Evolve\Observe\Exception\OpenTelemetryContextDetachFailed;
use Evolve\Observe\OpenTelemetryComposition;
use Evolve\Observe\Queue\JobExecutionContextInstrumentation;
use Evolve\Observe\Tests\Unit\RecordingMeterProvider;
use Evolve\Observe\Tests\Unit\SequenceClock;
use Evolve\Queue\Contracts\Delivery;
use Evolve\Queue\Contracts\MessageEnvelope;
use Evolve\Queue\Contracts\QueueName;
use Evolve\Queue\Contracts\QueueReceiver;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JobExecutionContextInstrumentationTest extends TestCase
{
    public function testDisabledReturnsExactOutcomeAfterOneExecution(): void
    {
        $outcome = ExecutionOutcome::succeeded(ExecutionIdentifier::generate(), ExecutionKind::QueueMessage, null, null);
        $calls = 0;
        $actual = (new JobExecutionContextInstrumentation(OpenTelemetryComposition::disabled()))
            ->run(new MessageEnvelope('opaque', ['TraceParent' => 'invalid']), static function () use (&$calls, $outcome): ExecutionOutcome {
                ++$calls;
                return $outcome;
            });
        self::assertSame(1, $calls);
        self::assertSame($outcome, $actual);
    }

    public function testDisabledPreservesExactCoreFailure(): void
    {
        $failure = new RuntimeException('private');
        try {
            (new JobExecutionContextInstrumentation(OpenTelemetryComposition::disabled()))
                ->run(new MessageEnvelope('opaque'), static function () use ($failure): ExecutionOutcome {
                    throw $failure;
                });
            self::fail('Expected Core failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }
    public function testFailedOutcomeRetainsIdentityAndRecordsOnlyBoundedErrorAndMetrics(): void
    {
        $exporter = new InMemoryExporter();
        $meters = new RecordingMeterProvider();
        $resource = $this->resource();
        $composition = new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new TracerProvider(new SimpleSpanProcessor($exporter), resource: $resource),
            meterProvider: $meters,
            resource: $resource,
        );
        $failure = new RuntimeException('private exception message');
        $outcome = ExecutionOutcome::failed(ExecutionIdentifier::generate(), ExecutionKind::QueueMessage, $failure, null);
        $actual = (new JobExecutionContextInstrumentation(
            $composition,
            (new SequenceClock(1_000_000_000, 1_100_000_000))(...),
        ))->run(new MessageEnvelope('private payload'), static fn(): ExecutionOutcome => $outcome);

        self::assertSame($outcome, $actual);
        self::assertSame(Names::SPAN_NAME_QUEUE_CONSUME, $exporter->getSpans()[0]->getName());
        self::assertSame(RuntimeException::class, $exporter->getSpans()[0]->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        self::assertSame([Names::ATTRIBUTE_QUEUE_ROLE => Names::QUEUE_ROLE_CONSUMER], $meters->meter->histogram(Names::METRIC_QUEUE_MESSAGE_DURATION)->records[0]['attributes']);
        self::assertSame(0.1, $meters->meter->histogram(Names::METRIC_QUEUE_MESSAGE_DURATION)->records[0]['amount']);
        self::assertCount(1, $meters->meter->counter(Names::METRIC_QUEUE_MESSAGE_COUNT)->records);
        self::assertCount(1, $meters->meter->counter(Names::METRIC_QUEUE_MESSAGE_FAILURES)->records);
        self::assertStringNotContainsString('private exception message', json_encode($exporter->getSpans()[0]->getAttributes()->toArray()));
    }

    public function testTraceSetupFailureStillRunsCoreOnceAndPreservesIdentity(): void
    {
        $provider = $this->createStub(TracerProviderInterface::class);
        $provider->method('getTracer')->willThrowException(new RuntimeException('telemetry unavailable'));
        $composition = new OpenTelemetryComposition(enabled: true, tracerProvider: $provider, resource: $this->resource());
        $outcome = ExecutionOutcome::succeeded(ExecutionIdentifier::generate(), ExecutionKind::QueueMessage, null, null);
        $calls = 0;
        $actual = (new JobExecutionContextInstrumentation($composition))
            ->run(new MessageEnvelope('opaque'), static function () use (&$calls, $outcome): ExecutionOutcome {
                ++$calls;
                return $outcome;
            });
        self::assertSame(1, $calls);
        self::assertSame($outcome, $actual);
    }

    public function testCoreStartFailureIsPreservedWhenDetachSucceeds(): void
    {
        $resource = $this->resource();
        $exporter = new InMemoryExporter();
        $composition = new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new TracerProvider(new SimpleSpanProcessor($exporter), resource: $resource),
            resource: $resource,
        );
        $failure = new ExecutionStartFailed('private start failure');
        try {
            (new JobExecutionContextInstrumentation($composition))
                ->run(new MessageEnvelope('opaque'), static function () use ($failure): ExecutionOutcome {
                    throw $failure;
                });
            self::fail('Expected Core start failure.');
        } catch (ExecutionStartFailed $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(ExecutionStartFailed::class, $exporter->getSpans()[0]->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
    }

    public function testDetachFailureQuarantinesJobRunnerAndPreventsSettlement(): void
    {
        $scope = $this->createStub(ScopeInterface::class);
        $scope->method('detach')->willReturn(1);
        $span = $this->createStub(SpanInterface::class);
        $span->method('activate')->willReturn($scope);
        $builder = $this->createStub(SpanBuilderInterface::class);
        $builder->method('setParent')->willReturnSelf();
        $builder->method('setSpanKind')->willReturnSelf();
        $builder->method('setAttribute')->willReturnSelf();
        $builder->method('startSpan')->willReturn($span);
        $tracer = $this->createStub(TracerInterface::class);
        $tracer->method('spanBuilder')->willReturn($builder);
        $provider = $this->createStub(TracerProviderInterface::class);
        $provider->method('getTracer')->willReturn($tracer);
        $composition = new OpenTelemetryComposition(enabled: true, tracerProvider: $provider, resource: $this->resource());

        // Isolation failure takes precedence over a simultaneous Core start failure.
        $startFailure = new ExecutionStartFailed('private start failure');
        try {
            (new JobExecutionContextInstrumentation($composition))->run(
                new MessageEnvelope('opaque'),
                static function () use ($startFailure): ExecutionOutcome {
                    throw $startFailure;
                },
            );
            self::fail('Expected isolation failure.');
        } catch (OpenTelemetryContextDetachFailed $caught) {
            self::assertSame(1, $caught->detachStatus());
        }
        $delivery = new class implements Delivery {
            public int $settlements = 0;
            public function message(): MessageEnvelope
            {
                return new MessageEnvelope('opaque');
            }
            public function acknowledge(): void
            {
                ++$this->settlements;
            }
            public function reject(): void
            {
                ++$this->settlements;
            }
        };
        $receiver = new class ($delivery) implements QueueReceiver {
            public function __construct(private Delivery $delivery) {}
            public function receive(QueueName $queue): Delivery
            {
                return $this->delivery;
            }
        };
        $registry = new ServiceRegistry();
        $registry->freeze();
        $runner = new JobRunner(
            $receiver,
            new ExecutionOrchestrator($registry),
            new JobExecutionContextInstrumentation($composition),
        );

        try {
            $runner->runOnce(new QueueName('jobs'), static fn(): string => 'ok');
            self::fail('Expected isolation failure.');
        } catch (OpenTelemetryContextDetachFailed $caught) {
            self::assertSame(1, $caught->detachStatus());
        }
        self::assertSame(0, $delivery->settlements);
        $this->expectException(ExecutionStartFailed::class);
        $runner->runOnce(new QueueName('jobs'), static fn(): string => 'not reached');
    }

    private function resource(): ResourceInfo
    {
        return ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'queue-consumer-test']));
    }
    public function testMeterRecordingFailureDoesNotReplaceCoreFailure(): void
    {
        $meters = new RecordingMeterProvider();
        $meters->meter->failOnHistogramRecord = true;
        $composition = new OpenTelemetryComposition(
            enabled: true,
            meterProvider: $meters,
            resource: $this->resource(),
        );
        $failure = new RuntimeException('private Core failure');
        try {
            (new JobExecutionContextInstrumentation($composition))
                ->run(new MessageEnvelope('opaque'), static function () use ($failure): ExecutionOutcome {
                    throw $failure;
                });
            self::fail('Expected Core failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame([Names::ATTRIBUTE_QUEUE_ROLE => Names::QUEUE_ROLE_CONSUMER], $meters->meter->counter(Names::METRIC_QUEUE_MESSAGE_COUNT)->records[0]['attributes']);
        self::assertCount(1, $meters->meter->counter(Names::METRIC_QUEUE_MESSAGE_FAILURES)->records);
    }

    public function testSpanEndFailureDoesNotReplaceExactCoreOutcome(): void
    {
        $scope = $this->createStub(ScopeInterface::class);
        $scope->method('detach')->willReturn(0);
        $span = $this->createStub(SpanInterface::class);
        $span->method('activate')->willReturn($scope);
        $span->method('end')->willThrowException(new RuntimeException('telemetry end failure'));
        $builder = $this->createStub(SpanBuilderInterface::class);
        $builder->method('setParent')->willReturnSelf();
        $builder->method('setSpanKind')->willReturnSelf();
        $builder->method('setAttribute')->willReturnSelf();
        $builder->method('startSpan')->willReturn($span);
        $tracer = $this->createStub(TracerInterface::class);
        $tracer->method('spanBuilder')->willReturn($builder);
        $provider = $this->createStub(TracerProviderInterface::class);
        $provider->method('getTracer')->willReturn($tracer);
        $composition = new OpenTelemetryComposition(enabled: true, tracerProvider: $provider, resource: $this->resource());
        $outcome = ExecutionOutcome::succeeded(ExecutionIdentifier::generate(), ExecutionKind::QueueMessage, 'ok', null);
        $actual = (new JobExecutionContextInstrumentation($composition))
            ->run(new MessageEnvelope('opaque'), static fn(): ExecutionOutcome => $outcome);
        self::assertSame($outcome, $actual);
    }
    public function testDurationClockSurroundsOnlyCoreAfterConsumerActivation(): void
    {
        $events = [];
        $ticks = [1_000_000_000, 1_150_000_000];
        $clock = static function () use (&$events, &$ticks): int {
            $events[] = count($ticks) === 2 ? 'clock start' : 'clock end';

            return array_shift($ticks) ?? 0;
        };
        $scope = $this->createStub(ScopeInterface::class);
        $scope->method('detach')->willReturnCallback(static function () use (&$events): int {
            $events[] = 'detach';

            return 0;
        });
        $span = $this->createStub(SpanInterface::class);
        $span->method('activate')->willReturnCallback(static function () use (&$events, $scope): ScopeInterface {
            $events[] = 'trace activate';

            return $scope;
        });
        $span->method('end')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'span end';
        });
        $builder = $this->createStub(SpanBuilderInterface::class);
        $builder->method('setParent')->willReturnSelf();
        $builder->method('setSpanKind')->willReturnSelf();
        $builder->method('setAttribute')->willReturnSelf();
        $builder->method('startSpan')->willReturnCallback(static function () use (&$events, $span): SpanInterface {
            $events[] = 'trace start';

            return $span;
        });
        $tracer = $this->createStub(TracerInterface::class);
        $tracer->method('spanBuilder')->willReturn($builder);
        $provider = $this->createStub(TracerProviderInterface::class);
        $provider->method('getTracer')->willReturn($tracer);
        $meters = new RecordingMeterProvider();
        $composition = new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: $provider,
            meterProvider: $meters,
            resource: $this->resource(),
        );
        $outcome = ExecutionOutcome::succeeded(ExecutionIdentifier::generate(), ExecutionKind::QueueMessage, 'ok', null);
        $actual = (new JobExecutionContextInstrumentation($composition, $clock))->run(
            new MessageEnvelope('opaque'),
            static function () use (&$events, $outcome): ExecutionOutcome {
                $events[] = 'Core';

                return $outcome;
            },
        );

        self::assertSame($outcome, $actual);
        self::assertSame(
            ['trace start', 'trace activate', 'clock start', 'Core', 'clock end', 'span end', 'detach'],
            $events,
        );
        self::assertSame(0.15, $meters->meter->histogram(Names::METRIC_QUEUE_MESSAGE_DURATION)->records[0]['amount']);
    }
}
