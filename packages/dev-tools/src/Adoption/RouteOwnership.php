<?php

declare(strict_types=1);

namespace Evolve\DevTools\Adoption;

use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class RouteOwnership
{
    public function __construct(
        private string $route,
        private RouteOwner $currentOwner,
        private RouteOwner $targetOwner,
    ) {
        if (trim($route) === '') {
            throw new InvalidArgumentException('Route identifier must be non-empty.');
        }
    }

    public function route(): string
    {
        return $this->route;
    }

    public function currentOwner(): RouteOwner
    {
        return $this->currentOwner;
    }

    public function targetOwner(): RouteOwner
    {
        return $this->targetOwner;
    }
}
