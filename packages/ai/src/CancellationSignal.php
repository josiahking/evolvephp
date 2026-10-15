<?php

declare(strict_types=1);

namespace Evolve\Ai;

interface CancellationSignal
{
    public function isCancellationRequested(): bool;
}
