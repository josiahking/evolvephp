<?php

declare(strict_types=1);

namespace Evolve\Bridge\LegacyHttp;

interface LegacyRemoteClientAuthenticator
{
    /**
     * @return array<string, list<string>>
     */
    public function authenticationHeaders(LegacyRemoteInvocation $invocation): array;
}
