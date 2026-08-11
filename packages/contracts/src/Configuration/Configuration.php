<?php

declare(strict_types=1);

namespace Evolve\Contracts\Configuration;

interface Configuration
{
    public function has(string $key): bool;

    public function get(string $key, mixed $default = null): mixed;

    public function require(string $key): mixed;

    /**
     * @return array<string, mixed>
     */
    public function all(): array;
}
