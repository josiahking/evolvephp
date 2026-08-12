<?php

declare(strict_types=1);

namespace Evolve\Core\Execution;

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Exception\InvalidResetParticipant;
use Throwable;

/**
 * @internal Coordinates explicitly registered execution-scope reset participants.
 */
final class ResetCoordinator
{
    /**
     * @var array<string, ResetParticipant>
     */
    private array $participants = [];

    /**
     * @var list<string>
     */
    private array $order = [];

    public function register(string $id, ResetParticipant $participant): void
    {
        if ($id === '') {
            throw new InvalidResetParticipant('Reset participant identifier must be a non-empty string.');
        }

        if (isset($this->participants[$id])) {
            throw new InvalidResetParticipant('Reset participant identifier is already registered.');
        }

        $this->participants[$id] = $participant;
        $this->order[] = $id;
    }

    /**
     * @return list<Throwable>
     */
    public function reset(): array
    {
        $failures = [];

        try {
            for ($index = count($this->order) - 1; $index >= 0; --$index) {
                $id = $this->order[$index];

                try {
                    $this->participants[$id]->reset();
                } catch (Throwable $exception) {
                    $failures[] = $exception;
                }
            }
        } finally {
            $this->participants = [];
            $this->order = [];
        }

        return $failures;
    }
}
