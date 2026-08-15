<?php

declare(strict_types=1);

namespace Evolve\Contracts\Component;

/**
 * Shared component kind vocabulary for future modules and plugins.
 *
 * @experimental
 */
enum ComponentType: string
{
    case Module = 'module';
    case Plugin = 'plugin';
}
