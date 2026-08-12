<?php

declare(strict_types=1);

namespace Evolve\Http\Middleware\Internal;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @internal
 */
final readonly class MiddlewareDispatcher implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private array $middleware,
        private RequestHandlerInterface $terminalHandler,
        private int $index = 0,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middleware[$this->index])) {
            return $this->terminalHandler->handle($request);
        }

        return $this->middleware[$this->index]->process(
            $request,
            new self($this->middleware, $this->terminalHandler, $this->index + 1),
        );
    }
}
