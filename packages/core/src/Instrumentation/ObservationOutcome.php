<?php

declare(strict_types=1);

namespace Evolve\Core\Instrumentation;

enum ObservationOutcome
{
    case Succeeded;
    case Failed;
}
