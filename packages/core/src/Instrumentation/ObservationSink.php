<?php

declare(strict_types=1);

namespace Evolve\Core\Instrumentation;

interface ObservationSink
{
    public function observe(Observation $observation): void;
}
