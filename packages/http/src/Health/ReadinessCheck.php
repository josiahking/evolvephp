<?php

declare(strict_types=1);

namespace Evolve\Http\Health;

interface ReadinessCheck
{
    public function isReady(): bool;
}
