<?php

declare(strict_types=1);

namespace Evolve\Migration;

use InvalidArgumentException;

/** @experimental This API may change before stable release. */
final readonly class MigrationIdentifier
{
    public function __construct(private string $value)
    {
        self::assertValid($value);
    }

    public static function assertValid(string $value): void
    {
        if (preg_match('/\A[a-z0-9_-]+(?:\.[a-z0-9_-]+)*\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Migration identifier must contain lowercase ASCII segments.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }
}
