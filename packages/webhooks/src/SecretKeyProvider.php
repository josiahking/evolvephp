<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

use Evolve\Secret\Contracts\SecretName;
use Evolve\Secret\Contracts\SecretResolver;
use InvalidArgumentException;

/** Adapts only the host's explicitly selected current signing key. */
final readonly class SecretKeyProvider implements KeyProvider
{
    public function __construct(
        private SecretResolver $resolver,
        private string $currentKeyId,
        private string $secretName,
        private int $expiresAt,
    ) {
        if ($currentKeyId === '' || $secretName === '' || $expiresAt < 1) {
            throw new InvalidArgumentException('Current signing key configuration is required.');
        }
    }

    public function resolve(string $keyId, int $at): ?string
    {
        if ($keyId !== $this->currentKeyId || $at < 0 || $at > $this->expiresAt) {
            return null;
        }
        return $this->resolver->resolve(new SecretName($this->secretName))?->value();
    }
}
