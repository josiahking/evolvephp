<?php

declare(strict_types=1);

namespace Evolve\I18n;

use Evolve\I18n\Exception\InvalidMessageName;

final readonly class MessageName
{
    private ?string $namespace;
    private string $key;

    public function __construct(private string $value)
    {
        $parts = explode('::', $value);
        if (count($parts) > 2) {
            throw new InvalidMessageName('Invalid message name.');
        }
        $namespace = count($parts) === 2 ? $parts[0] : null;
        $key = $parts[count($parts) - 1];
        if (($namespace !== null && !self::validNamespace($namespace)) || !self::validKey($key)) {
            throw new InvalidMessageName('Invalid message name.');
        }
        $this->namespace = $namespace;
        $this->key = $key;
    }

    public static function validNamespace(string $value): bool
    {
        return preg_match('/\A[a-z0-9_-]+\z/D', $value) === 1;
    }

    public static function validKey(string $value): bool
    {
        return preg_match('/\A[a-z0-9_-]+(?:\.[a-z0-9_-]+)*\z/D', $value) === 1;
    }

    public function namespace(): ?string
    {
        return $this->namespace;
    }
    public function key(): string
    {
        return $this->key;
    }
    public function __toString(): string
    {
        return $this->value;
    }
}
