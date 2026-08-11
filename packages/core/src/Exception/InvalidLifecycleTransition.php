<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Evolve\Contracts\Exception\LifecycleException;
use LogicException;

/**
 * @internal Core lifecycle implementation detail; catch LifecycleException instead.
 */
final class InvalidLifecycleTransition extends LogicException implements LifecycleException {}
