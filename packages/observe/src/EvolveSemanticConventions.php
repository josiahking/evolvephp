<?php

declare(strict_types=1);

namespace Evolve\Observe;

use Evolve\Core\Execution\ExecutionKind;

final class EvolveSemanticConventions
{
    public const SPAN_NAME_DATABASE = 'evolve.database';
    public const SPAN_NAME_CACHE = 'evolve.cache';
    public const SPAN_NAME_STORAGE = 'evolve.storage';
    public const SPAN_NAME_HTTP_CLIENT = 'evolve.http.client';

    public const ATTRIBUTE_DATABASE_OPERATION = 'evolve.database.operation';
    public const ATTRIBUTE_DATABASE_OPERATION_NAME = 'evolve.database.operation_name';
    public const ATTRIBUTE_DATABASE_FAILURE_CATEGORY = 'evolve.database.failure.category';
    public const ATTRIBUTE_DATABASE_SQLSTATE = 'evolve.database.sqlstate';
    public const ATTRIBUTE_DATABASE_DRIVER = 'evolve.database.driver';
    public const ATTRIBUTE_CACHE_OPERATION = 'evolve.cache.operation';
    public const ATTRIBUTE_STORAGE_OPERATION = 'evolve.storage.operation';
    public const ATTRIBUTE_STORAGE_FAILURE_CATEGORY = 'evolve.storage.failure.category';

    public const METRIC_DATABASE_DURATION = 'evolve.database.operation.duration';
    public const METRIC_DATABASE_COUNT = 'evolve.database.operation.count';
    public const METRIC_DATABASE_FAILURES = 'evolve.database.operation.failures';
    public const METRIC_CACHE_DURATION = 'evolve.cache.operation.duration';
    public const METRIC_CACHE_COUNT = 'evolve.cache.operation.count';
    public const METRIC_CACHE_FAILURES = 'evolve.cache.operation.failures';
    public const METRIC_STORAGE_DURATION = 'evolve.storage.operation.duration';
    public const METRIC_STORAGE_COUNT = 'evolve.storage.operation.count';
    public const METRIC_STORAGE_FAILURES = 'evolve.storage.operation.failures';
    public const METRIC_HTTP_CLIENT_DURATION = 'evolve.http.client.request.duration';
    public const METRIC_HTTP_CLIENT_COUNT = 'evolve.http.client.request.count';
    public const METRIC_HTTP_CLIENT_FAILURES = 'evolve.http.client.request.failures';

    public const SPAN_NAME_QUEUE_PRODUCE = 'evolve.queue.produce';

    public const SPAN_NAME_QUEUE_CONSUME = 'evolve.queue.consume';

    public const ATTRIBUTE_QUEUE_ROLE = 'evolve.queue.role';

    public const ATTRIBUTE_QUEUE_FAILURE_CATEGORY = 'evolve.queue.failure.category';

    public const QUEUE_ROLE_PRODUCER = 'producer';

    public const QUEUE_ROLE_CONSUMER = 'consumer';

    public const METRIC_QUEUE_MESSAGE_DURATION = 'evolve.queue.message.duration';

    public const METRIC_QUEUE_MESSAGE_COUNT = 'evolve.queue.message.count';

    public const METRIC_QUEUE_MESSAGE_FAILURES = 'evolve.queue.message.failures';

    public const SPAN_NAME_EXECUTION = 'evolve.execution';

    public const ATTRIBUTE_EXECUTION_ID = 'evolve.execution.id';

    public const ATTRIBUTE_EXECUTION_KIND = 'evolve.execution.kind';

    public const ATTRIBUTE_EXECUTION_OUTCOME = 'evolve.execution.outcome';

    public const EVENT_HANDLER_COMPLETED = 'evolve.execution.handler_completed';

    public const EVENT_SCOPE_CLOSE_STARTED = 'evolve.execution.scope_close_started';

    public const METRIC_EXECUTION_DURATION = 'evolve.execution.duration';

    public const METRIC_EXECUTION_COUNT = 'evolve.execution.count';

    public const METRIC_EXECUTION_ACTIVE = 'evolve.execution.active';

    public const METRIC_EXECUTION_FAILURES = 'evolve.execution.failures';

    public const METRIC_EXECUTION_QUARANTINES = 'evolve.execution.quarantines';

    public const METRIC_HTTP_SERVER_REQUEST_COUNT = 'evolve.http.server.request.count';

    public const METRIC_HTTP_SERVER_ACTIVE_REQUESTS = 'evolve.http.server.active_requests';

    public const METRIC_HTTP_SERVER_REQUEST_FAILURES = 'evolve.http.server.request.failures';

    public const OUTCOME_SUCCEEDED = 'succeeded';

    public const OUTCOME_FAILED = 'failed';

    public const EXECUTION_KIND_HTTP_REQUEST = ExecutionKind::HttpRequest->value;

    public const EXECUTION_KIND_QUEUE_MESSAGE = ExecutionKind::QueueMessage->value;

    public const EXECUTION_KIND_SCHEDULED_JOB = ExecutionKind::ScheduledJob->value;

    public const EXECUTION_KIND_CLI_COMMAND = ExecutionKind::CliCommand->value;

    public const EXECUTION_KIND_WORKER_TASK = ExecutionKind::WorkerTask->value;

    private function __construct() {}
}
