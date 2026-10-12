<?php

declare(strict_types=1);

namespace Evolve\Mcp\Client;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/** Enforces one JSON-only, bounded endpoint around a caller-owned PSR-18 client. */
final readonly class McpHttpPolicyClient implements ClientInterface
{
    /** @var \Closure(string): bool */
    private \Closure $approveDestination;

    /** @param callable(string): bool $approveDestination */
    public function __construct(
        private ClientInterface $inner,
        private StreamFactoryInterface $streams,
        private string $endpoint,
        callable $approveDestination,
        private int $maxResponseBytes,
        private ?string $bearerToken = null,
    ) {
        if ($maxResponseBytes < 1 || $maxResponseBytes > 16777216) {
            throw new \InvalidArgumentException('MCP response limit is invalid.');
        }
        $this->approveDestination = \Closure::fromCallable($approveDestination);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = (string) $request->getUri();
        if ($uri !== $this->endpoint || $request->getMethod() !== 'POST') {
            throw new McpClientException(McpClientFailureCategory::Security, 'MCP destination or method denied.');
        }
        try {
            $approved = ($this->approveDestination)($uri) === true;
        } catch (Throwable) {
            $approved = false;
        }
        if (!$approved) {
            throw new McpClientException(McpClientFailureCategory::Security, 'MCP destination or method denied.');
        }
        $request = $request->withHeader('Accept', 'application/json');
        if ($this->bearerToken !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->bearerToken);
        }

        try {
            $response = $this->inner->sendRequest($request);
        } catch (Throwable) {
            throw new McpClientException(McpClientFailureCategory::Connection, 'MCP HTTP transport failed.');
        }

        if ($response->hasHeader('Mcp-Session-Id')
            || $response->hasHeader('Location')
            || ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400)) {
            throw new McpClientException(McpClientFailureCategory::Security, 'MCP stateful or redirect response denied.');
        }
        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0]));
        if ($contentType !== 'application/json' && !($response->getStatusCode() === 202 && $contentType === '')) {
            throw new McpClientException(McpClientFailureCategory::Security, 'MCP response content type denied.');
        }

        $body = $response->getBody();
        if ($body->getSize() !== null && $body->getSize() > $this->maxResponseBytes) {
            throw new McpClientException(McpClientFailureCategory::Security, 'MCP response body limit exceeded.');
        }
        $bytes = '';
        try {
            while (!$body->eof()) {
                $chunk = $body->read(min(8192, $this->maxResponseBytes - strlen($bytes) + 1));
                if ($chunk === '') {
                    throw new McpClientException(McpClientFailureCategory::Connection, 'MCP response body stalled.');
                }
                $bytes .= $chunk;
                if (strlen($bytes) > $this->maxResponseBytes) {
                    throw new McpClientException(McpClientFailureCategory::Security, 'MCP response body limit exceeded.');
                }
            }
        } catch (McpClientException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new McpClientException(McpClientFailureCategory::Connection, 'MCP response body read failed.');
        }

        return $response->withBody($this->streams->createStream($bytes));
    }
}
