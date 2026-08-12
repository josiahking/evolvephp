<?php

declare(strict_types=1);

namespace Evolve\Core\Execution;

use Evolve\Contracts\Execution\ResetParticipant;
use Psr\Container\ContainerInterface;

interface ExecutionScope extends ContainerInterface
{
    public function registerResetParticipant(string $id, ResetParticipant $participant): void;

    public function close(): void;
}
