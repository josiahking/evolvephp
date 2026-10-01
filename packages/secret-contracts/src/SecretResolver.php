<?php

declare(strict_types=1);

namespace Evolve\Secret\Contracts;

/**
 * Resolves the provider's current/default value for a logical secret name.
 *
 * @experimental EvolvePHP 2 is pre-beta; this secret contract may change before stable release.
 */
interface SecretResolver
{
    /**
     * Returns null when the secret is currently absent or unresolved.
     *
     * @throws Exception\SecretException When a provider or backend operation fails.
     */
    public function resolve(SecretName $name): ?SecretValue;
}
