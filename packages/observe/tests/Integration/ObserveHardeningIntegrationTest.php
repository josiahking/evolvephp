<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Integration;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionOutcome;
use Evolve\Observe\EvolveSemanticConventions;
use Evolve\Observe\ExecutionMetricsInstrumentation;
use Evolve\Observe\ExecutionTraceInstrumentation;
use Evolve\Observe\Logging\ExecutionLogCorrelationInstrumentation;
use Evolve\Observe\OpenTelemetryComposition;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ObserveHardeningIntegrationTest extends TestCase
{
    public function testSuccessiveExecutionsClearActiveSpanContextAndExecutionCorrelation(): void
    {
        $composition = $this->enabledComposition();
        $trace = new ExecutionTraceInstrumentation($composition);
        $correlation = new ExecutionLogCorrelationInstrumentation($composition);
        $metrics = new ExecutionMetricsInstrumentation($composition);

        $first = $this->execute($trace, $correlation, $metrics, static function (ExecutionContext $context) use ($correlation): void {
            self::assertSame($context->identifier()->value(), $correlation->current()->executionId());
            self::assertTrue(Span::getCurrent()->getContext()->isValid());
        });

        $this->assertTrue($first->primarySucceeded());
        $this->assertTrue($correlation->current()->isEmpty());
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());

        $second = $this->execute($trace, $correlation, $metrics, static function (ExecutionContext $context) use ($correlation): void {
            self::assertSame($context->identifier()->value(), $correlation->current()->executionId());
            self::assertTrue(Span::getCurrent()->getContext()->isValid());
        });

        $this->assertTrue($second->primarySucceeded());
        $this->assertTrue($correlation->current()->isEmpty());
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testApplicationFailureDoesNotCauseCleanupQuarantineAndDoesNotPolluteNextExecution(): void
    {
        $composition = $this->enabledComposition();
        $trace = new ExecutionTraceInstrumentation($composition);
        $correlation = new ExecutionLogCorrelationInstrumentation($composition);
        $metrics = new ExecutionMetricsInstrumentation($composition);
        $fail = new RuntimeException('application failure');

        $outcome = $this->execute($trace, $correlation, $metrics, static function () use ($fail): void {
            throw $fail;
        });

        $this->assertTrue($outcome->primaryFailed());
        $this->assertSame($fail, $outcome->primaryThrowable());
        $this->assertFalse($outcome->cleanupFailed());
        $this->assertFalse($outcome->requiresQuarantine());
        $this->assertTrue($correlation->current()->isEmpty());
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());

        $success = $this->execute($trace, $correlation, $metrics, static function (ExecutionContext $context) use ($correlation): void {
            self::assertSame($context->identifier()->value(), $correlation->current()->executionId());
            self::assertTrue(Span::getCurrent()->getContext()->isValid());
        });

        $this->assertTrue($success->primarySucceeded());
        $this->assertTrue($correlation->current()->isEmpty());
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testExecutionMetricsReturnToBalancedZeroAcrossMultipleExecutions(): void
    {
        require_once dirname(__DIR__) . '/Unit/ExecutionMetricsInstrumentationTest.php';
        $meterProvider = new \Evolve\Observe\Tests\Unit\RecordingMeterProvider();
        $composition = $this->enabledComposition($meterProvider);
        $trace = new ExecutionTraceInstrumentation($composition);
        $correlation = new ExecutionLogCorrelationInstrumentation($composition);
        $metrics = new ExecutionMetricsInstrumentation($composition);
        $failure = new RuntimeException('application failure');

        $before = $this->execute($trace, $correlation, $metrics, static function (ExecutionContext $context) use ($correlation): string {
            self::assertSame($context->identifier()->value(), $correlation->current()->executionId());
            self::assertTrue(Span::getCurrent()->getContext()->isValid());

            return 'before';
        });
        $failed = $this->execute($trace, $correlation, $metrics, static function () use ($failure): never {
            throw $failure;
        });
        $after = $this->execute($trace, $correlation, $metrics, static function (ExecutionContext $context) use ($correlation): string {
            self::assertSame($context->identifier()->value(), $correlation->current()->executionId());
            self::assertTrue(Span::getCurrent()->getContext()->isValid());

            return 'after';
        });

        $this->assertTrue($before->primarySucceeded());
        $this->assertSame('before', $before->primaryResult());
        $this->assertTrue($failed->primaryFailed());
        $this->assertSame($failure, $failed->primaryThrowable());
        $this->assertFalse($failed->cleanupFailed());
        $this->assertFalse($failed->requiresQuarantine());
        $this->assertTrue($after->primarySucceeded());
        $this->assertSame('after', $after->primaryResult());
        $this->assertTrue($correlation->current()->isEmpty());
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
        $this->assertSame(
            0,
            $meterProvider->meter->upDownCounter(EvolveSemanticConventions::METRIC_EXECUTION_ACTIVE)->netAmount(),
        );
    }

    private function execute(ExecutionTraceInstrumentation $trace, ExecutionLogCorrelationInstrumentation $correlation, ExecutionMetricsInstrumentation $metrics, callable $operation): ExecutionOutcome
    {
        $registry = new ServiceRegistry();
        $registry->freeze();

        return (new ExecutionOrchestrator($registry, [$trace, $metrics], [$correlation, $trace]))->execute(
            ExecutionKind::HttpRequest,
            $operation,
        );
    }

    private function enabledComposition(?MeterProviderInterface $meterProvider = null): OpenTelemetryComposition
    {
        $resource = ResourceInfo::create(Attributes::create([
            ServiceAttributes::SERVICE_NAME => 'observe-hardening-integration',
        ]));

        return new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter()), resource: $resource),
            meterProvider: $meterProvider ?? new NoopMeterProvider(),
            resource: $resource,
        );
    }
}
