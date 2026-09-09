<?php

declare(strict_types=1);

namespace Evolve\DevTools\Adoption;

/**
 * @experimental
 */
enum OwnershipSystem: string
{
    case Host = 'host';
    case Evolve = 'evolve';
}
