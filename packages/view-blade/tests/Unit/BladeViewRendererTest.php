<?php

declare(strict_types=1);

namespace Evolve\View\Blade\Tests\Unit;

use Evolve\View\Blade\BladeViewPathResolver;
use Evolve\View\Blade\BladeViewRenderer;
use Evolve\View\Blade\Exception\InvalidBladeCachePath;
use Evolve\View\Blade\Internal\BladeViewFinder;
use Evolve\View\Exception\ViewNotFound;
use Evolve\View\ViewSource;
use PHPUnit\Framework\TestCase;

final class BladeViewRendererTest extends TestCase
{
    private string $root;
    private string $cache;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/evolve-blade-' . bin2hex(random_bytes(8));
        $this->cache = sys_get_temp_dir() . '/evolve-blade-cache-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        mkdir($this->cache);
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->cache] as $root) {
            foreach (glob($root . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }

    public function test_owned_engine_and_shared_data_override(): void
    {
        file_put_contents($this->root . '/page.blade.php', '{{ $value === null ? "null" : $value }}');
        $renderer = $this->renderer(['value' => 'shared']);
        self::assertNotSame($renderer->factory(), $this->renderer()->factory());
        self::assertNotSame($renderer->compiler(), $this->renderer()->compiler());
        self::assertSame('shared', $renderer->render('page'));
        self::assertSame('null', $renderer->render('page', ['value' => null]));
        self::assertSame('shared', $renderer->render('page'));
    }

    public function test_missing_top_level_view_remains_typed(): void
    {
        $this->expectException(ViewNotFound::class);
        $this->renderer()->render('missing');
    }

    public function test_malformed_top_level_name_remains_invalid(): void
    {
        $this->expectException(\Evolve\View\Exception\InvalidViewName::class);
        $this->renderer()->render('page.blade.php');
    }

    public function test_invalid_cache_paths_are_rejected(): void
    {
        foreach (['relative/path', $this->root . '/missing', $this->root . '/file'] as $path) {
            if (str_ends_with($path, '/file')) {
                file_put_contents($path, 'file');
            }
            try {
                new BladeViewRenderer(new BladeViewPathResolver([]), $path);
                self::fail('Expected invalid cache path: ' . $path);
            } catch (InvalidBladeCachePath $error) {
                self::assertStringContainsString('cache path', $error->getMessage());
            }
        }
    }

    public function test_finder_rejects_dynamic_source_mutation(): void
    {
        $finder = new BladeViewFinder(new BladeViewPathResolver([]));
        $this->expectException(\LogicException::class);
        $finder->addLocation($this->root);
    }

    public function test_factory_rejects_replacing_the_source_finder(): void
    {
        $renderer = $this->renderer();
        $this->expectException(\LogicException::class);
        $renderer->factory()->setFinder(new BladeViewFinder(new BladeViewPathResolver([])));
    }

    public function test_factory_rejects_prepending_locations(): void
    {
        $renderer = $this->renderer();
        $this->expectException(\LogicException::class);
        $renderer->factory()->prependLocation($this->root);
    }

    public function test_finder_rejects_every_dynamic_mutation(): void
    {
        $finder = new BladeViewFinder(new BladeViewPathResolver([]));
        foreach ([
            static fn() => $finder->addNamespace('billing', '/tmp'),
            static fn() => $finder->prependNamespace('billing', '/tmp'),
            static fn() => $finder->replaceNamespace('billing', '/tmp'),
            static fn() => $finder->addExtension('php'),
        ] as $mutation) {
            try {
                $mutation();
                self::fail('Expected finder mutation rejection.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('view', $error->getMessage());
            }
        }
    }

    public function test_existing_non_writable_cache_directory_is_rejected(): void
    {
        $candidate = PHP_OS_FAMILY === 'Windows' ? (string) getenv('WINDIR') . '/System32' : '/proc';
        if (!is_dir($candidate) || is_writable($candidate)) {
            self::markTestSkipped('This host has no known non-writable system directory for this check.');
        }
        $this->expectException(InvalidBladeCachePath::class);
        new BladeViewRenderer(new BladeViewPathResolver([]), $candidate);
    }

    public function test_finder_requires_evolve_logical_names(): void
    {
        $finder = new BladeViewFinder(new BladeViewPathResolver([]));
        $this->expectException(\Evolve\View\Exception\InvalidViewName::class);
        $finder->find('billing.invoice');
    }

    /** @param array<string, mixed> $shared */
    private function renderer(array $shared = []): BladeViewRenderer
    {
        return new BladeViewRenderer(new BladeViewPathResolver([new ViewSource(null, $this->root)]), $this->cache, $shared);
    }
}
