<?php

declare(strict_types=1);

namespace Evolve\Bridge\LegacyHttp;

interface LegacyRemoteTransport
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function send(string $method, string $endpoint, array $headers, string $body, float $connectTimeoutSeconds, float $requestTimeoutSeconds): LegacyRemoteTransportResponse;
}
