<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

/**
 * Host implementation must enforce HTTPS, public DNS resolution/IP pinning on connect,
 * no redirects, port 443, bounded response size (64 KiB), connect timeout at most 3 seconds, and read timeout at most 10 seconds.
 * A generic PSR-18 client is not sufficient for this contract.
 */
interface TrustedTransport
{
    /** @param array<string, string> $headers @return int HTTP response status */
    public function send(string $url, string $body, array $headers): int;
}
