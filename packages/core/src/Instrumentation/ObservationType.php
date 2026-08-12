<?php

declare(strict_types=1);

namespace Evolve\Core\Instrumentation;

enum ObservationType
{
    case ExecutionStarted;
    case HandlerCompleted;
    case ScopeCloseStarted;
    case ScopeCloseCompleted;
    case QuarantineRequired;
    case ExecutionCompleted;
}
