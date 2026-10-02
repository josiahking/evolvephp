<?php

declare(strict_types=1);

namespace Evolve\Scheduler;

enum ScheduledActionKind
{
    case Callback;
    case Command;
    case QueueJob;
}
