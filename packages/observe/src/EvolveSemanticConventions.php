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

    public const OUTCOME_SUCCEEDED = 'succeeded';

    public const OUTCOME_FAILED = 'failed';

    public const EXECUTION_KIND_HTTP_REQUEST = ExecutionKind::HttpRequest->value;

    public const EXECUTION_KIND_QUEUE_MESSAGE = ExecutionKind::QueueMessage->value;

    public const EXECUTION_KIND_SCHEDULED_JOB = ExecutionKind::ScheduledJob->value;

    public const EXECUTION_KIND_CLI_COMMAND = ExecutionKind::CliCommand->value;

    public const EXECUTION_KIND_WORKER_TASK = ExecutionKind::WorkerTask->value;

    private function __construct() {}
}
