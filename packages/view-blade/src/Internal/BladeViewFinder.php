<?php

declare(strict_types=1);

namespace Evolve\View\Blade\Internal;

use Evolve\View\ViewName;
use Evolve\View\ViewPathResolver;
use Illuminate\View\ViewFinderInterface;

/** @internal */
final readonly class BladeViewFinder implements ViewFinderInterface
{
    public function __construct(private ViewPathResolver $resolver) {}

    public function find($view)
    {
        return $this->resolver->resolve(new ViewName($view));
    }

    public function addLocation($location)
    {
        throw new \LogicException('Blade view sources are registered through ViewSource.');
    }

    public function prependLocation(string $location): void
    {
        throw new \LogicException('Blade view sources are registered through ViewSource.');
    }

    /** @param string|list<string> $hints */
    public function addNamespace($namespace, $hints)
    {
        throw new \LogicException('Blade view sources are registered through ViewSource.');
    }

    /** @param string|list<string> $hints */
    public function prependNamespace($namespace, $hints)
    {
        throw new \LogicException('Blade view sources are registered through ViewSource.');
    }

    /** @param string|list<string> $hints */
    public function replaceNamespace($namespace, $hints)
    {
        throw new \LogicException('Blade view sources are registered through ViewSource.');
    }

    public function addExtension($extension)
    {
        throw new \LogicException('Blade view extensions are fixed to .blade.php.');
    }

    public function flush()
    {
        // The Evolve resolver revalidates paths on every lookup.
    }
}
