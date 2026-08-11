<?php

declare(strict_types=1);

namespace Evolve\Core\Container;

enum ServiceLifetime
{
    case Application;
    case Execution;
    case Transient;
}
