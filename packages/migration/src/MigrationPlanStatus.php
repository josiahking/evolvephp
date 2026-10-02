<?php

declare(strict_types=1);

namespace Evolve\Migration;

enum MigrationPlanStatus
{
    case Pending;
    case Applied;
    case Drifted;
    case OrphanedApplied;
}
