<?php

declare(strict_types=1);

namespace Evolve\Scheduler;

enum CatchUpPolicy
{
    case Skip;
    case RunOnce;
}
