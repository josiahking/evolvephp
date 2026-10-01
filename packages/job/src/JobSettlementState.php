<?php

declare(strict_types=1);

namespace Evolve\Job;

/** @experimental EvolvePHP 2 is pre-beta; this job API may change before stable release. */
enum JobSettlementState
{
    case NotAttempted;
    case Acknowledged;
    case Rejected;
    case AcknowledgeFailed;
    case RejectFailed;
}
