<?php

declare(strict_types=1);

namespace Evolve\Http\Routing;

use Evolve\Http\Routing\Internal\RoutePattern;
use InvalidArgumentException;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class Route
{
    /**
     * @var list<string>
     */
    private array $methods;

    private string $path;

    /**
     * @param iterable<mixed> $methods
     */
    public function __construct(
        iterable $methods,
        string $path,
        private RequestHandlerInterface $handler,
    ) {
        $orderedMethods = [];
        $seenMethods = [];

        foreach ($methods as $method) {
            if (!is_string($method)) {
                throw new InvalidArgumentException('Route methods must be strings.');
            }

            if ($method === '') {
                throw new InvalidArgumentException('Route methods must not be empty.');
            }

            if (preg_match('/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/', $method) !== 1) {
                throw new InvalidArgumentException('Route methods must be valid HTTP tokens.');
            }

            if (isset($seenMethods[$method])) {
                throw new InvalidArgumentException('Route methods must not contain duplicates.');
            }

            $seenMethods[$method] = true;
            $orderedMethods[] = $method;
        }

        if ($orderedMethods === []) {
            throw new InvalidArgumentException('Route must declare at least one method.');
        }

        RoutePattern::fromPath($path);

        $this->methods = $orderedMethods;
        $this->path = $path;
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function handler(): RequestHandlerInterface
    {
        return $this->handler;
    }
}
