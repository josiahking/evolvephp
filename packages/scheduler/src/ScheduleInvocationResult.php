<?php

declare(strict_types=1);

namespace Evolve\Scheduler;

use LogicException;

/** @experimental This API may change before stable release. */
final readonly class ScheduleInvocationResult
{
    private function __construct(private bool $executed, private mixed $result) {}

    public static function executed(mixed $result): self
    {
        return new self(true, $result);
    }

    public static function overlapSkipped(): self
    {
        return new self(false, null);
    }

    public function wasExecuted(): bool
    {
        return $this->executed;
    }

    public function wasOverlapSkipped(): bool
    {
        return !$this->executed;
    }

    public function result(): mixed
    {
        if (!$this->executed) {
            throw new LogicException('Overlap-skipped invocation has no executed result.');
        }

        return $this->result;
    }
}
