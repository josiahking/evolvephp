<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Tests\Comparator;

use Evolve\Benchmarks\Comparator\ComparatorSmokeVerifier;
use PHPUnit\Framework\TestCase;

final class ComparatorSmokeVerifierTest extends TestCase
{
    public function testComparatorSmokeCommandExists(): void
    {
        self::assertFileExists(dirname(__DIR__, 2) . '/bin/comparator-smoke.php');
    }

    public function testComparatorSmokeVerifierChecksAvailableFixtureCorrectness(): void
    {
        $report = ComparatorSmokeVerifier::verifyMatrixFile(dirname(__DIR__, 2) . '/comparators/matrix.json');

        self::assertSame('evolvephp.comparator.smoke.v1', $report['schema_version']);
        self::assertSame('passed', $report['status']);
        self::assertSame(5, $report['comparator_count']);

        foreach ($report['comparators'] as $comparator) {
            self::assertContains($comparator['availability'], ['available', 'unavailable']);
            self::assertArrayHasKey('framework_version', $comparator);
            self::assertArrayHasKey('composer_constraint', $comparator);

            if ($comparator['availability'] === 'unavailable') {
                self::assertSame('phalcon', $comparator['id']);
                self::assertSame('5.20.3', $comparator['framework_version']);
                self::assertSame('suggest ext-phalcon 5.20.3', $comparator['composer_constraint']);
                self::assertNotEmpty($comparator['reason']);
                self::assertArrayNotHasKey('timing', $comparator);
                continue;
            }

            self::assertSame('passed', $comparator['status'], "Comparator {$comparator['id']} must pass correctness smoke");
            self::assertSame([
                'application_boot',
                'http_static',
                'http_parameterized',
                'http_middleware',
                'http_not_found',
                'http_repeated_warm',
            ], array_keys($comparator['scenarios']));
            self::assertSame([1, 2, 3, 4, 5], $comparator['scenarios']['http_middleware']['middleware_order']);
            self::assertSame(1, $comparator['scenarios']['http_repeated_warm']['bootstrap_count']);
            self::assertSame(3, $comparator['scenarios']['http_repeated_warm']['request_count']);
        }
    }
}
