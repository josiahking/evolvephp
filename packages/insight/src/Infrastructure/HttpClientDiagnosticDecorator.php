<?php

declare(strict_types=1);

namespace Evolve\Insight\Infrastructure;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final readonly class HttpClientDiagnosticDecorator implements ClientInterface
{
    public function __construct(private ClientInterface $client, private DiagnosticRecorder $recorder) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->recorder->run(
            'http',
            'sendRequest',
            fn(): ResponseInterface => $this->client->sendRequest($request),
            fn(ResponseInterface $response): array => $this->methodAttribute($request) + ['status_code' => $response->getStatusCode()],
            fn(\Throwable $failure): array => $this->methodAttribute($request),
        );
    }

    /** @return array{method?: string} */
    private function methodAttribute(RequestInterface $request): array
    {
        $method = $request->getMethod();

        return preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,32}$/D', $method) === 1 ? ['method' => $method] : [];
    }
}
