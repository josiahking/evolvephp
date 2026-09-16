<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Capture;

use Evolve\Insight\Capture\DeterministicDiagnosticSampler;
use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticDataClassification;
use Evolve\Insight\Capture\DiagnosticEntry;
use PHPUnit\Framework\TestCase;

final class DeterministicDiagnosticSamplerTest extends TestCase
{
    public function testZeroPercentRejectsAllAndOneHundredPercentAcceptsAll(): void
    {
        self::assertFalse((new DeterministicDiagnosticSampler(0))->accepts('execution-1'));
        self::assertTrue((new DeterministicDiagnosticSampler(100))->accepts('execution-1'));
    }

    public function testPercentageOutsideBoundsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DeterministicDiagnosticSampler(101);
    }

    public function testSameIdentifierAndConfigurationAlwaysReturnsSameDecision(): void
    {
        $sampler = new DeterministicDiagnosticSampler(25);

        self::assertSame($sampler->accepts('execution-1'), $sampler->accepts('execution-1'));
    }

    public function testDecisionDoesNotDependOnEntryPayload(): void
    {
        $sampler = new DeterministicDiagnosticSampler(50);
        $first = $this->entry('execution-1', 'http', 'request', 'route');
        $second = $this->entry('execution-1', 'database', 'query', 'sql');

        self::assertSame(
            $sampler->accepts($first->executionIdentifier()),
            $sampler->accepts($second->executionIdentifier()),
        );
    }

    public function testDifferentInstancesWithSameConfigurationMakeSameDecision(): void
    {
        self::assertSame(
            (new DeterministicDiagnosticSampler(50))->accepts('execution-1'),
            (new DeterministicDiagnosticSampler(50))->accepts('execution-1'),
        );
    }

    public function testImplementationUsesNoAmbientRandomnessOrTime(): void
    {
        $sampler = new DeterministicDiagnosticSampler(50);
        $decisions = array();

        for ($i = 0; $i < 10; $i++) {
            $decisions[] = $sampler->accepts('execution-ambient');
        }

        self::assertCount(1, array_unique($decisions));
    }

    private function entry(string $identifier, string $category, string $name, string $attributeName): DiagnosticEntry
    {
        return new DiagnosticEntry(
            $identifier,
            $category,
            $name,
            array(new DiagnosticAttribute($attributeName, DiagnosticDataClassification::PublicOperationalMetadata, 'value')),
        );
    }
}
