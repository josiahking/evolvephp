<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit;

use Evolve\Core\Execution\ExecutionKind;
use Evolve\Observe\EvolveSemanticConventions;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EvolveSemanticConventionsTest extends TestCase
{
    public function testConventionsClassIsFinalAndDeclarative(): void
    {
        $reflection = new ReflectionClass(EvolveSemanticConventions::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertSame([], $reflection->getProperties());
    }

    public function testExecutionSpanAndAttributeNamesUseEvolveNamespace(): void
    {
        $constants = $this->constants();

        $this->assertSame('evolve.execution', $constants['SPAN_NAME_EXECUTION']);
        $this->assertSame('evolve.execution.id', $constants['ATTRIBUTE_EXECUTION_ID']);
        $this->assertSame('evolve.execution.kind', $constants['ATTRIBUTE_EXECUTION_KIND']);
        $this->assertSame('evolve.execution.outcome', $constants['ATTRIBUTE_EXECUTION_OUTCOME']);

        foreach ([
            $constants['SPAN_NAME_EXECUTION'],
            $constants['ATTRIBUTE_EXECUTION_ID'],
            $constants['ATTRIBUTE_EXECUTION_KIND'],
            $constants['ATTRIBUTE_EXECUTION_OUTCOME'],
        ] as $name) {
            $this->assertStringStartsWith('evolve.', $name);
        }
    }

    public function testOutcomeVocabularyIsBounded(): void
    {
        $constants = $this->constants();

        $this->assertSame('succeeded', $constants['OUTCOME_SUCCEEDED']);
        $this->assertSame('failed', $constants['OUTCOME_FAILED']);
    }

    public function testExecutionKindValuesComeFromCoreBackedValues(): void
    {
        $constants = $this->constants();

        $this->assertSame(ExecutionKind::HttpRequest->value, $constants['EXECUTION_KIND_HTTP_REQUEST']);
        $this->assertSame(ExecutionKind::QueueMessage->value, $constants['EXECUTION_KIND_QUEUE_MESSAGE']);
        $this->assertSame(ExecutionKind::ScheduledJob->value, $constants['EXECUTION_KIND_SCHEDULED_JOB']);
        $this->assertSame(ExecutionKind::CliCommand->value, $constants['EXECUTION_KIND_CLI_COMMAND']);
        $this->assertSame(ExecutionKind::WorkerTask->value, $constants['EXECUTION_KIND_WORKER_TASK']);
    }

    public function testEventNamesAreStableEvolveConstants(): void
    {
        $constants = $this->constants();

        $this->assertSame('evolve.execution.handler_completed', $constants['EVENT_HANDLER_COMPLETED']);
        $this->assertSame('evolve.execution.scope_close_started', $constants['EVENT_SCOPE_CLOSE_STARTED']);
    }

    public function testStandardErrorTypeIsNotRedefinedAsCustomEvolveAttribute(): void
    {
        $this->assertSame('error.type', (new ReflectionClass(ErrorAttributes::class))->getConstant('ERROR_TYPE'));

        $values = array_values($this->constants());

        $this->assertNotContains(ErrorAttributes::ERROR_TYPE, $values);
    }

    /**
     * @return array<string, mixed>
     */
    private function constants(): array
    {
        return (new ReflectionClass(EvolveSemanticConventions::class))->getConstants();
    }
}
