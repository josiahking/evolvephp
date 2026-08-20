<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Evolve\Contracts\Exception\LifecycleException;
use Evolve\Core\Component\Lifecycle\ComponentLifecycleFailure;
use InvalidArgumentException;
use RuntimeException;

/**
 * @internal Core lifecycle shutdown failure aggregate.
 */
final class ComponentShutdownFailed extends RuntimeException implements LifecycleException
{
    /**
     * @var list<ComponentLifecycleFailure>
     */
    private array $failures;

    /**
     * @param array<mixed> $failures
     */
    public function __construct(array $failures)
    {
        if ($failures === [] || ! array_is_list($failures)) {
            throw new InvalidArgumentException('Component shutdown failures must be a non-empty list.');
        }

        foreach ($failures as $failure) {
            if (! $failure instanceof ComponentLifecycleFailure) {
                throw new InvalidArgumentException('Component shutdown failures must contain component lifecycle failures.');
            }
        }

        $this->failures = $failures;

        parent::__construct('Component shutdown failed.', 0, $failures[0]->throwable());
    }

    /**
     * @return list<ComponentLifecycleFailure>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
