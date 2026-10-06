<?php

declare(strict_types=1);

namespace Evolve\Insight\Query;

final class DiagnosticQueryCursorUnavailable extends \InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Diagnostic query cursor does not reference a retained batch.');
    }
}
