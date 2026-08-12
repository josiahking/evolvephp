<?php

declare(strict_types=1);

namespace Evolve\Core\Execution;

enum ExecutionKind: string
{
    case HttpRequest = 'http-request';
    case QueueMessage = 'queue-message';
    case ScheduledJob = 'scheduled-job';
    case CliCommand = 'cli-command';
    case WorkerTask = 'worker-task';
}
