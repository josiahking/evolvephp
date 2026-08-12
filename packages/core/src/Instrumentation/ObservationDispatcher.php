<?php

declare(strict_types=1);

namespace Evolve\Core\Instrumentation;

use Throwable;

final class ObservationDispatcher
{
    public function __construct(private ?ObservationSink $sink = null) {}

    public function observe(Observation $observation): ?InstrumentationFailure
    {
        if ($this->sink === null) {
            return null;
        }

        try {
            $this->sink->observe($observation);
        } catch (Throwable $throwable) {
            return InstrumentationFailure::fromThrowable($observation->type(), $throwable);
        }

        return null;
    }
}
