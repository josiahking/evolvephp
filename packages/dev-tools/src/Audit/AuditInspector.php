<?php

declare(strict_types=1);

namespace Evolve\DevTools\Audit;

/**
 * @experimental
 */
interface AuditInspector
{
    public function inspect(string $projectRoot): mixed;
}
