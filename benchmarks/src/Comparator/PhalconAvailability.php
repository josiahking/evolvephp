<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Comparator;

/**
 * PhalconAvailability detects whether the Phalcon extension is loaded.
 *
 * This is necessary because Phalcon is a compiled PHP extension, not a pure-PHP framework.
 * The fixture must handle the case where Composer can install Phalcon package dependencies
 * but the actual extension is not available (e.g., on developer machines).
 */
final class PhalconAvailability
{
    /**
     * Detect if the Phalcon extension is currently loaded.
     *
     * @return array{available: bool, extension_present: bool, extension_version?: string, status?: string, reason?: string}
     */
    public static function detect(): array
    {
        if (!extension_loaded('phalcon')) {
            return [
                'available' => false,
                'extension_present' => false,
                'status' => 'unavailable',
                'reason' => 'phalcon extension not loaded',
            ];
        }

        $version = phpversion('phalcon');

        return [
            'available' => true,
            'extension_present' => true,
            'extension_version' => $version ?: 'unknown',
            'status' => 'available',
        ];
    }

    /**
     * Create an unavailable marker with reason.
     *
     * @return array{available: false, extension_present: false, status: 'unavailable', reason: string}
     */
    public static function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'extension_present' => false,
            'status' => 'unavailable',
            'reason' => $reason,
        ];
    }
}
