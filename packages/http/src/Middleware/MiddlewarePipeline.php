<?php

declare(strict_types=1);

namespace Evolve\Http\Middleware;

use Evolve\Http\Middleware\Internal\MiddlewareDispatcher;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @var list<MiddlewareInterface>
     */
    private array $middleware;

    /**
     * @param iterable<mixed> $middleware
     */
    public function __construct(
        iterable $middleware,
        private RequestHandlerInterface $terminalHandler,
    ) {
        $orderedMiddleware = [];

        foreach ($middleware as $entry) {
            if (!$entry instanceof MiddlewareInterface) {
                throw new InvalidArgumentException('MiddlewarePipeline entries must implement ' . MiddlewareInterface::class . '.');
            }

            $orderedMiddleware[] = $entry;
        }

        $this->middleware = $orderedMiddleware;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return (new MiddlewareDispatcher($this->middleware, $this->terminalHandler))->handle($request);
    }
}
