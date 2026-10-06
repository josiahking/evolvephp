<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit\Queue;

use Evolve\Observe\Queue\MessageEnvelopeTraceContext;
use Evolve\Queue\Contracts\MessageEnvelope;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\TestCase;

final class MessageEnvelopeTraceContextTest extends TestCase
{
    public function testInjectionCanonicalizesReservedKeysAndPreservesOpaqueData(): void
    {
        $payload = "opaque\0payload";
        $original = new MessageEnvelope($payload, [
            'TraceParent' => 'stale',
            'TRACEPARENT' => 'stale-again',
            'TraceState' => 'stale',
            'Baggage' => 'private=value',
            'X-Other' => "raw\0value",
        ]);
        $span = Span::wrap(SpanContext::create(
            str_repeat('a', 32),
            str_repeat('b', 16),
            1,
        ));
        $derived = (new MessageEnvelopeTraceContext())->inject(
            $original,
            $span->storeInContext(Context::getRoot()),
        );

        self::assertNotSame($original, $derived);
        self::assertSame($payload, $derived->payload());
        self::assertSame('stale', $original->metadata()['TraceParent']);
        self::assertSame('private=value', $derived->metadata()['Baggage']);
        self::assertSame("raw\0value", $derived->metadata()['X-Other']);
        self::assertSame('00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01', $derived->metadata()['traceparent']);
        self::assertArrayNotHasKey('TraceParent', $derived->metadata());
        self::assertArrayNotHasKey('TRACEPARENT', $derived->metadata());
        self::assertArrayNotHasKey('TraceState', $derived->metadata());
        self::assertArrayNotHasKey('tracestate', $derived->metadata());
        self::assertArrayNotHasKey('baggage', $derived->metadata());
    }

    public function testExtractionUsesOnlyTraceContextAndInvalidParentIsAbsent(): void
    {
        $bridge = new MessageEnvelopeTraceContext();
        $message = new MessageEnvelope('opaque', [
            'TRACEPARENT' => '00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01',
            'baggage' => 'secret=value',
        ]);
        $span = Span::fromContext($bridge->extract($message));

        self::assertSame(str_repeat('a', 32), $span->getContext()->getTraceId());
        self::assertSame(str_repeat('b', 16), $span->getContext()->getSpanId());
        self::assertTrue($span->getContext()->isRemote());
        self::assertFalse(Span::fromContext($bridge->extract(new MessageEnvelope('opaque', ['TraceParent' => 'invalid'])))->getContext()->isValid());
    }
    public function testValidTraceStateRoundTripsWithoutBaggagePropagation(): void
    {
        $bridge = new MessageEnvelopeTraceContext();
        $traceparent = '00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01';
        $source = new MessageEnvelope('opaque', [
            'TraceParent' => $traceparent,
            'TraceState' => 'vendor=value',
            'baggage' => 'private=source',
        ]);
        $target = new MessageEnvelope('target', [
            'TRACESTATE' => 'stale',
            'Baggage' => 'private=target',
        ]);

        $derived = $bridge->inject($target, $bridge->extract($source));

        self::assertSame('target', $derived->payload());
        self::assertSame($traceparent, $derived->metadata()['traceparent']);
        self::assertSame('vendor=value', $derived->metadata()['tracestate']);
        self::assertSame('private=target', $derived->metadata()['Baggage']);
        self::assertArrayNotHasKey('TRACESTATE', $derived->metadata());
        self::assertArrayNotHasKey('baggage', $derived->metadata());
    }
}
