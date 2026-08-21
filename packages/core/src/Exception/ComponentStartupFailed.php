<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Exception\LifecycleException;
use Evolve\Core\Component\Lifecycle\ComponentLifecycleFailure;
use RuntimeException;
use Throwable;

/**
 * @internal Core lifecycle startup failure with attributed cleanup failures.
 */
final class ComponentStartupFailed extends RuntimeException implements LifecycleException
{
    public const PHASE_BOOT = 'boot';
    public const PHASE_READY = 'ready';

    /**
     * @param list<ComponentLifecycleFailure> $cleanupFailures
     */
    private function __construct(
        private ComponentIdentifier $component,
        private string $phase,
        private array $cleanupFailures,
        Throwable $previous,
    ) {
        parent::__construct('Component startup failed during ' . $phase . '.', 0, $previous);
    }

    /**
     * @param list<ComponentLifecycleFailure> $cleanupFailures
     */
    public static function duringBoot(ComponentIdentifier $component, Throwable $previous, array $cleanupFailures = []): self
    {
        return new self($component, self::PHASE_BOOT, self::assertCleanupFailures($cleanupFailures), $previous);
    }

    /**
     * @param list<ComponentLifecycleFailure> $cleanupFailures
     */
    public static function duringReady(ComponentIdentifier $component, Throwable $previous, array $cleanupFailures = []): self
    {
        return new self($component, self::PHASE_READY, self::assertCleanupFailures($cleanupFailures), $previous);
    }

    public function component(): ComponentIdentifier
    {
        return $this->component;
    }

    public function phase(): string
    {
        return $this->phase;
    }

    /**
     * @return list<ComponentLifecycleFailure>
     */
    public function cleanupFailures(): array
    {
        return $this->cleanupFailures;
    }

    /**
     * @param array<mixed> $failures
     * @return list<ComponentLifecycleFailure>
     */
    private static function assertCleanupFailures(array $failures): array
    {
        if (! array_is_list($failures)) {
            throw new \InvalidArgumentException('Component startup cleanup failures must be a list.');
        }

        foreach ($failures as $failure) {
            if (! $failure instanceof ComponentLifecycleFailure) {
                throw new \InvalidArgumentException('Component startup cleanup failures must contain component lifecycle failures.');
            }
        }

        return $failures;
    }
}
