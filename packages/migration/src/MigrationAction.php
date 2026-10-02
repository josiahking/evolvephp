<?php

declare(strict_types=1);

namespace Evolve\Migration;

use Closure;
use Evolve\Database\Contracts\DatabaseConnection;

/** @experimental This API may change before stable release. */
final readonly class MigrationAction
{
    /** @param Closure(?DatabaseConnection): mixed $operation */
    private function __construct(private Closure $operation) {}

    /** @param callable(?DatabaseConnection): mixed $operation */
    public static function fromCallable(callable $operation): self
    {
        return new self(Closure::fromCallable($operation));
    }

    public function invoke(?DatabaseConnection $connection): mixed
    {
        return ($this->operation)($connection);
    }
}
