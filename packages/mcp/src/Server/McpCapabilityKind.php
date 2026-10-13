<?php

declare(strict_types=1);

namespace Evolve\Mcp\Server;

enum McpCapabilityKind: string
{
    case Tool = 'tool';
    case Resource = 'resource';
    case Prompt = 'prompt';
}
