<?php

declare(strict_types=1);

namespace Evolve\Mcp\Client;

use RuntimeException;

/** A safe, categorical client failure. Raw SDK and transport throwables are not retained. */
final class McpClientException extends RuntimeException
{
    public function __construct(
        public readonly McpClientFailureCategory $category,
        string $message,
        public readonly ?McpClientFailureCategory $cleanupCategory = null,
    ) {
        parent::__construct($message);
    }
}
