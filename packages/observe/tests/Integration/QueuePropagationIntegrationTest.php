<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Integration;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Observe\EvolveSemanticConventions as Names;
use Evolve\Observe\ExecutionTraceInstrumentation;
use Evolve\Observe\OpenTelemetryComposition;
use Evolve\Observe\Queue\JobExecutionContextInstrumentation;
use Evolve\Observe\Queue\QueuePublisherInstrumentation;
use Evolve\Observe\Tests\Unit\RecordingMeterProvider;
use Evolve\Queue\Contracts\MessageEnvelope;
use Evolve\Queue\Contracts\QueueName;
use Evolve\Queue\Contracts\QueuePublisher;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use PHPUnit\Framework\TestCase;

final class QueuePropagationIntegrationTest extends TestCase
{
    public function testProducerConsumerCoreLineageAndSequentialIsolation(): void
    {
        $exporter = new InMemoryExporter();
        $meters = new RecordingMeterProvider();
        $resource = ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'queue-test']));
        $composition = new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new TracerProvider(new SimpleSpanProcessor($exporter), resource: $resource),
            meterProvider: $meters,
            resource: $resource,
        );
        $receiver = new class implements QueuePublisher {
            /** @var list<MessageEnvelope> */
            public array $messages = [];
            public function publish(QueueName $queue, MessageEnvelope $message): void
            {
                $this->messages[] = $message;
            }
        };
        $producer = new QueuePublisherInstrumentation($composition, $receiver);
        $consumer = new JobExecutionContextInstrumentation($composition);
        $trace = new ExecutionTraceInstrumentation($composition);
        $registry = new ServiceRegistry();
        $registry->freeze();
        $core = new ExecutionOrchestrator($registry, $trace, [$trace]);
        $queue = new QueueName('private-queue');
        $producer->publish($queue, new MessageEnvelope('private-payload', ['baggage' => 'private=value']));
        $first = $consumer->run(
            $receiver->messages[0],
            static fn() => $core->execute(ExecutionKind::QueueMessage, static fn() => 'ok'),
        );
        self::assertTrue($first->primarySucceeded());
        self::assertFalse(Span::getCurrent()->getContext()->isValid());

        $second = $consumer->run(
            new MessageEnvelope('different', ['baggage' => 'different=value']),
            static fn() => $core->execute(ExecutionKind::QueueMessage, static fn() => 'ok'),
        );
        self::assertTrue($second->primarySucceeded());
        self::assertFalse(Span::getCurrent()->getContext()->isValid());

        $spans = $exporter->getSpans();
        $byName = [];
        foreach ($spans as $span) {
            $byName[$span->getName()][] = $span;
            self::assertStringNotContainsString('private-queue', json_encode($span));
            self::assertStringNotContainsString('private-payload', json_encode($span));
            self::assertStringNotContainsString('private=value', json_encode($span));
        }
        self::assertCount(1, $byName[Names::SPAN_NAME_QUEUE_PRODUCE]);
        self::assertCount(2, $byName[Names::SPAN_NAME_QUEUE_CONSUME]);
        self::assertCount(2, $byName[Names::SPAN_NAME_EXECUTION]);
        self::assertSame($byName[Names::SPAN_NAME_QUEUE_PRODUCE][0]->getSpanId(), $byName[Names::SPAN_NAME_QUEUE_CONSUME][0]->getParentSpanId());
        self::assertSame($byName[Names::SPAN_NAME_QUEUE_CONSUME][0]->getSpanId(), $byName[Names::SPAN_NAME_EXECUTION][0]->getParentSpanId());
        self::assertSame(str_repeat('0', 16), $byName[Names::SPAN_NAME_QUEUE_CONSUME][1]->getParentSpanId());
        self::assertNotSame($byName[Names::SPAN_NAME_QUEUE_CONSUME][0]->getTraceId(), $byName[Names::SPAN_NAME_QUEUE_CONSUME][1]->getTraceId());
        self::assertSame(['baggage' => 'private=value', 'traceparent' => $receiver->messages[0]->metadata()['traceparent']], $receiver->messages[0]->metadata());

        $producerAttributes = [Names::ATTRIBUTE_QUEUE_ROLE => Names::QUEUE_ROLE_PRODUCER];
        $consumerAttributes = [Names::ATTRIBUTE_QUEUE_ROLE => Names::QUEUE_ROLE_CONSUMER];
        self::assertSame($producerAttributes, $meters->meter->counter(Names::METRIC_QUEUE_MESSAGE_COUNT)->records[0]['attributes']);
        self::assertSame($consumerAttributes, $meters->meter->counter(Names::METRIC_QUEUE_MESSAGE_COUNT)->records[1]['attributes']);
        self::assertSame($consumerAttributes, $meters->meter->counter(Names::METRIC_QUEUE_MESSAGE_COUNT)->records[2]['attributes']);
    }
}
