<?php

declare(strict_types=1);

namespace Evolve\Observe\Exception;

use RuntimeException;

final class OpenTelemetryContextDetachFailed extends RuntimeException
{
    public function __construct(private int $detachStatus)
    {
        parent::__construct('OpenTelemetry context detach failed.');
    }

    public function detachStatus(): int
    {
        return $this->detachStatus;
    }
}
