<?php

declare(strict_types=1);

namespace Evolve\Ai;

enum ModelFailureCategory: string
{
    case Authentication = 'authentication';
    case RateLimited = 'rate_limited';
    case Timeout = 'timeout';
    case Cancelled = 'cancelled';
    case Unavailable = 'unavailable';
    case InvalidRequest = 'invalid_request';
    case ProviderError = 'provider_error';
}
