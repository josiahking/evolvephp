<?php

declare(strict_types=1);

namespace Evolve\Testing\View;

/** Data array is captured by value; contained objects retain identity. @experimental */
final readonly class RecordedViewRender
{
    /** @param array<string, mixed> $data */
    public function __construct(public string $view, public array $data) {}
}
