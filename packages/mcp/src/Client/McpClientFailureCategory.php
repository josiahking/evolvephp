<?php

declare(strict_types=1);

namespace Evolve\Mcp\Client;

enum McpClientFailureCategory: string
{
    case Configuration = 'configuration';
    case Secret = 'secret';
    case Security = 'security';
    case Connection = 'connection';
    case Discovery = 'discovery';
    case Operation = 'operation';
    case Cleanup = 'cleanup';
    case Unusable = 'unusable';
}
