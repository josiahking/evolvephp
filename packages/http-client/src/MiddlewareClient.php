<?php

declare(strict_types=1);

namespace Evolve\Http\Client;

use Evolve\Http\Client\Internal\MiddlewareNextClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final readonly class MiddlewareClient implements ClientInterface
{
    private ClientInterface $client;

    public function __construct(
        ClientInterface $transport,
        ClientMiddleware ...$middleware,
    ) {
        $client = $transport;

        foreach (array_reverse($middleware) as $current) {
            $client = new MiddlewareNextClient($current, $client);
        }

        $this->client = $client;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->client->sendRequest($request);
    }
}
