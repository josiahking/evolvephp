<?php

declare(strict_types=1);

namespace Evolve\Observe;

use Evolve\Core\Execution\ExecutionKind;

final class EvolveSemanticConventions
{
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
