<?php

declare(strict_types=1);

namespace Evolve\Observe\Logging;

use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionContextAttacher;
use Evolve\Core\Execution\ExecutionContextAttachment;
use Evolve\Observe\Exception\OpenTelemetryContextDetachFailed;
use Evolve\Observe\OpenTelemetryComposition;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextKeyInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class ExecutionLogCorrelationInstrumentation implements ExecutionContextAttacher, LogCorrelationProvider
{
    /**
     * @var ContextKeyInterface<array<string, mixed>>
     */
    private ContextKeyInterface $executionContextKey;

    public function __construct(private OpenTelemetryComposition $composition)
    {
        $this->executionContextKey = Context::createKey(self::class . '.execution');
    }

    public function attach(ExecutionContext $context): ExecutionContextAttachment
    {
        if (!$this->composition->isEnabled()) {
            return new class implements ExecutionContextAttachment {
                public function detach(): void {}
            };
        }

        $scope = Context::getCurrent()
            ->with($this->executionContextKey, [
                'id' => $context->identifier()->value(),
                'kind' => $context->kind()->value,
            ])
            ->activate();

        return new class ($scope) implements ExecutionContextAttachment {
            public function __construct(private ScopeInterface $scope) {}

            public function detach(): void
            {
                $status = $this->scope->detach();

                if ($status !== 0) {
                    throw new OpenTelemetryContextDetachFailed($status);
                }
            }
        };
    }

    public function current(): LogCorrelation
    {
        if (!$this->composition->isEnabled()) {
            return new LogCorrelation();
        }

        [$executionId, $executionKind] = $this->executionCorrelation();
        [$traceId, $spanId, $traceFlags] = $this->traceCorrelation();

        return new LogCorrelation(
            traceId: $traceId,
            spanId: $spanId,
            traceFlags: $traceFlags,
            executionId: $executionId,
            executionKind: $executionKind,
        );
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function executionCorrelation(): array
    {
        try {
            $value = Context::getCurrent()->get($this->executionContextKey);
        } catch (Throwable) {
            return [null, null];
        }

        if (!is_array($value)) {
            return [null, null];
        }

        $identifier = $value['id'] ?? null;
        $kind = $value['kind'] ?? null;

        return [
            is_string($identifier) ? $identifier : null,
            is_string($kind) ? $kind : null,
        ];
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function traceCorrelation(): array
    {
        try {
            $spanContext = Span::getCurrent()->getContext();

            if (!$spanContext->isValid()) {
                return [null, null, null];
            }

            return [
                strtolower($spanContext->getTraceId()),
                strtolower($spanContext->getSpanId()),
                sprintf('%02x', $spanContext->getTraceFlags() & 0xff),
            ];
        } catch (Throwable) {
            return [null, null, null];
        }
    }
}
