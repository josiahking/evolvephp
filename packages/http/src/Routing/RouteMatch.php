<?php

declare(strict_types=1);

namespace Evolve\Http\Routing;

use InvalidArgumentException;

final readonly class RouteMatch
{
    /**
     * @var array<string, string>
     */
    private array $parameters;

    /**
     * @param array<mixed, mixed> $parameters
     */
    public function __construct(
        private Route $route,
        array $parameters,
    ) {
        foreach ($parameters as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new InvalidArgumentException('RouteMatch parameters must be an array of string keys and string values.');
            }
        }

        $this->parameters = $parameters;
    }

    public function route(): Route
    {
        return $this->route;
    }

    /**
     * @return array<string, string>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }
}
