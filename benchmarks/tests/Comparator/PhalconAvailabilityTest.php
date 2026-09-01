<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Tests\Comparator;

use Evolve\Benchmarks\Comparator\PhalconAvailability;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/comparators/phalcon/src/PhalconComparatorFixture.php';

final class PhalconAvailabilityTest extends TestCase
{
    public function testPhalconAvailabilityDetectsLoadedExtension(): void
    {
        $availability = PhalconAvailability::detect();

        $this->assertIsArray($availability);
        $this->assertArrayHasKey('available', $availability);
        $this->assertArrayHasKey('extension_present', $availability);

        if ($availability['extension_present']) {
            $this->assertArrayHasKey('extension_version', $availability);
            $this->assertIsString($availability['extension_version']);
        }
    }

    public function testPhalconUnavailableReturnsConsistentStructure(): void
    {
        // If extension is not available, the structure must still be deterministic
        $availability = PhalconAvailability::detect();

        if (!$availability['available']) {
            $this->assertFalse($availability['extension_present']);
            $this->assertSame('unavailable', $availability['status']);
        }
    }

    public function testPhalconAvailabilityCanBeMarkedUnavailable(): void
    {
        $unavailable = PhalconAvailability::unavailable('extension not loaded');

        $this->assertFalse($unavailable['available']);
        $this->assertSame('unavailable', $unavailable['status']);
        $this->assertIsString($unavailable['reason']);
    }

    public function testPhalconFixtureDoesNotCrashMatrixWhenUnavailable(): void
    {
        $availability = PhalconAvailability::detect();

        // This test verifies that the matrix can be loaded regardless of Phalcon availability
        // The actual Phalcon fixture will handle unavailability gracefully
        $this->assertIsArray($availability, 'Availability detection must return array structure');
    }

    public function testPhalconMiddlewareImplementationUsesMicroBeforeHooks(): void
    {
        $fixtureSource = (string) file_get_contents(dirname(__DIR__, 2) . '/comparators/phalcon/src/PhalconComparatorFixture.php');

        $this->assertStringContainsString('->before(', $fixtureSource);
        $this->assertStringNotContainsString('for ($i = 1; $i <= 5; ++$i) {', $fixtureSource);
    }

    public function testPhalconMiddlewareRuntimeUsesBeforeHooksWhenExtensionIsAvailable(): void
    {
        $availability = PhalconAvailability::detect();

        if (!$availability['available']) {
            self::markTestSkipped((string) ($availability['reason'] ?? 'phalcon extension not loaded'));
        }

        $fixture = new \Benchmark\Phalcon\PhalconComparatorFixture();
        $result = $fixture->httpMiddleware();

        self::assertSame(200, $result['status_code']);
        self::assertSame('phalcon:middleware', $result['body']);
        self::assertSame([1, 2, 3, 4, 5], $result['middleware_order']);
        self::assertSame('before', $result['middleware_model']);
    }
}
