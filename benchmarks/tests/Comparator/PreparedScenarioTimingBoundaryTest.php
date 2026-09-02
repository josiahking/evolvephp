<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Tests\Comparator;

use Benchmark\EvolvePHP\EvolvePhpComparatorFixture;
use Evolve\Benchmarks\Comparator\ComparatorScenarioExecutor;
use Evolve\Benchmarks\Comparator\PreparedComparatorFixture;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/comparators/evolvephp/src/EvolvePhpComparatorFixture.php';

final class PreparedScenarioTimingBoundaryTest extends TestCase
{
    public function testApplicationBootSubjectIncludesFrameworkConstruction(): void
    {
        $fixture = new EvolvePhpComparatorFixture();

        self::assertInstanceOf(PreparedComparatorFixture::class, $fixture);

        $subject = $fixture->prepareScenario('application_boot');
        $result = $subject->runOnce();

        self::assertSame('application_boot', $subject->scenarioId());
        self::assertSame('application_boot_constructs_framework', $subject->timingBoundary());
        self::assertSame(0, $subject->preparedFrameworkInstanceCount());
        self::assertSame('ok', $result['status']);
        self::assertTrue($result['framework_constructed_in_subject']);
        self::assertFalse($result['framework_prepared_outside_subject']);
    }

    public function testApplicationBootExecutorOverridesConfiguredWarmupsToZero(): void
    {
        $raw = ComparatorScenarioExecutor::run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            'evolvephp',
            'application_boot',
            5,
            1,
        );

        self::assertSame('available', $raw['availability']);
        self::assertSame('application_boot_constructs_framework', $raw['timing_boundary']);
        self::assertSame(0, $raw['prepared_framework_instance_count']);
        self::assertSame(0, $raw['warmups']);
        self::assertTrue($raw['last_result']['framework_constructed_in_subject']);
        self::assertFalse($raw['last_result']['framework_prepared_outside_subject']);
    }

    public function testWarmHttpSubjectExcludesFrameworkConstructionButStillRoutes(): void
    {
        $fixture = new EvolvePhpComparatorFixture();
        $subject = $fixture->prepareScenario('http_parameterized', ['id' => '123']);

        $result = $subject->runOnce();

        self::assertSame('http_parameterized', $subject->scenarioId());
        self::assertSame('prepared_warm_http_request', $subject->timingBoundary());
        self::assertSame(1, $subject->preparedFrameworkInstanceCount());
        self::assertFalse($result['framework_constructed_in_subject']);
        self::assertTrue($result['normal_framework_path_executed']);
        self::assertSame(200, $result['status_code']);
        self::assertSame('evolvephp:parameterized:123', $result['body']);
        self::assertSame(['id' => '123'], $result['parameters']);
    }

    public function testWarmHttpExecutorPreservesConfiguredWarmups(): void
    {
        $raw = ComparatorScenarioExecutor::run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            'evolvephp',
            'http_static',
            3,
            1,
        );

        self::assertSame('available', $raw['availability']);
        self::assertSame('prepared_warm_http_request', $raw['timing_boundary']);
        self::assertSame(1, $raw['prepared_framework_instance_count']);
        self::assertSame(3, $raw['warmups']);
        self::assertFalse($raw['last_result']['framework_constructed_in_subject']);
        self::assertTrue($raw['last_result']['normal_framework_path_executed']);
    }

    public function testRepeatedWarmSubjectReusesOnePreparedFrameworkInstance(): void
    {
        $fixture = new EvolvePhpComparatorFixture();
        $subject = $fixture->prepareScenario('http_repeated_warm', ['request_count' => 5]);

        $result = $subject->runOnce();

        self::assertSame('http_repeated_warm', $subject->scenarioId());
        self::assertSame('prepared_repeated_warm_requests', $subject->timingBoundary());
        self::assertSame(1, $subject->preparedFrameworkInstanceCount());
        self::assertFalse($result['framework_constructed_in_subject']);
        self::assertTrue($result['prepared_framework_instance_reused']);
        self::assertSame(5, $result['request_count']);
        self::assertSame(1, $result['bootstrap_count']);
    }
}
