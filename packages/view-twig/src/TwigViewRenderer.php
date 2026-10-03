<?php

declare(strict_types=1);

namespace Evolve\View\Twig;

use Evolve\View\Exception\ViewRenderFailed;
use Evolve\View\ViewName;
use Evolve\View\ViewRenderer;
use Twig\Environment;

/** @experimental */
final readonly class TwigViewRenderer implements ViewRenderer
{
    private Environment $environment;

    /** @param array<string, mixed> $sharedData */
    public function __construct(private TwigViewPathResolver $resolver, private array $sharedData = [])
    {
        $this->environment = new Environment(new TwigViewLoader($resolver), [
            'charset' => 'UTF-8',
            'autoescape' => 'html',
        ]);
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    /** @param array<string, mixed> $data */
    public function render(string $view, array $data = []): string
    {
        $this->resolver->resolve(new ViewName($view));
        $result = null;
        $failure = null;
        try {
            $result = $this->environment->render($view, array_replace($this->sharedData, $data));
        } catch (\Throwable $error) {
            $failure = $error;
        }
        try {
            $this->environment->resetGlobals();
        } catch (\Throwable $error) {
            if ($failure === null) {
                $failure = $error;
            }
        }
        if ($failure !== null) {
            throw new ViewRenderFailed('View rendering failed.', 0, $failure);
        }
        return $result;
    }
}
