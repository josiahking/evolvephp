<?php

declare(strict_types=1);

namespace Evolve\View\Blade;

use Evolve\View\Blade\Exception\InvalidBladeCachePath;
use Evolve\View\Blade\Internal\BladeViewFinder;
use Evolve\View\Exception\ViewRenderFailed;
use Evolve\View\ViewName;
use Evolve\View\ViewRenderer;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\ViewFinderInterface;

/** @experimental */
final readonly class BladeViewRenderer implements ViewRenderer
{
    private Factory $factory;
    private BladeCompiler $compiler;
    private EngineResolver $engines;

    /** @param array<string, mixed> $sharedData */
    public function __construct(private BladeViewPathResolver $resolver, string $cachePath, private array $sharedData = [])
    {
        if (!self::isAbsolute($cachePath) || !is_dir($cachePath) || ($canonical = realpath($cachePath)) === false || !is_writable($canonical)) {
            throw new InvalidBladeCachePath('Blade cache path must be an existing writable absolute directory.');
        }

        $files = new Filesystem();
        $container = new Container();
        $events = new Dispatcher($container);
        $this->compiler = new BladeCompiler($files, $canonical);
        $this->engines = new EngineResolver();
        $this->engines->register('blade', fn(): CompilerEngine => new CompilerEngine($this->compiler, $files));

        $this->factory = new class ($this->engines, new BladeViewFinder($resolver), $events) extends Factory {
            public function flushState()
            {
                parent::flushState();
                while ($this->getLoopStack() !== []) {
                    $this->popLoop();
                }
            }

            public function setFinder(ViewFinderInterface $finder)
            {
                throw new \LogicException('Blade view sources are registered through ViewSource.');
            }

            /**
             * @param \Illuminate\Contracts\Support\Arrayable<array-key, mixed>|array<array-key, mixed> $data
             * @param array<array-key, mixed> $mergeData
             */
            public function make($view, $data = [], $mergeData = [])
            {
                return $this->file($this->getFinder()->find($view), $data, $mergeData);
            }
        };
        $this->factory->setContainer($container);
    }

    public function factory(): Factory
    {
        return $this->factory;
    }

    public function compiler(): BladeCompiler
    {
        return $this->compiler;
    }

    /** @param array<string, mixed> $data */
    public function render(string $view, array $data = []): string
    {
        $this->resolver->resolve(new ViewName($view));
        $result = null;
        $failure = null;
        try {
            $result = $this->factory->make($view, array_replace($this->sharedData, $data))->render();
        } catch (\Throwable $error) {
            $failure = $error;
        }
        try {
            $this->factory->flushState();
            $engine = $this->engines->resolve('blade');
            if (!$engine instanceof CompilerEngine) {
                throw new \LogicException('Blade engine resolver no longer contains a CompilerEngine.');
            }
            $engine->forgetCompiledOrNotExpired();
            $this->engines->forget('blade');
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

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 || str_starts_with($path, '\\\\');
    }
}
