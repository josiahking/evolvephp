<?php

declare(strict_types=1);

namespace Evolve\Observe\Queue;

use Evolve\Queue\Contracts\MessageEnvelope;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;

final class MessageEnvelopeTraceContext
{
    public function extract(MessageEnvelope $message): ContextInterface
    {
        return TraceContextPropagator::getInstance()->extract(
            $message->metadata(),
            new class implements PropagationGetterInterface {
                public function keys(mixed $carrier): array
                {
                    if (!is_array($carrier)) {
                        return [];
                    }

                    $keys = [];
                    foreach (array_keys($carrier) as $key) {
                        if (is_string($key)) {
                            $keys[] = $key;
                        }
                    }

                    return $keys;
                }

                public function get(mixed $carrier, string $key): ?string
                {
                    if (!is_array($carrier)) {
                        return null;
                    }
                    foreach ($carrier as $name => $value) {
                        if (strcasecmp($name, $key) === 0) {
                            return $value;
                        }
                    }

                    return null;
                }
            },
            Context::getRoot(),
        );
    }

    public function inject(MessageEnvelope $message, ContextInterface $context): MessageEnvelope
    {
        $metadata = [];
        foreach ($message->metadata() as $key => $value) {
            if (strcasecmp($key, TraceContextPropagator::TRACEPARENT) !== 0
                && strcasecmp($key, TraceContextPropagator::TRACESTATE) !== 0) {
                $metadata[$key] = $value;
            }
        }

        TraceContextPropagator::getInstance()->inject(
            $metadata,
            new class implements PropagationSetterInterface {
                public function set(mixed &$carrier, string $key, string $value): void
                {
                    if (is_array($carrier)) {
                        $carrier[$key] = $value;
                    }
                }
            },
            $context,
        );

        return new MessageEnvelope($message->payload(), $metadata);
    }
}
