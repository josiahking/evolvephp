<?php

declare(strict_types=1);

namespace Evolve\Observe\Database;

use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Database\Contracts\DatabaseStatement;
use Evolve\Database\Contracts\Exception\DatabaseException;
use Evolve\Observe\EvolveSemanticConventions as Names;
use Evolve\Observe\MetricCardinalityPolicy;
use Evolve\Observe\OpenTelemetryComposition;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use Throwable;

final class DatabaseConnectionInstrumentation implements DatabaseConnection
{
    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(
        private OpenTelemetryComposition $composition,
        private DatabaseConnection $connection,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => hrtime(true);
    }

    public function execute(DatabaseStatement $statement): int
    {
        return $this->measure('execute', fn(): int => $this->connection->execute($statement), $statement);
    }

    /** @return iterable<array-key, mixed> */
    public function query(DatabaseStatement $statement): iterable
    {
        return $this->measure('query', fn(): iterable => $this->connection->query($statement), $statement);
    }

    public function transaction(callable $operation): mixed
    {
        if (!$this->composition->isEnabled()) {
            return $this->connection->transaction($operation);
        }

        return $this->measure('transaction', fn(): mixed => $this->connection->transaction(
            fn(DatabaseConnection $active): mixed => $operation(new self($this->composition, $active, $this->clock)),
        ));
    }

    /** @param callable(): mixed $delegate */
    private function measure(string $operation, callable $delegate, ?DatabaseStatement $statement = null): mixed
    {
        if (!$this->composition->isEnabled()) {
            return $delegate();
        }

        $span = null;
        $meter = null;
        try {
            if ($this->composition->tracerProvider() !== null) {
                $builder = $this->composition->tracerProvider()->getTracer('evolvephp/observe')
                    ->spanBuilder(Names::SPAN_NAME_DATABASE)
                    ->setParent(Context::getCurrent())
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(Names::ATTRIBUTE_DATABASE_OPERATION, $operation);
                if ($statement?->operationName() !== null) {
                    $builder->setAttribute(Names::ATTRIBUTE_DATABASE_OPERATION_NAME, $statement->operationName());
                }
                $span = $builder->startSpan();
            }
        } catch (Throwable) {
            $this->endSpan($span);
            $span = null;
        }
        try {
            if ($this->composition->meterProvider() !== null) {
                $meter = $this->composition->meterProvider()->getMeter('evolvephp/observe');
                $meter->createHistogram(Names::METRIC_DATABASE_DURATION, 's');
                $meter->createCounter(Names::METRIC_DATABASE_COUNT, '{operation}');
                $meter->createCounter(Names::METRIC_DATABASE_FAILURES, '{operation}');
            }
        } catch (Throwable) {
            $meter = null;
        }

        $started = null;
        if ($meter !== null) {
            try {
                $started = ($this->clock)();
            } catch (Throwable) {
                // Clock failure only disables duration recording.
            }
        }

        $failure = null;
        $result = null;
        try {
            $result = $delegate();
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        $this->record($meter, $started, $operation, $failure !== null);
        if ($failure !== null) {
            $this->recordFailure($span, $failure);
        }
        $this->endSpan($span);
        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }

    private function record(?MeterInterface $meter, ?int $started, string $operation, bool $failed): void
    {
        if ($meter === null) {
            return;
        }
        $attributes = MetricCardinalityPolicy::databaseAttributes($operation);
        if ($started !== null) {
            try {
                $elapsed = max(0, (($this->clock)() - $started) / 1_000_000_000);
                $meter->createHistogram(Names::METRIC_DATABASE_DURATION, 's')->record($elapsed, $attributes);
            } catch (Throwable) {
                // Telemetry cannot change database behavior.
            }
        }
        try {
            $meter->createCounter(Names::METRIC_DATABASE_COUNT, '{operation}')->add(1, $attributes);
        } catch (Throwable) {
            // Continue recording independent instruments.
        }
        if ($failed) {
            try {
                $meter->createCounter(Names::METRIC_DATABASE_FAILURES, '{operation}')->add(1, $attributes);
            } catch (Throwable) {
                // Telemetry cannot change database behavior.
            }
        }
    }

    private function recordFailure(?SpanInterface $span, Throwable $failure): void
    {
        if ($span === null) {
            return;
        }
        try {
            $span->setAttribute(ErrorAttributes::ERROR_TYPE, $failure::class);
            if ($failure instanceof DatabaseException) {
                $span->setAttribute(Names::ATTRIBUTE_DATABASE_OPERATION, $failure->operation()->value);
                $span->setAttribute(Names::ATTRIBUTE_DATABASE_FAILURE_CATEGORY, $failure->category()->value);
                $name = $failure->operationName();
                if ($name !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $name) === 1) {
                    $span->setAttribute(Names::ATTRIBUTE_DATABASE_OPERATION_NAME, $name);
                }
                $sqlState = $failure->sqlState();
                if ($sqlState !== null && preg_match('/^[A-Z0-9]{5}$/D', $sqlState) === 1) {
                    $span->setAttribute(Names::ATTRIBUTE_DATABASE_SQLSTATE, $sqlState);
                }
                $driver = $failure->driverName();
                if ($driver !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/D', $driver) === 1) {
                    $span->setAttribute(Names::ATTRIBUTE_DATABASE_DRIVER, $driver);
                }
            }
            $span->setStatus(StatusCode::STATUS_ERROR);
        } catch (Throwable) {
            // Preserve the application throwable.
        }
    }

    private function endSpan(?SpanInterface $span): void
    {
        try {
            $span?->end();
        } catch (Throwable) {
            // Span cleanup cannot change database behavior.
        }
    }
}
