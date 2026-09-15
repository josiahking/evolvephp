<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Storage;

use Evolve\Insight\Storage\DiagnosticObservationSnapshot;
use PHPUnit\Framework\TestCase;

final class DiagnosticObservationSnapshotTest extends TestCase
{
    public function testScalarValuesArePreserved(): void
    {
        $snapshot = new DiagnosticObservationSnapshot(
            'execution-completed',
            'succeeded',
            'RuntimeException',
            'reusable',
        );

        self::assertSame('execution-completed', $snapshot->type());
        self::assertSame('succeeded', $snapshot->outcome());
        self::assertSame('RuntimeException', $snapshot->errorType());
        self::assertSame('reusable', $snapshot->reuseDecision());
    }

    public function testNullableFieldsRemainNullable(): void
    {
        $snapshot = new DiagnosticObservationSnapshot(
            'execution-started',
            null,
            null,
            null,
        );

        self::assertSame('execution-started', $snapshot->type());
        self::assertNull($snapshot->outcome());
        self::assertNull($snapshot->errorType());
        self::assertNull($snapshot->reuseDecision());
    }

    public function testPublicApiExposesNoCoreObservation(): void
    {
        $snapshot = new DiagnosticObservationSnapshot(
            'handler-completed',
            'failed',
            'DomainException',
            'quarantine-required',
        );

        self::assertSame(
            array('errorType', 'outcome', 'reuseDecision', 'type'),
            $this->publicMethods($snapshot),
        );
    }

    /**
     * @return list<string>
     */
    private function publicMethods(object $object): array
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass($object))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        $methods = array_values(array_filter(
            $methods,
            static fn (string $method): bool => $method !== '__construct',
        ));
        sort($methods);

        return $methods;
    }
}
