<?php

declare(strict_types=1);

namespace Evolve\Http\Client\Internal;

use Evolve\Http\Client\ClientMiddleware;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final readonly class MiddlewareNextClient implements ClientInterface
{
    public function __construct(
        private ClientMiddleware $middleware,
        private ClientInterface $next,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->middleware->process($request, $this->next);
    }
}
