<?php

declare(strict_types=1);

namespace Evolve\Http\Client;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface ClientMiddleware
{
    public function process(
        RequestInterface $request,
        ClientInterface $next,
    ): ResponseInterface;
}
