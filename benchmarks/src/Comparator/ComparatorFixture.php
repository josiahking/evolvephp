<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Comparator;

interface ComparatorFixture
{
    public function id(): string;

    /**
     * @return array<string, mixed>
     */
    public function availability(): array;

    /**
     * @return array<string, mixed>
     */
    public function applicationBoot(): array;

    /**
     * @return array<string, mixed>
     */
    public function httpStatic(): array;

    /**
     * @return array<string, mixed>
     */
    public function httpParameterized(string $id): array;

    /**
     * @return array<string, mixed>
     */
    public function httpMiddleware(): array;

    /**
     * @return array<string, mixed>
     */
    public function httpNotFound(): array;

    /**
     * @return array<string, mixed>
     */
    public function httpRepeatedWarm(int $requestCount): array;
}
