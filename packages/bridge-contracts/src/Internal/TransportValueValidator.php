<?php

declare(strict_types=1);

namespace Evolve\Bridge\Contracts\Internal;

use InvalidArgumentException;

/**
 * @internal
 */
final class TransportValueValidator
{
    private const MAX_DEPTH = 64;

    private function __construct() {}

    public static function assertValid(mixed $value): void
    {
        self::assertValue($value, 0);
    }

    private static function assertValue(mixed $value, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Transport value nesting is too deep.');
        }

        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Transport values must not contain non-finite floats.');
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $nestedValue) {
                self::assertValue($nestedValue, $depth + 1);
            }

            return;
        }

        throw new InvalidArgumentException('Transport values must contain only null, bool, int, finite float, string or array values.');
    }
}
