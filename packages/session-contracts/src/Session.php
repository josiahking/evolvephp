<?php

declare(strict_types=1);

namespace Evolve\Session\Contracts;

interface Session
{
    public function has(string $key): bool;

    public function get(
        string $key,
        mixed $default = null,
    ): mixed;

    public function set(
        string $key,
        mixed $value,
    ): void;

    public function remove(string $key): void;

    public function clear(): void;
}
