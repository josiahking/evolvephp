<?php

declare(strict_types=1);

namespace Evolve\Bridge\Contracts;

/**
 * @experimental
 */
enum BridgeErrorKind: string
{
    case Configuration = 'configuration';
    case Compatibility = 'compatibility';
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case Validation = 'validation';
    case Translation = 'translation';
    case BootOrReadiness = 'boot_or_readiness';
    case Execution = 'execution';
    case Timeout = 'timeout';
    case Cancellation = 'cancellation';
    case Transport = 'transport';
    case Protocol = 'protocol';
    case UncertainOutcome = 'uncertain_outcome';
    case ResetOrQuarantine = 'reset_or_quarantine';
    case DependencyUnavailable = 'dependency_unavailable';
}
