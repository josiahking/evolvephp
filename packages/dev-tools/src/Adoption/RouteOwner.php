<?php

declare(strict_types=1);

namespace Evolve\DevTools\Adoption;

/**
 * @experimental
 */
enum RouteOwner: string
{
    case Host = 'host';
    case Evolve = 'evolve';
    case Disabled = 'disabled';
}
