<?php

declare(strict_types=1);

namespace Evolve\Bridge\Remote;

use Evolve\Bridge\Contracts\BridgeError;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @experimental
 */
interface RemoteBridgeAuthenticator
{
    public function authenticate(ServerRequestInterface $request, RemoteBridgeInvocation $invocation): ?BridgeError;
}
