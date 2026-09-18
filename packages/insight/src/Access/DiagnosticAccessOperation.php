<?php

declare(strict_types=1);

namespace Evolve\Insight\Access;

enum DiagnosticAccessOperation: string
{
    case List = 'list';
    case Detail = 'detail';
}
