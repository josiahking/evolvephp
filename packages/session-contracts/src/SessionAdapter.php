<?php

declare(strict_types=1);

namespace Evolve\Session\Contracts;

use Evolve\Contracts\Execution\ResetParticipant;

interface SessionAdapter extends ResetParticipant
{
    public function open(
        ?SessionIdentifier $identifier = null,
    ): Session;

    public function identifier(): SessionIdentifier;

    public function regenerate(): SessionIdentifier;

    public function invalidate(): void;

    public function close(): void;
}
