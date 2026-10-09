<?php

declare(strict_types=1);

namespace Evolve\Mcp\Transport;

use Evolve\Mcp\Server\McpServerDefinition;
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

/**
 * Opt-in modern MCP HTTP endpoint limited to eager JSON responses.
 */
final readonly class McpHttpRequestHandler implements RequestHandlerInterface
{
    /** @var \Closure(ServerRequestInterface): bool */
    private \Closure $authorize;

    private StatelessProtocol $protocol;

    private ScopedMcpCapabilityInvoker $invoker;

    private StatelessResponder $responder;

    /**
     * @param callable(ServerRequestInterface): bool $authorize
     */
    public function __construct(
        McpServerDefinition $definition,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        callable $authorize,
        private int $maxBodyBytes = StatelessHttpTransport::DEFAULT_MAX_BODY_BYTES,
    ) {
        if (!$definition->invoker() instanceof ScopedMcpCapabilityInvoker) {
            throw new InvalidArgumentException('MCP HTTP requires a scoped capability invoker.');
        }
        $protocol = $definition->statelessProtocol();
        if ($protocol === null) {
            throw new InvalidArgumentException('MCP definition has no modern stateless protocol.');
        }
        if ($maxBodyBytes < 1) {
            throw new InvalidArgumentException('MCP HTTP body limit must be positive.');
        }

        $this->protocol = $protocol;
        $this->invoker = $definition->invoker();
        $this->authorize = \Closure::fromCallable($authorize);
        $this->responder = new StatelessResponder($responseFactory, $streamFactory);
    }

    public function quarantineFailure(): ?\Throwable
    {
        return $this->invoker->quarantineFailure();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertHealthy();

        $middleware = [
            new CorsMiddleware(),
            new DnsRebindingProtectionMiddleware(
                responseFactory: $this->responseFactory,
                streamFactory: $this->streamFactory,
            ),
        ];
        $dispatch = new class (\Closure::fromCallable([$this, 'dispatch'])) implements RequestHandlerInterface {
            /** @param \Closure(ServerRequestInterface): ResponseInterface $callback */
            public function __construct(private \Closure $callback) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->callback)($request);
            }
        };
        $secured = new class ($middleware[1], $dispatch) implements RequestHandlerInterface {
            public function __construct(
                private DnsRebindingProtectionMiddleware $dns,
                private RequestHandlerInterface $dispatch,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->dns->process($request, $this->dispatch);
            }
        };

        return $middleware[0]->process($request, $secured);
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        if (($this->authorize)($request) !== true) {
            return $this->responseFactory->createResponse(403);
        }

        $transport = new StatelessHttpTransport(
            $this->protocol,
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
            $this->assertHealthy();
        }
        if ($response->getBody() instanceof CallbackStream || str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/event-stream')) {
            throw new RuntimeException('SDK produced an unsupported deferred MCP stream.');
        }

        return $response;
    }

    private function assertHealthy(): void
    {
        if ($this->invoker->quarantineFailure() !== null) {
            throw new RuntimeException(
                'MCP capability execution requires worker quarantine; recycle this worker before another request.',
                0,
                $this->invoker->quarantineFailure(),
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
