<?php

declare(strict_types=1);

namespace Evolve\Core\Lifecycle;

/**
 * @internal Core lifecycle state for the minimal application kernel.
 */
enum ApplicationState
{
    case Created;
    case Booting;
    case Ready;
    case Failed;
    case Stopped;
}
