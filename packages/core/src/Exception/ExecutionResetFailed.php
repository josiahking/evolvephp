<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use RuntimeException;
use Throwable;

final class ExecutionResetFailed extends RuntimeException
{
    /**
     * @var list<Throwable>
     */
    private array $failures;

    /**
     * @param list<Throwable> $failures
     */
    public function __construct(array $failures)
    {
        $this->failures = $failures;

        parent::__construct('Execution scope reset failed.', 0, $this->failures[0] ?? null);
    }

    /**
     * @return list<Throwable>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
