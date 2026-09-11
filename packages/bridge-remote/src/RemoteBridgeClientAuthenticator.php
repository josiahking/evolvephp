<?php

declare(strict_types=1);

namespace Evolve\Bridge\Remote;

use Psr\Http\Message\RequestInterface;

/**
 * @experimental
 */
interface RemoteBridgeClientAuthenticator
{
    /**
     * @return array<string, list<string>>
     */
    public function authenticationHeaders(
        RequestInterface $request,
        RemoteBridgeInvocation $invocation,
    ): array;
}
