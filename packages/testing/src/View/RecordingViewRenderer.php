<?php

declare(strict_types=1);

namespace Evolve\Testing\View;

use Evolve\View\ViewRenderer;

/** Deterministic recording renderer for application tests. @experimental */
final class RecordingViewRenderer implements ViewRenderer
{
    /** @var list<RecordedViewRender> */
    private array $renders = [];

    /** @param array<string, mixed> $data */
    public function render(string $view, array $data = []): string
    {
        $this->renders[] = new RecordedViewRender($view, $data);
        return '';
    }

    /** @return list<RecordedViewRender> */
    public function renders(): array
    {
        return $this->renders;
    }

    public function reset(): void
    {
        $this->renders = [];
    }
}
