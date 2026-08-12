<?php

declare(strict_types=1);

namespace Evolve\Contracts\Execution;

interface ResetParticipant
{
    public function reset(): void;
}
