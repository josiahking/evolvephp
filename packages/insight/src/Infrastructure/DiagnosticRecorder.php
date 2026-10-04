<?php

declare(strict_types=1);

namespace Evolve\Insight\Infrastructure;

use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticDataClassification;
use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\Capture\DiagnosticEntrySink;
use Evolve\Queue\Contracts\Exception\QueueException;
use Evolve\Storage\Contracts\Exception\StorageException;

final class DiagnosticRecorder
{
    /** @var \Closure(): (int|float) */
    private \Closure $clock;

    /** @param (callable(): (int|float))|null $clock */
    public function __construct(
        private DiagnosticEntrySink $sink,
        private ExecutionCorrelation $correlation,
        ?callable $clock = null,
    ) {
        $this->clock = $clock === null ? static fn(): int|float => hrtime(true) : \Closure::fromCallable($clock);
    }

    public function correlation(): ExecutionCorrelation
    {
        return $this->correlation;
    }

    /**
     * @template TResult
     * @param callable(): TResult $operation
     * @param (callable(TResult): array<string, string|int|float|bool|null>)|null $resultAttributes
     * @param (callable(\Throwable): array<string, string|int|float|bool|null>)|null $failureAttributes
     * @return TResult
     */
    public function run(string $category, string $name, callable $operation, ?callable $resultAttributes = null, ?callable $failureAttributes = null, ?int $slowThresholdNanoseconds = null): mixed
    {
        $identifier = $this->correlation->identifier();
        $start = $this->time();

        try {
            $result = $operation();
        } catch (\Throwable $failure) {
            $this->record($identifier, $category, $name, 'failure', $start, static function () use ($failure, $failureAttributes): array {
                return $failureAttributes === null ? [] : $failureAttributes($failure);
            }, $failure, $slowThresholdNanoseconds);
            throw $failure;
        }

        $this->record($identifier, $category, $name, 'success', $start, static function () use ($result, $resultAttributes): array {
            return $resultAttributes === null ? [] : $resultAttributes($result);
        }, null, $slowThresholdNanoseconds);

        return $result;
    }

    private function time(): int|float|null
    {
        if ($this->correlation->identifier() === null) {
            return null;
        }

        try {
            return ($this->clock)();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param callable(): array<string, string|int|float|bool|null> $extra */
    private function record(?string $identifier, string $category, string $name, string $outcome, int|float|null $start, callable $extra, ?\Throwable $failure = null, ?int $slowThresholdNanoseconds = null): void
    {
        if ($identifier === null) {
            return;
        }

        try {
            $attributes = [
                new DiagnosticAttribute('operation', DiagnosticDataClassification::PublicOperationalMetadata, $name),
                new DiagnosticAttribute('outcome', DiagnosticDataClassification::PublicOperationalMetadata, $outcome),
            ];
            $end = $this->time();
            if ($start !== null && $end !== null) {
                $duration = max(0, $end - $start);
                $attributes[] = new DiagnosticAttribute('duration_ns', DiagnosticDataClassification::PublicOperationalMetadata, $duration);
                if ($slowThresholdNanoseconds !== null && $duration >= $slowThresholdNanoseconds) {
                    $attributes[] = new DiagnosticAttribute('slow', DiagnosticDataClassification::PublicOperationalMetadata, true);
                }
            }
            if ($failure !== null) {
                if (strlen($failure::class) <= DiagnosticAttribute::MAX_STRING_VALUE_LENGTH) {
                    $attributes[] = new DiagnosticAttribute('error_type', DiagnosticDataClassification::InternalOperationalMetadata, $failure::class);
                }
                if ($failure instanceof QueueException || $failure instanceof StorageException) {
                    $attributes[] = new DiagnosticAttribute('failure_category', DiagnosticDataClassification::PublicOperationalMetadata, $failure->category()->value);
                }
            }
            foreach ($extra() as $key => $value) {
                $classification = $key === 'sql' ? DiagnosticDataClassification::BusinessSensitivePayload : (
                    in_array($key, ['fingerprint', 'operation_name', 'driver', 'sqlstate', 'vendor_code', 'parameter_count', 'parameter_types', 'repeat_occurrence', 'error_type'], true)
                        ? DiagnosticDataClassification::InternalOperationalMetadata
                        : DiagnosticDataClassification::PublicOperationalMetadata
                );
                $attributes[] = new DiagnosticAttribute($key, $classification, $value);
            }
            $this->sink->capture(new DiagnosticEntry($identifier, 'evolve.infrastructure.' . $category, $name, $attributes));
        } catch (\Throwable) {
            // Diagnostic recording is secondary to the infrastructure operation.
        }
    }
}
