<?php

declare(strict_types=1);

namespace Evolve\Observe\Logging;

interface LogCorrelationProvider
{
    public function current(): LogCorrelation;
}
