<?php

declare(strict_types=1);

namespace Evolve\Observe\Mcp;

use Evolve\Observe\EvolveSemanticConventions as Names;
use Evolve\Observe\MetricCardinalityPolicy;
use Evolve\Observe\OpenTelemetryComposition;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

/** Consumes neutral, normalized fields supplied by explicit host wiring. */
final readonly class McpOperationInstrumentation
{
    public function __construct(private OpenTelemetryComposition $composition) {}

    /** @param array<string, mixed> $fields */
    public function record(array $fields): void
    {
        $attributes = MetricCardinalityPolicy::mcpOperationAttributes($fields);
        if (!$this->composition->isEnabled()) {
            return;
        }
        if ($this->composition->tracerProvider() !== null) {
            $span = null;
            try {
                $builder = $this->composition->tracerProvider()->getTracer('evolvephp/observe')->spanBuilder(Names::SPAN_NAME_MCP_OPERATION);
                foreach ($attributes as $name => $value) {
                    $builder->setAttribute($name, $value);
                }
                foreach (['capability_hash', 'correlation', 'failure_category'] as $key) {
                    if (isset($fields[$key])) {
                        $builder->setAttribute('evolve.mcp.' . $key, $fields[$key]);
                    }
                }
                $span = $builder->startSpan();
                if ($fields['outcome'] !== 'success') {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }
            } catch (Throwable) {
                // Export is secondary to MCP behavior.
            } finally {
                try {
                    $span?->end();
                } catch (Throwable) {
                }
            }
        }
        if ($this->composition->loggerProvider() !== null) {
            try {
                $record = $this->composition->loggerProvider()
                    ->getLogger('evolvephp/observe')
                    ->logRecordBuilder()
                    ->setEventName(Names::SPAN_NAME_MCP_OPERATION)
                    ->setAttributes($attributes);
                if (isset($fields['correlation'])) {
                    $record->setAttribute('evolve.mcp.correlation', $fields['correlation']);
                }
                if (isset($fields['failure_category'])) {
                    $record->setAttribute('evolve.mcp.failure_category', $fields['failure_category']);
                }
                $record->emit();
            } catch (Throwable) {
                // Log export cannot affect MCP behavior.
            }
        }
        if ($this->composition->meterProvider() !== null) {
            try {
                $meter = $this->composition->meterProvider()->getMeter('evolvephp/observe');
                $meter->createCounter(Names::METRIC_MCP_OPERATION_COUNT, '{operation}')->add(1, $attributes);
                if (isset($fields['duration_ns'])) {
                    $meter->createHistogram(Names::METRIC_MCP_OPERATION_DURATION, 's')->record($fields['duration_ns'] / 1_000_000_000, $attributes);
                }
            } catch (Throwable) {
                // Export is secondary to MCP behavior.
            }
        }
    }
}
