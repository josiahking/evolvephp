<?php

declare(strict_types=1);

namespace Evolve\View;

use Evolve\View\Exception\InvalidViewName;

/** @experimental */
final readonly class ViewName
{
    private ?string $namespace;

    private string $path;

    public function __construct(private string $value)
    {
        $parts = explode('::', $value);
        if (count($parts) > 2) {
            throw new InvalidViewName('Invalid logical view name.');
        }
        $namespace = count($parts) === 2 ? $parts[0] : null;
        $path = $parts[count($parts) - 1];
        if (($namespace !== null && !self::isValidSegment($namespace))
            || !preg_match('/^[a-z0-9_-]+(?:\/[a-z0-9_-]+)*$/D', $path)) {
            throw new InvalidViewName('Invalid logical view name.');
        }
        $this->namespace = $namespace;
        $this->path = $path;
    }

    public static function isValidSegment(string $value): bool
    {
        return preg_match('/^[a-z0-9_-]+$/D', $value) === 1;
    }

    public function namespace(): ?string
    {
        return $this->namespace;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
