<?php

declare(strict_types=1);

namespace Evolve\View\Twig\Tests\Unit;

use Evolve\View\Exception\ViewNotFound;
use Evolve\View\Twig\TwigViewLoader;
use Evolve\View\Twig\TwigViewPathResolver;
use Evolve\View\Twig\TwigViewRenderer;
use Evolve\View\ViewSource;
use PHPUnit\Framework\TestCase;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

final class TwigViewRendererTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/evolve-twig-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function test_owned_environment_and_shared_data_override(): void
    {
        file_put_contents($this->root . '/page.html.twig', '{{ value is null ? "null" : value }}');
        $renderer = new TwigViewRenderer(new TwigViewPathResolver([new ViewSource(null, $this->root)]), ['value' => 'shared']);
        self::assertInstanceOf(TwigViewLoader::class, $renderer->environment()->getLoader());
        self::assertSame('shared', $renderer->render('page'));
        self::assertSame('null', $renderer->render('page', ['value' => null]));
        self::assertSame('shared', $renderer->render('page'));
    }

    public function test_missing_top_level_view_remains_typed(): void
    {
        $renderer = new TwigViewRenderer(new TwigViewPathResolver([new ViewSource(null, $this->root)]));
        $this->expectException(ViewNotFound::class);
        $renderer->render('missing');
    }

    public function test_malformed_top_level_name_remains_invalid(): void
    {
        $renderer = new TwigViewRenderer(new TwigViewPathResolver([new ViewSource(null, $this->root)]));
        $this->expectException(\Evolve\View\Exception\InvalidViewName::class);
        $renderer->render('page.html.twig');
    }

    public function test_loader_source_cache_key_and_freshness(): void
    {
        $path = $this->root . '/page.html.twig';
        file_put_contents($path, 'hello');
        $loader = new TwigViewLoader(new TwigViewPathResolver([new ViewSource(null, $this->root)]));
        self::assertTrue($loader->exists('page'));
        self::assertSame('hello', $loader->getSourceContext('page')->getCode());
        self::assertSame(realpath($path), $loader->getCacheKey('page'));
        self::assertTrue($loader->isFresh('page', time() + 1));
        self::assertFalse($loader->isFresh('page', 0));
        self::assertFalse($loader->exists('missing'));
    }

    public function test_globals_are_reset_after_success_and_failure(): void
    {
        file_put_contents($this->root . '/page.html.twig', '{{ stamp }}');
        file_put_contents($this->root . '/broken.html.twig', '{{ missing_function() }}');
        $renderer = new TwigViewRenderer(new TwigViewPathResolver([new ViewSource(null, $this->root)]));
        $extension = new class extends AbstractExtension implements GlobalsInterface {
            private int $calls = 0;

            public function getGlobals(): array
            {
                return ['stamp' => ++$this->calls];
            }
        };
        $renderer->environment()->addExtension($extension);
        self::assertSame('1', $renderer->render('page'));
        self::assertSame('2', $renderer->render('page'));
        try {
            $renderer->render('broken');
            self::fail('Expected Twig failure.');
        } catch (\Evolve\View\Exception\ViewRenderFailed) {
        }
        self::assertSame('3', $renderer->render('page'));
    }

    public function test_loader_fails_safely_when_resolved_source_cannot_be_read(): void
    {
        $resolver = new class implements \Evolve\View\ViewPathResolver {
            public function resolve(\Evolve\View\ViewName $name): string
            {
                return sys_get_temp_dir() . '/missing-twig-source-' . bin2hex(random_bytes(8));
            }
        };
        $loader = new TwigViewLoader($resolver);
        $this->expectException(\Twig\Error\LoaderError::class);
        $loader->getSourceContext('page');
    }
}
