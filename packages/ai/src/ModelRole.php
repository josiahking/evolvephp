<?php

declare(strict_types=1);

namespace Evolve\Ai;

enum ModelRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
