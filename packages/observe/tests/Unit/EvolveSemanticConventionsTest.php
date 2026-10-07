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
    public function testInfrastructureVocabularyIsFixed(): void
    {
        $constants = $this->constants();
        foreach (['SPAN_NAME_DATABASE' => 'evolve.database', 'SPAN_NAME_CACHE' => 'evolve.cache', 'SPAN_NAME_STORAGE' => 'evolve.storage', 'SPAN_NAME_HTTP_CLIENT' => 'evolve.http.client', 'ATTRIBUTE_DATABASE_OPERATION' => 'evolve.database.operation', 'ATTRIBUTE_DATABASE_OPERATION_NAME' => 'evolve.database.operation_name', 'ATTRIBUTE_DATABASE_FAILURE_CATEGORY' => 'evolve.database.failure.category', 'ATTRIBUTE_DATABASE_SQLSTATE' => 'evolve.database.sqlstate', 'ATTRIBUTE_DATABASE_DRIVER' => 'evolve.database.driver', 'ATTRIBUTE_CACHE_OPERATION' => 'evolve.cache.operation', 'ATTRIBUTE_STORAGE_OPERATION' => 'evolve.storage.operation', 'ATTRIBUTE_STORAGE_FAILURE_CATEGORY' => 'evolve.storage.failure.category', 'METRIC_DATABASE_DURATION' => 'evolve.database.operation.duration', 'METRIC_DATABASE_COUNT' => 'evolve.database.operation.count', 'METRIC_DATABASE_FAILURES' => 'evolve.database.operation.failures', 'METRIC_CACHE_DURATION' => 'evolve.cache.operation.duration', 'METRIC_CACHE_COUNT' => 'evolve.cache.operation.count', 'METRIC_CACHE_FAILURES' => 'evolve.cache.operation.failures', 'METRIC_STORAGE_DURATION' => 'evolve.storage.operation.duration', 'METRIC_STORAGE_COUNT' => 'evolve.storage.operation.count', 'METRIC_STORAGE_FAILURES' => 'evolve.storage.operation.failures', 'METRIC_HTTP_CLIENT_DURATION' => 'evolve.http.client.request.duration', 'METRIC_HTTP_CLIENT_COUNT' => 'evolve.http.client.request.count', 'METRIC_HTTP_CLIENT_FAILURES' => 'evolve.http.client.request.failures'] as $name => $value) {
            self::assertSame($value, $constants[$name] ?? null, $name);
        }
    }

    public function testQueueVocabularyIsFixed(): void
    {
        $constants = $this->constants();
        $this->assertSame('evolve.queue.produce', $constants['SPAN_NAME_QUEUE_PRODUCE']);
        $this->assertSame('evolve.queue.consume', $constants['SPAN_NAME_QUEUE_CONSUME']);
        $this->assertSame('evolve.queue.role', $constants['ATTRIBUTE_QUEUE_ROLE']);
        $this->assertSame('evolve.queue.failure.category', $constants['ATTRIBUTE_QUEUE_FAILURE_CATEGORY']);
        $this->assertSame('producer', $constants['QUEUE_ROLE_PRODUCER']);
        $this->assertSame('consumer', $constants['QUEUE_ROLE_CONSUMER']);
        $this->assertSame('evolve.queue.message.duration', $constants['METRIC_QUEUE_MESSAGE_DURATION']);
        $this->assertSame('evolve.queue.message.count', $constants['METRIC_QUEUE_MESSAGE_COUNT']);
        $this->assertSame('evolve.queue.message.failures', $constants['METRIC_QUEUE_MESSAGE_FAILURES']);
    }

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

    public function testMetricNamesUseAcceptedStableVocabulary(): void
    {
        $constants = $this->constants();

        $this->assertSame('evolve.execution.duration', $constants['METRIC_EXECUTION_DURATION']);
        $this->assertSame('evolve.execution.count', $constants['METRIC_EXECUTION_COUNT']);
        $this->assertSame('evolve.execution.active', $constants['METRIC_EXECUTION_ACTIVE']);
        $this->assertSame('evolve.execution.failures', $constants['METRIC_EXECUTION_FAILURES']);
        $this->assertSame('evolve.execution.quarantines', $constants['METRIC_EXECUTION_QUARANTINES']);
        $this->assertSame('evolve.http.server.request.count', $constants['METRIC_HTTP_SERVER_REQUEST_COUNT']);
        $this->assertSame('evolve.http.server.active_requests', $constants['METRIC_HTTP_SERVER_ACTIVE_REQUESTS']);
        $this->assertSame('evolve.http.server.request.failures', $constants['METRIC_HTTP_SERVER_REQUEST_FAILURES']);
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
