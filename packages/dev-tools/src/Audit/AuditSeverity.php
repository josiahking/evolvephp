<?php

declare(strict_types=1);

namespace Evolve\DevTools\Audit;

/**
 * @experimental
 */
enum AuditSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Risk = 'risk';
}
