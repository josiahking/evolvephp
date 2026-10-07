<?php

declare(strict_types=1);

namespace Evolve\Observe;

use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Instrumentation\ObservationOutcome;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;

final class MetricCardinalityPolicy
{
    /**
     * @var array<string, string>
     */
    private const EXECUTION_KIND_VALUES = [
        ExecutionKind::HttpRequest->value => 'http_request',
        ExecutionKind::QueueMessage->value => 'queue_message',
        ExecutionKind::ScheduledJob->value => 'scheduled_job',
        ExecutionKind::CliCommand->value => 'cli_command',
        ExecutionKind::WorkerTask->value => 'worker_task',
    ];

    private const DATABASE_OPERATIONS = ['execute', 'query', 'transaction'];

    private const CACHE_OPERATIONS = ['get', 'set', 'delete', 'clear', 'getMultiple', 'setMultiple', 'deleteMultiple', 'has'];

    private const STORAGE_OPERATIONS = ['put', 'open', 'read', 'delete', 'close'];

    /**
     * @var array<string, true>
     */
    private const HTTP_METHODS = [
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_CONNECT => true,
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_DELETE => true,
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_GET => true,
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_HEAD => true,
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OPTIONS => true,
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_PATCH => true,
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_POST => true,
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_PUT => true,
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_TRACE => true,
    ];

    /**
     * @return array<string, string>
     */
    public static function executionKindAttributes(ExecutionKind $kind): array
    {
        return [
            EvolveSemanticConventions::ATTRIBUTE_EXECUTION_KIND => self::EXECUTION_KIND_VALUES[$kind->value],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function executionOutcomeAttributes(ExecutionKind $kind, ObservationOutcome $outcome): array
    {
        return self::executionKindAttributes($kind) + [
            EvolveSemanticConventions::ATTRIBUTE_EXECUTION_OUTCOME => $outcome === ObservationOutcome::Failed
                ? EvolveSemanticConventions::OUTCOME_FAILED
                : EvolveSemanticConventions::OUTCOME_SUCCEEDED,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function httpServerAttributes(string $method): array
    {
        $method = strtoupper($method);

        return [
            HttpAttributes::HTTP_REQUEST_METHOD => isset(self::HTTP_METHODS[$method])
                ? $method
                : HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER,
        ];
    }

    /**
     * @return array<string, string>
     * @throws \InvalidArgumentException
     */
    public static function databaseAttributes(string $operation): array
    {
        self::assertOperation($operation, self::DATABASE_OPERATIONS);

        return [EvolveSemanticConventions::ATTRIBUTE_DATABASE_OPERATION => $operation];
    }

    /**
     * @return array<string, string>
     * @throws \InvalidArgumentException
     */
    public static function cacheAttributes(string $operation): array
    {
        self::assertOperation($operation, self::CACHE_OPERATIONS);

        return [EvolveSemanticConventions::ATTRIBUTE_CACHE_OPERATION => $operation];
    }

    /**
     * @return array<string, string>
     * @throws \InvalidArgumentException
     */
    public static function storageAttributes(string $operation): array
    {
        self::assertOperation($operation, self::STORAGE_OPERATIONS);

        return [EvolveSemanticConventions::ATTRIBUTE_STORAGE_OPERATION => $operation];
    }

    /** @return array<string, string> */
    public static function httpClientAttributes(string $method): array
    {
        return self::httpServerAttributes($method);
    }

    public static function databaseCardinalityBudget(): int
    {
        return count(self::DATABASE_OPERATIONS);
    }

    public static function cacheCardinalityBudget(): int
    {
        return count(self::CACHE_OPERATIONS);
    }

    public static function storageCardinalityBudget(): int
    {
        return count(self::STORAGE_OPERATIONS);
    }

    public static function executionOutcomeCardinalityBudget(): int
    {
        return self::executionKindCardinalityBudget() * 2;
    }

    public static function executionKindCardinalityBudget(): int
    {
        return count(self::EXECUTION_KIND_VALUES);
    }

    public static function httpMethodCardinalityBudget(): int
    {
        return count(self::HTTP_METHODS) + 1;
    }

    /** @return array<string, string> */
    public static function queueProducerAttributes(): array
    {
        return [EvolveSemanticConventions::ATTRIBUTE_QUEUE_ROLE => EvolveSemanticConventions::QUEUE_ROLE_PRODUCER];
    }

    /** @return array<string, string> */
    public static function queueConsumerAttributes(): array
    {
        return [EvolveSemanticConventions::ATTRIBUTE_QUEUE_ROLE => EvolveSemanticConventions::QUEUE_ROLE_CONSUMER];
    }

    public static function queueMessageCardinalityBudget(): int
    {
        return 2;
    }

    /** @param list<string> $allowed */
    private static function assertOperation(string $operation, array $allowed): void
    {
        if (!in_array($operation, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported infrastructure operation.');
        }
    }

    private function __construct() {}
}
