<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit;

use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Instrumentation\ObservationOutcome;
use Evolve\Observe\EvolveSemanticConventions;
use Evolve\Observe\MetricCardinalityPolicy;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MetricCardinalityPolicyTest extends TestCase
{
    public function testInfrastructureDimensionsAreClosed(): void
    {
        self::assertSame(['evolve.database.operation' => 'execute'], MetricCardinalityPolicy::databaseAttributes('execute'));
        self::assertSame(['evolve.cache.operation' => 'getMultiple'], MetricCardinalityPolicy::cacheAttributes('getMultiple'));
        self::assertSame(['evolve.storage.operation' => 'close'], MetricCardinalityPolicy::storageAttributes('close'));
        self::assertSame([HttpAttributes::HTTP_REQUEST_METHOD => HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER], MetricCardinalityPolicy::httpClientAttributes('secret-method'));
        self::assertSame(3, MetricCardinalityPolicy::databaseCardinalityBudget());
        self::assertSame(8, MetricCardinalityPolicy::cacheCardinalityBudget());
        self::assertSame(5, MetricCardinalityPolicy::storageCardinalityBudget());
        foreach (['execute', 'query', 'transaction'] as $operation) {
            self::assertSame(['evolve.database.operation' => $operation], MetricCardinalityPolicy::databaseAttributes($operation));
        }
        foreach (['get', 'set', 'delete', 'clear', 'getMultiple', 'setMultiple', 'deleteMultiple', 'has'] as $operation) {
            self::assertSame(['evolve.cache.operation' => $operation], MetricCardinalityPolicy::cacheAttributes($operation));
        }
        foreach (['put', 'open', 'read', 'delete', 'close'] as $operation) {
            self::assertSame(['evolve.storage.operation' => $operation], MetricCardinalityPolicy::storageAttributes($operation));
        }
        self::assertSame([HttpAttributes::HTTP_REQUEST_METHOD => 'GET'], MetricCardinalityPolicy::httpClientAttributes('get'));
    }

    #[DataProvider('infrastructurePolicies')]
    public function testInfrastructureOperationPoliciesRejectUnboundedValues(string $method): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MetricCardinalityPolicy::$method('private-token');
    }

    /** @return iterable<string, array{string}> */
    public static function infrastructurePolicies(): iterable
    {
        yield 'database' => ['databaseAttributes'];
        yield 'cache' => ['cacheAttributes'];
        yield 'storage' => ['storageAttributes'];
    }
    public function testQueueMetricsHaveExactlyTwoClosedRoleSeries(): void
    {
        $this->assertSame(2, MetricCardinalityPolicy::queueMessageCardinalityBudget());
        $this->assertSame(
            [EvolveSemanticConventions::ATTRIBUTE_QUEUE_ROLE => EvolveSemanticConventions::QUEUE_ROLE_PRODUCER],
            MetricCardinalityPolicy::queueProducerAttributes(),
        );
        $this->assertSame(
            [EvolveSemanticConventions::ATTRIBUTE_QUEUE_ROLE => EvolveSemanticConventions::QUEUE_ROLE_CONSUMER],
            MetricCardinalityPolicy::queueConsumerAttributes(),
        );
    }

    public function testPolicyIsFinalAndStateless(): void
    {
        $reflection = new ReflectionClass(MetricCardinalityPolicy::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertSame([], $reflection->getProperties());
    }

    public function testExecutionKindDimensionsUseAcceptedSemanticVocabulary(): void
    {
        $this->assertSame([EvolveSemanticConventions::ATTRIBUTE_EXECUTION_KIND => 'http_request'], MetricCardinalityPolicy::executionKindAttributes(ExecutionKind::HttpRequest));
        $this->assertSame([EvolveSemanticConventions::ATTRIBUTE_EXECUTION_KIND => 'queue_message'], MetricCardinalityPolicy::executionKindAttributes(ExecutionKind::QueueMessage));
        $this->assertSame([EvolveSemanticConventions::ATTRIBUTE_EXECUTION_KIND => 'scheduled_job'], MetricCardinalityPolicy::executionKindAttributes(ExecutionKind::ScheduledJob));
        $this->assertSame([EvolveSemanticConventions::ATTRIBUTE_EXECUTION_KIND => 'cli_command'], MetricCardinalityPolicy::executionKindAttributes(ExecutionKind::CliCommand));
        $this->assertSame([EvolveSemanticConventions::ATTRIBUTE_EXECUTION_KIND => 'worker_task'], MetricCardinalityPolicy::executionKindAttributes(ExecutionKind::WorkerTask));
    }

    public function testExecutionOutcomeDimensionsAreClosedToSucceededAndFailed(): void
    {
        $this->assertSame(
            [
                EvolveSemanticConventions::ATTRIBUTE_EXECUTION_KIND => 'http_request',
                EvolveSemanticConventions::ATTRIBUTE_EXECUTION_OUTCOME => EvolveSemanticConventions::OUTCOME_SUCCEEDED,
            ],
            MetricCardinalityPolicy::executionOutcomeAttributes(ExecutionKind::HttpRequest, ObservationOutcome::Succeeded),
        );
        $this->assertSame(
            [
                EvolveSemanticConventions::ATTRIBUTE_EXECUTION_KIND => 'http_request',
                EvolveSemanticConventions::ATTRIBUTE_EXECUTION_OUTCOME => EvolveSemanticConventions::OUTCOME_FAILED,
            ],
            MetricCardinalityPolicy::executionOutcomeAttributes(ExecutionKind::HttpRequest, ObservationOutcome::Failed),
        );
    }

    #[DataProvider('httpMethods')]
    public function testHttpMethodDimensionNormalizesToBoundedSemanticValues(string $method, string $expected): void
    {
        $this->assertSame(
            [HttpAttributes::HTTP_REQUEST_METHOD => $expected],
            MetricCardinalityPolicy::httpServerAttributes($method),
        );
    }

    public function testCardinalityBudgetsFollowFromClosedDimensions(): void
    {
        $this->assertSame(10, MetricCardinalityPolicy::executionOutcomeCardinalityBudget());
        $this->assertSame(5, MetricCardinalityPolicy::executionKindCardinalityBudget());
        $this->assertSame(10, MetricCardinalityPolicy::httpMethodCardinalityBudget());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function httpMethods(): iterable
    {
        yield 'uppercase recognised' => ['POST', 'POST'];
        yield 'case-normalised recognised' => ['get', 'GET'];
        yield 'unknown token' => ['CUSTOM-TOKEN', HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER];
        yield 'empty' => ['', HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER];
    }
}
