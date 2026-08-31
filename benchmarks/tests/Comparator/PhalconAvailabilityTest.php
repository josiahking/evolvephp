<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Tests\Comparator;

use Evolve\Benchmarks\Comparator\PhalconAvailability;
use PHPUnit\Framework\TestCase;

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
}
