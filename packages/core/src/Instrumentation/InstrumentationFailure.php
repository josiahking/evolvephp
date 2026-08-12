<?php

declare(strict_types=1);

namespace Evolve\Core\Instrumentation;

use Throwable;

final class InstrumentationFailure
{
    private function __construct(
        private ObservationType $observationType,
        private string $errorType,
    ) {
        if ($this->errorType === '') {
            throw new \InvalidArgumentException('Instrumentation failure error type must not be empty.');
        }
    }

    public static function fromThrowable(ObservationType $observationType, Throwable $throwable): self
    {
        return new self($observationType, $throwable::class);
    }

    public function observationType(): ObservationType
    {
        return $this->observationType;
    }

    public function errorType(): string
    {
        return $this->errorType;
    }
}
