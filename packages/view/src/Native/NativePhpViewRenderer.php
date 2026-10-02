<?php

declare(strict_types=1);

namespace Evolve\View\Native;

use Evolve\View\Exception\ViewRenderFailed;
use Evolve\View\Native\Internal\RenderSession;
use Evolve\View\ViewName;
use Evolve\View\ViewPathResolver;
use Evolve\View\ViewRenderer;

/** Native templates are trusted PHP, not sandboxed. @experimental */
final readonly class NativePhpViewRenderer implements ViewRenderer
{
    /** @param array<string, mixed> $sharedData */
    public function __construct(private ViewPathResolver $resolver, private array $sharedData = []) {}

    /** @param array<string, mixed> $data */
    public function render(string $view, array $data = []): string
    {
        $this->resolver->resolve(new ViewName($view));
        $level = ob_get_level();
        $session = new RenderSession($this->resolver);
        try {
            $data = array_replace($this->sharedData, $data);
            $body = $session->render($view, $data);
            if (($layout = $session->layout()) !== null) {
                $session->setBody($body);
                return $session->render($layout, $data);
            }
            return $body;
        } catch (\Throwable $error) {
            throw new ViewRenderFailed('View rendering failed.', 0, $error);
        } finally {
            RenderSession::discardBuffersAbove($level);
        }
    }
}
