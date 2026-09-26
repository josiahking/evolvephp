<?php

declare(strict_types=1);

namespace Evolve\Database\Contracts\Exception;

use Evolve\Contracts\Exception\EvolveException;
use Evolve\Database\Contracts\DatabaseFailureCategory;
use Evolve\Database\Contracts\DatabaseOperation;

/**
 * Public catch boundary for portable database failures.
 *
 * @experimental EvolvePHP 2 is pre-beta; this database contract may change before stable release.
 */
interface DatabaseException extends EvolveException
{
    public function operation(): DatabaseOperation;

    public function category(): DatabaseFailureCategory;

    public function operationName(): ?string;

    public function driverName(): ?string;

    public function sqlState(): ?string;

    public function vendorCode(): int|string|null;
}
