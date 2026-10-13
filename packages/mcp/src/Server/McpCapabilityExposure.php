<?php

declare(strict_types=1);

namespace Evolve\Mcp\Server;

enum McpCapabilityExposure: string
{
    case LocalOnly = 'local';
    case RemoteApproved = 'remote';
}
