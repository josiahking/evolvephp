<?php

declare(strict_types=1);

namespace Evolve\Http\Routing\Internal;

use InvalidArgumentException;

/**
 * @internal
 */
final readonly class RoutePattern
{
    /**
     * @var list<array{kind: 'static'|'parameter', value: string}>
     */
    private array $segments;

    private bool $isStatic;

    /**
     * @param list<array{kind: 'static'|'parameter', value: string}> $segments
     */
    private function __construct(
        private string $path,
        array $segments,
    ) {
        $this->segments = $segments;
        $this->isStatic = !$this->hasParameters($segments);
    }

    /**
     * @param list<array{kind: 'static'|'parameter', value: string}> $segments
     */
    private static function hasParameters(array $segments): bool
    {
        foreach ($segments as $segment) {
            if ($segment['kind'] === 'parameter') {
                return true;
            }
        }

        return false;
    }

    public static function fromPath(string $path): self
    {
        if ($path === '') {
            throw new InvalidArgumentException('Route path must not be empty.');
        }

        if ($path[0] !== '/') {
            throw new InvalidArgumentException('Route path must begin with /.');
        }

        if (str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException('Route path must not contain a query string or fragment.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidArgumentException('Route path must not contain control characters.');
        }

        if ($path === '/') {
            return new self($path, []);
        }

        $parameterNames = [];
        $segments = [];

        foreach (explode('/', substr($path, 1)) as $segment) {
            if ($segment === '') {
                $segments[] = ['kind' => 'static', 'value' => $segment];
                continue;
            }

            if (str_starts_with($segment, '{') || str_ends_with($segment, '}')) {
                if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $matches) !== 1) {
                    throw new InvalidArgumentException('Route parameter segments must use {name} syntax.');
                }

                $name = $matches[1];

                if (isset($parameterNames[$name])) {
                    throw new InvalidArgumentException('Route parameter names must be unique within a route path.');
                }

                $parameterNames[$name] = true;
                $segments[] = ['kind' => 'parameter', 'value' => $name];
                continue;
            }

            if (str_contains($segment, '{') || str_contains($segment, '}')) {
                throw new InvalidArgumentException('Route parameters must occupy an entire path segment.');
            }

            $segments[] = ['kind' => 'static', 'value' => $segment];
        }

        return new self($path, $segments);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
        if ($path === '') {
            return null;
        }

        if ($path[0] !== '/') {
            return null;
        }

        if ($this->isStatic) {
            return $path === $this->path ? [] : null;
        }

        $candidateSegments = $path === '/'
            ? []
            : explode('/', substr($path, 1));

        return $this->matchSegments($candidateSegments);
    }

    /**
     * @param list<string> $candidateSegments
     * @return array<string, string>|null
     *
     * @internal
     */
    public function matchSegments(array $candidateSegments): ?array
    {
        if (count($candidateSegments) !== count($this->segments)) {
            return null;
        }

        $parameters = [];

        foreach ($this->segments as $index => $segment) {
            $candidate = $candidateSegments[$index];

            if ($segment['kind'] === 'static') {
                if ($candidate !== $segment['value']) {
                    return null;
                }

                continue;
            }

            if ($candidate === '') {
                return null;
            }

            $parameters[$segment['value']] = $candidate;
        }

        return $parameters;
    }

    /**
     * @internal
     */
    public function isStatic(): bool
    {
        return $this->isStatic;
    }
}
