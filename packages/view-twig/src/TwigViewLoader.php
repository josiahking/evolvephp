<?php

declare(strict_types=1);

namespace Evolve\View\Twig;

use Evolve\View\Exception\InvalidViewName;
use Evolve\View\Exception\ViewNotFound;
use Evolve\View\ViewName;
use Evolve\View\ViewPathResolver;
use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/** @experimental */
final readonly class TwigViewLoader implements LoaderInterface
{
    public function __construct(private ViewPathResolver $resolver) {}

    public function getSourceContext(string $name): Source
    {
        $path = $this->path($name);
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new LoaderError('Template cannot be read: ' . $name);
        }
        return new Source($contents, $name, $path);
    }

    public function getCacheKey(string $name): string
    {
        return $this->path($name);
    }

    public function isFresh(string $name, int $time): bool
    {
        $modified = @filemtime($this->path($name));
        return $modified !== false && $modified <= $time;
    }

    public function exists(string $name): bool
    {
        try {
            $this->path($name);
            return true;
        } catch (LoaderError) {
            return false;
        }
    }

    private function path(string $name): string
    {
        try {
            return $this->resolver->resolve(new ViewName($name));
        } catch (InvalidViewName|ViewNotFound $error) {
            throw new LoaderError('Template not found: ' . $name, -1, null, $error);
        }
    }
}
