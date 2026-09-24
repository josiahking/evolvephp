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
