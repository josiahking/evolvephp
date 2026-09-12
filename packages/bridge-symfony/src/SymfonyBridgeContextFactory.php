<?php

declare(strict_types=1);

namespace Evolve\Bridge\Symfony;

use Evolve\Bridge\Contracts\BridgeContext;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @experimental
 */
final readonly class SymfonyBridgeContextFactory
{
    public function __construct(private TokenStorageInterface $tokens) {}

    public function create(
        string $requestIdentifier,
        string $correlationIdentifier,
        ?string $tenantIdentifier = null,
        ?string $locale = null,
        ?string $timezone = null,
    ): BridgeContext {
        return new BridgeContext(
            $requestIdentifier,
            $correlationIdentifier,
            $this->principalIdentifier(),
            $tenantIdentifier,
            $locale,
            $timezone,
        );
    }

    private function principalIdentifier(): ?string
    {
        $token = $this->tokens->getToken();

        if ($token === null) {
            return null;
        }

        $user = $token->getUser();

        if (! $user instanceof UserInterface) {
            return null;
        }

        return $user->getUserIdentifier();
    }
}
