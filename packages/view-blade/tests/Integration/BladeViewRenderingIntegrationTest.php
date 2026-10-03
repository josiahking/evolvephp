<?php

declare(strict_types=1);

namespace Evolve\View\Blade\Tests\Integration;

use Evolve\View\Blade\BladeViewPathResolver;
use Evolve\View\Blade\BladeViewRenderer;
use Evolve\View\Exception\ViewRenderFailed;
use Evolve\View\ViewSource;
use PHPUnit\Framework\TestCase;

final class BladeViewRenderingIntegrationTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            foreach (glob($root . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }

    public function test_plain_namespaced_include_layout_escaping_and_raw(): void
    {
        $global = $this->root();
        $billing = $this->root();
        $cache = $this->root();
        $this->template($global, 'layout', '<main>@yield("body")</main>');
        $this->template($global, 'page', "@extends('layout')\n@section('body')@include('billing::item')|{{ \$value }}|{!! \$value !!}@endsection");
        $this->template($billing, 'item', 'item');
        $renderer = new BladeViewRenderer(new BladeViewPathResolver([
            new ViewSource(null, $global),
            new ViewSource('billing', $billing),
        ]), $cache, ['value' => '<b>']);
        self::assertSame('<main>item|&lt;b&gt;|<b></main>', trim($renderer->render('page')));
        self::assertSame('<main>item|&lt;i&gt;|<i></main>', trim($renderer->render('page', ['value' => '<i>'])));
    }

    public function test_failed_render_does_not_poison_next_render_or_retain_data(): void
    {
        $root = $this->root();
        $cache = $this->root();
        $this->template($root, 'broken', '@section("stale")oops @include("missing")');
        $this->template($root, 'ok', '{{ $object->value }}@yield("stale")');
        $renderer = new BladeViewRenderer(new BladeViewPathResolver([new ViewSource(null, $root)]), $cache);
        try {
            $renderer->render('broken');
            self::fail('Expected Blade failure.');
        } catch (ViewRenderFailed $error) {
            self::assertNotNull($error->getPrevious());
        }
        $object = (object) ['value' => 'clean'];
        $reference = \WeakReference::create($object);
        self::assertSame('clean', trim($renderer->render('ok', ['object' => $object])));
        unset($object);
        self::assertNull($reference->get());
    }

    public function test_failed_loop_render_clears_loop_frames_before_next_render(): void
    {
        $root = $this->root();
        $cache = $this->root();
        $this->template($root, 'broken-loop', "@foreach (\$items as \$item)\n<?php throw new \\RuntimeException('loop failed'); ?>\n@endforeach");
        $this->template($root, 'fresh-loop', "@foreach (\$items as \$item){{ \$loop->depth }}:{{ \$loop->parent ? 'parent' : 'none' }}@endforeach");
        $renderer = new BladeViewRenderer(new BladeViewPathResolver([new ViewSource(null, $root)]), $cache);

        try {
            $renderer->render('broken-loop', ['items' => ['outer']]);
            self::fail('Expected render failure inside loop.');
        } catch (ViewRenderFailed $error) {
            self::assertNotNull($error->getPrevious());
        }

        self::assertSame('1:none', $renderer->render('fresh-loop', ['items' => ['fresh']]));
    }

    public function test_successful_renders_clear_sections_stacks_and_once_state(): void
    {
        $root = $this->root();
        $cache = $this->root();
        $this->template($root, 'state', "@section('title')\nold\n@endsection\n@push('scripts')\nscript\n@endpush\n@once('marker')\nmarker\n@endonce\n");
        $this->template($root, 'check', '@yield("title")@stack("scripts")');
        $renderer = new BladeViewRenderer(new BladeViewPathResolver([new ViewSource(null, $root)]), $cache);
        self::assertSame('marker', trim($renderer->render('state')));
        self::assertSame('', trim($renderer->render('check')));
        self::assertSame('marker', trim($renderer->render('state')));
    }

    public function test_failed_render_releases_its_object_data(): void
    {
        $root = $this->root();
        $cache = $this->root();
        $this->template($root, 'broken', '<?php throw new \\RuntimeException("failed"); ?>');
        $renderer = new BladeViewRenderer(new BladeViewPathResolver([new ViewSource(null, $root)]), $cache);
        $object = new \stdClass();
        $reference = \WeakReference::create($object);
        try {
            $renderer->render('broken', ['object' => $object]);
            self::fail('Expected Blade failure.');
        } catch (ViewRenderFailed $error) {
            self::assertNotNull($error->getPrevious());
        }
        unset($error, $object);
        self::assertNull($reference->get());
    }

    public function test_blade_syntax_failure_retains_the_engine_exception(): void
    {
        $root = $this->root();
        $cache = $this->root();
        $this->template($root, 'broken', '@if(true)unterminated');
        $renderer = new BladeViewRenderer(new BladeViewPathResolver([new ViewSource(null, $root)]), $cache);
        try {
            $renderer->render('broken');
            self::fail('Expected Blade syntax failure.');
        } catch (ViewRenderFailed $error) {
            self::assertInstanceOf(\Illuminate\View\ViewException::class, $error->getPrevious());
            self::assertInstanceOf(\ParseError::class, $error->getPrevious()->getPrevious());
        }
    }

    public function test_cleanup_failure_after_success_is_wrapped(): void
    {
        $root = $this->root();
        $cache = $this->root();
        $this->template($root, 'page', '<?php $__env->getEngineResolver()->register("blade", fn() => throw new \\RuntimeException("cleanup")); echo "ok"; ?>');
        $renderer = new BladeViewRenderer(new BladeViewPathResolver([new ViewSource(null, $root)]), $cache);
        try {
            $renderer->render('page');
            self::fail('Expected cleanup failure.');
        } catch (ViewRenderFailed $error) {
            self::assertSame('cleanup', $error->getPrevious()?->getMessage());
        }
    }

    public function test_cleanup_failure_does_not_mask_primary_render_failure(): void
    {
        $root = $this->root();
        $cache = $this->root();
        $this->template($root, 'page', '<?php $__env->getEngineResolver()->register("blade", fn() => throw new \\RuntimeException("cleanup")); throw new \\RuntimeException("primary"); ?>');
        $renderer = new BladeViewRenderer(new BladeViewPathResolver([new ViewSource(null, $root)]), $cache);
        try {
            $renderer->render('page');
            self::fail('Expected render failure.');
        } catch (ViewRenderFailed $error) {
            self::assertInstanceOf(\Illuminate\View\ViewException::class, $error->getPrevious());
            self::assertSame('primary', $error->getPrevious()->getPrevious()?->getMessage());
        }
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/evolve-blade-' . bin2hex(random_bytes(8));
        mkdir($root);
        $this->roots[] = $root;
        return $root;
    }

    private function template(string $root, string $name, string $source): void
    {
        file_put_contents($root . '/' . $name . '.blade.php', $source);
    }
}
