<?php

declare(strict_types=1);

namespace Evolve\View\Native;

use Evolve\View\Native\Internal\RenderSession;

/** Narrow template context. Data is available only by explicit key lookup. @experimental */
final readonly class NativeViewContext
{
    /** @param array<string, mixed> $data */
    public function __construct(private RenderSession $session, private array $data) {}

    public function data(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function text(string|int|float|bool|\Stringable|TrustedHtml $value): string
    {
        if ($value instanceof TrustedHtml) {
            return $value->html;
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function raw(TrustedHtml $value): string
    {
        return $value->html;
    }

    /** @param array<string, mixed> $data */
    public function partial(string $view, array $data = []): string
    {
        return $this->session->render($view, array_replace($this->data, $data));
    }

    public function layout(string $view): void
    {
        $this->session->selectLayout($view);
    }

    public function section(string $name, string|TrustedHtml $content): void
    {
        $this->session->section($name, $this->text($content));
    }

    public function sectionContent(string $name): string
    {
        return $this->session->sectionContent($name);
    }

    public function body(): string
    {
        return $this->session->body();
    }
}
