<?php

declare(strict_types=1);

namespace Evolve\Contracts\Component;

use Psr\Container\ContainerInterface;

/**
 * Restricted application-lifecycle boot context for component entry points.
 *
 * @experimental
 */
interface ComponentBootContext
{
    public function services(): ContainerInterface;

    public function deferFailureCleanup(callable $cleanup): void;
}
