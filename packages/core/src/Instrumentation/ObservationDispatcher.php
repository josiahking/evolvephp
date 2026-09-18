<?php

declare(strict_types=1);

namespace Evolve\Core\Instrumentation;

use InvalidArgumentException;
use Throwable;

final class ObservationDispatcher
{
    /**
     * @var list<ObservationSink>
     */
    private array $sinks;

    /**
     * @param ObservationSink|array<array-key, mixed>|null $sink
     */
    public function __construct(ObservationSink|array|null $sink = null)
    {
        $this->sinks = $this->normalizeSinks($sink);
    }

    public function observe(Observation $observation): ?InstrumentationFailure
    {
        return $this->observeAll($observation)[0] ?? null;
    }

    /**
     * @return list<InstrumentationFailure>
     */
    public function observeAll(Observation $observation): array
    {
        $failures = [];

        foreach ($this->sinks as $sink) {
            try {
                $sink->observe($observation);
            } catch (Throwable $throwable) {
                $failures[] = InstrumentationFailure::fromThrowable($observation->type(), $throwable);
            }
        }

        return $failures;
    }

    /**
     * @param ObservationSink|array<array-key, mixed>|null $sink
     *
     * @return list<ObservationSink>
     */
    private function normalizeSinks(ObservationSink|array|null $sink): array
    {
        if ($sink === null) {
            return [];
        }

        if ($sink instanceof ObservationSink) {
            return [$sink];
        }

        $sinks = [];
        $seen = [];

        foreach ($sink as $configuredSink) {
            if (! $configuredSink instanceof ObservationSink) {
                throw new InvalidArgumentException('Observation sinks must implement ObservationSink.');
            }

            $objectId = spl_object_id($configuredSink);

            if (isset($seen[$objectId])) {
                throw new InvalidArgumentException('Observation sinks must not contain duplicate object instances.');
            }

            $seen[$objectId] = true;
            $sinks[] = $configuredSink;
        }

        return $sinks;
    }
}
