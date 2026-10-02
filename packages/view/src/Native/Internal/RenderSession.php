<?php

declare(strict_types=1);

namespace Evolve\View\Native\Internal;

use Evolve\View\Native\NativeViewContext;
use Evolve\View\ViewName;
use Evolve\View\ViewPathResolver;

/** Internal, per top-level render state. */
final class RenderSession
{
    private int $depth = 0;

    private ?string $layout = null;

    /** @var array<string, string> */
    private array $sections = [];

    private string $body = '';

    public function __construct(private readonly ViewPathResolver $resolver) {}

    /** @param array<string, mixed> $data */
    public function render(string $name, array $data): string
    {
        if ($this->depth >= 32) {
            throw new \RuntimeException('Maximum view nesting depth exceeded.');
        }
        ++$this->depth;
        try {
            $path = $this->resolver->resolve(new ViewName($name));
            $view = new NativeViewContext($this, $data);
            $level = ob_get_level();
            ob_start();
            try {
                (static function (string $path, NativeViewContext $view): void {
                    require $path;
                })($path, $view);
                while (ob_get_level() > $level + 1) {
                    echo ob_get_clean();
                }
                if (ob_get_level() <= $level) {
                    throw new \RuntimeException('Render output buffer was closed by the template.');
                }
                return (string) ob_get_clean();
            } catch (\Throwable $error) {
                self::discardBuffersAbove($level);
                throw $error;
            }
        } finally {
            --$this->depth;
        }
    }

    public static function discardBuffersAbove(int $level): void
    {
        while (ob_get_level() > $level) {
            $before = ob_get_level();
            try {
                self::endBuffer(ob_end_clean(...));
            } catch (\Throwable) {
                // Keep the template failure as the primary Throwable.
            }
            if (ob_get_level() >= $before) {
                break;
            }
        }
    }

    /** @param callable(): bool $cleanup */
    private static function endBuffer(callable $cleanup): void
    {
        $cleanup();
    }

    public function selectLayout(string $name): void
    {
        if ($this->layout !== null) {
            throw new \LogicException('Only one layout may be selected.');
        }
        new ViewName($name);
        $this->layout = $name;
    }

    public function layout(): ?string
    {
        return $this->layout;
    }

    public function section(string $name, string $content): void
    {
        $this->sections[$name] = $content;
    }

    public function sectionContent(string $name): string
    {
        return $this->sections[$name] ?? '';
    }

    public function body(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }
}
