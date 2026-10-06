<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit\Queue;

use Evolve\Observe\EvolveSemanticConventions as Names;
use Evolve\Observe\OpenTelemetryComposition;
use Evolve\Observe\Queue\QueuePublisherInstrumentation;
use Evolve\Observe\Tests\Unit\RecordingMeterProvider;
use Evolve\Observe\Tests\Unit\SequenceClock;
use Evolve\Queue\Contracts\Exception\QueueException;
use Evolve\Queue\Contracts\MessageEnvelope;
use Evolve\Queue\Contracts\QueueFailureCategory;
use Evolve\Queue\Contracts\QueueName;
use Evolve\Queue\Contracts\QueueOperation;
use Evolve\Queue\Contracts\QueuePublisher;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueuePublisherInstrumentationTest extends TestCase
{
    public function testDisabledPassesExactEnvelopeOnce(): void
    {
        $message = new MessageEnvelope('opaque', ['baggage' => 'private']);
        $queue = new QueueName('opaque-queue');
        $publisher = new class implements QueuePublisher {
            public int $calls = 0;
            public ?MessageEnvelope $received = null;
            public function publish(QueueName $queue, MessageEnvelope $message): void
            {
                ++$this->calls;
                $this->received = $message;
            }
        };

        (new QueuePublisherInstrumentation(OpenTelemetryComposition::disabled(), $publisher))->publish($queue, $message);

        self::assertSame(1, $publisher->calls);
        self::assertSame($message, $publisher->received);
    }

    public function testDisabledPreservesExactPublisherFailure(): void
    {
        $failure = new RuntimeException('private failure');
        $publisher = new class ($failure) implements QueuePublisher {
            public function __construct(private RuntimeException $failure) {}
            public function publish(QueueName $queue, MessageEnvelope $message): void
            {
                throw $this->failure;
            }
        };

        try {
            (new QueuePublisherInstrumentation(OpenTelemetryComposition::disabled(), $publisher))
                ->publish(new QueueName('opaque-queue'), new MessageEnvelope('opaque'));
            self::fail('Expected publisher failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }
    public function testEnabledProducerUsesExplicitContextAndClosedMetrics(): void
    {
        $exporter = new InMemoryExporter();
        $meters = new RecordingMeterProvider();
        $resource = ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'queue-producer-test']));
        $composition = new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new TracerProvider(new SimpleSpanProcessor($exporter), resource: $resource),
            meterProvider: $meters,
            resource: $resource,
        );
        $message = new MessageEnvelope("opaque\0payload", [
            'TRACEPARENT' => 'stale',
            'TraceState' => 'stale',
            'baggage' => 'private=value',
            'custom' => 'secret',
        ]);
        $publisher = new class implements QueuePublisher {
            public ?MessageEnvelope $received = null;
            public bool $sawActiveSpan = false;
            public function publish(QueueName $queue, MessageEnvelope $message): void
            {
                $this->received = $message;
                $this->sawActiveSpan = Span::getCurrent()->getContext()->isValid();
            }
        };

        (new QueuePublisherInstrumentation(
            $composition,
            $publisher,
            (new SequenceClock(1_000_000_000, 1_250_000_000))(...),
        ))->publish(new QueueName('private-queue'), $message);

        self::assertFalse($publisher->sawActiveSpan);
        self::assertNotSame($message, $publisher->received);
        self::assertSame($message->payload(), $publisher->received->payload());
        self::assertSame('private=value', $publisher->received->metadata()['baggage']);
        self::assertSame('secret', $publisher->received->metadata()['custom']);
        self::assertArrayNotHasKey('TRACEPARENT', $publisher->received->metadata());
        self::assertArrayNotHasKey('TraceState', $publisher->received->metadata());
        self::assertArrayHasKey('traceparent', $publisher->received->metadata());
        self::assertCount(1, $exporter->getSpans());
        self::assertSame(Names::SPAN_NAME_QUEUE_PRODUCE, $exporter->getSpans()[0]->getName());
        self::assertSame(SpanKind::KIND_PRODUCER, $exporter->getSpans()[0]->getKind());
        self::assertSame(0.25, $meters->meter->histogram(Names::METRIC_QUEUE_MESSAGE_DURATION)->records[0]['amount']);
        self::assertSame([Names::ATTRIBUTE_QUEUE_ROLE => Names::QUEUE_ROLE_PRODUCER], $meters->meter->counter(Names::METRIC_QUEUE_MESSAGE_COUNT)->records[0]['attributes']);
        self::assertSame([], $meters->meter->counter(Names::METRIC_QUEUE_MESSAGE_FAILURES)->records);
    }

    public function testProducerFailureIsRethrownAfterBoundedErrorAndFailureMetric(): void
    {
        $exporter = new InMemoryExporter();
        $meters = new RecordingMeterProvider();
        $meters->meter->failOnHistogramRecord = true;
        $resource = ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'queue-producer-test']));
        $composition = new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new TracerProvider(new SimpleSpanProcessor($exporter), resource: $resource),
            meterProvider: $meters,
            resource: $resource,
        );
        $failure = new RuntimeException('private exception message');
        $publisher = new class ($failure) implements QueuePublisher {
            public function __construct(private RuntimeException $failure) {}
            public function publish(QueueName $queue, MessageEnvelope $message): void
            {
                throw $this->failure;
            }
        };

        try {
            (new QueuePublisherInstrumentation($composition, $publisher))
                ->publish(new QueueName('private-queue'), new MessageEnvelope('private-payload'));
            self::fail('Expected publisher failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(RuntimeException::class, $exporter->getSpans()[0]->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        self::assertSame([Names::ATTRIBUTE_QUEUE_ROLE => Names::QUEUE_ROLE_PRODUCER], $meters->meter->counter(Names::METRIC_QUEUE_MESSAGE_FAILURES)->records[0]['attributes']);
        self::assertCount(1, $meters->meter->counter(Names::METRIC_QUEUE_MESSAGE_COUNT)->records);
        self::assertStringNotContainsString('private exception message', json_encode($exporter->getSpans()[0]->getAttributes()->toArray()));
    }
    public function testTraceSetupFailureFallsBackToExactOriginalMessage(): void
    {
        $provider = $this->createStub(TracerProviderInterface::class);
        $provider->method('getTracer')->willThrowException(new RuntimeException('telemetry unavailable'));
        $resource = ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'queue-producer-test']));
        $composition = new OpenTelemetryComposition(enabled: true, tracerProvider: $provider, resource: $resource);
        $message = new MessageEnvelope('opaque', ['TRACEPARENT' => 'unchanged']);
        $publisher = new class implements QueuePublisher {
            public int $calls = 0;
            public ?MessageEnvelope $received = null;
            public function publish(QueueName $queue, MessageEnvelope $message): void
            {
                ++$this->calls;
                $this->received = $message;
            }
        };

        (new QueuePublisherInstrumentation($composition, $publisher))->publish(new QueueName('private'), $message);
        self::assertSame(1, $publisher->calls);
        self::assertSame($message, $publisher->received);
    }

    public function testQueueExceptionRecordsOnlySafeClassAndBoundedCategory(): void
    {
        $exporter = new InMemoryExporter();
        $resource = ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'queue-producer-test']));
        $composition = new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new TracerProvider(new SimpleSpanProcessor($exporter), resource: $resource),
            resource: $resource,
        );
        $failure = new class extends RuntimeException implements QueueException {
            public function operation(): QueueOperation
            {
                return QueueOperation::Publish;
            }
            public function category(): QueueFailureCategory
            {
                return QueueFailureCategory::Transport;
            }
        };
        $publisher = new class ($failure) implements QueuePublisher {
            public function __construct(private QueueException $failure) {}
            public function publish(QueueName $queue, MessageEnvelope $message): void
            {
                throw $this->failure;
            }
        };
        try {
            (new QueuePublisherInstrumentation($composition, $publisher))->publish(new QueueName('private'), new MessageEnvelope('opaque'));
            self::fail('Expected queue failure.');
        } catch (QueueException $caught) {
            self::assertSame($failure, $caught);
        }
        $attributes = $exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertSame($failure::class, $attributes[ErrorAttributes::ERROR_TYPE]);
        self::assertSame(QueueFailureCategory::Transport->value, $attributes[Names::ATTRIBUTE_QUEUE_FAILURE_CATEGORY]);
        self::assertCount(3, $attributes);
    }
    public function testDurationClockSurroundsOnlyDelegatedPublicationAfterTraceSetup(): void
    {
        $events = [];
        $ticks = [1_000_000_000, 1_200_000_000];
        $clock = static function () use (&$events, &$ticks): int {
            $events[] = count($ticks) === 2 ? 'clock start' : 'clock end';

            return array_shift($ticks) ?? 0;
        };
        $span = $this->createStub(SpanInterface::class);
        $span->method('storeInContext')->willReturnCallback(
            static function (ContextInterface $context) use (&$events): ContextInterface {
                $events[] = 'trace context';

                return $context;
            },
        );
        $span->method('end')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'span end';
        });
        $builder = $this->createStub(SpanBuilderInterface::class);
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
        $resource = ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'queue-timing-test']));
        $composition = new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: $provider,
            meterProvider: $meters,
            resource: $resource,
        );
        $publisher = new class implements QueuePublisher {
            public \Closure $record;
            public function publish(QueueName $queue, MessageEnvelope $message): void
            {
                ($this->record)();
            }
        };
        $publisher->record = static function () use (&$events): void {
            $events[] = 'publish';
        };

        (new QueuePublisherInstrumentation($composition, $publisher, $clock))
            ->publish(new QueueName('private'), new MessageEnvelope('opaque'));

        self::assertSame(
            ['trace start', 'trace context', 'clock start', 'publish', 'clock end', 'span end'],
            $events,
        );
        self::assertSame(0.2, $meters->meter->histogram(Names::METRIC_QUEUE_MESSAGE_DURATION)->records[0]['amount']);
    }

    public function testMeterOnlyProducerStillMeasuresDelegatedPublication(): void
    {
        $meters = new RecordingMeterProvider();
        $resource = ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'queue-meter-only-test']));
        $composition = new OpenTelemetryComposition(enabled: true, meterProvider: $meters, resource: $resource);
        $publisher = new class implements QueuePublisher {
            public int $calls = 0;
            public function publish(QueueName $queue, MessageEnvelope $message): void
            {
                ++$this->calls;
            }
        };

        (new QueuePublisherInstrumentation(
            $composition,
            $publisher,
            (new SequenceClock(1_000_000_000, 1_050_000_000))(...),
        ))->publish(new QueueName('private'), new MessageEnvelope('opaque'));

        self::assertSame(1, $publisher->calls);
        self::assertSame(0.05, $meters->meter->histogram(Names::METRIC_QUEUE_MESSAGE_DURATION)->records[0]['amount']);
    }
}
