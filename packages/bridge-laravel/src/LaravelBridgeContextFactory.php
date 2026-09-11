<?php

declare(strict_types=1);

namespace Evolve\Bridge\Laravel;

use Evolve\Bridge\Contracts\BridgeContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class LaravelBridgeContextFactory
{
    public function create(
        Request $request,
        string $requestIdentifier,
        string $correlationIdentifier,
        ?string $tenantIdentifier = null,
        ?string $locale = null,
        ?string $timezone = null,
    ): BridgeContext {
        return new BridgeContext(
            $requestIdentifier,
            $correlationIdentifier,
            $this->principalIdentifier($request),
            $tenantIdentifier,
            $locale,
            $timezone,
        );
    }

    private function principalIdentifier(Request $request): ?string
    {
        $principal = $request->user();

        if ($principal === null) {
            return null;
        }

        if (! $principal instanceof Authenticatable) {
            throw new InvalidArgumentException('Laravel authenticated principal must implement Illuminate\Contracts\Auth\Authenticatable.');
        }

        $identifier = $principal->getAuthIdentifier();

        if (is_string($identifier) || is_int($identifier)) {
            return (string) $identifier;
        }

        throw new InvalidArgumentException('Laravel authenticated principal identifier must be a safe scalar value.');
    }
}
