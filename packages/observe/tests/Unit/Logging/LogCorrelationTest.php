<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit\Logging;

use Evolve\Observe\Logging\LogCorrelation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class LogCorrelationTest extends TestCase
{
    public function testCorrelationIsFinalImmutableAndAllowsEmptySnapshots(): void
    {
        $reflection = new ReflectionClass(LogCorrelation::class);
        $correlation = new LogCorrelation();

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($correlation->isEmpty());
        $this->assertNull($correlation->traceId());
        $this->assertNull($correlation->spanId());
        $this->assertNull($correlation->traceFlags());
        $this->assertNull($correlation->executionId());
        $this->assertNull($correlation->executionKind());
        $this->assertSame([], $correlation->structuredFields());
        $this->assertSame([], $correlation->openTelemetryAttributes());
    }

    public function testStructuredFieldsContainOnlyPresentAcceptedCorrelationFields(): void
    {
        $correlation = new LogCorrelation(
            traceId: '11111111111111111111111111111111',
            spanId: '2222222222222222',
            traceFlags: '01',
            executionId: 'exec-123',
            executionKind: 'http-request',
        );

        $this->assertFalse($correlation->isEmpty());
        $this->assertSame('11111111111111111111111111111111', $correlation->traceId());
        $this->assertSame('2222222222222222', $correlation->spanId());
        $this->assertSame('01', $correlation->traceFlags());
        $this->assertSame('exec-123', $correlation->executionId());
        $this->assertSame('http-request', $correlation->executionKind());
        $this->assertSame(
            [
                'trace_id' => '11111111111111111111111111111111',
                'span_id' => '2222222222222222',
                'trace_flags' => '01',
                'evolve.execution.id' => 'exec-123',
                'evolve.execution.kind' => 'http-request',
            ],
            $correlation->structuredFields(),
        );
    }

    public function testOpenTelemetryAttributesNeverDuplicateNativeTraceFields(): void
    {
        $correlation = new LogCorrelation(
            traceId: '11111111111111111111111111111111',
            spanId: '2222222222222222',
            traceFlags: '01',
            executionId: 'exec-123',
            executionKind: 'http-request',
        );

        $this->assertSame(
            [
                'evolve.execution.id' => 'exec-123',
                'evolve.execution.kind' => 'http-request',
            ],
            $correlation->openTelemetryAttributes(),
        );
        $this->assertArrayNotHasKey('trace_id', $correlation->openTelemetryAttributes());
        $this->assertArrayNotHasKey('span_id', $correlation->openTelemetryAttributes());
        $this->assertArrayNotHasKey('trace_flags', $correlation->openTelemetryAttributes());
    }
}
