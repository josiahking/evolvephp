<?php

declare(strict_types=1);

namespace Evolve\Secret\Contracts\Exception;

use Evolve\Contracts\Exception\EvolveException;
use Evolve\Secret\Contracts\SecretFailureCategory;

/**
 * Portable catch boundary for secret provider and backend failures.
 *
 * Framework-owned implementations must not expose secret names or values, credentials,
 * tokens, endpoints, raw provider handles, or arbitrary provider metadata.
 *
 * @experimental EvolvePHP 2 is pre-beta; this secret contract may change before stable release.
 */
interface SecretException extends EvolveException
{
    public function category(): SecretFailureCategory;
}
