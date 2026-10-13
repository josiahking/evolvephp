<?php

declare(strict_types=1);

namespace Evolve\Mcp\Transport;

use Evolve\Mcp\Server\McpAuthorizedServerFactory;
use Evolve\Mcp\Server\McpPrincipal;
use InvalidArgumentException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Transport\CallbackStream;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\StatelessResponder;
use Mcp\Server\Transport\StatelessHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

/** Opt-in modern MCP HTTP endpoint limited to authenticated, eager JSON responses. */
final class McpHttpRequestHandler implements RequestHandlerInterface
{
    /** @var \Closure(ServerRequestInterface): mixed */
    private \Closure $authenticate;

    private StatelessResponder $responder;

    private ?Throwable $quarantineFailure = null;

    /** @param callable(ServerRequestInterface): mixed $authenticate */
    public function __construct(
        private McpAuthorizedServerFactory $servers,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        callable $authenticate,
        private int $maxBodyBytes = StatelessHttpTransport::DEFAULT_MAX_BODY_BYTES,
        private int $maxResponseBytes = 1048576,
    ) {
        if ($maxBodyBytes < 1 || $maxBodyBytes > 16777216
            || $maxResponseBytes < 1 || $maxResponseBytes > 16777216) {
            throw new InvalidArgumentException('MCP HTTP byte limit is invalid.');
        }
        $this->authenticate = \Closure::fromCallable($authenticate);
        $this->responder = new StatelessResponder($responseFactory, $streamFactory);
    }

    public function quarantineFailure(): ?Throwable
    {
        return $this->quarantineFailure;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertHealthy();

        $cors = new CorsMiddleware();
        $dns = new DnsRebindingProtectionMiddleware(
            responseFactory: $this->responseFactory,
            streamFactory: $this->streamFactory,
        );
        $dispatch = new class (\Closure::fromCallable([$this, 'dispatch'])) implements RequestHandlerInterface {
            /** @param \Closure(ServerRequestInterface): ResponseInterface $callback */
            public function __construct(private \Closure $callback) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->callback)($request);
            }
        };
        $secured = new class ($dns, $dispatch) implements RequestHandlerInterface {
            public function __construct(
                private DnsRebindingProtectionMiddleware $dns,
                private RequestHandlerInterface $dispatch,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->dns->process($request, $this->dispatch);
            }
        };

        return $cors->process($request, $secured);
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $principal = ($this->authenticate)($request);
        } catch (Throwable) {
            return $this->responseFactory->createResponse(403);
        }
        if (!$principal instanceof McpPrincipal) {
            return $this->responseFactory->createResponse(403);
        }

        try {
            $definition = $this->servers->create($principal);
        } catch (Throwable) {
            return $this->responseFactory->createResponse(403);
        }
        $protocol = $definition->statelessProtocol();
        $invoker = $definition->invoker();
        if ($protocol === null || !$invoker instanceof ScopedMcpCapabilityInvoker) {
            throw new RuntimeException('Authorized MCP server is invalid.');
        }

        $transport = new StatelessHttpTransport(
            $protocol,
            $this->responseFactory,
            $this->streamFactory,
            maxBodyBytes: $this->maxBodyBytes,
            middleware: [
                new CorsMiddleware(),
                new DnsRebindingProtectionMiddleware(
                    responseFactory: $this->responseFactory,
                    streamFactory: $this->streamFactory,
                ),
            ],
        );

        if ($request->getMethod() !== 'POST') {
            return $transport->handle($request);
        }
        if (str_contains(strtolower($request->getHeaderLine('Accept')), 'text/event-stream')) {
            return $this->responder->error(Error::forInvalidRequest('Streaming responses are unsupported by this endpoint.'), 406);
        }

        $payload = $this->readBoundedBody($request);
        if ($payload === null) {
            return $this->responder->error(Error::forInvalidRequest('MCP HTTP request body exceeds the configured limit.'), 413);
        }
        $decoded = json_decode($payload, true);
        if (is_array($decoded) && ($decoded['method'] ?? null) === StatelessProtocol::LISTEN_METHOD) {
            return $this->responder->error(Error::forInvalidRequest('Subscriptions are unsupported by this endpoint.'), 501);
        }

        try {
            $response = $transport->handle($request->withBody($this->streamFactory->createStream($payload)));
        } finally {
            if ($invoker->quarantineFailure() !== null) {
                $this->quarantineFailure = $invoker->quarantineFailure();
                $this->assertHealthy();
            }
        }
        if ($response->getBody() instanceof CallbackStream
            || str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/event-stream')) {
            throw new RuntimeException('SDK produced an unsupported deferred MCP stream.');
        }
        $body = $response->getBody();
        $size = $body->getSize();
        if ($size !== null && $size > $this->maxResponseBytes) {
            return $this->responder->error(Error::forInternalError('MCP response exceeds the configured limit.'), 500);
        }
        if ($size === null) {
            $bytes = '';
            while (!$body->eof()) {
                $chunk = $body->read(min(8192, $this->maxResponseBytes - strlen($bytes) + 1));
                if ($chunk === '' || strlen($bytes) + strlen($chunk) > $this->maxResponseBytes) {
                    return $this->responder->error(Error::forInternalError('MCP response exceeds the configured limit.'), 500);
                }
                $bytes .= $chunk;
            }
            $response = $response->withBody($this->streamFactory->createStream($bytes));
        }

        return $response;
    }

    private function assertHealthy(): void
    {
        if ($this->quarantineFailure !== null) {
            throw new RuntimeException(
                'MCP capability execution requires worker quarantine; recycle this worker before another request.',
                0,
                $this->quarantineFailure,
            );
        }
    }

    private function readBoundedBody(ServerRequestInterface $request): ?string
    {
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $size = $body->getSize();
        if ($size !== null && $size > $this->maxBodyBytes) {
            return null;
        }

        $payload = '';
        while (!$body->eof()) {
            $chunk = $body->read(min(8192, $this->maxBodyBytes - strlen($payload) + 1));
            if ($chunk === '') {
                break;
            }
            $payload .= $chunk;
            if (strlen($payload) > $this->maxBodyBytes) {
                return null;
            }
        }

        return $payload;
    }
}
