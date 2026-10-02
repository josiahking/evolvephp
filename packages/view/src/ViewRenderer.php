<?php

declare(strict_types=1);

namespace Evolve\View;

/** @experimental */
interface ViewRenderer
{
    /** @param array<string, mixed> $data */
    public function render(string $view, array $data = []): string;
}
