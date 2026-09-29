<?php

declare(strict_types=1);

namespace Evolve\Lock\Contracts\Exception;

use Evolve\Contracts\Exception\EvolveException;

/**
 * Public catch boundary for lock provider and backend failures.
 *
 * Implementations should not expose lock keys, ownership tokens, credentials, endpoints,
 * or other sensitive backend data through this framework-owned boundary.
 *
 * @experimental EvolvePHP 2 is pre-beta; this lock contract may change before stable release.
 */
interface LockException extends EvolveException {}
