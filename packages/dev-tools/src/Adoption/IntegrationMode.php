<?php

declare(strict_types=1);

namespace Evolve\DevTools\Adoption;

/**
 * @experimental
 */
enum IntegrationMode: string
{
    case Embedded = 'embedded';
    case Remote = 'remote';
}
