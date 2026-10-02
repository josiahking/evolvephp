<?php

declare(strict_types=1);

namespace Evolve\Migration;

use Evolve\Contracts\Component\ComponentIdentifier;

/** @experimental This API may change before stable release. */
final readonly class MigrationOwner
{
    private function __construct(private ?ComponentIdentifier $component) {}

    public static function application(): self
    {
        return new self(null);
    }

    public static function module(ComponentIdentifier $component): self
    {
        return new self($component);
    }

    public function isApplication(): bool
    {
        return $this->component === null;
    }

    public function componentIdentifier(): ?ComponentIdentifier
    {
        return $this->component;
    }

    public function key(): string
    {
        return $this->component === null ? 'application' : 'module:' . $this->component->value();
    }
}
