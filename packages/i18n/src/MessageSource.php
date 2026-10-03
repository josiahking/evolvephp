<?php

declare(strict_types=1);

namespace Evolve\I18n;

use Evolve\I18n\Exception\InvalidMessageSource;

final readonly class MessageSource
{
    private string $root;

    public function __construct(private ?string $namespace, string $root)
    {
        if ($namespace !== null && !MessageName::validNamespace($namespace)) {
            throw new InvalidMessageSource('Invalid message source namespace.');
        }
        $absolute = str_starts_with($root, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $root) === 1 || str_starts_with($root, '\\\\');
        $canonical = realpath($root);
        if (!$absolute || $canonical === false || !is_dir($canonical)) {
            throw new InvalidMessageSource('Message source root must be an existing absolute directory.');
        }
        $this->root = rtrim($canonical, '/\\') ?: $canonical;
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
