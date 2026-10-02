<?php

declare(strict_types=1);

namespace Evolve\View;

use Evolve\View\Exception\InvalidViewSource;

/** @experimental */
final readonly class ViewSource
{
    private string $root;

    public function __construct(private ?string $namespace, string $root)
    {
        if ($namespace !== null && !ViewName::isValidSegment($namespace)) {
            throw new InvalidViewSource('Invalid view source namespace.');
        }
        if (!self::isAbsolute($root) || !is_dir($root) || ($canonical = realpath($root)) === false) {
            throw new InvalidViewSource('View source root must be an existing absolute directory.');
        }
        $this->root = rtrim($canonical, '/\\') ?: $canonical;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 || str_starts_with($path, '\\\\');
    }

    public function namespace(): ?string
    {
        return $this->namespace;
    }

    public function root(): string
    {
        return $this->root;
    }
}
