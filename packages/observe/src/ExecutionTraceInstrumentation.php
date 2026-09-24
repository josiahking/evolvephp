<?php

declare(strict_types=1);

namespace Evolve\Observe;

use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionContextAttacher;
use Evolve\Core\Execution\ExecutionContextAttachment;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationOutcome;
use Evolve\Core\Instrumentation\ObservationSink;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Observe\Exception\OpenTelemetryContextDetachFailed;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;

final class ExecutionTraceInstrumentation implements ExecutionContextAttacher, ObservationSink
{
    private const INSTRUMENTATION_NAME = 'evolvephp/observe';

    /**
     * @var array<string, SpanInterface>
     */
    private array $spans = [];

    /**
     * @var array<string, true>
     */
    private array $ended = [];

    public function __construct(private OpenTelemetryComposition $composition) {}

    public function attach(ExecutionContext $context): ExecutionContextAttachment
    {
        if (!$this->composition->isEnabled() || $this->composition->tracerProvider() === null) {
            return new class implements ExecutionContextAttachment {
                public function detach(): void {}
            };
        }

        $identifier = $context->identifier()->value();
        $span = $this->composition
            ->tracerProvider()
            ->getTracer(self::INSTRUMENTATION_NAME)
            ->spanBuilder(EvolveSemanticConventions::SPAN_NAME_EXECUTION)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttributes([
                EvolveSemanticConventions::ATTRIBUTE_EXECUTION_ID => $identifier,
                EvolveSemanticConventions::ATTRIBUTE_EXECUTION_KIND => $context->kind()->value,
            ])
            ->startSpan();

        try {
            $scope = $span->activate();
        } catch (\Throwable $exception) {
            $span->end();

            throw $exception;
        }

        $this->spans[$identifier] = $span;
        unset($this->ended[$identifier]);

        return $this->attachment($identifier, static fn(): int => $scope->detach());
    }

    public function observe(Observation $observation): void
    {
        $identifier = $observation->identifier()->value();

        if (!isset($this->spans[$identifier]) || isset($this->ended[$identifier])) {
            return;
        }

        match ($observation->type()) {
            ObservationType::HandlerCompleted => $this->observeHandlerCompleted($identifier, $observation),
            ObservationType::ScopeCloseStarted => $this->observeScopeCloseStarted($identifier),
            default => null,
        };
    }

    private function observeHandlerCompleted(string $identifier, Observation $observation): void
    {
        $span = $this->spans[$identifier];
        $outcome = $observation->outcome() === ObservationOutcome::Failed
            ? EvolveSemanticConventions::OUTCOME_FAILED
            : EvolveSemanticConventions::OUTCOME_SUCCEEDED;

        $span->addEvent(EvolveSemanticConventions::EVENT_HANDLER_COMPLETED);
        $span->setAttribute(EvolveSemanticConventions::ATTRIBUTE_EXECUTION_OUTCOME, $outcome);

        if ($outcome !== EvolveSemanticConventions::OUTCOME_FAILED) {
            return;
        }

        $errorType = $observation->errorType();

        if ($errorType !== null) {
            $span->setAttribute(ErrorAttributes::ERROR_TYPE, $errorType);
        }

        $span->setStatus(StatusCode::STATUS_ERROR);
    }

    private function observeScopeCloseStarted(string $identifier): void
    {
        $span = $this->spans[$identifier];
        $span->addEvent(EvolveSemanticConventions::EVENT_SCOPE_CLOSE_STARTED);
        $span->end();
        $this->ended[$identifier] = true;
    }

    /**
     * @param \Closure(): int $detach
     */
    private function attachment(string $identifier, \Closure $detach): ExecutionContextAttachment
    {
        return new class ($detach, function () use ($identifier): void {
            unset($this->spans[$identifier], $this->ended[$identifier]);
        }) implements ExecutionContextAttachment {
            /**
             * @param \Closure(): int $detach
             * @param \Closure(): void $forget
             */
            public function __construct(
                private \Closure $detach,
                private \Closure $forget,
            ) {}

            public function detach(): void
            {
                try {
                    $status = ($this->detach)();
                } finally {
                    ($this->forget)();
                }

                if ($status !== 0) {
                    throw new OpenTelemetryContextDetachFailed($status);
                }
            }
        };
    }
}
