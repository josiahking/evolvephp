<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Watcher;

use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ProcessReuseDecision;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationOutcome;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticDataClassification;
use Evolve\Insight\Watcher\ExecutionLifecycleDiagnosticWatcher;
use PHPUnit\Framework\TestCase;

final class ExecutionLifecycleDiagnosticWatcherTest extends TestCase
{
    public function testItEmitsHandlerFailureDiagnosticOnlyForFailedHandlerCompletion(): void
    {
        $identifier = ExecutionIdentifier::generate();
        $entries = $this->watcher()->watch(new Observation(
            ObservationType::HandlerCompleted,
            $identifier,
            ExecutionKind::HttpRequest,
            ObservationOutcome::Failed,
            \RuntimeException::class,
        ));

        self::assertCount(1, $entries);
        self::assertSame($identifier->value(), $entries[0]->executionIdentifier());
        self::assertSame('evolve.execution', $entries[0]->category());
        self::assertSame('handler-failed', $entries[0]->name());
        self::assertSame(
            array(
                'execution_kind' => 'http-request',
                'error_type' => \RuntimeException::class,
            ),
            $this->attributeValues($entries[0]->attributes()),
        );
        self::assertSame(
            array(
                'execution_kind' => DiagnosticDataClassification::PublicOperationalMetadata,
                'error_type' => DiagnosticDataClassification::InternalOperationalMetadata,
            ),
            $this->attributeClassifications($entries[0]->attributes()),
        );

        self::assertSame(array(), $this->watcher()->watch(new Observation(
            ObservationType::HandlerCompleted,
            $identifier,
            ExecutionKind::HttpRequest,
            ObservationOutcome::Succeeded,
        )));
    }

    public function testItEmitsScopeCloseFailureDiagnosticOnlyForFailedScopeCloseCompletion(): void
    {
        $identifier = ExecutionIdentifier::generate();
        $entries = $this->watcher()->watch(new Observation(
            ObservationType::ScopeCloseCompleted,
            $identifier,
            ExecutionKind::QueueMessage,
            ObservationOutcome::Failed,
            \LogicException::class,
            ProcessReuseDecision::QuarantineRequired,
        ));

        self::assertCount(1, $entries);
        self::assertSame('evolve.runtime', $entries[0]->category());
        self::assertSame('scope-close-failed', $entries[0]->name());
        self::assertSame(
            array(
                'execution_kind' => 'queue-message',
                'error_type' => \LogicException::class,
                'reuse_decision' => 'quarantine-required',
            ),
            $this->attributeValues($entries[0]->attributes()),
        );
    }

    public function testItEmitsQuarantineDiagnosticWithBoundedPrimitiveAttributes(): void
    {
        $identifier = ExecutionIdentifier::generate();
        $entries = $this->watcher()->watch(new Observation(
            ObservationType::QuarantineRequired,
            $identifier,
            ExecutionKind::WorkerTask,
            reuseDecision: ProcessReuseDecision::QuarantineRequired,
        ));

        self::assertCount(1, $entries);
        self::assertSame('evolve.runtime', $entries[0]->category());
        self::assertSame('quarantine-required', $entries[0]->name());
        self::assertSame(
            array(
                'execution_kind' => 'worker-task',
                'reuse_decision' => 'quarantine-required',
            ),
            $this->attributeValues($entries[0]->attributes()),
        );

        foreach ($entries[0]->attributes() as $attribute) {
            self::assertContains($attribute->classification(), array(
                DiagnosticDataClassification::PublicOperationalMetadata,
                DiagnosticDataClassification::InternalOperationalMetadata,
            ));
            self::assertContains(gettype($attribute->value()), array('string', 'integer', 'double', 'boolean', 'NULL'));
        }
    }

    public function testNormalAndUnrelatedObservationsProduceNoDiagnostics(): void
    {
        $identifier = ExecutionIdentifier::generate();
        $watcher = $this->watcher();

        foreach (array(
            ObservationType::ExecutionStarted,
            ObservationType::ScopeCloseStarted,
            ObservationType::ExecutionCompleted,
        ) as $type) {
            self::assertSame(array(), $watcher->watch(new Observation($type, $identifier, ExecutionKind::CliCommand)));
        }

        self::assertSame(array(), $watcher->watch(new Observation(
            ObservationType::ScopeCloseCompleted,
            $identifier,
            ExecutionKind::CliCommand,
            ObservationOutcome::Succeeded,
            reuseDecision: ProcessReuseDecision::Reusable,
        )));
    }

    private function watcher(): ExecutionLifecycleDiagnosticWatcher
    {
        return new ExecutionLifecycleDiagnosticWatcher();
    }

    /**
     * @param list<DiagnosticAttribute> $attributes
     *
     * @return array<string, string|int|float|bool|null>
     */
    private function attributeValues(array $attributes): array
    {
        $values = array();

        foreach ($attributes as $attribute) {
            $values[$attribute->name()] = $attribute->value();
        }

        return $values;
    }

    /**
     * @param list<DiagnosticAttribute> $attributes
     *
     * @return array<string, DiagnosticDataClassification>
     */
    private function attributeClassifications(array $attributes): array
    {
        $classifications = array();

        foreach ($attributes as $attribute) {
            $classifications[$attribute->name()] = $attribute->classification();
        }

        return $classifications;
    }
}
